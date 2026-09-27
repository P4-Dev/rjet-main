<?php

declare(strict_types=1);

use App\DTOs\PaymentSettlementData;
use App\Enums\CnabFileStatus;
use App\Enums\PaymentRequestStatus;
use App\Enums\PaymentSettlementStatus;
use App\Events\PaymentRequest\PaymentRequestStatusChanged;
use App\Events\Settlement\PaymentSettlementCreated;
use App\Exceptions\PaymentSettlementException;
use App\Models\Branch;
use App\Models\BranchBankAccount;
use App\Models\CnabFile;
use App\Models\PaymentRequest;
use App\Models\PaymentSettlement;
use App\Models\PaymentSettlementItem;
use App\Models\User;
use App\Services\PaymentSettlementService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

function settlementService(): PaymentSettlementService
{
    return app(PaymentSettlementService::class);
}

/**
 * @param  list<PaymentRequest>  $paymentRequests
 */
function settlementDataFor(BranchBankAccount $account, array $paymentRequests): PaymentSettlementData
{
    return new PaymentSettlementData(
        branchId: (string) $account->branch_id,
        branchBankAccountId: (string) $account->getKey(),
        settlementDate: CarbonImmutable::today(PaymentSettlement::TIMEZONE),
        paymentRequestIds: array_map(fn (PaymentRequest $request): string => (string) $request->getKey(), $paymentRequests),
    );
}

function launchedRequestFor(Branch $branch, string $amount = '100.00'): PaymentRequest
{
    return PaymentRequest::factory()->launched()->depositTransfer()->forBranch($branch)
        ->create(['gross_amount' => $amount, 'net_amount' => $amount]);
}

beforeEach(function (): void {
    $this->operador = User::factory()->operador()->create();
    $this->account = BranchBankAccount::factory()->itau()->create();
});

it('creates a draft settlement with amount snapshots and bcmath totals', function (): void {
    Event::fake([PaymentSettlementCreated::class]);
    $first = launchedRequestFor($this->account->branch, '100.10');
    $second = launchedRequestFor($this->account->branch, '200.25');

    $settlement = settlementService()->create(settlementDataFor($this->account, [$first, $second]), $this->operador);

    expect($settlement->status)->toBe(PaymentSettlementStatus::Draft)
        ->and($settlement->items_count)->toBe(2)
        ->and((string) $settlement->total_amount)->toBe('300.35')
        ->and($settlement->created_by)->toBe($this->operador->getKey())
        ->and($settlement->items()->pluck('amount')->map(fn ($amount): string => (string) $amount)->sort()->values()->all())->toBe(['100.10', '200.25']);
    Event::assertDispatched(PaymentSettlementCreated::class, fn (PaymentSettlementCreated $event): bool => $event->settlement->is($settlement));
});

it('rejects invalid selections', function (Closure $makeData, string $expectedMessage): void {
    $data = $makeData($this->account);

    expect(fn () => settlementService()->create($data, $this->operador))
        ->toThrow(PaymentSettlementException::class, $expectedMessage);
    expect(PaymentSettlement::query()->count())->toBe(0);
})->with([
    'empty selection' => [
        fn (BranchBankAccount $account): PaymentSettlementData => settlementDataFor($account, []),
        fn (): string => PaymentSettlementException::emptySelection()->getMessage(),
    ],
    'mixed branches' => [
        fn (BranchBankAccount $account): PaymentSettlementData => settlementDataFor($account, [
            launchedRequestFor($account->branch),
            launchedRequestFor(Branch::factory()->create()),
        ]),
        fn (): string => PaymentSettlementException::mixedBranches()->getMessage(),
    ],
    'account of another branch' => [
        function (BranchBankAccount $account): PaymentSettlementData {
            $data = settlementDataFor($account, [launchedRequestFor($account->branch)]);

            return new PaymentSettlementData(
                $data->branchId,
                (string) BranchBankAccount::factory()->itau()->create()->getKey(),
                $data->settlementDate,
                $data->paymentRequestIds,
            );
        },
        fn (): string => PaymentSettlementException::bankAccountNotAllowed()->getMessage(),
    ],
    'inactive account' => [
        function (BranchBankAccount $account): PaymentSettlementData {
            $account->forceFill(['is_active' => false])->save();

            return settlementDataFor($account, [launchedRequestFor($account->branch)]);
        },
        fn (): string => PaymentSettlementException::bankAccountInactive()->getMessage(),
    ],
    'account without bank' => [
        function (BranchBankAccount $account): PaymentSettlementData {
            $account->forceFill(['bank_id' => null])->save();

            return settlementDataFor($account, [launchedRequestFor($account->branch)]);
        },
        fn (): string => PaymentSettlementException::bankAccountInactive()->getMessage(),
    ],
    'payment request not launched' => [
        fn (BranchBankAccount $account): PaymentSettlementData => settlementDataFor($account, [
            PaymentRequest::factory()->requested()->depositTransfer()->forBranch($account->branch)->create(),
        ]),
        fn (): string => PaymentSettlementException::paymentRequestNotEligible(1)->getMessage(),
    ],
]);

