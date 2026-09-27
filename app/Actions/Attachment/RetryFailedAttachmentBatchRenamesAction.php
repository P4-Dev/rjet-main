<?php

declare(strict_types=1);

namespace App\Actions\Attachment;

use App\Exceptions\AttachmentException;
use App\Jobs\Attachment\RenameAttachmentBatchJob;
use App\Models\AttachmentBatch;
use App\Models\User;

final class RetryFailedAttachmentBatchRenamesAction
{
    /**
     * @throws AttachmentException
     */
    public function __invoke(AttachmentBatch $batch, User $actor): void
    {
        if (! $actor->can('classify', $batch)) {
            throw AttachmentException::unauthorizedBatchOperation();
        }

        $batch->refresh();

        if (! $batch->isRenameRetryable()) {
            throw AttachmentException::batchNotRetryable($batch->status->value);
        }

        RenameAttachmentBatchJob::dispatch($batch);
    }
}
