<?php

declare(strict_types=1);

namespace App\Exceptions;

final class AppropriationException extends BusinessException
{
    public static function cannotDeleteWithPaymentRequests(string $id): self
    {
        return new self(
            message: "Appropriation {$id} cannot be deleted while payment requests exist.",
            userMessage: __('appropriations.errors.cannot_delete_with_payment_requests'),
        );
    }
}