it('rejects selections above the configured item limit', function (): void {
    config(['rjet.cnab.max_items' => 1]);

    expect(fn () => settlementService()->create(settlementDataFor($this->account, [
        launchedRequestFor($this->account->branch),
        launchedRequestFor($this->account->branch),
    ]), $this->operador))->toThrow(PaymentSettlementException::class, PaymentSettlementException::tooManyItems(1)->getMessage());
});

it('rejects a payment request already in a draft settlement', function (): void {
    $paymentRequest = launchedRequestFor($this->account->branch);
    settlementService()->create(settlementDataFor($this->account, [$paymentRequest]), $this->operador);

    expect(fn () => settlementService()->create(settlementDataFor($this->account, [$paymentRequest]), $this->operador))
        ->toThrow(PaymentSettlementException::class, PaymentSettlementException::paymentRequestAlreadyInSettlement()->getMessage());
});

it('confirms a draft settling every payment request with history and events', function (): void {
    Event::fake([PaymentRequestStatusChanged::class]);
    $settlement = PaymentSettlement::factory()->forAccount($this->account)->withItems(2)->create();

    settlementService()->confirm($settlement, $this->operador);

    $paymentRequestIds = $settlement->items()->pluck('payment_request_id');
    expect($settlement->fresh())
        ->status->toBe(PaymentSettlementStatus::Settled)
        ->settled_by->toBe($this->operador->getKey())
        ->settled_at->not->toBeNull()
        ->and(PaymentRequest::query()->whereKey($paymentRequestIds)->pluck('status')->unique()->all())->toBe([PaymentRequestStatus::Settled])
        ->and(DB::table('payment_request_status_history')->whereIn('payment_request_id', $paymentRequestIds)->where('to_status', 'settled')->count())->toBe(2);
    Event::assertDispatchedTimes(PaymentRequestStatusChanged::class, 2);
});

it('refuses to confirm a settlement dated in the future', function (): void {
    $settlement = PaymentSettlement::factory()->forAccount($this->account)
        ->dated(today(PaymentSettlement::TIMEZONE)->addDay()->toDateString())
        ->withItems(1)
        ->create();

    expect(fn () => settlementService()->confirm($settlement, $this->operador))
        ->toThrow(PaymentSettlementException::class, PaymentSettlementException::settlementDateInFuture()->getMessage());
});

it('refuses to confirm while a cnab file is in progress', function (string $state): void {
    $settlement = PaymentSettlement::factory()->forAccount($this->account)->withItems(1)->create();
    CnabFile::factory()->for($settlement, 'settlement')->{$state}()->create();

    expect(fn () => settlementService()->confirm($settlement, $this->operador))
        ->toThrow(PaymentSettlementException::class, PaymentSettlementException::cnabGenerationInProgress()->getMessage());
})->with(['queued', 'generating']);

it('refuses to confirm when an item changed and leaves every payment request untouched', function (Closure $change): void {
    $settlement = PaymentSettlement::factory()->forAccount($this->account)->withItems(2)->create();
    $items = $settlement->items()->with('paymentRequest')->get();
    $change($items->last()->paymentRequest);

    expect(fn () => settlementService()->confirm($settlement, $this->operador))
        ->toThrow(PaymentSettlementException::class, PaymentSettlementException::itemsChangedSinceSelection(1)->getMessage())
        ->and(PaymentRequest::withTrashed()->whereKey($items->pluck('payment_request_id'))->pluck('status')->unique()->all())->toBe([PaymentRequestStatus::Launched])
        ->and($settlement->fresh()->status)->toBe(PaymentSettlementStatus::Draft);
})->with([
    'trashed' => [fn (PaymentRequest $request) => $request->delete()],
    'amount changed' => [fn (PaymentRequest $request) => $request->forceFill(['net_amount' => bcadd((string) $request->net_amount, '1.00', 2)])->saveQuietly()],
]);

