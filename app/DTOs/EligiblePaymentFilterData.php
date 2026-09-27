<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\PaymentMethod;
use Carbon\CarbonImmutable;

final readonly class EligiblePaymentFilterData
{
    public function __construct(
        public ?string $branchId = null,
        public ?CarbonImmutable $dueFrom = null,
        public ?CarbonImmutable $dueUntil = null,
        public ?PaymentMethod $paymentMethod = null,
    ) {}
}
