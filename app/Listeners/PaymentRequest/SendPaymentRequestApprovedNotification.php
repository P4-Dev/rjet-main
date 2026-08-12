<?php

declare(strict_types=1);

namespace App\Listeners\PaymentRequest;

use App\Events\PaymentRequest\PaymentRequestApproved;
use App\Models\User;
use App\Notifications\PaymentRequestApprovedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

final class SendPaymentRequestApprovedNotification implements ShouldQueue
{
    public function handle(PaymentRequestApproved $event): void
    {
        $creator = $event->paymentRequest->creator
            ?? User::query()->find($event->paymentRequest->created_by);

        if (! $creator instanceof User) {
            return;
        }

        $creator->notify(new PaymentRequestApprovedNotification(
            $event->paymentRequest,
            $event->approval,
        ));
    }
}
