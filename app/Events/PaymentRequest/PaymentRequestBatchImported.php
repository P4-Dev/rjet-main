<?php

declare(strict_types=1);

namespace App\Events\PaymentRequest;

use App\Models\ImportBatch;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class PaymentRequestBatchImported
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly ImportBatch $batch,
    ) {}
}
