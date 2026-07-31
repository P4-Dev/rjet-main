<?php

declare(strict_types=1);

namespace App\Exceptions;

final class PaymentRequestException extends BusinessException
{
    public static function branchNotAllowed(string $branchId): self
    {
        return new self(
            message: "Branch {$branchId} is not allowed for this user.",
            userMessage: __('payment_requests.errors.branch_not_allowed'),
        );
    }

    public static function invalidStatusTransition(string $from, string $to): self
    {
        return new self(
            message: "Invalid status transition from {$from} to {$to}.",
            userMessage: __('payment_requests.errors.invalid_status_transition'),
        );
    }

    public static function cannotEditInStatus(string $status): self
    {
        return new self(
            message: "Cannot edit payment request in status {$status}.",
            userMessage: __('payment_requests.errors.cannot_edit_in_status'),
        );
    }

    public static function appropriationRequired(): self
    {
        return new self(
            message: 'Appropriation is required for this company.',
            userMessage: __('payment_requests.errors.appropriation_required'),
        );
    }

    public static function boletoAttachmentRequired(): self
    {
        return new self(
            message: 'Boleto payment method requires at least one attachment.',
            userMessage: __('payment_requests.errors.boleto_attachment_required'),
        );
    }

    public static function discountExceedsGross(): self
    {
        return new self(
            message: 'Discount amount exceeds gross amount.',
            userMessage: __('payment_requests.errors.discount_exceeds_gross'),
        );
    }

    public static function invalidNetAmount(): self
    {
        return new self(
            message: 'Invalid net amount calculation inputs.',
            userMessage: __('payment_requests.errors.invalid_net_amount'),
        );
    }

    public static function incompleteBankDetails(string $paymentMethod): self
    {
        return new self(
            message: "Incomplete bank details for payment method {$paymentMethod}.",
            userMessage: __('payment_requests.errors.incomplete_bank_details'),
        );
    }

    public static function pixDetailsIncomplete(): self
    {
        return new self(
            message: 'Pix details are incomplete.',
            userMessage: __('payment_requests.errors.pix_details_incomplete'),
        );
    }

    public static function transferDetailsIncomplete(): self
    {
        return new self(
            message: 'Transfer details are incomplete.',
            userMessage: __('payment_requests.errors.transfer_details_incomplete'),
        );
    }

    public static function invalidHolderDocument(): self
    {
        return new self(
            message: 'Holder document is invalid.',
            userMessage: __('payment_requests.errors.invalid_holder_document'),
        );
    }
}
