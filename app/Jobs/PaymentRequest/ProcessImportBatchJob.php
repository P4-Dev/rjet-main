<?php

declare(strict_types=1);

namespace App\Jobs\PaymentRequest;

use App\Models\ImportBatch;
use App\Services\ImportBatchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class ProcessImportBatchJob implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public ImportBatch $batch,
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) $this->batch->getKey()))->dontRelease(),
        ];
    }

    public function handle(ImportBatchService $service): void
    {
        $service->process($this->batch);
    }

    public function failed(?Throwable $exception): void
    {
        $reason = $exception?->getMessage() ?? 'Import job failed.';

        app(ImportBatchService::class)->markFailed($this->batch, $reason);

        logger()->error('ProcessImportBatchJob failed.', [
            'import_batch_id' => $this->batch->getKey(),
            'exception' => $reason,
        ]);
    }
}
