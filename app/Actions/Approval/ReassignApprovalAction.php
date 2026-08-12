<?php

declare(strict_types=1);

namespace App\Actions\Approval;

use App\Models\Approval;
use App\Models\User;
use App\Services\ApprovalService;

final class ReassignApprovalAction
{
    public function __construct(private readonly ApprovalService $approvalService) {}

    public function __invoke(Approval $approval, User $to, User $actor, ?string $reason = null): Approval
    {
        return $this->approvalService->reassign($approval, $to, $actor, $reason);
    }
}
