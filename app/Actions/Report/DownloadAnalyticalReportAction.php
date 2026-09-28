<?php

declare(strict_types=1);

namespace App\Actions\Report;

use App\Exceptions\AnalyticalReportException;
use App\Models\AnalyticalReport;
use App\Models\User;
use App\Services\AnalyticalReportService;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadAnalyticalReportAction
{
    public function __construct(
        private readonly AnalyticalReportService $reportService,
    ) {}

    /**
     * @throws AnalyticalReportException
     */
    public function __invoke(AnalyticalReport $report, User $actor, ?string $ipAddress = null): StreamedResponse
    {
        if (! $actor->can('download', $report)) {
            throw AnalyticalReportException::unauthorized();
        }

        return $this->reportService->download($report, $actor, $ipAddress);
    }
}
