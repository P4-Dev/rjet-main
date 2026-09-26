<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Supplier;
use Illuminate\Support\Carbon;

final class SupplierObserver
{
    /**
     * @var array<string, Carbon|null>
     */
    private array $restoreThresholds = [];

    public function deleted(Supplier $supplier): void
    {
        $supplier->companyPaymentMethods()->delete();
        $supplier->bankDetails()->delete();
        $supplier->addresses()->delete();
        $supplier->contacts()->delete();
    }

    public function restoring(Supplier $supplier): void
    {
        $this->restoreThresholds[(string) $supplier->getKey()] = $supplier->deleted_at;
    }

    public function restored(Supplier $supplier): void
    {
        $threshold = $this->restoreThresholds[(string) $supplier->getKey()] ?? $supplier->updated_at;

        $supplier->companyPaymentMethods()->onlyTrashed()
            ->where('deleted_at', '>=', $threshold)
            ->restore();

        $supplier->bankDetails()->onlyTrashed()
            ->where('deleted_at', '>=', $threshold)
            ->restore();

        $supplier->addresses()->onlyTrashed()
            ->where('deleted_at', '>=', $threshold)
            ->restore();

        $supplier->contacts()->onlyTrashed()
            ->where('deleted_at', '>=', $threshold)
            ->restore();

        unset($this->restoreThresholds[(string) $supplier->getKey()]);
    }

    public function forceDeleted(Supplier $supplier): void
    {
        $supplier->companyPaymentMethods()->withTrashed()->forceDelete();
        $supplier->bankDetails()->withTrashed()->forceDelete();
        $supplier->addresses()->withTrashed()->forceDelete();
        $supplier->contacts()->withTrashed()->forceDelete();
    }
}
