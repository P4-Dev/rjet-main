<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SupplierCompanyPaymentMethod;
use App\Models\User;

final class SupplierCompanyPaymentMethodPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->seesAllBranches();
    }

    public function view(User $user, SupplierCompanyPaymentMethod $supplierCompanyPaymentMethod): bool
    {
        return $user->role->seesAllBranches();
    }

    public function create(User $user): bool
    {
        return $user->isAdm();
    }

    public function update(User $user, SupplierCompanyPaymentMethod $supplierCompanyPaymentMethod): bool
    {
        return $user->isAdm();
    }

    public function delete(User $user, SupplierCompanyPaymentMethod $supplierCompanyPaymentMethod): bool
    {
        return $user->isAdm();
    }

    public function restore(User $user, SupplierCompanyPaymentMethod $supplierCompanyPaymentMethod): bool
    {
        return $user->isAdm();
    }

    public function forceDelete(User $user, SupplierCompanyPaymentMethod $supplierCompanyPaymentMethod): bool
    {
        return $user->isAdm();
    }
}
