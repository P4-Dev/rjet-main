<?php

declare(strict_types=1);

namespace App\Events\Report;

use App\Models\AnalyticalReport;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class AnalyticalReportGenerationFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly AnalyticalReport $report,
    ) {}
}
