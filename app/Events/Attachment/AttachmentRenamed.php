<?php

declare(strict_types=1);

namespace App\Events\Attachment;

use App\Models\Attachment;
use App\Models\AttachmentBatchItem;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class AttachmentRenamed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Attachment $attachment,
        public readonly AttachmentBatchItem $item,
    ) {}
}
