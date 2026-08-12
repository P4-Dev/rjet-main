<?php

declare(strict_types=1);

use App\DTOs\PaymentRequestBankDetailsData;
use App\DTOs\PaymentRequestData;
use App\Enums\ApprovalStatus;
use App\Enums\DepositType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRequestStatus;
use App\Enums\PixKeyType;
use App\Events\Approval\ApprovalAssigned;
use App\Events\PaymentRequest\PaymentRequestApproved;
use App\Events\PaymentRequest\PaymentRequestRejected;
use App\Exceptions\ApprovalException;
use App\Models\Approval;
use App\Models\ApprovalRule;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\PaymentRequest;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\PaymentRequestService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->approvalService = app(ApprovalService::class);
    $this->paymentRequestService = app(PaymentRequestService::class);
    Notification::fake();
});

/**
 * @return array{branch: Branch, request: PaymentRequest, rule: ApprovalRule, approver: User, actor: User}
 */
function approvalContext(string $amount = '1000.00'): array
{
    $branch = Branch::factory()->create();
    $branch->company->update(['approval_sla_business_days' => 2]);
    $approver = User::factory()->operador()->approver()->create();
    $rule = ApprovalRule::factory()
        ->forBranch($branch)
        ->forApprover($approver)
        ->range(0, 50000)
        ->create();

    $actor = User::factory()->adm()->create();
    $request = app(PaymentRequestService::class)->create(new PaymentRequestData(
        branchId: (string) $branch->getKey(),
        supplierId: (string) Supplier::factory()->create()->getKey(),
        costCenterId: (string) CostCenter::factory()->for($branch)->create()->getKey(),
        appropriationId: null,
        paymentMethod: PaymentMethod::Deposit,
        grossAmount: $amount,
        discountAmount: '0.00',
        dueDate: CarbonImmutable::now()->addWeek(),
        bankDetails: new PaymentRequestBankDetailsData(
            depositType: DepositType::Pix,
            pixKeyType: PixKeyType::Email,
            pixKey: 'a@b.com',
        ),
    ), $actor);

    return compact('branch', 'request', 'rule', 'approver', 'actor');
}

it('routes after create for cliente and operador', function (): void {
    Event::fake([ApprovalAssigned::class]);

    $branch = Branch::factory()->create();
    $branch->company->update(['approval_sla_business_days' => 2]);
    $approver = User::factory()->operador()->approver()->create();
    ApprovalRule::factory()->forBranch($branch)->forApprover($approver)->range(0, null)->create();

    $cliente = User::factory()->cliente()->withBranches([$branch])->create();
    $request = app(PaymentRequestService::class)->create(new PaymentRequestData(
        branchId: (string) $branch->getKey(),
        supplierId: (string) Supplier::factory()->create()->getKey(),
        costCenterId: (string) CostCenter::factory()->for($branch)->create()->getKey(),
        appropriationId: null,
        paymentMethod: PaymentMethod::Deposit,
        grossAmount: '100.00',
        discountAmount: '0.00',
        dueDate: CarbonImmutable::now()->addWeek(),
        bankDetails: new PaymentRequestBankDetailsData(
            depositType: DepositType::Pix,
            pixKeyType: PixKeyType::Email,
            pixKey: 'c@d.com',
        ),
    ), $cliente);

    expect($request->fresh()->currentPendingApproval()?->status)->toBe(ApprovalStatus::Pending);
    Event::assertDispatched(ApprovalAssigned::class);

    $operador = User::factory()->operador()->create();
    $request2 = app(PaymentRequestService::class)->create(new PaymentRequestData(
        branchId: (string) $branch->getKey(),
        supplierId: (string) Supplier::factory()->create()->getKey(),
        costCenterId: (string) CostCenter::factory()->for($branch)->create()->getKey(),
        appropriationId: null,
        paymentMethod: PaymentMethod::Deposit,
        grossAmount: '200.00',
        discountAmount: '0.00',
        dueDate: CarbonImmutable::now()->addWeek(),
        bankDetails: new PaymentRequestBankDetailsData(
            depositType: DepositType::Pix,
            pixKeyType: PixKeyType::Email,
            pixKey: 'e@f.com',
        ),
    ), $operador);

    expect($request2->fresh()->currentPendingApproval()?->status)->toBe(ApprovalStatus::Pending);
});

it('throws noMatchingRule when no rule exists and keeps request requested', function (): void {
    $branch = Branch::factory()->create();
    $actor = User::factory()->adm()->create();

    $request = $this->paymentRequestService->create(new PaymentRequestData(
        branchId: (string) $branch->getKey(),
        supplierId: (string) Supplier::factory()->create()->getKey(),
        costCenterId: (string) CostCenter::factory()->for($branch)->create()->getKey(),
        appropriationId: null,
        paymentMethod: PaymentMethod::Deposit,
        grossAmount: '50.00',
        discountAmount: '0.00',
        dueDate: CarbonImmutable::now()->addWeek(),
        bankDetails: new PaymentRequestBankDetailsData(
            depositType: DepositType::Pix,
            pixKeyType: PixKeyType::Email,
            pixKey: 'x@y.com',
        ),
    ), $actor);

    expect($request->status)->toBe(PaymentRequestStatus::Requested)
        ->and($request->approvals()->count())->toBe(0);

    expect(fn () => $this->approvalService->route($request, $actor))
        ->toThrow(ApprovalException::class);
});

