<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ImportBatch;
use App\Models\User;

final class ImportBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOperador() || $user->isAdm();
    }

    public function view(User $user, ImportBatch $importBatch): bool
    {
        return $user->isOperador() || $user->isAdm();
    }

    public function create(User $user): bool
    {
        return $user->isOperador() || $user->isAdm();
    }

    public function update(User $user, ImportBatch $importBatch): bool
    {
        return false;
    }

    public function delete(User $user, ImportBatch $importBatch): bool
    {
        return $user->isAdm();
    }

    public function restore(User $user, ImportBatch $importBatch): bool
    {
        return $user->isAdm();
    }

    public function forceDelete(User $user, ImportBatch $importBatch): bool
    {
        return $user->isAdm();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdm();
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->isAdm();
    }

    public function restoreAny(User $user): bool
    {
        return $user->isAdm();
    }
}
