<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CnabPaymentType;
use App\Models\CnabFile;
use App\Models\CnabFileItem;
use App\Models\PaymentSettlementItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CnabFileItem>
 */
final class CnabFileItemFactory extends Factory
{
    protected $model = CnabFileItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cnab_file_id' => CnabFile::factory(),
            'payment_settlement_item_id' => fn (array $attributes): string => (string) PaymentSettlementItem::factory()
                ->state(['payment_settlement_id' => CnabFile::withTrashed()->whereKey($attributes['cnab_file_id'])->value('payment_settlement_id')])
                ->create()
                ->getKey(),
            'payment_type' => CnabPaymentType::Transfer,
            'reference' => fn (array $attributes): string => CnabFileItem::referenceFor(
                (string) PaymentSettlementItem::query()->whereKey($attributes['payment_settlement_item_id'])->value('payment_request_id'),
            ),
            'amount' => fn (array $attributes): string => (string) PaymentSettlementItem::query()
                ->whereKey($attributes['payment_settlement_item_id'])
                ->value('amount'),
            'is_valid' => true,
            'validation_errors' => null,
        ];
    }

    public function invalid(string $code): static
    {
        return $this->state(fn (): array => [
            'is_valid' => false,
            'validation_errors' => [['code' => $code, 'field' => null, 'params' => []]],
        ]);
    }

    public function boleto(): static
    {
        return $this->state(fn (): array => ['payment_type' => CnabPaymentType::Boleto]);
    }

    public function pixKey(): static
    {
        return $this->state(fn (): array => ['payment_type' => CnabPaymentType::PixKey]);
    }
}
