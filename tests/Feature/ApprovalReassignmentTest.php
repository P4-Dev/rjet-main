<?php

declare(strict_types=1);

use App\Events\Approval\ApprovalReassigned;
use App\Models\Approval;
use App\Models\ApprovalReassignment;
use App\Models\ApprovalRule;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Notifications\ApprovalReassignedNotification;
use App\Services\UserService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;

it('reassigns pending approvals when approver is deactivated', function (): void {
    Event::fake([ApprovalReassigned::class]);
    Notification::fake();

    $adm = User::factory()->adm()->create(['created_at' => now()->subDay()]);
    $actor = User::factory()->adm()->create();
    $approver = User::factory()->operador()->approver()->create();
    $request = PaymentRequest::factory()->depositPix()->create();

    $approval = Approval::factory()
        ->pending()
        ->forPaymentRequest($request)
        ->forApprover($approver)
        ->create();

    actingAs($actor);
    app(UserService::class)->deactivate($approver);

    expect($approval->fresh()->approver_user_id)->toBe($adm->getKey())
        ->and(ApprovalReassignment::query()->where('approval_id', $approval->getKey())->count())->toBe(1);

    Event::assertDispatched(ApprovalReassigned::class);
});

it('notifies new approver on reassignment', function (): void {
    Notification::fake();

    $adm = User::factory()->adm()->create(['created_at' => now()->subDay()]);
    $actor = User::factory()->adm()->create();
    $approver = User::factory()->operador()->approver()->create();
    $request = PaymentRequest::factory()->depositPix()->create();

    Approval::factory()
        ->pending()
        ->forPaymentRequest($request)
        ->forApprover($approver)
        ->create();

    actingAs($actor);
    app(UserService::class)->deactivate($approver);

    Notification::assertSentTo($adm, ApprovalReassignedNotification::class);
});

it('blocks force delete of user with pending approvals', function (): void {
    $actor = User::factory()->adm()->create();
    $approver = User::factory()->operador()->approver()->create();
    $request = PaymentRequest::factory()->depositPix()->create();

    Approval::factory()
        ->pending()
        ->forPaymentRequest($request)
        ->forApprover($approver)
        ->create();

    expect(fn () => app(UserService::class)->forceDelete($approver))
        ->toThrow(\App\Exceptions\UserException::class);
});
