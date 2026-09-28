<?php

declare(strict_types=1);

namespace App\Listeners\Report;

use App\Events\Report\AnalyticalReportRequested;
use App\Jobs\Report\GenerateAnalyticalReportJob;

final class QueueAnalyticalReportGeneration
{
    public function handle(AnalyticalReportRequested $event): void
    {
        GenerateAnalyticalReportJob::dispatch($event->report);
    }
}
