<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BranchBankAccount;
use App\Models\User;

final class BranchBankAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->seesAllBranches();
    }

    public function view(User $user, BranchBankAccount $account): bool
    {
        return $user->role->seesAllBranches();
    }

    public function create(User $user): bool
    {
        return $user->isAdm();
    }

    public function update(User $user, BranchBankAccount $account): bool
    {
        return $user->isAdm();
    }

    public function delete(User $user, BranchBankAccount $account): bool
    {
        return $user->isAdm();
    }

    public function restore(User $user, BranchBankAccount $account): bool
    {
        return $user->isAdm();
    }

    public function forceDelete(User $user, BranchBankAccount $account): bool
    {
        return $user->isAdm();
    }
}
