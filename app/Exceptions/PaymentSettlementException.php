<?php

declare(strict_types=1);

namespace App\Exceptions;

final class PaymentSettlementException extends BusinessException
{
    public static function emptySelection(): self
    {
        return new self(
            message: 'Payment settlement requires at least one payment request.',
            userMessage: __('payment_settlements.errors.empty_selection'),
        );
    }

    public static function mixedBranches(): self
    {
        return new self(
            message: 'Payment settlement selection spans more than one branch.',
            userMessage: __('payment_settlements.errors.mixed_branches'),
        );
    }

    public static function paymentRequestNotEligible(int $count): self
    {
        return new self(
            message: "{$count} payment request(s) are not eligible for settlement.",
            userMessage: __('payment_settlements.errors.not_eligible', ['count' => $count]),
        );
    }

    public static function paymentRequestAlreadyInSettlement(): self
    {
        return new self(
            message: 'One or more payment requests already have an active settlement item.',
            userMessage: __('payment_settlements.errors.already_in_settlement'),
        );
    }

    public static function bankAccountNotAllowed(): self
    {
        return new self(
            message: 'Paying bank account does not belong to the settlement branch.',
            userMessage: __('payment_settlements.errors.bank_account_not_allowed'),
        );
    }

    public static function bankAccountInactive(): self
    {
        return new self(
            message: 'Paying bank account is inactive, trashed or has no bank.',
            userMessage: __('payment_settlements.errors.bank_account_inactive'),
        );
    }

    public static function tooManyItems(int $max): self
    {
        return new self(
            message: "Payment settlement exceeds the maximum of {$max} items.",
            userMessage: __('payment_settlements.errors.too_many_items', ['max' => $max]),
        );
    }

    public static function totalAmountOverflow(): self
    {
        return new self(
            message: 'Payment settlement total amount exceeds decimal(10,2).',
            userMessage: __('payment_settlements.errors.total_amount_overflow'),
        );
    }

    public static function notDraft(string $status): self
    {
        return new self(
            message: "Payment settlement is not a draft (status {$status}).",
            userMessage: __('payment_settlements.errors.not_draft'),
        );
    }

    public static function settlementDateInFuture(): self
    {
        return new self(
            message: 'Cannot confirm a payment settlement dated in the future.',
            userMessage: __('payment_settlements.errors.settlement_date_in_future'),
        );
    }

    public static function cnabGenerationInProgress(): self
    {
        return new self(
            message: 'A CNAB file is queued or generating for this settlement.',
            userMessage: __('payment_settlements.errors.cnab_in_progress'),
        );
    }

    public static function cnabFileAlreadyGenerated(): self
    {
        return new self(
            message: 'A generated CNAB file is active for this settlement.',
            userMessage: __('payment_settlements.errors.cnab_already_generated'),
        );
    }

    public static function itemsChangedSinceSelection(int $count): self
    {
        return new self(
            message: "{$count} settlement item(s) changed since selection.",
            userMessage: __('payment_settlements.errors.items_changed', ['count' => $count]),
        );
    }

    public static function cannotDeleteActive(): self
    {
        return new self(
            message: 'Only cancelled payment settlements can be deleted.',
            userMessage: __('payment_settlements.errors.cannot_delete_active'),
        );
    }

    public static function unauthorized(): self
    {
        return new self(
            message: 'Unauthorized payment settlement operation.',
            userMessage: __('payment_settlements.errors.unauthorized'),
            code: 403,
        );
    }
}
