<?php

declare(strict_types=1);

namespace App\Jobs\Report;

use App\Exceptions\BusinessException;
use App\Models\AnalyticalReport;
use App\Services\AnalyticalReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class GenerateAnalyticalReportJob implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 75;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public AnalyticalReport $report,
    ) {
        $this->onQueue((string) config('rjet.reports.queue'));
    }

    public function handle(AnalyticalReportService $service): void
    {
        $service->generate($this->report);
    }

    /**
     * The raw exception message goes only to the log.
     */
    public function failed(?Throwable $exception): void
    {
        $report = $this->report->fresh();

        if ($report !== null && ! $report->status->isTerminal()) {
            $reason = $exception instanceof BusinessException
                ? $exception->getUserMessage()
                : __('analytical_reports.messages.generation_interrupted');

            app(AnalyticalReportService::class)->markFailed($report, $reason);
        }

        logger()->error('GenerateAnalyticalReportJob failed.', [
            'analytical_report_id' => $this->report->getKey(),
            'exception' => $exception?->getMessage(),
            'exception_class' => $exception !== null ? $exception::class : null,
        ]);
    }
}
