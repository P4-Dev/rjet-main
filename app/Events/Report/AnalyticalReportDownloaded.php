<?php

declare(strict_types=1);

namespace App\Events\Report;

use App\Models\AnalyticalReport;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class AnalyticalReportDownloaded
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly AnalyticalReport $report,
        public readonly User $user,
        public readonly ?string $ipAddress = null,
    ) {}
}
