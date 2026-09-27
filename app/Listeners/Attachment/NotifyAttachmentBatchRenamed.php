<?php

declare(strict_types=1);

namespace App\Listeners\Attachment;

use App\Events\Attachment\AttachmentBatchRenamed;
use App\Notifications\AttachmentBatchRenamedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

final class NotifyAttachmentBatchRenamed implements ShouldQueue
{
    public function handle(AttachmentBatchRenamed $event): void
    {
        $event->batch->loadMissing('creator');
        $creator = $event->batch->creator;

        if ($creator === null) {
            return;
        }

        $creator->notify(new AttachmentBatchRenamedNotification($event->batch));
    }
}
