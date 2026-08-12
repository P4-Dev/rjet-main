<?php

declare(strict_types=1);

use App\DTOs\ApprovalRuleData;
use App\Exceptions\ApprovalRuleException;
use App\Models\ApprovalRule;
use App\Models\Branch;
use App\Models\User;
use App\Services\ApprovalRuleService;

beforeEach(function (): void {
    $this->service = app(ApprovalRuleService::class);
    $this->actor = User::factory()->adm()->create();
});

it('rejects cliente approver', function (): void {
    $branch = Branch::factory()->create();
    $cliente = User::factory()->cliente()->create(['can_approve' => true]);

    expect(fn () => $this->service->create(new ApprovalRuleData(
        branchId: (string) $branch->getKey(),
        minAmount: '0.00',
        maxAmount: '1000.00',
        approverUserId: (string) $cliente->getKey(),
    ), $this->actor))->toThrow(ApprovalRuleException::class);
});

it('rejects approver without can_approve', function (): void {
    $branch = Branch::factory()->create();
    $operador = User::factory()->operador()->create(['can_approve' => false]);

    expect(fn () => $this->service->create(new ApprovalRuleData(
        branchId: (string) $branch->getKey(),
        minAmount: '0.00',
        maxAmount: '1000.00',
        approverUserId: (string) $operador->getKey(),
    ), $this->actor))->toThrow(ApprovalRuleException::class);
});

it('rejects inactive approver', function (): void {
    $branch = Branch::factory()->create();
    $approver = User::factory()->operador()->approver()->create(['is_active' => false]);

    expect(fn () => $this->service->create(new ApprovalRuleData(
        branchId: (string) $branch->getKey(),
        minAmount: '0.00',
        maxAmount: '1000.00',
        approverUserId: (string) $approver->getKey(),
    ), $this->actor))->toThrow(ApprovalRuleException::class);
});

it('rejects invalid amount range', function (): void {
    $branch = Branch::factory()->create();
    $approver = User::factory()->operador()->approver()->create();

    expect(fn () => $this->service->create(new ApprovalRuleData(
        branchId: (string) $branch->getKey(),
        minAmount: '1000.00',
        maxAmount: '100.00',
        approverUserId: (string) $approver->getKey(),
    ), $this->actor))->toThrow(ApprovalRuleException::class);
});

it('detects overlapping rules', function (): void {
    $branch = Branch::factory()->create();
    $approver = User::factory()->operador()->approver()->create();

    ApprovalRule::factory()->forBranch($branch)->forApprover($approver)->range(0, 5000)->create();

    $overlaps = $this->service->findOverlapping($branch, '1000.00', '2000.00');

    expect($overlaps)->toHaveCount(1);
});
