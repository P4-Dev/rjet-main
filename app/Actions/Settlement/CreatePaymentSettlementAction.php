<?php

declare(strict_types=1);

namespace App\Actions\Settlement;

use App\DTOs\PaymentSettlementData;
use App\Exceptions\PaymentSettlementException;
use App\Models\PaymentSettlement;
use App\Models\User;
use App\Services\PaymentSettlementService;

final class CreatePaymentSettlementAction
{
    public function __construct(
        private readonly PaymentSettlementService $settlementService,
    ) {}

    /**
     * @throws PaymentSettlementException
     */
    public function __invoke(PaymentSettlementData $data, User $actor): PaymentSettlement
    {
        if (! $actor->can('create', PaymentSettlement::class)) {
            throw PaymentSettlementException::unauthorized();
        }

        return $this->settlementService->create($data, $actor);
    }
}
