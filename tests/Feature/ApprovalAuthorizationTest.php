<?php

declare(strict_types=1);

use App\Models\Approval;
use App\Models\ApprovalRule;
use App\Models\Branch;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Policies\ApprovalRulePolicy;
use App\Policies\PaymentRequestPolicy;

it('denies approve and reject for cliente', function (): void {
    $request = PaymentRequest::factory()->awaitingApproval()->create();
    $cliente = User::factory()->cliente()->withBranches([$request->branch])->create();

    $policy = app(PaymentRequestPolicy::class);

    expect($policy->approve($cliente, $request))->toBeFalse()
        ->and($policy->reject($cliente, $request))->toBeFalse();
});

it('allows assignee with can_approve to approve', function (): void {
    $approver = User::factory()->operador()->approver()->create();
    $request = PaymentRequest::factory()->depositPix()->create();
    Approval::factory()->pending()->forPaymentRequest($request)->forApprover($approver)->create();

    expect(app(PaymentRequestPolicy::class)->approve($approver, $request->fresh()))->toBeTrue();
});

it('denies adm without can_approve', function (): void {
    $adm = User::factory()->adm()->create(['can_approve' => false]);
    $request = PaymentRequest::factory()->awaitingApproval()->create();

    expect(app(PaymentRequestPolicy::class)->approve($adm, $request))->toBeFalse();
});

it('allows adm with can_approve to approve any pending', function (): void {
    $adm = User::factory()->adm()->approver()->create();
    $request = PaymentRequest::factory()->awaitingApproval()->create();

    expect(app(PaymentRequestPolicy::class)->approve($adm, $request))->toBeTrue();
});

it('restricts approval rule crud to adm', function (): void {
    $policy = app(ApprovalRulePolicy::class);
    $rule = ApprovalRule::factory()->create();

    $adm = User::factory()->adm()->create();
    $operador = User::factory()->operador()->create();
    $cliente = User::factory()->cliente()->create();

    expect($policy->viewAny($adm))->toBeTrue()
        ->and($policy->create($adm))->toBeTrue()
        ->and($policy->viewAny($operador))->toBeFalse()
        ->and($policy->viewAny($cliente))->toBeFalse()
        ->and($policy->update($operador, $rule))->toBeFalse();
});

it('allows cliente to view approvals of visible payment request', function (): void {
    $request = PaymentRequest::factory()->awaitingApproval()->create();
    $cliente = User::factory()->cliente()->withBranches([$request->branch])->create();
    $approval = $request->fresh()->currentPendingApproval();

    expect($cliente->can('view', $approval))->toBeTrue();
});
