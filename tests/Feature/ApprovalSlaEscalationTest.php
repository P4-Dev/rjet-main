<?php

declare(strict_types=1);

use App\Enums\ApprovalStatus;
use App\Events\Approval\ApprovalSlaBreached;
use App\Models\Approval;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Notifications\ApprovalSlaBreachedNotification;
use App\Services\ApprovalService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

it('escalates overdue once', function (): void {
    Event::fake([ApprovalSlaBreached::class]);

    $request = PaymentRequest::factory()->depositPix()->create();
    $approver = User::factory()->operador()->approver()->create();

    $approval = Approval::factory()
        ->overdue()
        ->forPaymentRequest($request)
        ->forApprover($approver)
        ->create();

    $service = app(ApprovalService::class);
    expect($service->escalateOverdue())->toBe(1)
        ->and($approval->fresh()->escalated_at)->not->toBeNull()
        ->and($approval->fresh()->status)->toBe(ApprovalStatus::Pending);

    Event::assertDispatchedTimes(ApprovalSlaBreached::class, 1);
    expect($service->escalateOverdue())->toBe(0);
    Event::assertDispatchedTimes(ApprovalSlaBreached::class, 1);
});

it('notifies approver and admins via database only on sla breach', function (): void {
    Notification::fake();

    $request = PaymentRequest::factory()->depositPix()->create();
    $approver = User::factory()->operador()->approver()->create();
    $adm = User::factory()->adm()->create();

    $approval = Approval::factory()
        ->overdue()
        ->forPaymentRequest($request)
        ->forApprover($approver)
        ->create();

    app(ApprovalService::class)->escalate($approval);

    Notification::assertSentTo($approver, ApprovalSlaBreachedNotification::class, function ($notification, $channels): bool {
        return $channels === ['database'];
    });
    Notification::assertSentTo($adm, ApprovalSlaBreachedNotification::class, function ($notification, $channels): bool {
        return $channels === ['database'];
    });
});

it('excludes soft-deleted payment requests from overdue query', function (): void {
    $request = PaymentRequest::factory()->depositPix()->create();
    $approver = User::factory()->operador()->approver()->create();

    Approval::factory()
        ->overdue()
        ->forPaymentRequest($request)
        ->forApprover($approver)
        ->create();

    $request->delete();

    expect(app(ApprovalService::class)->escalateOverdue(dryRun: true))->toBe(0);
});
