<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Cnab\RecoverStuckCnabFilesAction;
use Illuminate\Console\Command;

final class CnabRecoverStuckFilesCommand extends Command
{
    protected $signature = 'cnab:recover-stuck-files {--dry-run : Count stuck CNAB files without changing them}';

    protected $description = 'Requeue or fail CNAB files stuck in progress after a worker crash';

    public function handle(RecoverStuckCnabFilesAction $action): int
    {
        $dryRun = (bool) $this->option('dry-run');
        ['requeued' => $requeued, 'failed' => $failed] = $action($dryRun);

        $this->info($dryRun
            ? "Found {$requeued} CNAB file(s) to requeue and {$failed} to fail (dry-run)."
            : "Requeued {$requeued} CNAB file(s) and failed {$failed}.");

        return self::SUCCESS;
    }
}
