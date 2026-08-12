<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Approval\EscalateOverdueApprovalsAction;
use Illuminate\Console\Command;

final class ApprovalsEscalateSlaCommand extends Command
{
    protected $signature = 'approvals:escalate-sla {--dry-run : Count overdue approvals without escalating}';

    protected $description = 'Escalate overdue pending approvals past their SLA due date';

    public function handle(EscalateOverdueApprovalsAction $action): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $count = $action($dryRun);

        $this->info($dryRun
            ? "Found {$count} overdue approval(s) (dry-run)."
            : "Escalated {$count} overdue approval(s).");

        return self::SUCCESS;
    }
}
