<?php

declare(strict_types=1);

namespace App\Listeners\Approval;

use App\Enums\UserRole;
use App\Events\Approval\ApprovalSlaBreached;
use App\Models\User;
use App\Notifications\ApprovalSlaBreachedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;

final class SendApprovalSlaBreachedNotifications implements ShouldQueue
{
    public function handle(ApprovalSlaBreached $event): void
    {
        $approval = $event->approval;
        $notification = new ApprovalSlaBreachedNotification($approval);

        /** @var Collection<int, User> $recipients */
        $recipients = collect();

        $approver = $approval->approver ?? User::query()->find($approval->approver_user_id);
        if ($approver instanceof User) {
            $recipients->push($approver);
        }

        User::query()
            ->where('role', UserRole::Adm)
            ->active()
            ->get()
            ->each(fn (User $admin): Collection => $recipients->push($admin));

        $recipients
            ->unique(fn (User $user): string => (string) $user->getKey())
            ->each(fn (User $user): mixed => $user->notify($notification));
    }
}
