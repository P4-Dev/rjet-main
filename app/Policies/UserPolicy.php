<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdm();
    }

    public function view(User $user, User $model): bool
    {
        return $user->isAdm();
    }

    public function create(User $user): bool
    {
        return $user->isAdm();
    }

    public function update(User $user, User $model): bool
    {
        return $user->isAdm();
    }

    /**
     * Adm não pode excluir a si mesmo nem o último administrador ativo
     * (guardrail espelhado no UserService, que lança UserException).
     */
    public function delete(User $user, User $model): bool
    {
        if (! $user->isAdm()) {
            return false;
        }

        if ($user->is($model)) {
            return false;
        }

        return ! $model->isLastActiveAdmin();
    }

    public function restore(User $user, User $model): bool
    {
        return $user->isAdm();
    }

    public function forceDelete(User $user, User $model): bool
    {
        if (! $user->isAdm()) {
            return false;
        }

        if ($user->is($model)) {
            return false;
        }

        return ! $model->isLastActiveAdmin();
    }
}
