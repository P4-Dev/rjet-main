<?php

declare(strict_types=1);

namespace App\Exceptions;

final class BankException extends BusinessException
{
    public static function cannotDeleteWithAccounts(string $bankId): self
    {
        return new self(
            message: "Cannot delete bank {$bankId}: it still has branch bank accounts.",
            userMessage: __('banks.errors.cannot_delete_with_accounts'),
        );
    }

    public static function cannotDeleteWithPaymentRequestBankDetails(string $id): self
    {
        return new self(
            message: "Cannot delete bank {$id}: it still has payment request bank details.",
            userMessage: __('banks.errors.cannot_delete_with_payment_request_bank_details'),
        );
    }

    public static function cannotDeleteWithSupplierBankDetails(string $id): self
    {
        return new self(
            message: "Cannot delete bank {$id}: it still has supplier bank details.",
            userMessage: __('banks.errors.cannot_delete_with_supplier_bank_details'),
        );
    }

    public static function codeAlreadyExists(string $code): self
    {
        return new self(
            message: "Bank code {$code} already exists.",
            userMessage: __('banks.errors.code_already_exists'),
        );
    }
}
