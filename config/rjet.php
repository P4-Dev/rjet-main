<?php

declare(strict_types=1);

return [
    'attachments' => [
        'disk' => env('RJET_ATTACHMENTS_DISK', env('FILESYSTEM_DISK', 'local')),
        'directory' => 'attachments',
        'max_kilobytes' => (int) env('RJET_ATTACHMENTS_MAX_KB', 10240),
        'max_files' => (int) env('RJET_ATTACHMENTS_MAX_FILES', 10),
        'staging_ttl_hours' => (int) env('RJET_ATTACHMENTS_STAGING_TTL_HOURS', 24),
        'accepted_mime_types' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
        ],
    ],

    'ocr' => [
        'enabled' => (bool) env('RJET_OCR_ENABLED', true),
        'driver' => env('RJET_OCR_DRIVER', 'local'),
    ],
];
