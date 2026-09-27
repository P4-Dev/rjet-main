<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\AttachmentBatch;
use App\Services\AttachmentBatchService;

/**
 * Soft deletes never trigger FK cascades, so the batch → items → attachments cascade lives here.
 * Attachments already rebound to a PaymentRequest are never touched.
 */
final class AttachmentBatchObserver
{
    public function __construct(
        private readonly AttachmentBatchService $attachmentBatchService,
    ) {}

    public function deleted(AttachmentBatch $batch): void
    {
        if ($batch->isForceDeleting()) {
            return;
        }

        $this->attachmentBatchService->softDeleteItems($batch);
    }

    public function restored(AttachmentBatch $batch): void
    {
        $this->attachmentBatchService->restoreItems($batch);
    }

    public function forceDeleting(AttachmentBatch $batch): void
    {
        $this->attachmentBatchService->forceDeleteItems($batch);
    }
}
