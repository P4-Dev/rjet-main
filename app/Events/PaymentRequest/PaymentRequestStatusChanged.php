<?php

declare(strict_types=1);

namespace App\Events\PaymentRequest;

use App\Enums\PaymentRequestStatus;
use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class PaymentRequestStatusChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly PaymentRequest $paymentRequest,
        public readonly PaymentRequestStatus $from,
        public readonly PaymentRequestStatus $to,
        public readonly ?User $actor,
    ) {}
}
