<?php

declare(strict_types=1);

namespace App\Actions\Approval;

use App\Models\Approval;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\ApprovalService;

final class ResubmitPaymentRequestForApprovalAction
{
    public function __construct(private readonly ApprovalService $approvalService) {}

    public function __invoke(PaymentRequest $paymentRequest, User $actor): Approval
    {
        return $this->approvalService->resubmit($paymentRequest, $actor);
    }
}
