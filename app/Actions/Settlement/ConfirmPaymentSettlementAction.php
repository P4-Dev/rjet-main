<?php

declare(strict_types=1);

namespace App\Actions\Settlement;

use App\Exceptions\PaymentSettlementException;
use App\Models\PaymentSettlement;
use App\Models\User;
use App\Services\PaymentSettlementService;

final class ConfirmPaymentSettlementAction
{
    public function __construct(
        private readonly PaymentSettlementService $settlementService,
    ) {}

    /**
     * @throws PaymentSettlementException
     */
    public function __invoke(PaymentSettlement $settlement, User $actor): PaymentSettlement
    {
        if (! $actor->can('confirm', $settlement)) {
            throw PaymentSettlementException::unauthorized();
        }

        return $this->settlementService->confirm($settlement, $actor);
    }
}
