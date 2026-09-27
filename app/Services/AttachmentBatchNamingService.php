<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\AttachmentNamingResult;
use App\Enums\AttachmentBatchDestinationType;
use App\Enums\AttachmentBatchItemStatus;
use App\Enums\AttachmentBatchStatus;
use App\Events\Attachment\AttachmentBatchRenamed;
use App\Events\Attachment\AttachmentRenamed;
use App\Exceptions\AttachmentException;
use App\Models\Attachment;
use App\Models\AttachmentBatch;
use App\Models\AttachmentBatchItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Throwable;

final class AttachmentBatchNamingService
{
    public const MAX_COLLISION_SUFFIX = 99;

    public const MIN_SEQUENCE_PADDING = 3;

    public function __construct(
        private readonly AttachmentService $attachmentService,
        private readonly AttachmentBatchService $attachmentBatchService,
    ) {}

    /**
     * Builds `YYYYMMDD_HHMMSS_SEQ` (without extension). SEQ is 1-based, zero-padded to at least 3 digits.
     */
    public static function formatBaseName(
        CarbonInterface $generatedAt,
        int $position,
        int $totalItems,
        string $timezone,
    ): string {
        $padding = max(self::MIN_SEQUENCE_PADDING, strlen((string) max(1, $totalItems)));

        return sprintf(
            '%s_%s',
            $generatedAt->copy()->setTimezone($timezone)->format('Ymd_His'),
            str_pad((string) $position, $padding, '0', STR_PAD_LEFT),
        );
    }

    /**
     * Idempotent: renamed batches are skipped; retries only process items still `classified` or `failed`.
     */
    public function renameBatch(AttachmentBatch $batch): void
    {
        $batch->refresh();

        if (! $this->startRenaming($batch)) {
            return;
        }

        $items = $batch->items()->with(['attachment', 'paymentRequest'])->get();
        $totalItems = $items->count();
        $timezone = (string) config('app.timezone');

        foreach ($items->values() as $index => $item) {
            if (! in_array($item->status, [AttachmentBatchItemStatus::Classified, AttachmentBatchItemStatus::Failed], true)) {
                continue;
            }

            $baseName = self::formatBaseName($batch->naming_generated_at, $index + 1, $totalItems, $timezone);

            try {
                $this->renameItem($batch, $item, $baseName);
            } catch (AttachmentException $e) {
                logger()->warning('Attachment batch item rename failed.', [
                    'attachment_batch_id' => $batch->getKey(),
                    'attachment_batch_item_id' => $item->getKey(),
                    'exception' => $e->getMessage(),
                ]);

                $this->markItemFailed($item, $e->getUserMessage());
            } catch (Throwable $e) {
                logger()->error('Unexpected attachment batch item rename failure.', [
                    'attachment_batch_id' => $batch->getKey(),
                    'attachment_batch_item_id' => $item->getKey(),
                    'exception' => $e->getMessage(),
                ]);

                $this->markItemFailed(
                    $item,
                    AttachmentException::storageMoveFailed((string) $item->attachment_id)->getUserMessage(),
                );
            }
        }

        $this->finishRenaming($batch);
    }

    /**
     * Moves the file to its standardized name; PaymentRequest destinations rebind the same Attachment row.
     *
     * @throws AttachmentException
     */
    public function renameItem(AttachmentBatch $batch, AttachmentBatchItem $item, string $baseName): AttachmentNamingResult
    {
        $attachment = $item->attachment;

        if ($attachment === null) {
            throw AttachmentException::fileNotFound((string) $item->attachment_id);
        }

        $paymentRequest = null;

        if ($item->destination_type === AttachmentBatchDestinationType::PaymentRequest) {
            $paymentRequest = $item->paymentRequest;

            if ($paymentRequest === null) {
                throw AttachmentException::invalidDestination();
            }
        }

        $result = $this->resolveName(
            $attachment,
            $baseName,
            $this->attachmentService->directoryFor($paymentRequest ?? $batch),
        );

        $attributes = ['standardized_name' => $result->standardizedName];

        if ($paymentRequest !== null) {
            $attributes['attachable_type'] = $paymentRequest->getMorphClass();
            $attributes['attachable_id'] = $paymentRequest->getKey();
        }

        $this->attachmentService->moveAndUpdatePath(
            $attachment,
            $result->path,
            $attributes,
            function () use ($item): void {
                $item->forceFill([
                    'status' => AttachmentBatchItemStatus::Renamed,
                    'renamed_at' => now(),
                    'rename_error' => null,
                ])->save();
            },
        );

        if ($paymentRequest !== null) {
            $this->attachmentService->syncAttachableFlags($paymentRequest, $batch);
        }

        Event::dispatch(new AttachmentRenamed($attachment, $item));

        return $result;
    }

