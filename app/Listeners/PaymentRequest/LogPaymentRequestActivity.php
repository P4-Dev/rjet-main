<?php

declare(strict_types=1);

namespace App\Listeners\PaymentRequest;

use App\Events\PaymentRequest\PaymentRequestCreated;
use App\Events\PaymentRequest\PaymentRequestStatusChanged;
use Illuminate\Support\Facades\Log;

final class LogPaymentRequestActivity
{
    public function handleCreated(PaymentRequestCreated $event): void
    {
        Log::info('Payment request created.', [
            'payment_request_id' => $event->paymentRequest->getKey(),
            'status' => $event->paymentRequest->status->value,
        ]);
    }

    public function handleStatusChanged(PaymentRequestStatusChanged $event): void
    {
        // Hook for F8 dashboard cache invalidation.
        Log::info('Payment request status changed.', [
            'payment_request_id' => $event->paymentRequest->getKey(),
            'from' => $event->from->value,
            'to' => $event->to->value,
            'actor_id' => $event->actor?->getKey(),
        ]);
    }
}
