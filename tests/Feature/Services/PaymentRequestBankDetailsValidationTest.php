<?php

declare(strict_types=1);

use App\Enums\AccountType;
use App\Enums\DepositType;
use App\Enums\PaymentMethod;
use App\Enums\PixKeyType;
use App\Enums\UserRole;
use App\Exceptions\PaymentRequestException;
use App\DTOs\PaymentRequestBankDetailsData;
use App\Models\Bank;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestBankDetails;
use App\Models\User;
use App\Services\PaymentRequestService;
use Database\Factories\PaymentRequestFactory;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->service = app(PaymentRequestService::class);
    $this->actor = User::factory()->create(['role' => UserRole::Adm]);
});

it('accepts pix with key only, qr only, or both', function (array $details): void {
    expect(fn () => $this->service->assertBankDetails(PaymentMethod::Deposit, $details))
        ->not->toThrow(PaymentRequestException::class);
})->with([
    'key only' => [[
        'deposit_type' => DepositType::Pix,
        'pix_key_type' => PixKeyType::Email,
        'pix_key' => 'ok@example.com',
    ]],
    'qr only' => [[
        'deposit_type' => DepositType::Pix,
        'pix_qr_code' => '00020126PIX',
    ]],
    'both' => [[
        'deposit_type' => DepositType::Pix,
        'pix_key_type' => PixKeyType::Random,
        'pix_key' => fake()->uuid(),
        'pix_qr_code' => '00020126PIX',
    ]],
]);

it('rejects pix without key and without qr', function (): void {
    expect(fn () => $this->service->assertBankDetails(PaymentMethod::Deposit, [
        'deposit_type' => DepositType::Pix,
    ]))->toThrow(PaymentRequestException::class);
});

it('rejects invalid pix cpf key', function (): void {
    expect(fn () => $this->service->assertBankDetails(PaymentMethod::Deposit, [
        'deposit_type' => DepositType::Pix,
        'pix_key_type' => PixKeyType::Cpf,
        'pix_key' => '11111111111',
    ]))->toThrow(ValidationException::class);
});

it('validates complete transfer details', function (): void {
    $bank = Bank::factory()->create();

    expect(fn () => $this->service->assertBankDetails(PaymentMethod::Deposit, [
        'deposit_type' => DepositType::Transfer,
        'holder_document' => fake()->cnpj(false),
        'bank_id' => $bank->getKey(),
        'agency' => '1234',
        'account_number' => '123456',
        'account_digit' => '7',
        'account_type' => AccountType::Checking,
    ]))->not->toThrow(PaymentRequestException::class);
});

it('rejects incomplete transfer details', function (array $missing): void {
    $bank = Bank::factory()->create();
    $base = [
        'deposit_type' => DepositType::Transfer,
        'holder_document' => fake()->cnpj(false),
        'bank_id' => $bank->getKey(),
        'agency' => '1234',
        'account_number' => '123456',
        'account_digit' => '7',
        'account_type' => AccountType::Checking,
    ];

    expect(fn () => $this->service->assertBankDetails(PaymentMethod::Deposit, array_merge($base, $missing)))
        ->toThrow(PaymentRequestException::class);
})->with([
    'holder_document' => [['holder_document' => null]],
    'account_type' => [['account_type' => null]],
    'bank_id' => [['bank_id' => null]],
    'agency' => [['agency' => null]],
    'account_number' => [['account_number' => null]],
    'account_digit' => [['account_digit' => null]],
]);

it('allows optional transfer fields to be null', function (): void {
    $bank = Bank::factory()->create();

    expect(fn () => $this->service->assertBankDetails(PaymentMethod::Deposit, [
        'deposit_type' => DepositType::Transfer,
        'holder_document' => fake()->cpf(false),
        'bank_id' => $bank->getKey(),
        'agency' => '1234',
        'agency_digit' => null,
        'account_number' => '123456',
        'account_digit' => '7',
        'account_type' => AccountType::Checking,
        'holder_name' => null,
    ]))->not->toThrow(PaymentRequestException::class);
});

it('rejects invalid holder document lengths and check digits', function (string $document): void {
    $bank = Bank::factory()->create();

    expect(fn () => $this->service->assertBankDetails(PaymentMethod::Deposit, [
        'deposit_type' => DepositType::Transfer,
        'holder_document' => $document,
        'bank_id' => $bank->getKey(),
        'agency' => '1234',
        'account_number' => '123456',
        'account_digit' => '7',
        'account_type' => AccountType::Checking,
    ]))->toThrow(PaymentRequestException::class);
})->with([
    '12 digits' => ['123456789012'],
    'invalid cpf' => ['11111111111'],
]);

it('rejects boleto without digitable line', function (): void {
    expect(fn () => $this->service->assertBankDetails(PaymentMethod::Boleto, []))
        ->toThrow(PaymentRequestException::class);
});

it('enforces partial unique index for bank details 1:1', function (): void {
    $request = PaymentRequest::factory()->create();

    PaymentRequestBankDetails::factory()->boleto()->create([
        'payment_request_id' => $request->getKey(),
    ]);

    expect(fn () => PaymentRequestBankDetails::factory()->boleto()->create([
        'payment_request_id' => $request->getKey(),
    ]))->toThrow(QueryException::class);

    PaymentRequestBankDetails::query()->where('payment_request_id', $request->getKey())->first()->delete();

    $second = PaymentRequestBankDetails::factory()->boleto()->create([
        'payment_request_id' => $request->getKey(),
    ]);

    expect($second->fresh())->not->toBeNull();
});

it('persists holder_document as digits only via DTO', function (): void {
    $dto = PaymentRequestBankDetailsData::fromArray([
        'deposit_type' => DepositType::Transfer->value,
        'holder_document' => '123.456.789-09',
    ]);

    // May be invalid CPF for assert, but fromArray must strip non-digits.
    expect($dto->holderDocument)->toBe('12345678909');
});
