<?php

declare(strict_types=1);

namespace App\Actions\Attachment;

use App\DTOs\AttachmentBatchItemClassificationData;
use App\Exceptions\AttachmentException;
use App\Models\AttachmentBatchItem;
use App\Models\User;
use App\Services\AttachmentBatchClassificationService;

final class ClassifyAttachmentBatchItemAction
{
    public function __construct(
        private readonly AttachmentBatchClassificationService $classificationService,
    ) {}

    /**
     * @throws AttachmentException
     */
    public function __invoke(
        AttachmentBatchItem $item,
        AttachmentBatchItemClassificationData $data,
        User $actor,
    ): AttachmentBatchItem {
        if (! $actor->can('classify', $item->loadMissing('batch'))) {
            throw AttachmentException::unauthorizedBatchOperation();
        }

        return $this->classificationService->classifyItem($item, $data, $actor);
    }
}
