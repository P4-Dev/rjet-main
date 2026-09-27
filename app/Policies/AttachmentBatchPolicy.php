<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AttachmentBatch;
use App\Models\User;

final class AttachmentBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOperador() || $user->isAdm();
    }

    public function view(User $user, AttachmentBatch $attachmentBatch): bool
    {
        return $user->isOperador() || $user->isAdm();
    }

    public function create(User $user): bool
    {
        return $user->isOperador() || $user->isAdm();
    }

    public function update(User $user, AttachmentBatch $attachmentBatch): bool
    {
        return false;
    }

    public function classify(User $user, AttachmentBatch $attachmentBatch): bool
    {
        return $user->isOperador() || $user->isAdm();
    }

    public function delete(User $user, AttachmentBatch $attachmentBatch): bool
    {
        return $user->isAdm();
    }

    public function restore(User $user, AttachmentBatch $attachmentBatch): bool
    {
        return $user->isAdm();
    }

    public function forceDelete(User $user, AttachmentBatch $attachmentBatch): bool
    {
        return $user->isAdm();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdm();
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->isAdm();
    }

    public function restoreAny(User $user): bool
    {
        return $user->isAdm();
    }
}
