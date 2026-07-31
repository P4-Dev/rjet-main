<?php

declare(strict_types=1);

use App\Enums\PaymentRequestStatus;
use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\Branch;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestStatusHistory;
use App\Models\User;
use App\Policies\AttachmentPolicy;
use App\Policies\PaymentRequestPolicy;
use App\Policies\PaymentRequestStatusHistoryPolicy;

it('scopes visibility by linked branches for cliente', function (): void {
    $mine = Branch::factory()->create();
    $theirs = Branch::factory()->create();
    $cliente = User::factory()->cliente()->withBranches([$mine])->create();

    $visible = PaymentRequest::factory()->forBranch($mine)->create();
    $hidden = PaymentRequest::factory()->forBranch($theirs)->create();

    $ids = PaymentRequest::query()->visibleTo($cliente)->pluck('id');

    expect($ids)->toContain($visible->getKey())
        ->and($ids)->not->toContain($hidden->getKey());
});

it('lets operador and adm see all branches', function (): void {
    $request = PaymentRequest::factory()->create();
    $operador = User::factory()->operador()->create();
    $adm = User::factory()->adm()->create();

    expect(PaymentRequest::query()->visibleTo($operador)->whereKey($request)->exists())->toBeTrue()
        ->and(PaymentRequest::query()->visibleTo($adm)->whereKey($request)->exists())->toBeTrue();
});

it('applies editable matrix by role and status', function (UserRole $role, PaymentRequestStatus $status, bool $expected): void {
    $branch = Branch::factory()->create();
    $user = User::factory()->create(['role' => $role]);

    if ($role === UserRole::Cliente) {
        $user = User::factory()->cliente()->withBranches([$branch])->create();
    }

    $request = PaymentRequest::factory()->forBranch($branch)->create(['status' => $status]);
    $policy = new PaymentRequestPolicy;

    expect($policy->update($user, $request))->toBe($expected);
})->with([
    'cliente requested' => [UserRole::Cliente, PaymentRequestStatus::Requested, true],
    'cliente launched' => [UserRole::Cliente, PaymentRequestStatus::Launched, false],
    'operador launched' => [UserRole::Operador, PaymentRequestStatus::Launched, true],
    'operador settled' => [UserRole::Operador, PaymentRequestStatus::Settled, false],
    'adm settled' => [UserRole::Adm, PaymentRequestStatus::Settled, true],
]);

it('allows delete only for adm', function (): void {
    $request = PaymentRequest::factory()->create();
    $policy = new PaymentRequestPolicy;

    expect($policy->delete(User::factory()->cliente()->create(), $request))->toBeFalse()
        ->and($policy->delete(User::factory()->operador()->create(), $request))->toBeFalse()
        ->and($policy->delete(User::factory()->adm()->create(), $request))->toBeTrue();
});

it('allows transitionStatus for operador and adm only', function (): void {
    $request = PaymentRequest::factory()->requested()->create();
    $policy = new PaymentRequestPolicy;

    expect($policy->transitionStatus(User::factory()->cliente()->create(), $request))->toBeFalse()
        ->and($policy->transitionStatus(User::factory()->operador()->create(), $request))->toBeTrue()
        ->and($policy->transitionStatus(User::factory()->adm()->create(), $request))->toBeTrue();
});

it('delegates attachment policy to parent visibility', function (): void {
    $mine = Branch::factory()->create();
    $theirs = Branch::factory()->create();
    $cliente = User::factory()->cliente()->withBranches([$mine])->create();

    $attachment = Attachment::factory()->for(
        PaymentRequest::factory()->forBranch($theirs)->create(),
        'attachable',
    )->create();

    expect((new AttachmentPolicy)->view($cliente, $attachment))->toBeFalse();
});

it('ties manageAttachments to update rules', function (): void {
    $branch = Branch::factory()->create();
    $cliente = User::factory()->cliente()->withBranches([$branch])->create();
    $policy = new PaymentRequestPolicy;

    $requested = PaymentRequest::factory()->forBranch($branch)->requested()->create();
    $launched = PaymentRequest::factory()->forBranch($branch)->launched()->create();

    expect($policy->manageAttachments($cliente, $requested))->toBeTrue()
        ->and($policy->manageAttachments($cliente, $launched))->toBeFalse();
});

it('forbids mutating status history for all roles', function (): void {
    $history = PaymentRequestStatusHistory::factory()->create();
    $policy = new PaymentRequestStatusHistoryPolicy;
    $user = User::factory()->adm()->create();

    expect($policy->create($user))->toBeFalse()
        ->and($policy->update($user, $history))->toBeFalse()
        ->and($policy->delete($user, $history))->toBeFalse();
});
