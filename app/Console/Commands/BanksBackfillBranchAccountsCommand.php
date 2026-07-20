<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Bank;
use App\Models\BranchBankAccount;
use Illuminate\Console\Command;

final class BanksBackfillBranchAccountsCommand extends Command
{
    protected $signature = 'banks:backfill-branch-accounts';

    protected $description = 'Link branch bank accounts to banks by matching bank_code to banks.code';

    public function handle(): int
    {
        $linked = 0;
        $orphans = 0;

        BranchBankAccount::query()
            ->whereNull('bank_id')
            ->orderBy('id')
            ->each(function (BranchBankAccount $account) use (&$linked, &$orphans): void {
                $bank = Bank::query()
                    ->where('code', $account->bank_code)
                    ->first();

                if ($bank === null) {
                    $orphans++;

                    return;
                }

                $account->bank_id = $bank->getKey();
                $account->save();
                $linked++;
            });

        $this->info("Linked: {$linked}");
        $this->warn("Orphans (no matching bank code): {$orphans}");

        return self::SUCCESS;
    }
}
