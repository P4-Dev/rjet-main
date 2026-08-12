<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ApprovalStatus;
use App\Enums\PaymentRequestStatus;
use App\Enums\UserRole;
use App\Events\Approval\ApprovalAssigned;
use App\Events\Approval\ApprovalReassigned;
use App\Events\Approval\ApprovalSlaBreached;
use App\Events\PaymentRequest\PaymentRequestApproved;
use App\Events\PaymentRequest\PaymentRequestRejected;
use App\Exceptions\ApprovalException;
use App\Models\Approval;
use App\Models\ApprovalReassignment;
use App\Models\ApprovalRule;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Support\BusinessDays;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

final class ApprovalService
{
    public function resolveRule(PaymentRequest $paymentRequest): ApprovalRule
    {
        $netAmount = (string) $paymentRequest->net_amount;

        $driver = DB::connection()->getDriverName();
        $nullsLast = $driver === 'pgsql'
            ? 'max_amount ASC NULLS LAST'
            : 'CASE WHEN max_amount IS NULL THEN 1 ELSE 0 END ASC, max_amount ASC';

        /** @var ApprovalRule|null $rule */
        $rule = ApprovalRule::query()
            ->active()
            ->forBranch((string) $paymentRequest->branch_id)
            ->where('min_amount', '<=', $netAmount)
            ->where(function (Builder $query) use ($netAmount): void {
                $query->whereNull('max_amount')
                    ->orWhere('max_amount', '>=', $netAmount);
            })
            ->orderByDesc('min_amount')
            ->orderByRaw($nullsLast)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($rule === null) {
            throw ApprovalException::noMatchingRule();
        }

        return $rule;
    }

    /**
     * @throws ApprovalException
     */
    public function route(PaymentRequest $paymentRequest, User $actor): Approval
    {
        if ($paymentRequest->currentPendingApproval() !== null) {
            throw ApprovalException::alreadyPending();
        }

        $rule = $this->resolveRule($paymentRequest);

        $paymentRequest->loadMissing('branch.company');
        $days = (int) ($paymentRequest->branch?->company?->approval_sla_business_days ?? 0);

        if ($days <= 0) {
            throw ApprovalException::slaNotConfigured();
        }

        $assignedAt = now();
        $dueAt = BusinessDays::add($assignedAt, $days);
        $fingerprint = $paymentRequest->currentMaterialFingerprint();

        $approval = DB::transaction(function () use ($paymentRequest, $rule, $assignedAt, $dueAt, $fingerprint, $actor): Approval {
            $approval = Approval::query()->create([
                'payment_request_id' => $paymentRequest->getKey(),
                'approval_rule_id' => $rule->getKey(),
                'approver_user_id' => $rule->approver_user_id,
                'status' => ApprovalStatus::Pending,
                'reason' => null,
                'amount_snapshot' => $paymentRequest->net_amount,
                'branch_id_snapshot' => $paymentRequest->branch_id,
                'supplier_id_snapshot' => $paymentRequest->supplier_id,
                'material_fingerprint' => $fingerprint,
                'assigned_at' => $assignedAt,
                'due_at' => $dueAt,
                'decided_at' => null,
                'decided_by' => null,
                'escalated_at' => null,
            ]);

            // created_by/updated_by are guarded; BlameableObserver only fills when Auth is present.
            if ($approval->created_by === null) {
                $approval->forceFill([
                    'created_by' => $actor->getKey(),
                    'updated_by' => $actor->getKey(),
                ])->saveQuietly();
            }

            return $approval;
        });

        Event::dispatch(new ApprovalAssigned($approval));

        return $approval->fresh(['paymentRequest', 'approver', 'approvalRule']) ?? $approval;
    }

    /**
     * @throws ApprovalException
     */
    public function approve(Approval $approval, User $actor, ?string $notes = null): Approval
    {
        $this->assertPending($approval);
        $this->assertAuthorizedApprover($approval, $actor);

        $paymentRequest = $approval->paymentRequest;
        if ($paymentRequest === null || ! $paymentRequest->isVisibleTo($actor)) {
            throw ApprovalException::unauthorizedApprover();
        }

        $approval = DB::transaction(function () use ($approval, $actor, $notes): Approval {
            $approval->update([
                'status' => ApprovalStatus::Approved,
                'reason' => $notes,
                'decided_at' => now(),
                'decided_by' => $actor->getKey(),
            ]);

            return $approval->fresh(['paymentRequest', 'approver', 'decidedBy']) ?? $approval;
        });

        Event::dispatch(new PaymentRequestApproved(
            $approval->paymentRequest ?? $paymentRequest,
            $approval,
            $actor,
        ));

        return $approval;
    }

