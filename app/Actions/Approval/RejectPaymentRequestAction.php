<?php

declare(strict_types=1);

namespace App\Actions\Approval;

use App\Models\Approval;
use App\Models\User;
use App\Services\ApprovalService;

final class RejectPaymentRequestAction
{
    public function __construct(private readonly ApprovalService $approvalService) {}

    public function __invoke(Approval $approval, User $actor, string $reason): Approval
    {
        return $this->approvalService->reject($approval, $actor, $reason);
    }
}
