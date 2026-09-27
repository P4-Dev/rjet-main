<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\EligiblePaymentFilterData;
use App\DTOs\PaymentSettlementData;
use App\Enums\PaymentRequestStatus;
use App\Enums\PaymentSettlementStatus;
use App\Events\Settlement\PaymentSettlementCancelled;
use App\Events\Settlement\PaymentSettlementConfirmed;
use App\Events\Settlement\PaymentSettlementCreated;
use App\Exceptions\PaymentRequestException;
use App\Exceptions\PaymentSettlementException;
use App\Models\BranchBankAccount;
use App\Models\PaymentRequest;
use App\Models\PaymentSettlement;
use App\Models\PaymentSettlementItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

final class PaymentSettlementService
{
    private const MAX_TOTAL_AMOUNT = '99999999.99';

    public function __construct(
        private readonly PaymentRequestService $paymentRequestService,
        private readonly CnabFileService $cnabFileService,
    ) {}

    /**
     * @return Builder<PaymentRequest>
     */
    public function eligibleQuery(EligiblePaymentFilterData $filter, User $user): Builder
    {
        return PaymentRequest::query()
            ->visibleTo($user)
            ->eligibleForSettlement()
            ->when($filter->branchId, fn (Builder $query, string $branchId): Builder => $query->forBranch($branchId))
            ->dueBetween($filter->dueFrom?->toDateString(), $filter->dueUntil?->toDateString())
            ->when($filter->paymentMethod, fn (Builder $query, $method): Builder => $query->where('payment_method', $method))
            ->with(['supplier', 'bankDetails.bank', 'branch']);
    }

    /**
     * @throws PaymentSettlementException
     */
    public function create(PaymentSettlementData $data, User $actor): PaymentSettlement
    {
        $this->assertOperator($actor);

        $paymentRequestIds = array_values(array_unique($data->paymentRequestIds));

        if ($paymentRequestIds === []) {
            throw PaymentSettlementException::emptySelection();
        }

        $maxItems = (int) config('rjet.cnab.max_items');

        if (count($paymentRequestIds) > $maxItems) {
            throw PaymentSettlementException::tooManyItems($maxItems);
        }

        try {
            $settlement = DB::transaction(function () use ($data, $actor, $paymentRequestIds): PaymentSettlement {
                /** @var Collection<int, PaymentRequest> $paymentRequests */
                $paymentRequests = PaymentRequest::query()
                    ->visibleTo($actor)
                    ->whereKey($paymentRequestIds)
                    ->orderBy('due_date')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $this->assertSelectable($paymentRequests, $paymentRequestIds, $data->branchId);
                $this->assertPayingAccount($data->branchBankAccountId, $data->branchId);

                $settlement = new PaymentSettlement([
                    'branch_id' => $data->branchId,
                    'branch_bank_account_id' => $data->branchBankAccountId,
                    'status' => PaymentSettlementStatus::Draft,
                    'settlement_date' => $data->settlementDate->toDateString(),
                    'notes' => $data->notes,
                ]);
                $settlement->forceFill(['created_by' => $actor->getKey(), 'updated_by' => $actor->getKey()]);
                $settlement->save();

                $totalAmount = '0.00';

                foreach ($paymentRequests as $paymentRequest) {
                    $settlement->items()->create([
                        'payment_request_id' => $paymentRequest->getKey(),
                        'amount' => (string) $paymentRequest->net_amount,
                    ]);

                    $totalAmount = bcadd($totalAmount, (string) $paymentRequest->net_amount, 2);
                }

                $this->assertTotalFits($totalAmount);

                $settlement->forceFill([
                    'items_count' => $paymentRequests->count(),
                    'total_amount' => $totalAmount,
                ])->save();

                return $settlement;
            });
        } catch (UniqueConstraintViolationException) {
            throw PaymentSettlementException::paymentRequestAlreadyInSettlement();
        }

        Event::dispatch(new PaymentSettlementCreated($settlement));

        return $settlement;
    }

