<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AttachmentBatchItem;
use App\Models\User;

final class AttachmentBatchItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOperador() || $user->isAdm();
    }

    public function view(User $user, AttachmentBatchItem $item): bool
    {
        $batch = $item->batch;

        return $batch !== null && $user->can('view', $batch);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AttachmentBatchItem $item): bool
    {
        return $this->classify($user, $item);
    }

    public function classify(User $user, AttachmentBatchItem $item): bool
    {
        $batch = $item->batch;

        return $batch !== null
            && $batch->isPendingClassification()
            && $user->can('classify', $batch);
    }

    public function delete(User $user, AttachmentBatchItem $item): bool
    {
        return $user->isAdm();
    }

    public function restore(User $user, AttachmentBatchItem $item): bool
    {
        return $user->isAdm();
    }

    public function forceDelete(User $user, AttachmentBatchItem $item): bool
    {
        return $user->isAdm();
    }
}
