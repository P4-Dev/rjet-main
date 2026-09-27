<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CnabConfig;
use App\Models\User;

final class CnabConfigPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOperador() || $user->isAdm();
    }

    public function view(User $user, CnabConfig $cnabConfig): bool
    {
        return $user->isOperador() || $user->isAdm();
    }

    public function create(User $user): bool
    {
        return $user->isAdm();
    }

    public function update(User $user, CnabConfig $cnabConfig): bool
    {
        return $user->isAdm();
    }

    public function delete(User $user, CnabConfig $cnabConfig): bool
    {
        return $user->isAdm();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdm();
    }

    public function restore(User $user, CnabConfig $cnabConfig): bool
    {
        return $user->isAdm();
    }

    public function restoreAny(User $user): bool
    {
        return $user->isAdm();
    }

    public function forceDelete(User $user, CnabConfig $cnabConfig): bool
    {
        return $user->isAdm();
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->isAdm();
    }
}
