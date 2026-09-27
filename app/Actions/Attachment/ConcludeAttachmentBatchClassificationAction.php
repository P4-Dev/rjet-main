<?php

declare(strict_types=1);

namespace App\Actions\Attachment;

use App\Exceptions\AttachmentException;
use App\Models\AttachmentBatch;
use App\Models\User;
use App\Services\AttachmentBatchClassificationService;

final class ConcludeAttachmentBatchClassificationAction
{
    public function __construct(
        private readonly AttachmentBatchClassificationService $classificationService,
    ) {}

    /**
     * @throws AttachmentException
     */
    public function __invoke(AttachmentBatch $batch, User $actor): AttachmentBatch
    {
        if (! $actor->can('classify', $batch)) {
            throw AttachmentException::unauthorizedBatchOperation();
        }

        return $this->classificationService->conclude($batch, $actor);
    }
}
