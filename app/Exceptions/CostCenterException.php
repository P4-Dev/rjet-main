<?php

declare(strict_types=1);

namespace App\Exceptions;

final class CostCenterException extends BusinessException
{
    public static function cannotDeleteWithPaymentRequests(string $id): self
    {
        return new self(
            message: "Cost center {$id} cannot be deleted while payment requests exist.",
            userMessage: __('cost_centers.errors.cannot_delete_with_payment_requests'),
        );
    }
}
