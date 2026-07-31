<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Attachment;
use App\Models\User;

final class AttachmentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Attachment $attachment): bool
    {
        if ($attachment->attachable === null) {
            return $user->isAdm();
        }

        return $user->can('view', $attachment->attachable);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Attachment $attachment): bool
    {
        if ($attachment->attachable === null) {
            return $user->isAdm();
        }

        return $user->can('manageAttachments', $attachment->attachable);
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        return $this->update($user, $attachment);
    }

    public function restore(User $user, Attachment $attachment): bool
    {
        return $user->isAdm();
    }

    public function forceDelete(User $user, Attachment $attachment): bool
    {
        return $user->isAdm();
    }
}
