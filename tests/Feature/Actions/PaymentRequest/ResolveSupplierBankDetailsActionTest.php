<?php

declare(strict_types=1);

use App\Actions\PaymentRequest\ResolveSupplierBankDetailsAction;
use App\Enums\DepositType;
use App\Enums\PixKeyType;
use App\Models\Supplier;

it('returns pix form state from supplier bank details', function (): void {
    $supplier = Supplier::factory()->withPixDetails()->create();
    $details = $supplier->load('bankDetails')->bankDetails;

    $state = app(ResolveSupplierBankDetailsAction::class)((string) $supplier->getKey());

    expect($state)->toMatchArray([
        'deposit_type' => DepositType::Pix->value,
        'pix_key_type' => PixKeyType::Email->value,
        'pix_key' => $details->pix_key,
    ]);
});

it('returns transfer form state from supplier bank details', function (): void {
    $supplier = Supplier::factory()->withTransferDetails()->create();
    $details = $supplier->load('bankDetails')->bankDetails;

    $state = app(ResolveSupplierBankDetailsAction::class)((string) $supplier->getKey());

    expect($state)->toMatchArray([
        'deposit_type' => DepositType::Transfer->value,
        'bank_id' => $details->bank_id,
        'agency' => '1234',
        'account_number' => '123456',
        'account_digit' => '7',
        'holder_document' => $details->holder_document,
        'holder_name' => $details->holder_name,
    ]);
});

it('returns null when the supplier has no bank details', function (): void {
    $supplier = Supplier::factory()->create();

    $state = app(ResolveSupplierBankDetailsAction::class)((string) $supplier->getKey());

    expect($state)->toBeNull();
});
