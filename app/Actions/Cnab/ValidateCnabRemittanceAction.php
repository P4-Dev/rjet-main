<?php

declare(strict_types=1);

namespace App\Actions\Cnab;

use App\DTOs\CnabValidationReport;
use App\Exceptions\CnabException;
use App\Exceptions\PaymentSettlementException;
use App\Models\PaymentSettlement;
use App\Models\User;
use App\Services\CnabRemittanceService;

final class ValidateCnabRemittanceAction
{
    public function __construct(
        private readonly CnabRemittanceService $remittanceService,
    ) {}

    /**
     * @throws PaymentSettlementException
     * @throws CnabException
     */
    public function __invoke(PaymentSettlement $settlement, User $actor): CnabValidationReport
    {
        if (! $actor->can('generateCnab', $settlement)) {
            throw PaymentSettlementException::unauthorized();
        }

        return $this->remittanceService->validate($settlement);
    }
}
