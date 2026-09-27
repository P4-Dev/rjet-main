<?php

declare(strict_types=1);

namespace App\Listeners\Attachment;

use App\Events\Attachment\AttachmentBatchClassified;
use App\Jobs\Attachment\RenameAttachmentBatchJob;

final class QueueAttachmentBatchRename
{
    public function handle(AttachmentBatchClassified $event): void
    {
        RenameAttachmentBatchJob::dispatch($event->batch);
    }
}
