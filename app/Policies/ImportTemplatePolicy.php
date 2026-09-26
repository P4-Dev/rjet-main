<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ImportTemplate;
use App\Models\User;

final class ImportTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdm();
    }

    public function view(User $user, ImportTemplate $importTemplate): bool
    {
        return $user->isAdm();
    }

    public function create(User $user): bool
    {
        return $user->isAdm();
    }

    public function update(User $user, ImportTemplate $importTemplate): bool
    {
        return $user->isAdm();
    }

    public function delete(User $user, ImportTemplate $importTemplate): bool
    {
        return $user->isAdm();
    }

    public function restore(User $user, ImportTemplate $importTemplate): bool
    {
        return $user->isAdm();
    }

    public function forceDelete(User $user, ImportTemplate $importTemplate): bool
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
