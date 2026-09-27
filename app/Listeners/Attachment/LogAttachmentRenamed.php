<?php

declare(strict_types=1);

namespace App\Listeners\Attachment;

use App\Events\Attachment\AttachmentRenamed;
use Illuminate\Support\Facades\Log;

final class LogAttachmentRenamed
{
    public function handle(AttachmentRenamed $event): void
    {
        Log::info('Attachment renamed from batch.', [
            'attachment_id' => $event->attachment->getKey(),
            'attachment_batch_id' => $event->item->attachment_batch_id,
            'attachment_batch_item_id' => $event->item->getKey(),
            'standardized_name' => $event->attachment->standardized_name,
            'attachable_type' => $event->attachment->attachable_type,
            'attachable_id' => $event->attachment->attachable_id,
        ]);
    }
}
