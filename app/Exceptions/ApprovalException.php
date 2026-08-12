<?php

declare(strict_types=1);

namespace App\Exceptions;

final class ApprovalException extends BusinessException
{
    public static function noMatchingRule(): self
    {
        return new self(
            message: 'No active approval rule matches this payment request.',
            userMessage: __('approvals.errors.no_matching_rule'),
        );
    }

    public static function approvalRequired(): self
    {
        return new self(
            message: 'Launch requires a valid approved approval with matching material fingerprint.',
            userMessage: __('payment_requests.errors.approval_required'),
        );
    }

    public static function notPending(): self
    {
        return new self(
            message: 'Approval is not pending.',
            userMessage: __('approvals.errors.not_pending'),
        );
    }

    public static function unauthorizedApprover(): self
    {
        return new self(
            message: 'User is not authorized to decide this approval.',
            userMessage: __('approvals.errors.unauthorized_approver'),
        );
    }

    public static function reasonRequired(): self
    {
        return new self(
            message: 'Rejection reason is required (min 5 characters).',
            userMessage: __('approvals.errors.reason_required'),
        );
    }

    public static function alreadyPending(): self
    {
        return new self(
            message: 'Payment request already has a pending approval.',
            userMessage: __('approvals.errors.already_pending'),
        );
    }

    public static function cannotResubmit(): self
    {
        return new self(
            message: 'Payment request cannot be resubmitted for approval in its current state.',
            userMessage: __('approvals.errors.cannot_resubmit'),
        );
    }

    public static function slaNotConfigured(): self
    {
        return new self(
            message: 'Company approval SLA is invalid.',
            userMessage: __('approvals.errors.sla_not_configured'),
        );
    }

    public static function fingerprintMismatch(): self
    {
        return new self(
            message: 'Material data changed after approval; fingerprint mismatch.',
            userMessage: __('approvals.errors.fingerprint_mismatch'),
        );
    }
}
