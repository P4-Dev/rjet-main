<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\BranchBankAccount;
use Illuminate\Database\Eloquent\Builder;

final class BranchBankAccountObserver
{
    /**
     * Garante no máximo uma conta padrão por filial (complementa o índice único
     * parcial do banco): desmarca as demais antes de persistir esta como padrão.
     */
    public function saving(BranchBankAccount $account): void
    {
        if (! $account->is_default) {
            return;
        }

        BranchBankAccount::query()
            ->where('branch_id', $account->branch_id)
            ->when($account->exists, fn (Builder $query) => $query->whereKeyNot($account->getKey()))
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}
