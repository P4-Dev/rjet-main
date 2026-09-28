<?php

declare(strict_types=1);

namespace App\Actions\Report;

use App\Exceptions\AnalyticalReportException;
use App\Models\AnalyticalReport;
use App\Models\User;
use App\Services\AnalyticalReportService;

final class RetryAnalyticalReportAction
{
    public function __construct(
        private readonly AnalyticalReportService $reportService,
    ) {}

    /**
     * @throws AnalyticalReportException
     */
    public function __invoke(AnalyticalReport $report, User $actor): AnalyticalReport
    {
        if (! $actor->can('retry', $report)) {
            throw AnalyticalReportException::unauthorized();
        }

        return $this->reportService->retry($report, $actor);
    }
}
