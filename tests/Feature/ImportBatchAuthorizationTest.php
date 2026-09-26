<?php

declare(strict_types=1);

use App\Models\ImportBatch;
use App\Models\ImportTemplate;
use App\Models\User;
use App\Policies\ImportBatchPolicy;
use App\Policies\ImportTemplatePolicy;

it('covers import template policy matrix', function (): void {
    $adm = User::factory()->adm()->create();
    $operador = User::factory()->operador()->create();
    $cliente = User::factory()->cliente()->create();
    $template = ImportTemplate::factory()->create();
    $policy = new ImportTemplatePolicy;

    expect($policy->viewAny($adm))->toBeTrue()
        ->and($policy->create($adm))->toBeTrue()
        ->and($policy->update($adm, $template))->toBeTrue()
        ->and($policy->delete($adm, $template))->toBeTrue()
        ->and($policy->viewAny($operador))->toBeFalse()
        ->and($policy->create($operador))->toBeFalse()
        ->and($policy->viewAny($cliente))->toBeFalse();
});

it('covers import batch policy matrix', function (): void {
    $adm = User::factory()->adm()->create();
    $operador = User::factory()->operador()->create();
    $cliente = User::factory()->cliente()->create();
    $batch = ImportBatch::factory()->create();
    $policy = new ImportBatchPolicy;

    expect($policy->viewAny($adm))->toBeTrue()
        ->and($policy->viewAny($operador))->toBeTrue()
        ->and($policy->create($operador))->toBeTrue()
        ->and($policy->update($operador, $batch))->toBeFalse()
        ->and($policy->delete($operador, $batch))->toBeFalse()
        ->and($policy->delete($adm, $batch))->toBeTrue()
        ->and($policy->viewAny($cliente))->toBeFalse()
        ->and($policy->create($cliente))->toBeFalse();
});
