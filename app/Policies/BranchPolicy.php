<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;

final class BranchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->seesAllBranches();
    }

    public function view(User $user, Branch $branch): bool
    {
        return $user->role->seesAllBranches();
    }

    public function create(User $user): bool
    {
        return $user->isAdm();
    }

    public function update(User $user, Branch $branch): bool
    {
        return $user->isAdm();
    }

    public function delete(User $user, Branch $branch): bool
    {
        return $user->isAdm();
    }

    public function restore(User $user, Branch $branch): bool
    {
        return $user->isAdm();
    }

    public function forceDelete(User $user, Branch $branch): bool
    {
        return $user->isAdm();
    }
}
