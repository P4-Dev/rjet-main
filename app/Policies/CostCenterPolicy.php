<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CostCenter;
use App\Models\User;

final class CostCenterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->seesAllBranches();
    }

    public function view(User $user, CostCenter $costCenter): bool
    {
        return $user->role->seesAllBranches();
    }

    public function create(User $user): bool
    {
        return $user->isAdm();
    }

    public function update(User $user, CostCenter $costCenter): bool
    {
        return $user->isAdm();
    }

    public function delete(User $user, CostCenter $costCenter): bool
    {
        return $user->isAdm();
    }

    public function restore(User $user, CostCenter $costCenter): bool
    {
        return $user->isAdm();
    }

    public function forceDelete(User $user, CostCenter $costCenter): bool
    {
        return $user->isAdm();
    }
}
