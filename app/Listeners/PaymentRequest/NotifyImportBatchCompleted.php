<?php

declare(strict_types=1);

namespace App\Listeners\PaymentRequest;

use App\Events\PaymentRequest\PaymentRequestBatchImported;
use App\Notifications\PaymentRequestBatchImportedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

final class NotifyImportBatchCompleted implements ShouldQueue
{
    public function handle(PaymentRequestBatchImported $event): void
    {
        $creator = $event->batch->creator;

        if ($creator === null) {
            return;
        }

        $creator->notify(new PaymentRequestBatchImportedNotification($event->batch));
    }
}
