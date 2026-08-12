<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Approval;
use App\Models\User;

final class ApprovalPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Approval $approval): bool
    {
        $paymentRequest = $approval->paymentRequest;

        return $paymentRequest !== null && $paymentRequest->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Approval $approval): bool
    {
        return false;
    }

    public function delete(User $user, Approval $approval): bool
    {
        return false;
    }
}
