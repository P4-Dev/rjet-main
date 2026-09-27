<?php

declare(strict_types=1);

namespace App\Events\Attachment;

use App\Models\AttachmentBatch;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class AttachmentBatchClassified
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly AttachmentBatch $batch,
    ) {}
}
