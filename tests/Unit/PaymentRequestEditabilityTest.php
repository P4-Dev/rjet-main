<?php

declare(strict_types=1);

use App\Models\Approval;
use App\Models\PaymentRequest;
use App\Models\User;
use Tests\TestCase;

uses(TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

it('allows adm to edit regardless of approval state', function (): void {
    $adm = User::factory()->adm()->create();
    $request = PaymentRequest::factory()->depositPix()->awaitingApproval()->create();

    expect($request->fresh()->isEditableBy($adm))->toBeTrue();
});

it('blocks non-adm edit while approval is pending', function (): void {
    $request = PaymentRequest::factory()->depositPix()->awaitingApproval()->create();
    $cliente = User::factory()->cliente()->withBranches([$request->branch])->create();
    $operador = User::factory()->operador()->create();

    expect($request->fresh()->isEditableBy($cliente))->toBeFalse()
        ->and($request->fresh()->isEditableBy($operador))->toBeFalse();
});

it('allows cliente edit after rejection when returned', function (): void {
    $request = PaymentRequest::factory()->depositPix()->returned()->create();
    $cliente = User::factory()->cliente()->withBranches([$request->branch])->create();

    expect($request->fresh()->isReturnedToRequester())->toBeTrue()
        ->and($request->fresh()->isEditableBy($cliente))->toBeTrue();
});

it('blocks cliente edit when approved fingerprint still matches', function (): void {
    $request = PaymentRequest::factory()->depositPix()->create();
    $approver = User::factory()->adm()->approver()->create();
    $cliente = User::factory()->cliente()->withBranches([$request->branch])->create();

    Approval::factory()
        ->forPaymentRequest($request)
        ->forApprover($approver)
        ->approved()
        ->state(['material_fingerprint' => $request->currentMaterialFingerprint()])
        ->create();

    expect($request->fresh()->hasApprovedForLaunch())->toBeTrue()
        ->and($request->fresh()->isEditableBy($cliente))->toBeFalse();
});

it('allows operador edit on requested when not awaiting approval', function (): void {
    $request = PaymentRequest::factory()->depositPix()->create();
    $operador = User::factory()->operador()->create();

    expect($request->fresh()->currentPendingApproval())->toBeNull()
        ->and($request->fresh()->isEditableBy($operador))->toBeTrue();
});
