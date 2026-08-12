<?php

declare(strict_types=1);

namespace App\Actions\Approval;

use App\Models\Approval;
use App\Models\User;
use App\Services\ApprovalService;

final class ApprovePaymentRequestAction
{
    public function __construct(private readonly ApprovalService $approvalService) {}

    public function __invoke(Approval $approval, User $actor, ?string $notes = null): Approval
    {
        return $this->approvalService->approve($approval, $actor, $notes);
    }
}
