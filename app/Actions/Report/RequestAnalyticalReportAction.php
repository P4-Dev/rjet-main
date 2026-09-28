<?php

declare(strict_types=1);

namespace App\Actions\Report;

use App\DTOs\AnalyticalReportData;
use App\Exceptions\AnalyticalReportException;
use App\Models\AnalyticalReport;
use App\Models\User;
use App\Services\AnalyticalReportService;

final class RequestAnalyticalReportAction
{
    public function __construct(
        private readonly AnalyticalReportService $reportService,
    ) {}

    /**
     * @throws AnalyticalReportException
     */
    public function __invoke(AnalyticalReportData $data, User $actor): AnalyticalReport
    {
        if (! $actor->can('create', AnalyticalReport::class)) {
            throw AnalyticalReportException::unauthorized();
        }

        return $this->reportService->request($data, $actor);
    }
}
