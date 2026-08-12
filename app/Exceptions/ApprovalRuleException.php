<?php

declare(strict_types=1);

namespace App\Exceptions;

final class ApprovalRuleException extends BusinessException
{
    public static function invalidAmountRange(): self
    {
        return new self(
            message: 'Approval rule max amount must be greater than or equal to min amount.',
            userMessage: __('approval_rules.errors.invalid_amount_range'),
        );
    }

    public static function approverNotEligible(): self
    {
        return new self(
            message: 'Approver must be active, can_approve, and Operador or Adm.',
            userMessage: __('approval_rules.errors.approver_not_eligible'),
        );
    }

    public static function branchRequired(): self
    {
        return new self(
            message: 'A valid branch is required for the approval rule.',
            userMessage: __('approval_rules.errors.branch_required'),
        );
    }
}
