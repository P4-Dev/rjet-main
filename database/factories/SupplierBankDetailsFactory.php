<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccountType;
use App\Enums\DepositType;
use App\Enums\PixKeyType;
use App\Models\Bank;
use App\Models\Supplier;
use App\Models\SupplierBankDetails;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierBankDetails>
 */
final class SupplierBankDetailsFactory extends Factory
{
    protected $model = SupplierBankDetails::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'deposit_type' => null,
            'pix_key_type' => null,
            'pix_key' => null,
            'bank_id' => null,
            'agency' => null,
            'agency_digit' => null,
            'account_number' => null,
            'account_digit' => null,
            'account_type' => null,
            'holder_name' => null,
            'holder_document' => null,
        ];
    }

    public function pix(): static
    {
        return $this->state(fn (): array => [
            'deposit_type' => DepositType::Pix,
            'pix_key_type' => PixKeyType::Email,
            'pix_key' => fake()->unique()->safeEmail(),
        ]);
    }

    public function pixCpf(): static
    {
        return $this->state(fn (): array => [
            'deposit_type' => DepositType::Pix,
            'pix_key_type' => PixKeyType::Cpf,
            'pix_key' => fake()->cpf(false),
        ]);
    }

    public function transfer(): static
    {
        return $this->state(fn (): array => [
            'deposit_type' => DepositType::Transfer,
            'holder_document' => fake()->cnpj(false),
            'bank_id' => Bank::factory(),
            'agency' => '1234',
            'agency_digit' => null,
            'account_number' => '123456',
            'account_digit' => '7',
            'account_type' => AccountType::Checking,
            'holder_name' => fake()->name(),
        ]);
    }
}
