<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ApprovalRuleData;
use App\Enums\UserRole;
use App\Exceptions\ApprovalRuleException;
use App\Models\ApprovalRule;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class ApprovalRuleService
{
    public function create(ApprovalRuleData $data, User $actor): ApprovalRule
    {
        $this->assertBranchExists($data->branchId);
        $this->assertRange($data->minAmount, $data->maxAmount);
        $this->assertApproverEligible(User::query()->findOrFail($data->approverUserId));

        /** @var ApprovalRule $rule */
        $rule = ApprovalRule::query()->create($data->toArray());

        return $rule->fresh(['branch.company', 'approver']) ?? $rule;
    }

    public function update(ApprovalRule $rule, ApprovalRuleData $data, User $actor): ApprovalRule
    {
        $this->assertBranchExists($data->branchId);
        $this->assertRange($data->minAmount, $data->maxAmount);
        $this->assertApproverEligible(User::query()->findOrFail($data->approverUserId));

        $rule->update($data->toArray());

        return $rule->fresh(['branch.company', 'approver']) ?? $rule;
    }

    public function delete(ApprovalRule $rule): void
    {
        $rule->delete();
    }

    public function activate(ApprovalRule $rule): ApprovalRule
    {
        $rule->update(['is_active' => true]);

        return $rule->fresh() ?? $rule;
    }

    public function deactivate(ApprovalRule $rule): ApprovalRule
    {
        $rule->update(['is_active' => false]);

        return $rule->fresh() ?? $rule;
    }

    /**
     * @throws ApprovalRuleException
     */
    public function assertApproverEligible(User $approver): void
    {
        if (
            ! $approver->is_active
            || ! $approver->canApprove()
            || ! in_array($approver->role, [UserRole::Operador, UserRole::Adm], true)
        ) {
            throw ApprovalRuleException::approverNotEligible();
        }
    }

    /**
     * @throws ApprovalRuleException
     */
    public function assertRange(string $minAmount, ?string $maxAmount): void
    {
        if (bccomp($minAmount, '0', 2) < 0) {
            throw ApprovalRuleException::invalidAmountRange();
        }

        if ($maxAmount !== null && bccomp($maxAmount, $minAmount, 2) < 0) {
            throw ApprovalRuleException::invalidAmountRange();
        }
    }

    /**
     * @return Collection<int, ApprovalRule>
     */
    public function findOverlapping(
        Branch|string $branch,
        string $minAmount,
        ?string $maxAmount,
        ?string $excludeId = null,
    ): Collection {
        $branchId = $branch instanceof Branch ? (string) $branch->getKey() : $branch;

        return ApprovalRule::query()
            ->active()
            ->forBranch($branchId)
            ->when($excludeId, fn (Builder $q): Builder => $q->whereKeyNot($excludeId))
            ->get()
            ->filter(function (ApprovalRule $rule) use ($minAmount, $maxAmount): bool {
                $ruleMin = (string) $rule->min_amount;
                $ruleMax = $rule->max_amount !== null ? (string) $rule->max_amount : null;

                $newMax = $maxAmount;
                $overlaps = bccomp($ruleMin, $newMax ?? '9999999999.99', 2) <= 0
                    && bccomp($minAmount, $ruleMax ?? '9999999999.99', 2) <= 0;

                return $overlaps;
            })
            ->values();
    }

    /**
     * @throws ApprovalRuleException
     */
    private function assertBranchExists(string $branchId): void
    {
        if (! Branch::query()->whereKey($branchId)->exists()) {
            throw ApprovalRuleException::branchRequired();
        }
    }
}
