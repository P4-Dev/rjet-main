<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Attachment;
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
