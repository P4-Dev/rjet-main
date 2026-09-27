<?php

declare(strict_types=1);

namespace App\Actions\Attachment;

use App\Exceptions\AttachmentException;
use App\Models\AttachmentBatch;
use App\Models\User;
use App\Services\AttachmentBatchService;

final class CreateAttachmentBatchAction
{
    public function __construct(
        private readonly AttachmentBatchService $attachmentBatchService,
    ) {}

    /**
     * @param  array<int|string, mixed>  $files
     *
     * @throws AttachmentException
     */
    public function __invoke(array $files, User $actor): AttachmentBatch
    {
        if (! $actor->can('create', AttachmentBatch::class)) {
            throw AttachmentException::unauthorizedBatchOperation();
        }

        return $this->attachmentBatchService->createFromUploads($files, $actor);
    }
}
