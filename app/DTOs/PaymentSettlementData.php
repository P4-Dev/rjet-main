<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;

final readonly class PaymentSettlementData
{
    /**
     * @param  list<string>  $paymentRequestIds
     */
    public function __construct(
        public string $branchId,
        public string $branchBankAccountId,
        public CarbonImmutable $settlementDate,
        public array $paymentRequestIds,
        public ?string $notes = null,
    ) {}
}
