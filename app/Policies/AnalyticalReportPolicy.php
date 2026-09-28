<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AnalyticalReport;
use App\Models\User;

final class AnalyticalReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isStaff($user);
    }

    public function view(User $user, AnalyticalReport $analyticalReport): bool
    {
        if (! $this->isStaff($user)) {
            return false;
        }

        return ! $analyticalReport->trashed() || $user->isAdm();
    }

    public function create(User $user): bool
    {
        return $this->isStaff($user);
    }

    public function update(User $user, AnalyticalReport $analyticalReport): bool
    {
        return false;
    }

    public function delete(User $user, AnalyticalReport $analyticalReport): bool
    {
        return $user->isAdm();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdm();
    }

    public function restore(User $user, AnalyticalReport $analyticalReport): bool
    {
        return $user->isAdm();
    }

    public function restoreAny(User $user): bool
    {
        return $user->isAdm();
    }

    public function forceDelete(User $user, AnalyticalReport $analyticalReport): bool
    {
        return $user->isAdm();
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->isAdm();
    }

    public function download(User $user, AnalyticalReport $analyticalReport): bool
    {
        return $this->view($user, $analyticalReport) && $analyticalReport->isDownloadable();
    }

    public function retry(User $user, AnalyticalReport $analyticalReport): bool
    {
        return $this->view($user, $analyticalReport) && $analyticalReport->isRetryable();
    }

    private function isStaff(User $user): bool
    {
        return $user->role->seesAllBranches();
    }
}
