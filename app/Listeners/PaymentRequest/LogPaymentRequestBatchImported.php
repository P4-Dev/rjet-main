<?php

declare(strict_types=1);

namespace App\Listeners\PaymentRequest;

use App\Events\PaymentRequest\PaymentRequestBatchImported;
use Illuminate\Support\Facades\Log;

final class LogPaymentRequestBatchImported
{
    public function handle(PaymentRequestBatchImported $event): void
    {
        Log::info('Payment request batch imported.', [
            'import_batch_id' => $event->batch->getKey(),
            'success_count' => $event->batch->success_count,
            'error_count' => $event->batch->error_count,
            'total_rows' => $event->batch->total_rows,
            'actor_id' => $event->batch->created_by,
        ]);
    }
}
