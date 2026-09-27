<?php

declare(strict_types=1);

namespace App\Observers;

use App\Exceptions\BranchException;
use App\Models\Bank;
use App\Models\BranchBankAccount;
use App\Services\BranchBankAccountService;
use Illuminate\Database\Eloquent\Builder;

final class BranchBankAccountObserver
{
    /**
     * Sync bank_code/bank_name from Bank when bank_id is set, and ensure at most
     * one default account per branch (complements the partial unique index).
     */
    public function saving(BranchBankAccount $account): void
    {
        if ($account->bank_id !== null && $account->isDirty('bank_id')) {
            $bank = Bank::query()->find($account->bank_id);

            if ($bank !== null) {
                $account->bank_code = $bank->code;
                $account->bank_name = $bank->name;
            }
        }

        if (! $account->is_default) {
            return;
        }

        BranchBankAccount::query()
            ->where('branch_id', $account->branch_id)
            ->when($account->exists, fn (Builder $query) => $query->whereKeyNot($account->getKey()))
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    /**
     * @throws BranchException
     */
    public function updating(BranchBankAccount $account): void
    {
        app(BranchBankAccountService::class)->ensureBranchChangeAllowed($account);
    }

    /**
     * Soft delete fires no FK, so a live CNAB configuration must be guarded here.
     *
     * @throws BranchException
     */
    public function deleting(BranchBankAccount $account): void
    {
        if ($account->isForceDeleting()) {
            return;
        }

        app(BranchBankAccountService::class)->ensureDeletable($account);
    }
}
