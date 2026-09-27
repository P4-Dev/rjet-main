<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\CnabLayout;
use Carbon\CarbonImmutable;

final readonly class CnabRemittanceData
{
    /**
     * @param  array{document: string, name: string}  $company
     * @param  array{bank_code: string, agency: string, agency_digit: ?string, account_number: string, account_digit: ?string}  $debitAccount
     * @param  array{agreement_code: ?string, wallet_code: ?string, payment_type_code: string, line_ending: string}  $config
     * @param  list<CnabRemittanceItemData>  $items
     */
    public function __construct(
        public CnabLayout $layout,
        public int $fileSequence,
        public CarbonImmutable $generatedAt,
        public CarbonImmutable $paymentDate,
        public array $company,
        public array $debitAccount,
        public array $config,
        public array $items,
    ) {}

    public function totalAmount(): string
    {
        return array_reduce(
            $this->items,
            fn (string $carry, CnabRemittanceItemData $item): string => bcadd($carry, $item->amount, 2),
            '0.00',
        );
    }
}
