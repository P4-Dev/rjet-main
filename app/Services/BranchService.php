<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BranchException;
use App\Models\Branch;

final class BranchService
{
    /**
     * Soft-deletes a branch after the bank-account guard, cascading cost centers.
     *
     * IMPORTANT (DBA): this guard lives only in the Service layer — never as an
     * Eloquent Model event on Branch — so Company cascade soft-deletes are not blocked.
     */
    public function delete(Branch $branch): void
    {
        $this->ensureDeletable($branch);

        $branch->approvalRules()->delete();
        $branch->costCenters()->delete();
        $branch->delete();
    }

    /**
     * @throws BranchException
     */
    public function ensureDeletable(Branch $branch): void
    {
        if ($branch->bankAccounts()->exists()) {
            throw BranchException::cannotDeleteWithBankAccounts((string) $branch->getKey());
        }

        if ($branch->paymentRequests()->exists()) {
            throw BranchException::cannotDeleteWithPaymentRequests((string) $branch->getKey());
        }
    }
}
