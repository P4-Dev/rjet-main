<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PaymentRequest;
use App\Models\User;

final class PaymentRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PaymentRequest $paymentRequest): bool
    {
        return $paymentRequest->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, PaymentRequest $paymentRequest): bool
    {
        return $paymentRequest->isEditableBy($user);
    }

    public function delete(User $user, PaymentRequest $paymentRequest): bool
    {
        return $user->isAdm();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdm();
    }

    public function restore(User $user, PaymentRequest $paymentRequest): bool
    {
        return $user->isAdm();
    }

    public function forceDelete(User $user, PaymentRequest $paymentRequest): bool
    {
        return $user->isAdm();
    }

    public function transitionStatus(User $user, PaymentRequest $paymentRequest): bool
    {
        if (! ($user->isOperador() || $user->isAdm())) {
            return false;
        }

        return $paymentRequest->status->allowedTransitions() !== [];
    }

    public function manageAttachments(User $user, PaymentRequest $paymentRequest): bool
    {
        return $this->update($user, $paymentRequest);
    }
}
