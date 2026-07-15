<?php

declare(strict_types=1);

use App\Enums\AccountType;
use App\Enums\UserRole;

it('resolves user role labels via translation', function (): void {
    expect(UserRole::Cliente->getLabel())->toBe('Cliente')
        ->and(UserRole::Operador->getLabel())->toBe('Operador')
        ->and(UserRole::Adm->getLabel())->toBe('Administrador');
});

it('exposes color and icon for every user role', function (): void {
    foreach (UserRole::cases() as $role) {
        expect($role->getColor())->toBeString()->not->toBeEmpty()
            ->and($role->getIcon())->toBeString()->toStartWith('heroicon-');
    }
});

it('computes user role permission helpers', function (): void {
    expect(UserRole::Adm->canManageRegistrations())->toBeTrue()
        ->and(UserRole::Operador->canManageRegistrations())->toBeFalse()
        ->and(UserRole::Cliente->canManageRegistrations())->toBeFalse();

    expect(UserRole::Adm->seesAllBranches())->toBeTrue()
        ->and(UserRole::Operador->seesAllBranches())->toBeTrue()
        ->and(UserRole::Cliente->seesAllBranches())->toBeFalse();
});

it('resolves account type labels via translation', function (): void {
    expect(AccountType::Checking->getLabel())->toBe('Conta corrente')
        ->and(AccountType::Savings->getLabel())->toBe('Conta poupança');

    foreach (AccountType::cases() as $type) {
        expect($type->getColor())->toBeString()->not->toBeEmpty()
            ->and($type->getIcon())->toBeString()->toStartWith('heroicon-');
    }
});
