<?php

declare(strict_types=1);

namespace App\Exceptions;

final class BranchException extends BusinessException
{
    public static function cannotDeleteWithBankAccounts(string $branchId): self
    {
        return new self(
            message: "Cannot delete branch {$branchId}: it still has bank accounts.",
            userMessage: __('branches.errors.cannot_delete_with_bank_accounts'),
        );
    }

    public static function cannotDeleteWithPaymentRequests(string $id): self
    {
        return new self(
            message: "Cannot delete branch {$id}: it still has payment requests.",
            userMessage: __('branches.errors.cannot_delete_with_payment_requests'),
        );
    }

    public static function bankAccountHasCnabConfig(string $accountId): self
    {
        return new self(
            message: "Cannot delete bank account {$accountId}: it has a live CNAB configuration.",
            userMessage: __('branch_bank_accounts.errors.has_cnab_config'),
        );
    }

    public static function bankAccountBranchLocked(string $accountId): self
    {
        return new self(
            message: "Cannot change the branch of bank account {$accountId}: it is referenced by settlements or CNAB configurations.",
            userMessage: __('branch_bank_accounts.errors.branch_locked'),
        );
    }
}
