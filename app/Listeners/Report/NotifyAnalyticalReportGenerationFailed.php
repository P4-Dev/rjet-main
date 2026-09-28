<?php

declare(strict_types=1);

namespace App\Listeners\Report;

use App\Events\Report\AnalyticalReportGenerationFailed;
use App\Notifications\AnalyticalReportGenerationFailedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

final class NotifyAnalyticalReportGenerationFailed implements ShouldQueue
{
    public bool $afterCommit = true;

    public function handle(AnalyticalReportGenerationFailed $event): void
    {
        $event->report->loadMissing('creator');
        $creator = $event->report->creator;

        if ($creator === null || ! $creator->is_active) {
            return;
        }

        $creator->notify(new AnalyticalReportGenerationFailedNotification($event->report));
    }
}
