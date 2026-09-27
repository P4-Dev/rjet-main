<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AttachmentBatchItemStatus;
use App\Enums\AttachmentBatchStatus;
use App\Enums\AttachmentType;
use App\Exceptions\AttachmentException;
use App\Models\Attachment;
use App\Models\AttachmentBatch;
use App\Models\AttachmentBatchItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class AttachmentBatchService
{
    public function __construct(
        private readonly AttachmentService $attachmentService,
    ) {}

    /**
     * @param  array<int|string, mixed>  $files
     *
     * @throws AttachmentException
     */
    public function createFromUploads(array $files, User $actor): AttachmentBatch
    {
        if (! ($actor->isOperador() || $actor->isAdm())) {
            throw AttachmentException::unauthorizedBatchOperation();
        }

        $files = array_values(array_filter($files, static fn (mixed $file): bool => $file instanceof UploadedFile));

        if ($files === []) {
            throw AttachmentException::batchEmpty();
        }

        foreach ($files as $file) {
            $this->attachmentService->assertUploadedFileIsAllowed($file);
        }

        /** @var list<Attachment> $storedAttachments */
        $storedAttachments = [];

        try {
            return DB::transaction(function () use ($files, $actor, &$storedAttachments): AttachmentBatch {
                /** @var AttachmentBatch $batch */
                $batch = AttachmentBatch::query()->create([
                    'status' => AttachmentBatchStatus::PendingClassification,
                ]);

                if ($batch->created_by === null) {
                    $batch->forceFill([
                        'created_by' => $actor->getKey(),
                        'updated_by' => $actor->getKey(),
                    ])->saveQuietly();
                }

                foreach ($files as $index => $file) {
                    $attachment = $this->attachmentService->storeUploadedFile(
                        $batch,
                        $file,
                        AttachmentType::Other,
                        $index,
                    );
                    $storedAttachments[] = $attachment;

                    AttachmentBatchItem::query()->create([
                        'attachment_batch_id' => $batch->getKey(),
                        'attachment_id' => $attachment->getKey(),
                        'sort_order' => $index,
                        'status' => AttachmentBatchItemStatus::Pending,
                    ]);
                }

                $this->syncCounters($batch);

                return $batch;
            });
        } catch (Throwable $e) {
            foreach ($storedAttachments as $attachment) {
                Storage::disk($attachment->disk)->delete($attachment->path);
            }

            throw $e;
        }
    }

    public function syncCounters(AttachmentBatch $batch): void
    {
        /** @var array<string, int> $counts */
        $counts = AttachmentBatchItem::query()
            ->where('attachment_batch_id', $batch->getKey())
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();

        $total = array_sum($counts);
        $pending = $counts[AttachmentBatchItemStatus::Pending->value] ?? 0;

        $batch->forceFill([
            'items_count' => $total,
            'classified_count' => $total - $pending,
            'renamed_count' => $counts[AttachmentBatchItemStatus::Renamed->value] ?? 0,
            'failed_count' => $counts[AttachmentBatchItemStatus::Failed->value] ?? 0,
        ])->saveQuietly();
    }

    /**
     * @throws AttachmentException
     */
    public function delete(AttachmentBatch $batch, User $actor): void
    {
        if (! $actor->can('delete', $batch)) {
            throw AttachmentException::unauthorizedBatchOperation();
        }

        $batch->delete();
    }

    /**
     * @throws AttachmentException
     */
    public function forceDelete(AttachmentBatch $batch, User $actor): void
    {
        if (! $actor->can('forceDelete', $batch)) {
            throw AttachmentException::unauthorizedBatchOperation();
        }

        $batch->forceDelete();
    }

    /**
     * Soft-deletes the item; its Attachment is soft-deleted only while still bound to the batch.
     */
    public function softDeleteItem(AttachmentBatchItem $item): void
    {
        $item->loadMissing('attachment');
        $attachment = $item->attachment;

        $item->delete();

        if ($attachment !== null && $this->isBoundToBatch($attachment, (string) $item->attachment_batch_id)) {
            $attachment->delete();
        }
    }

    /**
     * Force-deletes the item (classifications cascade via FK); the Attachment goes only while still bound to the batch.
     */
    public function forceDeleteItem(AttachmentBatchItem $item): void
    {
        $attachment = Attachment::withTrashed()->find($item->attachment_id);

        $item->forceDelete();

        if ($attachment !== null && $this->isBoundToBatch($attachment, (string) $item->attachment_batch_id)) {
            $attachment->forceDelete();
        }
    }

    public function softDeleteItems(AttachmentBatch $batch): void
    {
        $items = AttachmentBatchItem::query()
            ->where('attachment_batch_id', $batch->getKey())
            ->with('attachment.attachable')
            ->get();

        foreach ($items as $item) {
            $this->softDeleteItem($item);
        }
    }

    public function forceDeleteItems(AttachmentBatch $batch): void
    {
        $items = AttachmentBatchItem::withTrashed()
            ->where('attachment_batch_id', $batch->getKey())
            ->get();

        foreach ($items as $item) {
            $this->forceDeleteItem($item);
        }
    }

    public function restoreItems(AttachmentBatch $batch): void
    {
        $items = AttachmentBatchItem::onlyTrashed()
            ->where('attachment_batch_id', $batch->getKey())
            ->get();

        foreach ($items as $item) {
            $item->restore();
        }

        $attachments = Attachment::onlyTrashed()
            ->where('attachable_type', $batch->getMorphClass())
            ->where('attachable_id', $batch->getKey())
            ->with('attachable')
            ->get();

        foreach ($attachments as $attachment) {
            $attachment->restore();
        }
    }

    private function isBoundToBatch(Attachment $attachment, string $batchId): bool
    {
        return $attachment->attachable_type === (new AttachmentBatch)->getMorphClass()
            && (string) $attachment->attachable_id === $batchId;
    }
}
