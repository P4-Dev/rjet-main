<?php

declare(strict_types=1);

use App\Models\CnabConfig;
use App\Models\CnabFile;
use App\Models\PaymentSettlement;
use App\Models\User;

it('denies cliente every settlement, cnab file and config ability', function (): void {
    $cliente = User::factory()->cliente()->withBranches(1)->create();
    $settlement = PaymentSettlement::factory()->create();
    $file = CnabFile::factory()->for($settlement, 'settlement')->generated()->create();
    $config = CnabConfig::query()->findOrFail($file->cnab_config_id);

    expect($cliente->can('viewAny', PaymentSettlement::class))->toBeFalse()
        ->and($cliente->can('view', $settlement))->toBeFalse()
        ->and($cliente->can('create', PaymentSettlement::class))->toBeFalse()
        ->and($cliente->can('confirm', $settlement))->toBeFalse()
        ->and($cliente->can('cancel', $settlement))->toBeFalse()
        ->and($cliente->can('generateCnab', $settlement))->toBeFalse()
        ->and($cliente->can('download', $file))->toBeFalse()
        ->and($cliente->can('viewAny', CnabConfig::class))->toBeFalse()
        ->and($cliente->can('view', $config))->toBeFalse();
});

it('lets staff operate draft settlements', function (string $role): void {
    $user = User::factory()->{$role}()->create();
    $draft = PaymentSettlement::factory()->create();
    $settled = PaymentSettlement::factory()->settled()->create();

    expect($user->can('viewAny', PaymentSettlement::class))->toBeTrue()
        ->and($user->can('create', PaymentSettlement::class))->toBeTrue()
        ->and($user->can('confirm', $draft))->toBeTrue()
        ->and($user->can('cancel', $draft))->toBeTrue()
        ->and($user->can('generateCnab', $draft))->toBeTrue()
        ->and($user->can('confirm', $settled))->toBeFalse()
        ->and($user->can('generateCnab', $settled))->toBeFalse();
})->with(['operador', 'adm']);

it('lets only adm delete cancelled settlements', function (): void {
    $adm = User::factory()->adm()->create();
    $operador = User::factory()->operador()->create();
    $cancelled = PaymentSettlement::factory()->cancelled()->create();
    $draft = PaymentSettlement::factory()->create();

    expect($adm->can('delete', $cancelled))->toBeTrue()
        ->and($adm->can('delete', $draft))->toBeFalse()
        ->and($operador->can('delete', $cancelled))->toBeFalse();
});

it('lets only adm regenerate and nobody delete cnab files', function (): void {
    $adm = User::factory()->adm()->create();
    $operador = User::factory()->operador()->create();
    $file = CnabFile::factory()->generated()->create();

    expect($adm->can('regenerate', $file))->toBeTrue()
        ->and($operador->can('regenerate', $file))->toBeFalse()
        ->and($operador->can('download', $file))->toBeTrue()
        ->and($adm->can('delete', $file))->toBeFalse();
});

it('restricts cnab config writes to adm while operador only reads', function (): void {
    $adm = User::factory()->adm()->create();
    $operador = User::factory()->operador()->create();
    $config = CnabConfig::factory()->create();

    expect($operador->can('viewAny', CnabConfig::class))->toBeTrue()
        ->and($operador->can('view', $config))->toBeTrue()
        ->and($operador->can('create', CnabConfig::class))->toBeFalse()
        ->and($operador->can('update', $config))->toBeFalse()
        ->and($operador->can('delete', $config))->toBeFalse()
        ->and($adm->can('create', CnabConfig::class))->toBeTrue()
        ->and($adm->can('update', $config))->toBeTrue();
});
