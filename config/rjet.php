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

    'imports' => [
        'disk' => env('RJET_IMPORTS_DISK', env('FILESYSTEM_DISK', 'local')),
        'directory' => 'imports',
        'max_kilobytes' => (int) env('RJET_IMPORTS_MAX_KILOBYTES', 5120),
        'max_rows' => (int) env('RJET_IMPORTS_MAX_ROWS', 500),
        'accepted_extensions' => ['csv', 'xlsx'],
    ],

    'cnab' => [
        'disk' => env('RJET_CNAB_DISK', env('FILESYSTEM_DISK', 'local')),
        'directory' => 'cnab',
        'max_items' => (int) env('RJET_CNAB_MAX_ITEMS', 500),
        'line_ending' => "\r\n",
        'queue' => [
            'connection' => env('RJET_CNAB_QUEUE_CONNECTION', 'cnab_database'),
            'name' => env('RJET_CNAB_QUEUE', 'cnab'),
        ],
        'recovery' => [
            'margin_seconds' => 60,
            'max_age_minutes' => 20,
        ],
    ],

    'dashboard' => [
        'cache_ttl_seconds' => (int) env('RJET_DASHBOARD_CACHE_TTL', 300),
        'chart_max_categories' => 12,
    ],

    'reports' => [
        'disk' => env('RJET_REPORTS_DISK') ?: env('FILESYSTEM_DISK', 'local'),
        'directory' => 'reports',
        'queue' => env('RJET_REPORTS_QUEUE', 'default'),
        'max_rows' => (int) env('RJET_REPORTS_MAX_ROWS', 20000),
        'max_in_progress_per_user' => 3,
        'max_attachment_columns' => 10,
        'retention_days' => (int) env('RJET_REPORTS_RETENTION_DAYS', 30),
        'attachment_redirect_ttl_minutes' => 5,
        'stale_after_minutes' => 30,
    ],
];
