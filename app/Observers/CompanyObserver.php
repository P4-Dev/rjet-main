<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Branch;
use App\Models\Company;

final class CompanyObserver
{
    /**
     * Soft-delete threshold captured per company for cascade restore.
     *
     * @var array<string, \Illuminate\Support\Carbon|null>
     */
    private array $restoreThresholds = [];

    /**
     * Soft-delete order (bulk via query — never per-row Model events that trip guards):
     * 1) supplier payment overrides
     * 2) appropriations
     * 3) per branch: cost centers, then bank accounts
     * 4) branches
     */
    public function deleted(Company $company): void
    {
        $company->supplierPaymentMethods()->delete();
        $company->appropriations()->delete();

        $company->branches()->get()->each(function (Branch $branch): void {
            $branch->costCenters()->delete();
            $branch->bankAccounts()->delete();
        });

        $company->branches()->delete();
    }

    public function restoring(Company $company): void
    {
        $this->restoreThresholds[(string) $company->getKey()] = $company->deleted_at;
    }

    public function restored(Company $company): void
    {
        $threshold = $this->restoreThresholds[(string) $company->getKey()] ?? $company->updated_at;

        $company->supplierPaymentMethods()->onlyTrashed()
            ->where('deleted_at', '>=', $threshold)
            ->restore();

        $company->appropriations()->onlyTrashed()
            ->where('deleted_at', '>=', $threshold)
            ->restore();

        $company->branches()->onlyTrashed()
            ->where('deleted_at', '>=', $threshold)
            ->get()
            ->each(function (Branch $branch) use ($threshold): void {
                $branch->restore();

                $branch->costCenters()->onlyTrashed()
                    ->where('deleted_at', '>=', $threshold)
                    ->restore();

                $branch->bankAccounts()->onlyTrashed()
                    ->where('deleted_at', '>=', $threshold)
                    ->restore();
            });

        unset($this->restoreThresholds[(string) $company->getKey()]);
    }

    public function forceDeleted(Company $company): void
    {
        $company->supplierPaymentMethods()->withTrashed()->forceDelete();
        $company->appropriations()->withTrashed()->forceDelete();

        $company->branches()->withTrashed()->get()->each(function (Branch $branch): void {
            $branch->costCenters()->withTrashed()->forceDelete();
            $branch->bankAccounts()->withTrashed()->forceDelete();
        });

        $company->branches()->withTrashed()->forceDelete();
    }
}
