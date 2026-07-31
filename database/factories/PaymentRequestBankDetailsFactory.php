<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccountType;
use App\Enums\DepositType;
use App\Enums\PixKeyType;
use App\Models\Bank;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestBankDetails;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentRequestBankDetails>
 */
final class PaymentRequestBankDetailsFactory extends Factory
{
    protected $model = PaymentRequestBankDetails::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_request_id' => PaymentRequest::factory(),
            'deposit_type' => null,
            'pix_key_type' => null,
            'pix_key' => null,
            'pix_qr_code' => null,
            'digitable_line' => null,
            'barcode' => null,
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

    public function boleto(): static
    {
        return $this->state(fn (): array => [
            'digitable_line' => PaymentRequestFactory::VALID_DIGITABLE_LINE,
            'barcode' => '23791100000000123451234567890123456789012345',
        ]);
    }

    public function pix(): static
    {
        return $this->state(fn (): array => [
            'deposit_type' => DepositType::Pix,
            'pix_key_type' => PixKeyType::Random,
            'pix_key' => fake()->uuid(),
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

    public function pixQrCode(): static
    {
        return $this->state(fn (): array => [
            'deposit_type' => DepositType::Pix,
            'pix_qr_code' => '00020126580014BR.GOV.BCB.PIX0136'.fake()->uuid().'5204000053039865802BR5913Empresa Teste6009SAO PAULO62070503***6304ABCD',
        ]);
    }

    public function pixBoth(): static
    {
        return $this->state(fn (): array => [
            'deposit_type' => DepositType::Pix,
            'pix_key_type' => PixKeyType::Random,
            'pix_key' => fake()->uuid(),
            'pix_qr_code' => '00020126580014BR.GOV.BCB.PIX0136'.fake()->uuid().'5204000053039865802BR5913Empresa Teste6009SAO PAULO62070503***6304ABCD',
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

    public function transferMissingHolderDocument(): static
    {
        return $this->transfer()->state(fn (): array => [
            'holder_document' => null,
        ]);
    }
}
