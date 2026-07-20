<?php

declare(strict_types=1);

use App\Enums\AddressType;
use App\Enums\ContactType;
use App\Enums\PaymentMethod;
use App\Enums\PersonType;

it('resolves person type labels via translation', function (): void {
    expect(PersonType::Pf->getLabel())->toBe('Pessoa física')
        ->and(PersonType::Pj->getLabel())->toBe('Pessoa jurídica');

    foreach (PersonType::cases() as $type) {
        expect($type->getColor())->toBeString()->not->toBeEmpty()
            ->and($type->getIcon())->toBeString()->toStartWith('heroicon-');
    }
});

it('resolves payment method labels via translation', function (): void {
    expect(PaymentMethod::Boleto->getLabel())->toBe('Boleto')
        ->and(PaymentMethod::Deposit->getLabel())->toBe('Depósito');

    foreach (PaymentMethod::cases() as $method) {
        expect($method->getColor())->toBeString()->not->toBeEmpty()
            ->and($method->getIcon())->toBeString()->toStartWith('heroicon-');
    }
});

it('resolves address and contact type labels', function (): void {
    expect(AddressType::Main->getLabel())->toBe('Principal')
        ->and(ContactType::Technical->getLabel())->toBe('Técnico');
});
