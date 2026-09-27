<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CnabFileStatus;
use App\Models\CnabFile;
use App\Models\User;

final class CnabFilePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isStaff($user);
    }

    public function view(User $user, CnabFile $cnabFile): bool
    {
        return $this->isStaff($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function download(User $user, CnabFile $cnabFile): bool
    {
        return $this->isStaff($user) && $cnabFile->status === CnabFileStatus::Generated;
    }

    public function retry(User $user, CnabFile $cnabFile): bool
    {
        return $this->isStaff($user)
            && $cnabFile->status === CnabFileStatus::Failed
            && $this->settlementIsDraft($cnabFile);
    }

    public function regenerate(User $user, CnabFile $cnabFile): bool
    {
        return $user->isAdm()
            && $cnabFile->status === CnabFileStatus::Generated
            && $this->settlementIsDraft($cnabFile);
    }

    public function update(User $user, CnabFile $cnabFile): bool
    {
        return false;
    }

    public function delete(User $user, CnabFile $cnabFile): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, CnabFile $cnabFile): bool
    {
        return false;
    }

    public function forceDelete(User $user, CnabFile $cnabFile): bool
    {
        return false;
    }

    private function isStaff(User $user): bool
    {
        return $user->isOperador() || $user->isAdm();
    }

    private function settlementIsDraft(CnabFile $cnabFile): bool
    {
        return $cnabFile->loadMissing('settlement')->settlement?->isDraft() ?? false;
    }
}
