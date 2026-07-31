<?php

declare(strict_types=1);

namespace App\Exceptions;

final class SupplierException extends BusinessException
{
    public static function documentInconsistentWithPersonType(): self
    {
        return new self(
            message: 'Supplier document is inconsistent with person type.',
            userMessage: __('suppliers.errors.document_inconsistent_with_person_type'),
        );
    }

    public static function duplicateDocument(string $document): self
    {
        return new self(
            message: "Supplier document {$document} already exists.",
            userMessage: __('suppliers.errors.duplicate_document'),
        );
    }

    public static function cannotDeleteWithPaymentRequests(string $id): self
    {
        return new self(
            message: "Cannot delete supplier {$id}: it still has payment requests.",
            userMessage: __('suppliers.errors.cannot_delete_with_payment_requests'),
        );
    }
}
