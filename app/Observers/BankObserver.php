<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Bank;
use App\Services\BankService;

final class BankObserver
{
    public function updated(Bank $bank): void
    {
        if ($bank->wasChanged(['code', 'name'])) {
            app(BankService::class)->syncBranchAccountSnapshots($bank);
        }
    }
}
