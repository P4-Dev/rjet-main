<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\UserException;
use App\Models\Approval;
use App\Models\ApprovalRule;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class UserService
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $branchIds
     */
    public function create(array $attributes, array $branchIds = [], ?string $defaultBranchId = null): User
    {
        return DB::transaction(function () use ($attributes, $branchIds, $defaultBranchId): User {
            $user = User::create($attributes);

            $this->syncBranches($user, $branchIds, $defaultBranchId);

            return $user->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $branchIds
     */
    public function update(User $user, array $attributes, array $branchIds = [], ?string $defaultBranchId = null): User
    {
        return DB::transaction(function () use ($user, $attributes, $branchIds, $defaultBranchId): User {
            $user->update($attributes);

            $this->syncBranches($user, $branchIds, $defaultBranchId);

            return $user->refresh();
        });
    }

    public function deactivate(User $user): User
    {
        $this->guardAgainstSelf($user);
        $this->guardAgainstLastActiveAdmin($user);

        $user->update(['is_active' => false]);

        app(ApprovalService::class)->reassignFromInactiveApprover(
            $user,
            Auth::user() ?? $user,
        );

        return $user;
    }

    public function delete(User $user): void
    {
        $this->guardAgainstSelf($user);
        $this->guardAgainstLastActiveAdmin($user);
        $this->guardAgainstPendingApprovalsOrRules($user);

        $user->delete();
    }

    /**
     * @throws UserException
     */
    public function forceDelete(User $user): void
    {
        $this->guardAgainstSelf($user);
        $this->guardAgainstLastActiveAdmin($user);
        $this->guardAgainstPendingApprovalsOrRules($user);

        $user->forceDelete();
    }

    /**
     * Sincroniza as filiais do usuário gerando o UUID do pivot manualmente
     * (attach/sync não populam a PK uuid do pivot) e garantindo no máximo uma
     * filial padrão (respeita o índice único parcial de branch_user).
     *
     * @param  array<int, string>  $branchIds
     */
    public function syncBranches(User $user, array $branchIds, ?string $defaultBranchId = null): void
    {
        $branchIds = array_values(array_unique(array_filter($branchIds)));

        DB::transaction(function () use ($user, $branchIds, $defaultBranchId): void {
            /** @var array<int, string> $current */
            $current = $user->branches()->pluck('branches.id')->all();

            $toDetach = array_diff($current, $branchIds);
            $toAttach = array_diff($branchIds, $current);

            if ($toDetach !== []) {
                $user->branches()->detach($toDetach);
            }

            foreach ($toAttach as $branchId) {
                $user->branches()->attach($branchId, [
                    'id' => (string) Str::orderedUuid(),
                    'is_default' => false,
                ]);
            }

            $user->branches()->newPivotStatement()
                ->where('user_id', $user->getKey())
                ->update(['is_default' => false]);

            if ($defaultBranchId !== null && in_array($defaultBranchId, $branchIds, true)) {
                $user->branches()->updateExistingPivot($defaultBranchId, ['is_default' => true]);
            }
        });
    }

    private function guardAgainstSelf(User $user): void
    {
        if (Auth::check() && Auth::user()?->is($user)) {
            throw UserException::cannotDeleteSelf();
        }
    }

    private function guardAgainstLastActiveAdmin(User $user): void
    {
        if ($user->isLastActiveAdmin()) {
            throw UserException::cannotDeleteLastActiveAdmin();
        }
    }

    private function guardAgainstPendingApprovalsOrRules(User $user): void
    {
        $hasPending = Approval::query()
            ->pending()
            ->where('approver_user_id', $user->getKey())
            ->exists();

        $hasRules = ApprovalRule::query()
            ->where('approver_user_id', $user->getKey())
            ->exists();

        if ($hasPending || $hasRules) {
            throw UserException::cannotDeleteWithPendingApprovals();
        }
    }
}
