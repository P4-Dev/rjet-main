<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\AnalyticalReport;
use App\Models\Branch;

final class BranchObserver
{
    /**
     * Reports reference branches with a restrict FK, so they go first (Eloquent, to delete their files).
     */
    public function forceDeleting(Branch $branch): void
    {
        AnalyticalReport::withTrashed()
            ->where('branch_id', $branch->getKey())
            ->get()
            ->each(fn (AnalyticalReport $report): bool => (bool) $report->forceDelete());
    }
}
