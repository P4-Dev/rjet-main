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
}
