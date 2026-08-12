<?php

declare(strict_types=1);

namespace App\Exceptions;

final class UserException extends BusinessException
{
    public static function cannotDeleteSelf(): self
    {
        return new self(
            message: 'An administrator cannot delete or deactivate their own account.',
            userMessage: __('users.errors.cannot_delete_self'),
        );
    }

    public static function cannotDeleteLastActiveAdmin(): self
    {
        return new self(
            message: 'Cannot delete or deactivate the last active administrator.',
            userMessage: __('users.errors.cannot_delete_last_admin'),
        );
    }

    public static function cannotDeleteWithPendingApprovals(): self
    {
        return new self(
            message: 'Cannot delete user with pending approvals or active approval rules.',
            userMessage: __('users.errors.cannot_delete_with_pending_approvals'),
        );
    }
}
