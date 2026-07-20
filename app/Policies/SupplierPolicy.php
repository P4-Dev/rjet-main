<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;

final class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->seesAllBranches();
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $user->role->seesAllBranches();
    }

    public function create(User $user): bool
    {
        return $user->isAdm();
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $user->isAdm();
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return $user->isAdm();
    }

    public function restore(User $user, Supplier $supplier): bool
    {
        return $user->isAdm();
    }

    public function forceDelete(User $user, Supplier $supplier): bool
    {
        return $user->isAdm();
    }
}
