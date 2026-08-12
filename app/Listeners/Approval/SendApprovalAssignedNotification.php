<?php

declare(strict_types=1);

namespace App\Listeners\Approval;

use App\Events\Approval\ApprovalAssigned;
use App\Models\User;
use App\Notifications\ApprovalAssignedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

final class SendApprovalAssignedNotification implements ShouldQueue
{
    public function handle(ApprovalAssigned $event): void
    {
        $approver = $event->approval->approver;

        if (! $approver instanceof User) {
            return;
        }

        $approver->notify(new ApprovalAssignedNotification($event->approval));
    }
}