    /**
     * @throws PaymentSettlementException
     */
    public function releaseItem(PaymentSettlementItem $item, User $actor): void
    {
        $this->assertOperator($actor);

        DB::transaction(function () use ($item, $actor): void {
            $settlement = $this->lockSettlement((string) $item->payment_settlement_id);

            if (! $settlement->isDraft()) {
                throw PaymentSettlementException::notDraft($settlement->status->value);
            }

            if ($settlement->hasActiveCnabGeneration()) {
                throw PaymentSettlementException::cnabGenerationInProgress();
            }

            if ($settlement->hasGeneratedCnabFile()) {
                throw PaymentSettlementException::cnabFileAlreadyGenerated();
            }

            $item->refresh();

            if (! $item->isActive()) {
                return;
            }

            $item->forceFill([
                'released_at' => now(),
                'released_by' => $actor->getKey(),
            ])->save();

            $this->recalculateTotals($settlement);
        });
    }

    /**
     * @throws PaymentSettlementException
     * @throws PaymentRequestException
     */
    public function confirm(PaymentSettlement $settlement, User $actor): PaymentSettlement
    {
        $this->assertOperator($actor);

        $confirmed = DB::transaction(function () use ($settlement, $actor): PaymentSettlement {
            $locked = $this->lockSettlement((string) $settlement->getKey());

            if (! $locked->status->canTransitionTo(PaymentSettlementStatus::Settled)) {
                throw PaymentSettlementException::notDraft($locked->status->value);
            }

            if ($locked->isSettlementDateFuture()) {
                throw PaymentSettlementException::settlementDateInFuture();
            }

            if ($locked->hasActiveCnabGeneration()) {
                throw PaymentSettlementException::cnabGenerationInProgress();
            }

            $items = $locked->activeItems()->get();

            if ($items->isEmpty()) {
                throw PaymentSettlementException::emptySelection();
            }

            /** @var Collection<string, PaymentRequest> $paymentRequests */
            $paymentRequests = PaymentRequest::withTrashed()
                ->whereKey($items->pluck('payment_request_id')->all())
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (PaymentRequest $request): string => (string) $request->getKey());

            $changedCount = $items
                ->filter(fn (PaymentSettlementItem $item): bool => $this->hasChanged($item, $paymentRequests->get((string) $item->payment_request_id)))
                ->count();

            if ($changedCount > 0) {
                throw PaymentSettlementException::itemsChangedSinceSelection($changedCount);
            }

            $historyNote = __('payment_settlements.messages.history_note', [
                'date' => $locked->settlement_date->format('d/m/Y'),
                'id' => substr((string) $locked->getKey(), -8),
            ]);

            foreach ($items as $item) {
                /** @var PaymentRequest $paymentRequest */
                $paymentRequest = $paymentRequests->get((string) $item->payment_request_id);

                $this->paymentRequestService->transitionStatus(
                    $paymentRequest,
                    PaymentRequestStatus::Settled,
                    $actor,
                    notes: $historyNote,
                    settlement: $locked,
                );
            }

            $locked->forceFill([
                'status' => PaymentSettlementStatus::Settled,
                'settled_at' => now(),
                'settled_by' => $actor->getKey(),
            ])->save();

            return $locked;
        });

        Event::dispatch(new PaymentSettlementConfirmed($confirmed));

