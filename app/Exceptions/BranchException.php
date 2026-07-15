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
}