    /**
     * @throws ApprovalException
     */
    public function reject(Approval $approval, User $actor, string $reason): Approval
    {
        $this->assertPending($approval);
        $this->assertAuthorizedApprover($approval, $actor);

        $paymentRequest = $approval->paymentRequest;
        if ($paymentRequest === null || ! $paymentRequest->isVisibleTo($actor)) {
            throw ApprovalException::unauthorizedApprover();
        }

        if (mb_strlen(trim($reason)) < 5) {
            throw ApprovalException::reasonRequired();
        }

        $approval = DB::transaction(function () use ($approval, $actor, $reason): Approval {
            $approval->update([
                'status' => ApprovalStatus::Rejected,
                'reason' => trim($reason),
                'decided_at' => now(),
                'decided_by' => $actor->getKey(),
            ]);

            return $approval->fresh(['paymentRequest', 'approver', 'decidedBy']) ?? $approval;
        });

        Event::dispatch(new PaymentRequestRejected(
            $approval->paymentRequest ?? $paymentRequest,
            $approval,
            $actor,
        ));

        return $approval;
    }

    /**
     * @throws ApprovalException
     */
    public function resubmit(PaymentRequest $paymentRequest, User $actor): Approval
    {
        if ($paymentRequest->status !== PaymentRequestStatus::Requested) {
            throw ApprovalException::cannotResubmit();
        }

        if ($paymentRequest->currentPendingApproval() !== null) {
            throw ApprovalException::alreadyPending();
        }

        $latest = $paymentRequest->latestApproval();
        $canResubmit = $paymentRequest->isReturnedToRequester()
            || ($latest?->status === ApprovalStatus::Approved && ! $paymentRequest->hasApprovedForLaunch())
            || $latest === null
            || ($latest->status === ApprovalStatus::Rejected);

        if (! $canResubmit && $paymentRequest->hasApprovedForLaunch()) {
            throw ApprovalException::cannotResubmit();
        }

        if (! $canResubmit) {
            throw ApprovalException::cannotResubmit();
        }

        return $this->route($paymentRequest, $actor);
    }

    public function escalateOverdue(bool $dryRun = false): int
    {
        $count = 0;

        Approval::query()
            ->overdue()
            ->with(['paymentRequest.branch.company', 'approver'])
            ->limit(500)
            ->get()
            ->each(function (Approval $approval) use ($dryRun, &$count): void {
                if ($dryRun) {
                    $count++;

                    return;
                }

                $this->escalate($approval);
                $count++;
            });

        return $count;
    }

    public function escalate(Approval $approval): void
    {
        if (! $approval->isPending() || $approval->escalated_at !== null) {
            return;
        }

        $approval->update(['escalated_at' => now()]);

        Event::dispatch(new ApprovalSlaBreached($approval->fresh() ?? $approval));
    }

    /**
     * @throws ApprovalException
     */
    public function reassign(Approval $approval, User $to, User $actor, ?string $reason = null): Approval
    {
        $this->assertPending($approval);

        if (! $to->is_active || (! $to->isAdm() && ! $to->canApprove())) {
            throw ApprovalException::unauthorizedApprover();
        }

        $fromId = (string) $approval->approver_user_id;

        $result = DB::transaction(function () use ($approval, $to, $actor, $reason, $fromId): array {
            $approval->update(['approver_user_id' => $to->getKey()]);

            $reassignment = ApprovalReassignment::query()->create([
                'approval_id' => $approval->getKey(),
                'from_approver_user_id' => $fromId,
                'to_approver_user_id' => $to->getKey(),
                'reason' => $reason,
                'created_by' => $actor->getKey(),
                'created_at' => now(),
            ]);

            return [
                'approval' => $approval->fresh(['approver', 'paymentRequest', 'reassignments']) ?? $approval,
                'reassignment' => $reassignment,
            ];
        });

        /** @var Approval $freshApproval */
        $freshApproval = $result['approval'];
        /** @var ApprovalReassignment $reassignment */
        $reassignment = $result['reassignment'];

        Event::dispatch(new ApprovalReassigned($freshApproval, $reassignment));

        return $freshApproval;
    }

    public function reassignFromInactiveApprover(User $inactive, User $actor): int
    {
        /** @var User|null $to */
        $to = User::query()
            ->where('role', UserRole::Adm)
            ->active()
            ->orderBy('created_at')
            ->first();

        if ($to === null) {
            Log::warning('No active Adm available to reassign approvals from inactive approver.', [
                'inactive_user_id' => $inactive->getKey(),
            ]);

            return 0;
        }

        $count = 0;

        Approval::query()
            ->pending()
            ->where('approver_user_id', $inactive->getKey())
            ->whereHas('paymentRequest')
            ->get()
            ->each(function (Approval $approval) use ($to, $actor, &$count): void {
                $this->reassign($approval, $to, $actor, 'approver_inactive');
                $count++;
            });

        return $count;
    }

    /**
     * @throws ApprovalException
     */
    private function assertPending(Approval $approval): void
    {
        if (! $approval->isPending()) {
            throw ApprovalException::notPending();
        }
    }

    /**
     * @throws ApprovalException
     */
    private function assertAuthorizedApprover(Approval $approval, User $actor): void
    {
        if (! $actor->canApprove()) {
            throw ApprovalException::unauthorizedApprover();
        }

        if ($actor->isAdm()) {
            return;
        }

        if ((string) $approval->approver_user_id !== (string) $actor->getKey()) {
            throw ApprovalException::unauthorizedApprover();
        }
    }
}
