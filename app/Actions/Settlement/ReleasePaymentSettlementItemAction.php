<?php

declare(strict_types=1);

namespace App\Actions\Settlement;

use App\Exceptions\PaymentSettlementException;
use App\Models\PaymentSettlementItem;
use App\Models\User;
use App\Services\PaymentSettlementService;

final class ReleasePaymentSettlementItemAction
{
    public function __construct(
        private readonly PaymentSettlementService $settlementService,
    ) {}

    /**
     * @throws PaymentSettlementException
     */
    public function __invoke(PaymentSettlementItem $item, User $actor): void
    {
        if (! $actor->can('update', $item->loadMissing('settlement')->settlement)) {
            throw PaymentSettlementException::unauthorized();
        }

        $this->settlementService->releaseItem($item, $actor);
    }
}
