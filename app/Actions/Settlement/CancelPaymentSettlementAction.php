<?php

declare(strict_types=1);

namespace App\Actions\Settlement;

use App\Exceptions\PaymentSettlementException;
use App\Models\PaymentSettlement;
use App\Models\User;
use App\Services\PaymentSettlementService;

final class CancelPaymentSettlementAction
{
    public function __construct(
        private readonly PaymentSettlementService $settlementService,
    ) {}

    /**
     * @throws PaymentSettlementException
     */
    public function __invoke(PaymentSettlement $settlement, User $actor, string $reason): PaymentSettlement
    {
        if (! $actor->can('cancel', $settlement)) {
            throw PaymentSettlementException::unauthorized();
        }

        return $this->settlementService->cancel($settlement, $actor, $reason);
    }
}
