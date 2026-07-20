<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Appropriation;
use App\Models\User;

final class AppropriationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->seesAllBranches();
    }

    public function view(User $user, Appropriation $appropriation): bool
    {
        return $user->role->seesAllBranches();
    }

    public function create(User $user): bool
    {
        return $user->isAdm();
    }

    public function update(User $user, Appropriation $appropriation): bool
    {
        return $user->isAdm();
    }

    public function delete(User $user, Appropriation $appropriation): bool
    {
        return $user->isAdm();
    }

    public function restore(User $user, Appropriation $appropriation): bool
    {
        return $user->isAdm();
    }

    public function forceDelete(User $user, Appropriation $appropriation): bool
    {
        return $user->isAdm();
    }
}