        return $confirmed;
    }

    /**
     * @throws PaymentSettlementException
     */
    public function cancel(PaymentSettlement $settlement, User $actor, string $reason): PaymentSettlement
    {
        $this->assertOperator($actor);

        $cancelled = DB::transaction(function () use ($settlement, $actor, $reason): PaymentSettlement {
            $locked = $this->lockSettlement((string) $settlement->getKey());

            if (! $locked->status->canTransitionTo(PaymentSettlementStatus::Cancelled)) {
                throw PaymentSettlementException::notDraft($locked->status->value);
            }

            if ($locked->hasActiveCnabGeneration()) {
                throw PaymentSettlementException::cnabGenerationInProgress();
            }

            $locked->activeItems()->get()->each(fn (PaymentSettlementItem $item): bool => $item->forceFill([
                'released_at' => now(),
                'released_by' => $actor->getKey(),
            ])->save());

            $this->cnabFileService->supersedeForCancellation($locked, $actor);

            $locked->forceFill([
                'status' => PaymentSettlementStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->getKey(),
                'cancellation_reason' => $reason,
            ])->save();

            return $locked;
        });

        Event::dispatch(new PaymentSettlementCancelled($cancelled));

        return $cancelled;
    }

    /**
     * @throws PaymentSettlementException
     */
    public function recalculateTotals(PaymentSettlement $settlement): void
    {
        $amounts = $settlement->activeItems()->pluck('amount');

        $totalAmount = $amounts->reduce(
            fn (string $carry, mixed $amount): string => bcadd($carry, (string) $amount, 2),
            '0.00',
        );

        $this->assertTotalFits($totalAmount);

        $settlement->forceFill([
            'items_count' => $amounts->count(),
            'total_amount' => $totalAmount,
        ])->save();
    }

    /**
     * @throws PaymentSettlementException
     */
    public function delete(PaymentSettlement $settlement, User $actor): void
    {
        $this->assertDeletable($settlement, $actor);

        $settlement->delete();
    }

    /**
     * Order matters (restrict FKs): files first (their items cascade, the observer removes the .rem
     * after commit), then the settlement (its items cascade).
     *
     * @throws PaymentSettlementException
     */
    public function forceDelete(PaymentSettlement $settlement, User $actor): void
    {
        $this->assertDeletable($settlement, $actor);

        DB::transaction(function () use ($settlement): void {
            $settlement->cnabFiles()->withTrashed()->get()->each->forceDelete();
            $settlement->forceDelete();
        });
    }

    /**
     * @param  Collection<int, PaymentRequest>  $paymentRequests
     * @param  list<string>  $requestedIds
     *
     * @throws PaymentSettlementException
     */
    private function assertSelectable(Collection $paymentRequests, array $requestedIds, string $branchId): void
    {
        $notEligibleCount = (count($requestedIds) - $paymentRequests->count())
            + $paymentRequests->filter(fn (PaymentRequest $request): bool => $request->status !== PaymentRequestStatus::Launched)->count();

        if ($notEligibleCount > 0) {
            throw PaymentSettlementException::paymentRequestNotEligible($notEligibleCount);
        }

        $alreadyInSettlement = PaymentSettlementItem::query()
            ->active()
            ->whereIn('payment_request_id', $requestedIds)
            ->exists();

        if ($alreadyInSettlement) {
            throw PaymentSettlementException::paymentRequestAlreadyInSettlement();
        }

        $branchIds = $paymentRequests->pluck('branch_id')->map(fn ($id): string => (string) $id)->unique();

        if ($branchIds->count() !== 1 || $branchIds->first() !== $branchId) {
            throw PaymentSettlementException::mixedBranches();
        }
    }

    /**
     * @throws PaymentSettlementException
     */
    private function assertPayingAccount(string $accountId, string $branchId): void
    {
        $account = BranchBankAccount::query()->find($accountId);

        if ($account === null || ! $account->is_active || $account->bank_id === null) {
            throw PaymentSettlementException::bankAccountInactive();
        }

        if ((string) $account->branch_id !== $branchId) {
            throw PaymentSettlementException::bankAccountNotAllowed();
        }
    }

    /**
     * @throws PaymentSettlementException
     */
    private function assertTotalFits(string $totalAmount): void
    {
        if (bccomp($totalAmount, self::MAX_TOTAL_AMOUNT, 2) > 0) {
            throw PaymentSettlementException::totalAmountOverflow();
        }
    }

    /**
     * @throws PaymentSettlementException
     */
    private function assertDeletable(PaymentSettlement $settlement, User $actor): void
    {
        if (! $actor->isAdm()) {
            throw PaymentSettlementException::unauthorized();
        }

        if ($settlement->status !== PaymentSettlementStatus::Cancelled) {
            throw PaymentSettlementException::cannotDeleteActive();
        }
    }

    /**
     * @throws PaymentSettlementException
     */
    private function assertOperator(User $actor): void
    {
        if (! ($actor->isOperador() || $actor->isAdm())) {
            throw PaymentSettlementException::unauthorized();
        }
    }

    private function hasChanged(PaymentSettlementItem $item, ?PaymentRequest $paymentRequest): bool
    {
        return $paymentRequest === null
            || $paymentRequest->trashed()
            || $paymentRequest->status !== PaymentRequestStatus::Launched
            || bccomp((string) $paymentRequest->net_amount, (string) $item->amount, 2) !== 0;
    }

    private function lockSettlement(string $settlementId): PaymentSettlement
    {
        /** @var PaymentSettlement $settlement */
        $settlement = PaymentSettlement::query()
            ->whereKey($settlementId)
            ->lockForUpdate()
            ->firstOrFail();

        return $settlement;
    }
}
