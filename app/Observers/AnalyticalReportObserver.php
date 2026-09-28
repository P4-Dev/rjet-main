<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\AnalyticalReport;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Storage;

final class AnalyticalReportObserver implements ShouldHandleEventsAfterCommit
{
    public function forceDeleted(AnalyticalReport $report): void
    {
        if (blank($report->disk) || blank($report->path)) {
            return;
        }

        Storage::disk($report->disk)->delete($report->path);
    }
}
