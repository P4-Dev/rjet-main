<?php

declare(strict_types=1);

namespace App\Listeners\Settlement;

use App\Events\Settlement\PaymentSettlementCancelled;
use App\Events\Settlement\PaymentSettlementConfirmed;
use App\Events\Settlement\PaymentSettlementCreated;
use App\Models\PaymentSettlement;
use Illuminate\Support\Facades\Log;

final class LogPaymentSettlementActivity
{
    public function handleCreated(PaymentSettlementCreated $event): void
    {
        Log::info('Payment settlement created.', $this->context($event->settlement));
    }

    public function handleConfirmed(PaymentSettlementConfirmed $event): void
    {
        Log::info('Payment settlement confirmed.', [
            ...$this->context($event->settlement),
            'settled_by' => $event->settlement->settled_by,
        ]);
    }

    public function handleCancelled(PaymentSettlementCancelled $event): void
    {
        Log::info('Payment settlement cancelled.', [
            ...$this->context($event->settlement),
            'cancelled_by' => $event->settlement->cancelled_by,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function context(PaymentSettlement $settlement): array
    {
        return [
            'payment_settlement_id' => $settlement->getKey(),
            'branch_id' => $settlement->branch_id,
            'status' => $settlement->status->value,
            'items_count' => $settlement->items_count,
            'total_amount' => (string) $settlement->total_amount,
        ];
    }
}
