<?php

declare(strict_types=1);

namespace App\Exceptions;

final class ImportException extends BusinessException
{
    public static function templateInactive(): self
    {
        return new self(
            message: 'Import template is inactive or deleted.',
            userMessage: __('import_templates.errors.inactive'),
        );
    }

    public static function templateMissingRequiredMappings(): self
    {
        return new self(
            message: 'Import template is missing required mappings.',
            userMessage: __('import_templates.errors.missing_required_mappings'),
        );
    }

    public static function incompatibleFormat(): self
    {
        return new self(
            message: 'Spreadsheet format does not match the template accepted format.',
            userMessage: __('import_batches.errors.incompatible_format'),
        );
    }

    public static function unreadableSpreadsheet(?\Throwable $previous = null): self
    {
        return new self(
            message: 'Spreadsheet could not be read.',
            userMessage: __('import_batches.errors.unreadable'),
            previous: $previous,
        );
    }

    public static function rowLimitExceeded(int $max): self
    {
        return new self(
            message: "Spreadsheet exceeds the maximum of {$max} rows.",
            userMessage: __('import_batches.errors.row_limit_exceeded', ['max' => $max]),
        );
    }

    public static function emptySpreadsheet(): self
    {
        return new self(
            message: 'Spreadsheet has no data rows.',
            userMessage: __('import_batches.errors.empty'),
        );
    }

    public static function batchNotPending(): self
    {
        return new self(
            message: 'Import batch is not pending.',
            userMessage: __('import_batches.errors.not_pending'),
        );
    }

    public static function unauthorizedImport(): self
    {
        return new self(
            message: 'User is not authorized to import batches.',
            userMessage: __('import_batches.errors.unauthorized'),
        );
    }

    public static function supplierNotFound(string $document): self
    {
        return new self(
            message: "Supplier not found for document {$document}.",
            userMessage: __('import_batches.errors.supplier_not_found', ['document' => $document]),
        );
    }

    public static function invalidDocument(string $document): self
    {
        return new self(
            message: "Invalid CPF/CNPJ: {$document}.",
            userMessage: __('import_batches.errors.invalid_document', ['document' => $document]),
        );
    }

    public static function invalidAmount(string $value): self
    {
        return new self(
            message: "Invalid amount: {$value}.",
            userMessage: __('import_batches.errors.invalid_amount', ['value' => $value]),
        );
    }

    public static function missingCreator(): self
    {
        return new self(
            message: 'Import batch has no creator.',
            userMessage: __('import_batches.errors.missing_creator'),
        );
    }

    public static function processingFailed(?\Throwable $previous = null): self
    {
        return new self(
            message: 'Import batch processing failed.',
            userMessage: __('import_batches.errors.processing_failed'),
            previous: $previous,
        );
    }

    public static function costCenterNotFound(string $code): self
    {
        return new self(
            message: "Cost center not found: {$code}.",
            userMessage: __('import_batches.errors.cost_center_not_found', ['code' => $code]),
        );
    }

    public static function appropriationRequired(): self
    {
        return new self(
            message: 'Appropriation is required for this company.',
            userMessage: __('import_batches.errors.appropriation_required'),
        );
    }

    public static function appropriationNotFound(string $code = ''): self
    {
        return new self(
            message: "Appropriation not found: {$code}.",
            userMessage: __('import_batches.errors.appropriation_not_found', ['code' => $code]),
        );
    }

    public static function boletoNotSupportedInBatch(): self
    {
        return new self(
            message: 'Boleto is not supported in batch import.',
            userMessage: __('import_batches.errors.boleto_not_supported'),
        );
    }

    public static function duplicateRowInBatch(int $row): self
    {
        return new self(
            message: "Duplicate row within batch at row {$row}.",
            userMessage: __('import_batches.errors.duplicate_row', ['row' => $row]),
        );
    }

    public static function versionImmutable(): self
    {
        return new self(
            message: 'Published import template version mappings are immutable.',
            userMessage: __('import_templates.errors.version_immutable'),
        );
    }

    public static function versionInUse(): self
    {
        return new self(
            message: 'Import template version has linked batches and cannot be deleted.',
            userMessage: __('import_templates.errors.version_in_use'),
        );
    }

    public static function unsafeReprocess(): self
    {
        return new self(
            message: 'Import batch reprocess is unsafe because payment requests already exist.',
            userMessage: __('import_batches.errors.not_pending'),
        );
    }
}
