<?php

declare(strict_types=1);

namespace App\Integrations\Cnab;

use App\DTOs\CnabRemittanceData;
use App\DTOs\CnabRemittanceItemData;
use App\DTOs\CnabRemittanceResult;
use App\DTOs\CnabValidationError;
use App\Enums\CnabLayout;
use App\Models\Branch;
use App\Models\BranchBankAccount;
use App\Models\CnabConfig;
use Carbon\CarbonImmutable;

interface CnabRemittanceAdapter
{
    public function layout(): CnabLayout;

    public function supportsBankCode(string $code): bool;

    /**
     * @return list<CnabValidationError>
     */
    public function validateConfig(CnabConfig $config, BranchBankAccount $account, Branch $branch): array;

    /**
     * @return list<CnabValidationError>
     */
    public function validateItem(CnabRemittanceItemData $item, CarbonImmutable $paymentDate): array;

    public function paymentFormCode(CnabRemittanceItemData $item, string $debitBankCode): string;

    public function build(CnabRemittanceData $data): CnabRemittanceResult;

    public function fileName(CnabRemittanceData $data): string;
}