it('rejects second pending route', function (): void {
    ['request' => $request, 'actor' => $actor] = approvalContext();

    expect(fn () => $this->approvalService->route($request->fresh(), $actor))
        ->toThrow(ApprovalException::class);
});

it('approves and allows launch while keeping request requested until launch', function (): void {
    Event::fake([PaymentRequestApproved::class, ApprovalAssigned::class]);
    ['request' => $request, 'approver' => $approver, 'actor' => $actor] = approvalContext();

    $pending = $request->fresh()->currentPendingApproval();
    $approved = $this->approvalService->approve($pending, $approver);

    expect($approved->status)->toBe(ApprovalStatus::Approved)
        ->and($request->fresh()->status)->toBe(PaymentRequestStatus::Requested)
        ->and($request->fresh()->hasApprovedForLaunch())->toBeTrue();

    Event::assertDispatched(PaymentRequestApproved::class);

    $launched = $this->paymentRequestService->transitionStatus(
        $request->fresh(),
        PaymentRequestStatus::Launched,
        $actor,
    );

    expect($launched->status)->toBe(PaymentRequestStatus::Launched);
});

it('requires reason on reject and dispatches event when valid', function (): void {
    Event::fake([PaymentRequestRejected::class, ApprovalAssigned::class]);
    ['request' => $request, 'approver' => $approver] = approvalContext();
    $pending = $request->fresh()->currentPendingApproval();

    expect(fn () => $this->approvalService->reject($pending, $approver, 'no'))
        ->toThrow(ApprovalException::class);

    $rejected = $this->approvalService->reject($pending->fresh(), $approver, 'Valor fora da política.');

    expect($rejected->status)->toBe(ApprovalStatus::Rejected)
        ->and($request->fresh()->isReturnedToRequester())->toBeTrue();

    Event::assertDispatched(PaymentRequestRejected::class);
});

it('resubmits after reject creating a second pending approval', function (): void {
    ['request' => $request, 'approver' => $approver, 'actor' => $actor] = approvalContext();
    $pending = $request->fresh()->currentPendingApproval();
    $this->approvalService->reject($pending, $approver, 'Motivo válido de rejeição.');

    $second = $this->approvalService->resubmit($request->fresh(), $actor);

    expect($second->status)->toBe(ApprovalStatus::Pending)
        ->and($request->fresh()->approvals()->count())->toBe(2)
        ->and($request->fresh()->approvals()->where('status', ApprovalStatus::Rejected)->count())->toBe(1);
});

it('blocks launch without valid approval even for adm', function (): void {
    $branch = Branch::factory()->create();
    $actor = User::factory()->adm()->approver()->create();
    $request = $this->paymentRequestService->create(new PaymentRequestData(
        branchId: (string) $branch->getKey(),
        supplierId: (string) Supplier::factory()->create()->getKey(),
        costCenterId: (string) CostCenter::factory()->for($branch)->create()->getKey(),
        appropriationId: null,
        paymentMethod: PaymentMethod::Deposit,
        grossAmount: '80.00',
        discountAmount: '0.00',
        dueDate: CarbonImmutable::now()->addWeek(),
        bankDetails: new PaymentRequestBankDetailsData(
            depositType: DepositType::Pix,
            pixKeyType: PixKeyType::Email,
            pixKey: 'z@z.com',
        ),
    ), $actor);

    expect(fn () => $this->paymentRequestService->transitionStatus(
        $request,
        PaymentRequestStatus::Launched,
        $actor,
    ))->toThrow(ApprovalException::class);
});

it('allows launch after approve when only notes or cost center changed', function (): void {
    ['request' => $request, 'approver' => $approver, 'actor' => $actor, 'branch' => $branch] = approvalContext();
    $this->approvalService->approve($request->fresh()->currentPendingApproval(), $approver);

    $newCostCenter = CostCenter::factory()->for($branch)->create();
    $request->update([
        'notes' => 'apenas observação',
        'cost_center_id' => $newCostCenter->getKey(),
    ]);

    expect($request->fresh()->hasApprovedForLaunch())->toBeTrue();

    $launched = $this->paymentRequestService->transitionStatus(
        $request->fresh(),
        PaymentRequestStatus::Launched,
        $actor,
    );

    expect($launched->status)->toBe(PaymentRequestStatus::Launched);
});

