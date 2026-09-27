<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\AccountType;
use App\Enums\CnabPaymentType;
use App\Enums\PixKeyType;
use Carbon\CarbonImmutable;

final readonly class CnabRemittanceItemData
{
    public function __construct(
        public string $settlementItemId,
        public string $reference,
        public CnabPaymentType $paymentType,
        public string $amount,
        public CarbonImmutable $dueDate,
        public ?string $beneficiaryName = null,
        public ?string $beneficiaryDocument = null,
        public ?string $beneficiaryBankCode = null,
        public ?string $agency = null,
        public ?string $agencyDigit = null,
        public ?string $accountNumber = null,
        public ?string $accountDigit = null,
        public ?AccountType $accountType = null,
        public ?PixKeyType $pixKeyType = null,
        public ?string $pixKey = null,
        public ?string $barcode = null,
    ) {}
}
