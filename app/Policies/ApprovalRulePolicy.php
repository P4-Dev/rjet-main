<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ApprovalRule;
use App\Models\User;

final class ApprovalRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdm();
    }

    public function view(User $user, ApprovalRule $approvalRule): bool
    {
        return $user->isAdm();
    }

    public function create(User $user): bool
    {
        return $user->isAdm();
    }

    public function update(User $user, ApprovalRule $approvalRule): bool
    {
        return $user->isAdm();
    }

    public function delete(User $user, ApprovalRule $approvalRule): bool
    {
        return $user->isAdm();
    }

    public function restore(User $user, ApprovalRule $approvalRule): bool
    {
        return $user->isAdm();
    }

    public function forceDelete(User $user, ApprovalRule $approvalRule): bool
    {
        return $user->isAdm();
    }
}
