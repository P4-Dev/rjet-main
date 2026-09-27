<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BranchException;
use App\Models\BranchBankAccount;
use App\Models\CnabConfig;
use App\Models\PaymentSettlement;

final class BranchBankAccountService
{
    /**
     * @throws BranchException
     */
    public function ensureDeletable(BranchBankAccount $account): void
    {
        if (CnabConfig::query()->forAccount($account)->exists()) {
            throw BranchException::bankAccountHasCnabConfig((string) $account->getKey());
        }
    }

    /**
     * @throws BranchException
     */
    public function delete(BranchBankAccount $account): void
    {
        $this->ensureDeletable($account);

        $account->delete();
    }

    /**
     * @throws BranchException
     */
    public function ensureBranchChangeAllowed(BranchBankAccount $account): void
    {
        if (! $account->exists || ! $account->isDirty('branch_id')) {
            return;
        }

        $accountId = (string) $account->getKey();

        $isReferenced = PaymentSettlement::withTrashed()->where('branch_bank_account_id', $accountId)->exists()
            || CnabConfig::withTrashed()->where('branch_bank_account_id', $accountId)->exists();

        if ($isReferenced) {
            throw BranchException::bankAccountBranchLocked($accountId);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws BranchException
     */
    public function update(BranchBankAccount $account, array $data): BranchBankAccount
    {
        $account->fill($data);
        $this->ensureBranchChangeAllowed($account);
        $account->save();

        return $account;
    }
}
