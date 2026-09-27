<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Bank;
use App\Models\Branch;
use App\Models\BranchBankAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BranchBankAccount>
 */
final class BranchBankAccountFactory extends Factory
{
    protected $model = BranchBankAccount::class;

    /**
     * @var array<string, string>
     */
    private const BANKS = [
        '341' => 'Itaú Unibanco',
        '237' => 'Bradesco',
        '001' => 'Banco do Brasil',
        '033' => 'Santander',
        '104' => 'Caixa Econômica Federal',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = fake()->randomElement(array_keys(self::BANKS));

        return [
            'branch_id' => Branch::factory(),
            'bank_id' => null,
            'bank_code' => $code,
            'bank_name' => self::BANKS[$code],
            'agency' => (string) fake()->numberBetween(1, 9999),
            'agency_digit' => (string) fake()->numberBetween(0, 9),
            'account_number' => (string) fake()->numberBetween(1000, 99999999),
            'account_digit' => (string) fake()->numberBetween(0, 9),
            'account_type' => fake()->randomElement(AccountType::cases()),
            'holder_name' => fake()->company(),
            'is_default' => false,
            'is_active' => true,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_default' => true,
        ]);
    }

    /**
     * banks_code_unique is partial, so a repeated Bank::factory() for 341 would collide.
     */
    public function itau(): static
    {
        return $this->state(fn (array $attributes): array => [
            'bank_id' => Bank::query()->firstOrCreate(
                ['code' => '341'],
                ['name' => 'Itaú Unibanco', 'ispb' => '60701190', 'is_active' => true],
            )->getKey(),
            'bank_code' => '341',
            'bank_name' => 'Itaú Unibanco',
            'agency' => (string) fake()->numberBetween(1000, 9999),
            'account_number' => (string) fake()->numberBetween(10000, 99999),
            'account_digit' => (string) fake()->numberBetween(0, 9),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
