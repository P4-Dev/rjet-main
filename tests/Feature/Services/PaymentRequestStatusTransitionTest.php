<?php

declare(strict_types=1);

use App\DTOs\PaymentRequestBankDetailsData;
use App\DTOs\PaymentRequestData;
use App\Enums\DepositType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRequestStatus;
use App\Enums\PixKeyType;
use App\Events\PaymentRequest\PaymentRequestStatusChanged;
use App\Exceptions\PaymentRequestException;
use App\Models\Approval;
use App\Models\ApprovalRule;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\PaymentRequestService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->service = app(PaymentRequestService::class);
    $this->actor = User::factory()->adm()->approver()->create();

    $branch = Branch::factory()->create();
    $branch->company->update(['approval_sla_business_days' => 2]);
    $approver = User::factory()->operador()->approver()->create();
    ApprovalRule::factory()->forBranch($branch)->forApprover($approver)->range(0, null)->create();

    $this->request = $this->service->create(new PaymentRequestData(
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
            pixKey: 'a@b.com',
        ),
    ), $this->actor);

    $pending = $this->request->fresh()->currentPendingApproval();
    app(ApprovalService::class)->approve($pending, $approver);
    $this->request = $this->request->fresh();
});

it('transitions requested to launched and records history', function (): void {
    Event::fake([PaymentRequestStatusChanged::class]);

    $updated = $this->service->transitionStatus(
        $this->request,
        PaymentRequestStatus::Launched,
        $this->actor,
        'ok',
    );

    expect($updated->status)->toBe(PaymentRequestStatus::Launched)
        ->and($updated->statusHistories()->count())->toBe(2);

    Event::assertDispatched(PaymentRequestStatusChanged::class);
});

it('transitions launched to settled', function (): void {
    $this->service->transitionStatus($this->request, PaymentRequestStatus::Launched, $this->actor);
    $settled = $this->service->transitionStatus($this->request->fresh(), PaymentRequestStatus::Settled, $this->actor);

    expect($settled->status)->toBe(PaymentRequestStatus::Settled);
});

it('rejects invalid transitions', function (PaymentRequestStatus $to): void {
    expect(fn () => $this->service->transitionStatus($this->request, $to, $this->actor))
        ->toThrow(PaymentRequestException::class);
})->with([
    'skip to settled' => [PaymentRequestStatus::Settled],
]);

it('rejects regression from settled', function (): void {
    $this->service->transitionStatus($this->request, PaymentRequestStatus::Launched, $this->actor);
    $settled = $this->service->transitionStatus($this->request->fresh(), PaymentRequestStatus::Settled, $this->actor);

    expect(fn () => $this->service->transitionStatus($settled, PaymentRequestStatus::Launched, $this->actor))
        ->toThrow(PaymentRequestException::class);
});

it('rejects status transitions by cliente even when the enum allows it', function (): void {
    $cliente = User::factory()->cliente()->withBranches([$this->request->branch])->create();

    expect(fn () => $this->service->transitionStatus(
        $this->request,
        PaymentRequestStatus::Launched,
        $cliente,
    ))->toThrow(PaymentRequestException::class);

    expect($this->request->fresh()->status)->toBe(PaymentRequestStatus::Requested);
});
