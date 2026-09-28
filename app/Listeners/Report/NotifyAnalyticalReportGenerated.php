<?php

declare(strict_types=1);

namespace App\Listeners\Report;

use App\Events\Report\AnalyticalReportGenerated;
use App\Notifications\AnalyticalReportGeneratedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

final class NotifyAnalyticalReportGenerated implements ShouldQueue
{
    public bool $afterCommit = true;

    public function handle(AnalyticalReportGenerated $event): void
    {
        $event->report->loadMissing('creator');
        $creator = $event->report->creator;

        if ($creator === null || ! $creator->is_active) {
            return;
        }

        $creator->notify(new AnalyticalReportGeneratedNotification($event->report));
    }
}
