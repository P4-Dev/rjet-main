<?php

declare(strict_types=1);

namespace App\Events\PaymentRequest;

use App\Models\PaymentRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class PaymentRequestCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly PaymentRequest $paymentRequest,
    ) {}
}