    /**
     * @throws AttachmentException
     */
    public function resolveName(Attachment $attachment, string $baseName, string $directory): AttachmentNamingResult
    {
        $extension = $this->attachmentService->extensionFor($attachment->mime_type, $attachment->original_name);

        for ($suffix = 1; $suffix <= self::MAX_COLLISION_SUFFIX; $suffix++) {
            $name = $suffix === 1
                ? "{$baseName}.{$extension}"
                : "{$baseName}_{$suffix}.{$extension}";
            $path = $directory.'/'.$name;

            if (
                $path === $attachment->path
                || ! $this->attachmentService->isPathTaken($attachment->disk, $path, (string) $attachment->getKey())
            ) {
                return new AttachmentNamingResult(
                    standardizedName: $name,
                    path: $path,
                    collisionSuffix: $suffix === 1 ? null : $suffix,
                );
            }
        }

        throw AttachmentException::namingCollisionUnresolved($baseName);
    }

    public function markFailed(AttachmentBatch $batch, string $reason): void
    {
        $batch->refresh();

        if ($batch->status !== AttachmentBatchStatus::Renaming) {
            return;
        }

        $this->attachmentBatchService->syncCounters($batch);

        $batch->update([
            'status' => AttachmentBatchStatus::Failed,
            'failure_reason' => Str::limit($reason, 2000),
        ]);
    }

    private function startRenaming(AttachmentBatch $batch): bool
    {
        if ($batch->status === AttachmentBatchStatus::Renaming) {
            return true;
        }

        $isRetry = in_array($batch->status, [AttachmentBatchStatus::PartiallyFailed, AttachmentBatchStatus::Failed], true);

        if (
            ! $batch->status->canTransitionTo(AttachmentBatchStatus::Renaming)
            || ($isRetry && ! $batch->hasItemsPendingRename())
        ) {
            logger()->info('Attachment batch rename skipped.', [
                'attachment_batch_id' => $batch->getKey(),
                'status' => $batch->status->value,
            ]);

            return false;
        }

        $batch->update([
            'status' => AttachmentBatchStatus::Renaming,
            'renaming_started_at' => now(),
            'naming_generated_at' => $batch->naming_generated_at ?? now(),
            'failure_reason' => null,
        ]);

        return true;
    }

    private function finishRenaming(AttachmentBatch $batch): void
    {
        $this->attachmentBatchService->syncCounters($batch);
        $batch->refresh();

        $status = match (true) {
            $batch->items_count > 0 && $batch->renamed_count === $batch->items_count => AttachmentBatchStatus::Renamed,
            $batch->renamed_count > 0 => AttachmentBatchStatus::PartiallyFailed,
            default => AttachmentBatchStatus::Failed,
        };

        $batch->update([
            'status' => $status,
            'renamed_at' => $status === AttachmentBatchStatus::Renamed ? now() : null,
            'failure_reason' => $status === AttachmentBatchStatus::Failed
                ? __('attachment_batches.messages.rename_failed')
                : null,
        ]);

        if ($status === AttachmentBatchStatus::Renamed) {
            Event::dispatch(new AttachmentBatchRenamed($batch->fresh(['creator']) ?? $batch));
        }
    }

    private function markItemFailed(AttachmentBatchItem $item, string $error): void
    {
        $item->forceFill([
            'status' => AttachmentBatchItemStatus::Failed,
            'rename_error' => Str::limit($error, 500),
        ])->save();
    }
}
