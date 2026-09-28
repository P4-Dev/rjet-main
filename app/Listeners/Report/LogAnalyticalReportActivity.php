<?php

declare(strict_types=1);

namespace App\Listeners\Report;

use App\Events\Report\AnalyticalReportDownloaded;
use App\Events\Report\AnalyticalReportGenerated;
use App\Events\Report\AnalyticalReportGenerationFailed;
use App\Events\Report\AnalyticalReportRequested;
use App\Models\AnalyticalReport;
use Illuminate\Support\Facades\Log;

final class LogAnalyticalReportActivity
{
    public function handleRequested(AnalyticalReportRequested $event): void
    {
        Log::info('Analytical report requested.', [
            ...$this->context($event->report),
            'user_id' => $event->report->updated_by ?? $event->report->created_by,
        ]);
    }

    public function handleGenerated(AnalyticalReportGenerated $event): void
    {
        Log::info('Analytical report generated.', [
            ...$this->context($event->report),
            'user_id' => $event->report->created_by,
            'rows_count' => $event->report->rows_count,
            'attachments_count' => $event->report->attachments_count,
            'size' => $event->report->size,
        ]);
    }

    public function handleFailed(AnalyticalReportGenerationFailed $event): void
    {
        Log::warning('Analytical report generation failed.', [
            ...$this->context($event->report),
            'user_id' => $event->report->created_by,
            'failure_reason' => $event->report->failure_reason,
        ]);
    }

    public function handleDownloaded(AnalyticalReportDownloaded $event): void
    {
        Log::info('Analytical report downloaded.', [
            ...$this->context($event->report),
            'user_id' => $event->user->getKey(),
            'ip_address' => $event->ipAddress,
            'rows_count' => $event->report->rows_count,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function context(AnalyticalReport $report): array
    {
        return [
            'analytical_report_id' => $report->getKey(),
            'status' => $report->status->value,
        ];
    }
}
