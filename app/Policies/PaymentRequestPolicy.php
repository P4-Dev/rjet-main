<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PaymentRequestStatus;
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

    public function approve(User $user, PaymentRequest $paymentRequest): bool
    {
        if (! $user->canApprove() || ! $paymentRequest->isVisibleTo($user)) {
            return false;
        }

        $pending = $paymentRequest->currentPendingApproval();

        if ($pending === null) {
            return false;
        }

        return $user->isAdm() || (string) $pending->approver_user_id === (string) $user->getKey();
    }

    public function reject(User $user, PaymentRequest $paymentRequest): bool
    {
        return $this->approve($user, $paymentRequest);
    }

    public function resubmitForApproval(User $user, PaymentRequest $paymentRequest): bool
    {
        return $this->update($user, $paymentRequest)
            && $paymentRequest->status === PaymentRequestStatus::Requested;
    }
}
