<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\CnabFile;
use App\Models\PaymentSettlement;

/**
 * Soft deletes never trigger FK cascades, so settlement → cnab_files lives here (per model, so
 * CnabFile observers fire). Force delete order is handled by PaymentSettlementService.
 */
final class PaymentSettlementObserver
{
    public function deleted(PaymentSettlement $settlement): void
    {
        if ($settlement->isForceDeleting()) {
            return;
        }

        $settlement->cnabFiles()->get()->each(fn (CnabFile $file): ?bool => $file->delete());
    }

    /**
     * Runs before deleted_at is cleared, so only files trashed with the settlement come back.
     */
    public function restoring(PaymentSettlement $settlement): void
    {
        if ($settlement->deleted_at === null) {
            return;
        }

        $settlement->cnabFiles()
            ->onlyTrashed()
            ->where('deleted_at', '>=', $settlement->deleted_at)
            ->get()
            ->each(fn (CnabFile $file): bool => $file->restore());
    }
}
