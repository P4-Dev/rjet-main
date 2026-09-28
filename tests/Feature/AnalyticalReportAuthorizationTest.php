<?php

declare(strict_types=1);

use App\Models\AnalyticalReport;
use App\Models\User;

it('applies the role matrix to report abilities', function (string $role, bool $staff, bool $adm): void {
    $user = User::factory()->{$role}()->create();
    $report = AnalyticalReport::factory()->generated()->create();

    expect($user->can('viewAny', AnalyticalReport::class))->toBe($staff)
        ->and($user->can('view', $report))->toBe($staff)
        ->and($user->can('create', AnalyticalReport::class))->toBe($staff)
        ->and($user->can('update', $report))->toBeFalse()
        ->and($user->can('delete', $report))->toBe($adm)
        ->and($user->can('deleteAny', AnalyticalReport::class))->toBe($adm)
        ->and($user->can('restore', $report))->toBe($adm)
        ->and($user->can('restoreAny', AnalyticalReport::class))->toBe($adm)
        ->and($user->can('forceDelete', $report))->toBe($adm)
        ->and($user->can('forceDeleteAny', AnalyticalReport::class))->toBe($adm)
        ->and($user->can('download', $report))->toBe($staff);
})->with([
    'cliente' => ['cliente', false, false],
    'operador' => ['operador', true, false],
    'adm' => ['adm', true, true],
]);

it('hides a deleted report from operators and keeps it available to admins', function (): void {
    $operador = User::factory()->operador()->create();
    $adm = User::factory()->adm()->create();
    $generated = AnalyticalReport::factory()->generated()->create();
    $failed = AnalyticalReport::factory()->failed()->create();
    $generated->delete();
    $failed->delete();

    expect($operador->can('view', $generated))->toBeFalse()
        ->and($operador->can('download', $generated))->toBeFalse()
        ->and($operador->can('retry', $failed))->toBeFalse()
        ->and($adm->can('view', $generated))->toBeTrue()
        ->and($adm->can('download', $generated))->toBeTrue()
        ->and($adm->can('retry', $failed))->toBeTrue();
});

it('allows download only for generated and retry only for failed or stale reports', function (string $state, bool $download, bool $retry): void {
    $operador = User::factory()->operador()->create();
    $cliente = User::factory()->cliente()->create();
    $report = AnalyticalReport::factory()->{$state}()->create();

    expect($operador->can('download', $report))->toBe($download)
        ->and($operador->can('retry', $report))->toBe($retry)
        ->and($cliente->can('download', $report))->toBeFalse()
        ->and($cliente->can('retry', $report))->toBeFalse();
})->with([
    'queued' => ['queued', false, false],
    'generating' => ['generating', false, false],
    'generated' => ['generated', true, false],
    'failed' => ['failed', false, true],
    'stale' => ['stale', false, true],
]);
