<?php

declare(strict_types=1);

namespace App\Exceptions;

final class AttachmentException extends BusinessException
{
    public static function invalidMimeType(string $mime): self
    {
        return new self(
            message: "Invalid attachment MIME type: {$mime}.",
            userMessage: __('attachments.errors.invalid_mime_type'),
        );
    }

    public static function fileTooLarge(int $maxKilobytes): self
    {
        return new self(
            message: "Attachment exceeds max size of {$maxKilobytes} KB.",
            userMessage: __('attachments.errors.file_too_large', ['max' => $maxKilobytes]),
        );
    }

    public static function fileNotFound(string $path): self
    {
        return new self(
            message: "Attachment file not found: {$path}.",
            userMessage: __('attachments.errors.file_not_found'),
        );
    }

    public static function batchClassificationIncomplete(): self
    {
        return new self(
            message: 'Attachment batch has pending items; classification cannot be concluded.',
            userMessage: __('attachments.errors.batch_classification_incomplete'),
        );
    }

    public static function batchNotClassifiable(string $status): self
    {
        return new self(
            message: "Attachment batch is not classifiable in status {$status}.",
            userMessage: __('attachments.errors.batch_not_classifiable'),
        );
    }

    public static function batchNotRetryable(string $status): self
    {
        return new self(
            message: "Attachment batch has no failed renames to retry in status {$status}.",
            userMessage: __('attachments.errors.batch_not_retryable'),
        );
    }

    public static function invalidDestination(): self
    {
        return new self(
            message: 'Invalid or incomplete attachment batch item destination.',
            userMessage: __('attachments.errors.invalid_destination'),
        );
    }

    public static function namingCollisionUnresolved(string $base): self
    {
        return new self(
            message: "Could not resolve a unique standardized name for base {$base}.",
            userMessage: __('attachments.errors.naming_collision_unresolved'),
        );
    }

    public static function storageMoveFailed(string $attachmentId): self
    {
        return new self(
            message: "Storage move failed for attachment {$attachmentId}.",
            userMessage: __('attachments.errors.storage_move_failed'),
        );
    }

    public static function batchEmpty(): self
    {
        return new self(
            message: 'Attachment batch requires at least one file.',
            userMessage: __('attachments.errors.batch_empty'),
        );
    }

    public static function unauthorizedBatchOperation(): self
    {
        return new self(
            message: 'Unauthorized attachment batch operation.',
            userMessage: __('attachments.errors.unauthorized_batch_operation'),
            code: 403,
        );
    }
}
