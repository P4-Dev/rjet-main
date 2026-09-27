<?php

declare(strict_types=1);

namespace App\Jobs\Attachment;

use App\Exceptions\BusinessException;
use App\Models\AttachmentBatch;
use App\Services\AttachmentBatchNamingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class RenameAttachmentBatchJob implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public AttachmentBatch $batch,
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) $this->batch->getKey()))
                ->dontRelease()
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(AttachmentBatchNamingService $service): void
    {
        $service->renameBatch($this->batch);
    }

    /**
     * The raw exception message goes only to the log; `failure_reason` is shown in the UI.
     */
    public function failed(?Throwable $exception): void
    {
        $reason = $exception instanceof BusinessException
            ? $exception->getUserMessage()
            : __('attachment_batches.messages.rename_interrupted');

        app(AttachmentBatchNamingService::class)->markFailed($this->batch, $reason);

        logger()->error('RenameAttachmentBatchJob failed.', [
            'attachment_batch_id' => $this->batch->getKey(),
            'exception' => $exception?->getMessage(),
            'exception_class' => $exception !== null ? $exception::class : null,
        ]);
    }
}