it('notifies creator via mail and database on approve', function (): void {
    ['request' => $request, 'approver' => $approver] = approvalContext();
    $creator = User::query()->findOrFail($request->created_by);

    $this->approvalService->approve($request->fresh()->currentPendingApproval(), $approver);

    Notification::assertSentTo(
        $creator,
        \App\Notifications\PaymentRequestApprovedNotification::class,
        function ($notification, $channels): bool {
            return in_array('mail', $channels, true) && in_array('database', $channels, true);
        },
    );
});

it('enforces partial unique pending approval', function (): void {
    ['request' => $request, 'approver' => $approver] = approvalContext();

    expect(fn () => Approval::factory()
        ->pending()
        ->forPaymentRequest($request->fresh())
        ->forApprover($approver)
        ->create()
    )->toThrow(QueryException::class);
});

it('resubmits after material edit invalidates approved fingerprint', function (): void {
    ['request' => $request, 'approver' => $approver, 'actor' => $actor] = approvalContext();
    $this->approvalService->approve($request->fresh()->currentPendingApproval(), $approver);

    $request->update(['net_amount' => '2500.00', 'gross_amount' => '2500.00']);

    expect($request->fresh()->hasApprovedForLaunch())->toBeFalse();

    $second = $this->approvalService->resubmit($request->fresh(), $actor);

    expect($second->status)->toBe(ApprovalStatus::Pending)
        ->and($request->fresh()->approvals()->count())->toBe(2)
        ->and($request->fresh()->approvals()->where('status', ApprovalStatus::Approved)->count())->toBe(1);
});

it('sets due_at from company sla business days', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-07 10:00:00')); // Friday

    ['request' => $request] = approvalContext();
    $pending = $request->fresh()->currentPendingApproval();

    expect($pending)->not->toBeNull()
        ->and($pending->due_at->toDateString())->toBe('2026-08-11'); // Friday + 2 business days = Tuesday

    Carbon::setTestNow();
});

it('sets approval created_by from route actor even without Auth', function (): void {
    Auth::logout();

    ['request' => $request, 'actor' => $actor] = approvalContext();
    $pending = $request->fresh()->currentPendingApproval();

    expect($pending)->not->toBeNull()
        ->and((string) $pending->created_by)->toBe((string) $actor->getKey());
});

it('keeps approval committed when approved event listener throws', function (): void {
    Event::listen(PaymentRequestApproved::class, function (): void {
        throw new RuntimeException('notification failed');
    });

    ['request' => $request, 'approver' => $approver] = approvalContext();
    $pending = $request->fresh()->currentPendingApproval();

    try {
        $this->approvalService->approve($pending, $approver);
        $this->fail('Expected listener exception');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('notification failed');
    }

    expect($pending->fresh()->status)->toBe(ApprovalStatus::Approved)
        ->and($request->fresh()->hasApprovedForLaunch())->toBeTrue();
});

it('resolves overlapping rules preferring higher min then finite max then newest', function (): void {
    $branch = Branch::factory()->create();
    $branch->company->update(['approval_sla_business_days' => 2]);
    $broadApprover = User::factory()->operador()->approver()->create();
    $narrowApprover = User::factory()->operador()->approver()->create();
    $finiteApprover = User::factory()->operador()->approver()->create();
    $newerApprover = User::factory()->operador()->approver()->create();

    ApprovalRule::factory()->forBranch($branch)->forApprover($broadApprover)->range(0, null)
        ->create(['created_at' => now()->subDays(3)]);
    $narrow = ApprovalRule::factory()->forBranch($branch)->forApprover($narrowApprover)->range(500, null)
        ->create(['created_at' => now()->subDays(2)]);

    $actor = User::factory()->adm()->create();
    $request = $this->paymentRequestService->create(new PaymentRequestData(
        branchId: (string) $branch->getKey(),
        supplierId: (string) Supplier::factory()->create()->getKey(),
        costCenterId: (string) CostCenter::factory()->for($branch)->create()->getKey(),
        appropriationId: null,
        paymentMethod: PaymentMethod::Deposit,
        grossAmount: '1000.00',
        discountAmount: '0.00',
        dueDate: CarbonImmutable::now()->addWeek(),
        bankDetails: new PaymentRequestBankDetailsData(
            depositType: DepositType::Pix,
            pixKeyType: PixKeyType::Email,
            pixKey: 'rule@test.com',
        ),
    ), $actor);

    expect($this->approvalService->resolveRule($request->fresh())->is($narrow))->toBeTrue();

    ApprovalRule::query()->whereKey($narrow->getKey())->delete();
    $finite = ApprovalRule::factory()->forBranch($branch)->forApprover($finiteApprover)->range(0, 5000)
        ->create(['created_at' => now()->subDay()]);
    ApprovalRule::factory()->forBranch($branch)->forApprover($newerApprover)->range(0, null)
        ->create(['created_at' => now()]);

    expect($this->approvalService->resolveRule($request->fresh())->is($finite))->toBeTrue();
});
