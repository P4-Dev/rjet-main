<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Bank;
use App\Models\User;

final class BankPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->seesAllBranches();
    }

    public function view(User $user, Bank $bank): bool
    {
        return $user->role->seesAllBranches();
    }

    public function create(User $user): bool
    {
        return $user->isAdm();
    }

    public function update(User $user, Bank $bank): bool
    {
        return $user->isAdm();
    }

    public function delete(User $user, Bank $bank): bool
    {
        return $user->isAdm();
    }

    public function restore(User $user, Bank $bank): bool
    {
        return $user->isAdm();
    }

    public function forceDelete(User $user, Bank $bank): bool
    {
        return $user->isAdm();
    }
}
