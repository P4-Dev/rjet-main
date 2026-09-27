<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentSettlementStatus;
use App\Models\Branch;
use App\Models\BranchBankAccount;
use App\Models\PaymentRequest;
use App\Models\PaymentSettlement;
use App\Models\User;
use App\Services\PaymentSettlementService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentSettlement>
 */
final class PaymentSettlementFactory extends Factory
{
    protected $model = PaymentSettlement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'branch_bank_account_id' => fn (array $attributes): BranchBankAccountFactory => BranchBankAccount::factory()
                ->itau()
                ->state(['branch_id' => $attributes['branch_id']]),
            'status' => PaymentSettlementStatus::Draft,
            'settlement_date' => today(PaymentSettlement::TIMEZONE)->toDateString(),
            'items_count' => 0,
            'total_amount' => '0.00',
            'notes' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (): array => ['status' => PaymentSettlementStatus::Draft]);
    }

    public function settled(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentSettlementStatus::Settled,
            'settled_at' => now(),
            'settled_by' => User::factory()->operador(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentSettlementStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => User::factory()->operador(),
            'cancellation_reason' => fake()->sentence(),
        ]);
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(fn (): array => ['branch_id' => $branch->getKey()]);
    }

    public function forAccount(BranchBankAccount $account): static
    {
        return $this->state(fn (): array => [
            'branch_id' => $account->branch_id,
            'branch_bank_account_id' => $account->getKey(),
        ]);
    }

    public function dated(string $date): static
    {
        return $this->state(fn (): array => ['settlement_date' => $date]);
    }

    public function withItems(int $count = 3): static
    {
        return $this->afterCreating(function (PaymentSettlement $settlement) use ($count): void {
            PaymentRequest::factory()
                ->count($count)
                ->launched()
                ->depositTransfer()
                ->state(['branch_id' => $settlement->branch_id])
                ->create()
                ->each(fn (PaymentRequest $request) => $settlement->items()->create([
                    'payment_request_id' => $request->getKey(),
                    'amount' => (string) $request->net_amount,
                ]));

            app(PaymentSettlementService::class)->recalculateTotals($settlement);
        });
    }
}
