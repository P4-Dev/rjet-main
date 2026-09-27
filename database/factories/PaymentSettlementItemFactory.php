<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PaymentRequest;
use App\Models\PaymentSettlement;
use App\Models\PaymentSettlementItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentSettlementItem>
 */
final class PaymentSettlementItemFactory extends Factory
{
    protected $model = PaymentSettlementItem::class;

    /**
     * A new launched request per item, so the active-item partial unique never collides.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_settlement_id' => PaymentSettlement::factory(),
            'payment_request_id' => fn (array $attributes): string => (string) PaymentRequest::factory()
                ->launched()
                ->depositTransfer()
                ->state(['branch_id' => PaymentSettlement::withTrashed()->whereKey($attributes['payment_settlement_id'])->value('branch_id')])
                ->create()
                ->getKey(),
            'amount' => fn (array $attributes): string => (string) PaymentRequest::withTrashed()
                ->whereKey($attributes['payment_request_id'])
                ->value('net_amount'),
        ];
    }

    public function released(): static
    {
        return $this->state(fn (): array => [
            'released_at' => now(),
            'released_by' => User::factory()->operador(),
        ]);
    }

    public function forPaymentRequest(PaymentRequest $paymentRequest): static
    {
        return $this->state(fn (): array => [
            'payment_request_id' => $paymentRequest->getKey(),
            'amount' => (string) $paymentRequest->net_amount,
        ]);
    }
}
