<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PaymentRequestStatusHistory;
use App\Models\User;

final class PaymentRequestStatusHistoryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PaymentRequestStatusHistory $paymentRequestStatusHistory): bool
    {
        return $user->can('view', $paymentRequestStatusHistory->paymentRequest);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PaymentRequestStatusHistory $paymentRequestStatusHistory): bool
    {
        return false;
    }

    public function delete(User $user, PaymentRequestStatusHistory $paymentRequestStatusHistory): bool
    {
        return false;
    }

    public function restore(User $user, PaymentRequestStatusHistory $paymentRequestStatusHistory): bool
    {
        return false;
    }

    public function forceDelete(User $user, PaymentRequestStatusHistory $paymentRequestStatusHistory): bool
    {
        return false;
    }
}
