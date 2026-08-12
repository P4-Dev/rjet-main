<?php

declare(strict_types=1);

namespace App\Actions\Approval;

use App\Services\ApprovalService;

final class EscalateOverdueApprovalsAction
{
    public function __construct(private readonly ApprovalService $approvalService) {}

    public function __invoke(bool $dryRun = false): int
    {
        return $this->approvalService->escalateOverdue($dryRun);
    }
}
