<?php

declare(strict_types=1);

use App\Enums\CnabFileStatus;
use App\Enums\CnabLayout;
use App\Enums\CnabPaymentType;
use App\Enums\DepositType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentSettlementStatus;
use App\Enums\PixKeyType;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestBankDetails;
use Tests\TestCase;

uses(TestCase::class);

it('allows settlement transitions only from draft', function (PaymentSettlementStatus $from, PaymentSettlementStatus $to, bool $allowed): void {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with([
    'draft to settled' => [PaymentSettlementStatus::Draft, PaymentSettlementStatus::Settled, true],
    'draft to cancelled' => [PaymentSettlementStatus::Draft, PaymentSettlementStatus::Cancelled, true],
    'draft to draft' => [PaymentSettlementStatus::Draft, PaymentSettlementStatus::Draft, false],
    'settled to cancelled' => [PaymentSettlementStatus::Settled, PaymentSettlementStatus::Cancelled, false],
    'settled to draft' => [PaymentSettlementStatus::Settled, PaymentSettlementStatus::Draft, false],
    'cancelled to draft' => [PaymentSettlementStatus::Cancelled, PaymentSettlementStatus::Draft, false],
    'cancelled to settled' => [PaymentSettlementStatus::Cancelled, PaymentSettlementStatus::Settled, false],
]);

it('follows the cnab file status matrix', function (CnabFileStatus $from, CnabFileStatus $to, bool $allowed): void {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with([
    'queued to generating' => [CnabFileStatus::Queued, CnabFileStatus::Generating, true],
    'queued to failed' => [CnabFileStatus::Queued, CnabFileStatus::Failed, true],
    'queued to generated' => [CnabFileStatus::Queued, CnabFileStatus::Generated, false],
    'generating to generated' => [CnabFileStatus::Generating, CnabFileStatus::Generated, true],
    'generating to failed' => [CnabFileStatus::Generating, CnabFileStatus::Failed, true],
    'generating to queued' => [CnabFileStatus::Generating, CnabFileStatus::Queued, false],
    'generated to superseded' => [CnabFileStatus::Generated, CnabFileStatus::Superseded, true],
    'generated to failed' => [CnabFileStatus::Generated, CnabFileStatus::Failed, false],
    'failed to queued' => [CnabFileStatus::Failed, CnabFileStatus::Queued, false],
    'superseded to generated' => [CnabFileStatus::Superseded, CnabFileStatus::Generated, false],
]);

it('lists active and in-progress cnab file statuses', function (): void {
    expect(CnabFileStatus::activeValues())->toBe(['queued', 'generating', 'generated'])
        ->and(CnabFileStatus::inProgressValues())->toBe(['queued', 'generating']);
});

it('binds the itau 240 layout to bank 341', function (): void {
    expect(CnabLayout::Itau240->bankCode())->toBe('341');
});

it('maps payment requests to cnab payment types', function (PaymentMethod $method, array $details, ?CnabPaymentType $expected): void {
    $paymentRequest = new PaymentRequest(['payment_method' => $method]);
    $paymentRequest->setRelation('bankDetails', $details === [] ? null : new PaymentRequestBankDetails($details));

    expect(CnabPaymentType::fromPaymentRequest($paymentRequest))->toBe($expected);
})->with([
    'boleto' => [PaymentMethod::Boleto, [], CnabPaymentType::Boleto],
    'transfer' => [PaymentMethod::Deposit, ['deposit_type' => DepositType::Transfer], CnabPaymentType::Transfer],
    'pix key' => [PaymentMethod::Deposit, ['deposit_type' => DepositType::Pix, 'pix_key_type' => PixKeyType::Random, 'pix_key' => 'c0a8012e-0000-4000-8000-000000000001'], CnabPaymentType::PixKey],
    'pix key and qr code' => [PaymentMethod::Deposit, ['deposit_type' => DepositType::Pix, 'pix_key_type' => PixKeyType::Random, 'pix_key' => 'c0a8012e-0000-4000-8000-000000000001', 'pix_qr_code' => '000201010212'], CnabPaymentType::PixKey],
    'pix qr code only' => [PaymentMethod::Deposit, ['deposit_type' => DepositType::Pix, 'pix_qr_code' => '000201010212'], null],
    'deposit without details' => [PaymentMethod::Deposit, [], null],
]);
