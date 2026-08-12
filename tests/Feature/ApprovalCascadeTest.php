<?php

declare(strict_types=1);

use App\Models\Approval;
use App\Models\ApprovalReassignment;
use App\Models\ApprovalRule;
use App\Models\Branch;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\BranchService;

it('keeps approvals when payment request is soft deleted', function (): void {
    $request = PaymentRequest::factory()->depositPix()->create();
    $approver = User::factory()->operador()->approver()->create();

    $approval = Approval::factory()
        ->pending()
        ->forPaymentRequest($request)
        ->forApprover($approver)
        ->create();

    $request->delete();

    expect($request->fresh()->trashed())->toBeTrue()
        ->and(Approval::query()->find($approval->getKey()))->not->toBeNull();
});

it('force deletes approvals and reassignments with payment request', function (): void {
    $request = PaymentRequest::factory()->depositPix()->create();
    $approver = User::factory()->operador()->approver()->create();
    $actor = User::factory()->adm()->create();
    $to = User::factory()->adm()->create();

    $approval = Approval::factory()
        ->pending()
        ->forPaymentRequest($request)
        ->forApprover($approver)
        ->create();

    $reassignment = ApprovalReassignment::factory()->create([
        'approval_id' => $approval->getKey(),
        'from_approver_user_id' => $approver->getKey(),
        'to_approver_user_id' => $to->getKey(),
        'created_by' => $actor->getKey(),
    ]);

    $approvalId = $approval->getKey();
    $reassignmentId = $reassignment->getKey();

    $request->forceDelete();

    expect(Approval::query()->find($approvalId))->toBeNull()
        ->and(ApprovalReassignment::query()->find($reassignmentId))->toBeNull();
});

it('soft deletes approval rules when branch is deleted via BranchService', function (): void {
    $branch = Branch::factory()->create();
    $approver = User::factory()->operador()->approver()->create();
    $rule = ApprovalRule::factory()->forBranch($branch)->forApprover($approver)->create();

    app(BranchService::class)->delete($branch);

    expect($branch->fresh()->trashed())->toBeTrue()
        ->and(ApprovalRule::withTrashed()->find($rule->getKey())?->trashed())->toBeTrue()
        ->and(ApprovalRule::query()->find($rule->getKey()))->toBeNull();
});
