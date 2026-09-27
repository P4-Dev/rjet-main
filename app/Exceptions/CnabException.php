<?php

declare(strict_types=1);

namespace App\Exceptions;

final class CnabException extends BusinessException
{
    public static function configNotFound(): self
    {
        return new self(
            message: 'Paying bank account has no live CNAB configuration.',
            userMessage: __('cnab_files.errors.config_not_found'),
        );
    }

    public static function configInactive(): self
    {
        return new self(
            message: 'CNAB configuration is inactive.',
            userMessage: __('cnab_files.errors.config_inactive'),
        );
    }

    public static function configAlreadyExists(): self
    {
        return new self(
            message: 'Bank account already has a live CNAB configuration.',
            userMessage: __('cnab_configs.errors.already_exists'),
        );
    }

    public static function configLockedAfterIssue(): self
    {
        return new self(
            message: 'CNAB configuration account, layout and sequence are locked after the first issued file.',
            userMessage: __('cnab_configs.errors.locked_after_issue'),
        );
    }

    public static function layoutNotSupported(string $layout): self
    {
        return new self(
            message: "CNAB layout {$layout} has no adapter.",
            userMessage: __('cnab_configs.errors.layout_not_supported'),
        );
    }

    public static function bankMismatch(string $expected, string $actual): self
    {
        return new self(
            message: "CNAB layout expects bank {$expected}, account bank is {$actual}.",
            userMessage: __('cnab_configs.errors.bank_mismatch', ['expected' => $expected, 'actual' => $actual]),
        );
    }

    /**
     * @param  list<string>  $messages
     */
    public static function configInvalid(array $messages): self
    {
        return new self(
            message: 'CNAB configuration failed adapter validation.',
            userMessage: implode(' ', $messages),
        );
    }

    public static function paymentDateInPast(): self
    {
        return new self(
            message: 'Cannot generate CNAB for a settlement dated in the past.',
            userMessage: __('cnab_files.errors.payment_date_in_past'),
        );
    }

    public static function settlementNotDraft(): self
    {
        return new self(
            message: 'CNAB can only be generated for draft settlements.',
            userMessage: __('cnab_files.errors.settlement_not_draft'),
        );
    }

    public static function generationAlreadyActive(): self
    {
        return new self(
            message: 'Settlement already has an active CNAB file.',
            userMessage: __('cnab_files.errors.generation_already_active'),
        );
    }

    public static function remittanceInvalid(int $errorCount): self
    {
        return new self(
            message: "CNAB remittance dry-run failed with {$errorCount} error(s).",
            userMessage: __('cnab_files.errors.remittance_invalid', ['count' => $errorCount]),
        );
    }

    public static function fileNotRetryable(): self
    {
        return new self(
            message: 'CNAB file is not retryable.',
            userMessage: __('cnab_files.errors.not_retryable'),
        );
    }

    public static function fileNotDownloadable(): self
    {
        return new self(
            message: 'CNAB file is not downloadable.',
            userMessage: __('cnab_files.errors.not_downloadable'),
        );
    }

    public static function fileMissing(): self
    {
        return new self(
            message: 'CNAB file is missing from storage.',
            userMessage: __('cnab_files.errors.file_missing'),
        );
    }

    public static function fileIntegrityCheckFailed(): self
    {
        return new self(
            message: 'CNAB file checksum mismatch.',
            userMessage: __('cnab_files.errors.integrity_failed'),
        );
    }

    public static function storageWriteFailed(): self
    {
        return new self(
            message: 'Failed to write CNAB file to storage.',
            userMessage: __('cnab_files.errors.storage_failed'),
        );
    }

    public static function fileSequenceExhausted(): self
    {
        return new self(
            message: 'CNAB file sequence (NSA) exceeded 999999.',
            userMessage: __('cnab_files.errors.file_sequence_exhausted'),
        );
    }

    public static function fileSequenceBelowIssued(int $max): self
    {
        return new self(
            message: "CNAB last file sequence cannot be lower than {$max}.",
            userMessage: __('cnab_configs.errors.sequence_below_issued', ['max' => $max]),
        );
    }

    public static function configHasActiveGeneration(): self
    {
        return new self(
            message: 'CNAB configuration has a queued or generating file.',
            userMessage: __('cnab_configs.errors.has_active_generation'),
        );
    }
}
