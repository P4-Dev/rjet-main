<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

final class AnalyticalReportException extends BusinessException
{
    public static function unauthorized(): self
    {
        return new self(
            message: 'User is not allowed to perform this analytical report action.',
            userMessage: __('analytical_reports.errors.unauthorized'),
            code: 403,
        );
    }

    public static function invalidPeriod(): self
    {
        return new self(
            message: 'Analytical report period is missing or inverted.',
            userMessage: __('analytical_reports.errors.invalid_period'),
        );
    }

    public static function noMatchingRequests(): self
    {
        return new self(
            message: 'Analytical report filters match no payment requests.',
            userMessage: __('analytical_reports.errors.no_matching_requests'),
        );
    }

    public static function tooManyRows(int $found, int $max): self
    {
        return new self(
            message: "Analytical report filters match {$found} rows (max {$max}).",
            userMessage: __('analytical_reports.errors.too_many_rows', ['found' => $found, 'max' => $max]),
        );
    }

    public static function tooManyInProgress(int $max): self
    {
        return new self(
            message: "Requester already has {$max} analytical reports in progress.",
            userMessage: __('analytical_reports.errors.too_many_in_progress', ['max' => $max]),
        );
    }

    public static function requesterUnavailable(): self
    {
        return new self(
            message: 'Analytical report requester is missing, inactive or no longer authorized.',
            userMessage: __('analytical_reports.errors.requester_unavailable'),
        );
    }

    public static function notDownloadable(): self
    {
        return new self(
            message: 'Analytical report is not downloadable.',
            userMessage: __('analytical_reports.errors.not_downloadable'),
        );
    }

    public static function fileMissing(): self
    {
        return new self(
            message: 'Analytical report file is missing from storage.',
            userMessage: __('analytical_reports.errors.file_missing'),
        );
    }

    public static function notRetryable(): self
    {
        return new self(
            message: 'Analytical report is not retryable.',
            userMessage: __('analytical_reports.errors.not_retryable'),
        );
    }

    public static function storageWriteFailed(?Throwable $previous = null): self
    {
        return new self(
            message: 'Failed to write analytical report file to storage.',
            userMessage: __('analytical_reports.errors.storage_write_failed'),
            previous: $previous,
        );
    }
}
