<?php

declare(strict_types=1);

namespace App\Jobs\Cnab;

use App\Exceptions\BusinessException;
use App\Models\CnabFile;
use App\Services\CnabFileService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class GenerateCnabFileJob implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 10;

    public int $maxExceptions = 3;

    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    /**
     * Timing invariant: timeout (120) < lock expiry (180) < CNAB connection retry_after (240).
     */
    public function __construct(
        public CnabFile $file,
    ) {
        $this->onConnection(config('rjet.cnab.queue.connection'));
        $this->onQueue(config('rjet.cnab.queue.name'));
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) $this->file->payment_settlement_id))
                ->releaseAfter(30)
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(CnabFileService $service): void
    {
        $service->generate($this->file);
    }

    /**
     * An assigned file sequence stays consumed; the raw exception message goes only to the log.
     */
    public function failed(?Throwable $exception): void
    {
        $file = $this->file->fresh();

        if ($file !== null && ! $file->status->isTerminal()) {
            $reason = $exception instanceof BusinessException
                ? $exception->getUserMessage()
                : __('cnab_files.messages.generation_interrupted');

            app(CnabFileService::class)->markFailed($file, $reason);
        }

        logger()->error('GenerateCnabFileJob failed.', [
            'cnab_file_id' => $this->file->getKey(),
            'exception' => $exception?->getMessage(),
            'exception_class' => $exception !== null ? $exception::class : null,
        ]);
    }
}