it('cancels releasing items, superseding the generated file and making requests eligible again', function (): void {
    $settlement = PaymentSettlement::factory()->forAccount($this->account)->withItems(2)->create();
    $file = CnabFile::factory()->for($settlement, 'settlement')->generated()->create();

    settlementService()->cancel($settlement, $this->operador, 'Wrong account');

    expect($settlement->fresh())
        ->status->toBe(PaymentSettlementStatus::Cancelled)
        ->cancellation_reason->toBe('Wrong account')
        ->and($settlement->items()->whereNull('released_at')->count())->toBe(0)
        ->and($file->fresh()->status)->toBe(CnabFileStatus::Superseded)
        ->and(PaymentRequest::query()->eligibleForSettlement()->whereKey($settlement->items()->pluck('payment_request_id'))->count())->toBe(2);
});

it('refuses to cancel while a cnab file is in progress', function (): void {
    $settlement = PaymentSettlement::factory()->forAccount($this->account)->withItems(1)->create();
    CnabFile::factory()->for($settlement, 'settlement')->generating()->create();

    expect(fn () => settlementService()->cancel($settlement, $this->operador, 'Reason'))
        ->toThrow(PaymentSettlementException::class, PaymentSettlementException::cnabGenerationInProgress()->getMessage());
});

it('releases an item and recalculates totals', function (): void {
    $settlement = PaymentSettlement::factory()->forAccount($this->account)->create();
    $kept = PaymentSettlementItem::factory()->for($settlement, 'settlement')->forPaymentRequest(launchedRequestFor($this->account->branch, '40.05'))->create();
    $released = PaymentSettlementItem::factory()->for($settlement, 'settlement')->forPaymentRequest(launchedRequestFor($this->account->branch, '59.95'))->create();
    settlementService()->recalculateTotals($settlement);

    settlementService()->releaseItem($released, $this->operador);

    expect($settlement->fresh())
        ->items_count->toBe(1)
        ->and((string) $settlement->fresh()->total_amount)->toBe('40.05')
        ->and($released->fresh()->released_by)->toBe($this->operador->getKey())
        ->and($kept->fresh()->isActive())->toBeTrue();
});

it('refuses to delete a settlement unless adm deletes a cancelled one', function (string $role, string $state, string $expectedMessage): void {
    $settlement = PaymentSettlement::factory()->forAccount($this->account)->{$state}()->create();

    expect(fn () => settlementService()->delete($settlement, User::factory()->{$role}()->create()))
        ->toThrow(PaymentSettlementException::class, $expectedMessage)
        ->and($settlement->fresh()->trashed())->toBeFalse();
})->with([
    'adm deleting a draft' => ['adm', 'draft', fn (): string => PaymentSettlementException::cannotDeleteActive()->getMessage()],
    'adm deleting a settled one' => ['adm', 'settled', fn (): string => PaymentSettlementException::cannotDeleteActive()->getMessage()],
    'operador deleting a cancelled one' => ['operador', 'cancelled', fn (): string => PaymentSettlementException::unauthorized()->getMessage()],
]);

it('refuses to release an item while a cnab file is active', function (string $state, string $expectedMessage): void {
    $settlement = PaymentSettlement::factory()->forAccount($this->account)->withItems(2)->create();
    CnabFile::factory()->for($settlement, 'settlement')->{$state}()->create();

    expect(fn () => settlementService()->releaseItem($settlement->items()->first(), $this->operador))
        ->toThrow(PaymentSettlementException::class, $expectedMessage);
})->with([
    'queued' => ['queued', fn (): string => PaymentSettlementException::cnabGenerationInProgress()->getMessage()],
    'generated' => ['generated', fn (): string => PaymentSettlementException::cnabFileAlreadyGenerated()->getMessage()],
]);
