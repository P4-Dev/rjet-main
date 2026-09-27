<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\AttachmentBatchItemClassificationData;
use App\Enums\AttachmentBatchDestinationType;
use App\Enums\AttachmentBatchItemStatus;
use App\Enums\AttachmentBatchStatus;
use App\Events\Attachment\AttachmentBatchClassified;
use App\Exceptions\AttachmentException;
use App\Models\AttachmentBatch;
use App\Models\AttachmentBatchItem;
use App\Models\AttachmentBatchItemClassification;
use App\Models\PaymentRequest;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

final class AttachmentBatchClassificationService
{
    public const OPERATIONAL_LABEL_MAX_LENGTH = 120;

    public function __construct(
        private readonly AttachmentBatchService $attachmentBatchService,
    ) {}

    /**
     * Every save appends a new history row; previous classifications are never updated.
     *
     * @throws AttachmentException
     */
    public function classifyItem(
        AttachmentBatchItem $item,
        AttachmentBatchItemClassificationData $data,
        User $actor,
    ): AttachmentBatchItem {
        $this->assertActorCanClassify($actor);
        $this->assertDestination($data, $actor);

        DB::transaction(function () use ($item, $data, $actor): void {
            $batch = $this->lockPendingBatch((string) $item->attachment_batch_id);
            $classifiedAt = now();

            $item->forceFill([
                ...$data->toModelAttributes(),
                'status' => AttachmentBatchItemStatus::Classified,
                'classified_by' => $actor->getKey(),
                'classified_at' => $classifiedAt,
                'rename_error' => null,
            ])->save();

            AttachmentBatchItemClassification::query()->create([
                ...$data->toModelAttributes(),
                'attachment_batch_item_id' => $item->getKey(),
                'user_id' => $actor->getKey(),
                'classified_at' => $classifiedAt,
            ]);

            $this->attachmentBatchService->syncCounters($batch);
        });

        return $item->refresh();
    }

    /**
     * Ids outside the batch are ignored; items missing from the list keep their relative order at the end.
     *
     * @param  list<string>  $orderedItemIds
     *
     * @throws AttachmentException
     */
    public function reorder(AttachmentBatch $batch, array $orderedItemIds, User $actor): void
    {
        $this->assertActorCanClassify($actor);

        DB::transaction(function () use ($batch, $orderedItemIds): void {
            $this->lockPendingBatch((string) $batch->getKey());

            $currentIds = AttachmentBatchItem::query()
                ->where('attachment_batch_id', $batch->getKey())
                ->orderBy('sort_order')
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all();

            $requestedIds = array_values(array_intersect(array_map('strval', $orderedItemIds), $currentIds));
            $finalOrder = array_values(array_unique([...$requestedIds, ...$currentIds]));

            foreach ($finalOrder as $position => $itemId) {
                AttachmentBatchItem::query()
                    ->whereKey($itemId)
                    ->update(['sort_order' => $position]);
            }
        });
    }

    /**
     * @throws AttachmentException
     */
    public function conclude(AttachmentBatch $batch, User $actor): AttachmentBatch
    {
        $this->assertActorCanClassify($actor);

        $batch = DB::transaction(function () use ($batch): AttachmentBatch {
            $locked = $this->lockPendingBatch((string) $batch->getKey());

            if (! $locked->allItemsClassified()) {
                throw AttachmentException::batchClassificationIncomplete();
            }

            if (! $locked->status->canTransitionTo(AttachmentBatchStatus::Classified)) {
                throw AttachmentException::batchNotClassifiable($locked->status->value);
            }

            $locked->update([
                'status' => AttachmentBatchStatus::Classified,
                'classified_at' => now(),
            ]);

            $this->attachmentBatchService->syncCounters($locked);

            return $locked;
        });

        Event::dispatch(new AttachmentBatchClassified($batch));

        return $batch;
    }

    /**
     * @throws AttachmentException
     */
    public function assertDestination(AttachmentBatchItemClassificationData $data, User $actor): void
    {
        $isValid = match ($data->destinationType) {
            AttachmentBatchDestinationType::PaymentRequest => $data->paymentRequestId !== null
                && $data->supplierId === null
                && $data->operationalLabel === null
                && $this->actorCanViewPaymentRequest($data->paymentRequestId, $actor),
            AttachmentBatchDestinationType::Supplier => $data->supplierId !== null
                && $data->paymentRequestId === null
                && $data->operationalLabel === null
                && Supplier::query()->whereKey($data->supplierId)->exists(),
            AttachmentBatchDestinationType::OperationalCategory => $data->operationalLabel !== null
                && $data->operationalLabel !== ''
                && mb_strlen($data->operationalLabel) <= self::OPERATIONAL_LABEL_MAX_LENGTH
                && $data->paymentRequestId === null
                && $data->supplierId === null,
        };

        if (! $isValid) {
            throw AttachmentException::invalidDestination();
        }
    }

    /**
     * @throws AttachmentException
     */
    private function lockPendingBatch(string $batchId): AttachmentBatch
    {
        /** @var AttachmentBatch|null $batch */
        $batch = AttachmentBatch::query()->whereKey($batchId)->lockForUpdate()->first();

        if ($batch === null) {
            throw AttachmentException::batchNotClassifiable('missing');
        }

        if (! $batch->isPendingClassification()) {
            throw AttachmentException::batchNotClassifiable($batch->status->value);
        }

        return $batch;
    }

    /**
     * @throws AttachmentException
     */
    private function assertActorCanClassify(User $actor): void
    {
        if (! ($actor->isOperador() || $actor->isAdm())) {
            throw AttachmentException::unauthorizedBatchOperation();
        }
    }

    private function actorCanViewPaymentRequest(string $paymentRequestId, User $actor): bool
    {
        $paymentRequest = PaymentRequest::query()->find($paymentRequestId);

        return $paymentRequest !== null && $actor->can('view', $paymentRequest);
    }
}
