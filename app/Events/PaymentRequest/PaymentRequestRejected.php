<?php

declare(strict_types=1);

namespace App\Events\PaymentRequest;

use App\Models\Approval;
use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class PaymentRequestRejected
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly PaymentRequest $paymentRequest,
        public readonly Approval $approval,
        public readonly User $actor,
    ) {}
}
