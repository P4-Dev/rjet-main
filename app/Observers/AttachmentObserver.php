<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Attachment;
use App\Models\AttachmentBatchItem;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class AttachmentObserver
{
    public function created(Attachment $attachment): void
    {
        $this->syncAttachableFlag($attachment);
    }

    public function deleted(Attachment $attachment): void
    {
        $this->syncAttachableFlag($attachment);
    }

    public function restored(Attachment $attachment): void
    {
        $this->syncAttachableFlag($attachment);
    }

    /**
     * `attachment_batch_items.attachment_id` is restrictOnDelete, and rebound attachments keep their batch item
     * after moving to a PaymentRequest. Removing the items here lets PaymentRequest/Company force deletes succeed;
     * classifications follow through the item FK cascade.
     */
    public function forceDeleting(Attachment $attachment): void
    {
        AttachmentBatchItem::withTrashed()
            ->where('attachment_id', $attachment->getKey())
            ->forceDelete();
    }

    public function forceDeleted(Attachment $attachment): void
    {
        try {
            Storage::disk($attachment->disk)->delete($attachment->path);
        } catch (Throwable) {
            // Ignore missing files on cleanup.
        }

        $this->syncAttachableFlag($attachment);
    }

    private function syncAttachableFlag(Attachment $attachment): void
    {
        $attachable = $attachment->attachable;

        if ($attachable !== null && method_exists($attachable, 'syncHasAttachmentsFlag')) {
            $attachable->syncHasAttachmentsFlag();
        }
    }
}
