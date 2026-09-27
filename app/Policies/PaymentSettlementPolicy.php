<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PaymentSettlementStatus;
use App\Models\PaymentSettlement;
use App\Models\User;

final class PaymentSettlementPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isStaff($user);
    }

    public function view(User $user, PaymentSettlement $paymentSettlement): bool
    {
        return $this->isStaff($user);
    }

    public function create(User $user): bool
    {
        return $this->isStaff($user);
    }

    public function update(User $user, PaymentSettlement $paymentSettlement): bool
    {
        return $this->isStaff($user) && $paymentSettlement->isDraft();
    }

    public function confirm(User $user, PaymentSettlement $paymentSettlement): bool
    {
        return $this->isStaff($user) && $paymentSettlement->isDraft();
    }

    public function cancel(User $user, PaymentSettlement $paymentSettlement): bool
    {
        return $this->isStaff($user) && $paymentSettlement->isDraft();
    }

    public function generateCnab(User $user, PaymentSettlement $paymentSettlement): bool
    {
        return $this->isStaff($user) && $paymentSettlement->isDraft();
    }

    public function delete(User $user, PaymentSettlement $paymentSettlement): bool
    {
        return $user->isAdm() && $paymentSettlement->status === PaymentSettlementStatus::Cancelled;
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdm();
    }

    public function restore(User $user, PaymentSettlement $paymentSettlement): bool
    {
        return $user->isAdm();
    }

    public function restoreAny(User $user): bool
    {
        return $user->isAdm();
    }

    public function forceDelete(User $user, PaymentSettlement $paymentSettlement): bool
    {
        return $user->isAdm() && $paymentSettlement->status === PaymentSettlementStatus::Cancelled;
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->isAdm();
    }

    private function isStaff(User $user): bool
    {
        return $user->isOperador() || $user->isAdm();
    }
}
