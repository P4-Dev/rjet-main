<?php

declare(strict_types=1);

namespace App\Listeners\PaymentRequest;

use App\Events\PaymentRequest\PaymentRequestRejected;
use App\Models\User;
use App\Notifications\PaymentRequestRejectedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

final class SendPaymentRequestRejectedNotification implements ShouldQueue
{
    public function handle(PaymentRequestRejected $event): void
    {
        $creator = $event->paymentRequest->creator
            ?? User::query()->find($event->paymentRequest->created_by);

        if (! $creator instanceof User) {
            return;
        }

        $creator->notify(new PaymentRequestRejectedNotification(
            $event->paymentRequest,
            $event->approval,
        ));
    }
}
