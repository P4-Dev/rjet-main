<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

final class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->seesAllBranches();
    }

    public function view(User $user, Company $company): bool
    {
        return $user->role->seesAllBranches();
    }

    public function create(User $user): bool
    {
        return $user->isAdm();
    }

    public function update(User $user, Company $company): bool
    {
        return $user->isAdm();
    }

    public function delete(User $user, Company $company): bool
    {
        return $user->isAdm();
    }

    public function restore(User $user, Company $company): bool
    {
        return $user->isAdm();
    }

    public function forceDelete(User $user, Company $company): bool
    {
        return $user->isAdm();
    }
}
