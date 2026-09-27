<?php

declare(strict_types=1);

namespace App\Actions\Attachment;

use App\Exceptions\AttachmentException;
use App\Models\AttachmentBatch;
use App\Models\User;
use App\Services\AttachmentBatchClassificationService;

final class ReorderAttachmentBatchItemsAction
{
    public function __construct(
        private readonly AttachmentBatchClassificationService $classificationService,
    ) {}

    /**
     * @param  list<string>  $orderedItemIds
     *
     * @throws AttachmentException
     */
    public function __invoke(AttachmentBatch $batch, array $orderedItemIds, User $actor): void
    {
        if (! $actor->can('classify', $batch)) {
            throw AttachmentException::unauthorizedBatchOperation();
        }

        $this->classificationService->reorder($batch, $orderedItemIds, $actor);
    }
}
