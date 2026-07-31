<?php

declare(strict_types=1);

namespace App\Actions\PaymentRequest;

use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\Supplier;

final class ResolveSupplierPaymentMethodAction
{
    public function __invoke(string $supplierId, ?string $branchId): ?PaymentMethod
    {
        $supplier = Supplier::query()->find($supplierId);

        if ($supplier === null) {
            return null;
        }

        if (blank($branchId)) {
            return $supplier->default_payment_method;
        }

        $branch = Branch::query()->with('company')->find($branchId);

        if ($branch?->company === null) {
            return null;
        }

        return $supplier->paymentMethodFor($branch->company);
    }
}
