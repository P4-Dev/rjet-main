<?php

declare(strict_types=1);

namespace App\Listeners\Approval;

use App\Events\Approval\ApprovalReassigned;
use App\Models\User;
use App\Notifications\ApprovalReassignedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

final class SendApprovalReassignedNotification implements ShouldQueue
{
    public function handle(ApprovalReassigned $event): void
    {
        $approver = $event->approval->approver;

        if (! $approver instanceof User) {
            $approver = User::query()->find($event->reassignment->to_approver_user_id);
        }

        if (! $approver instanceof User) {
            return;
        }

        $approver->notify(new ApprovalReassignedNotification($event->approval));
    }
}
