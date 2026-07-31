<?php

declare(strict_types=1);

return [
    'label' => 'Attachment',
    'plural' => 'Attachments',

    'fields' => [
        'file' => 'File',
        'type' => 'Type',
        'original_name' => 'File name',
        'mime_type' => 'MIME type',
        'size' => 'Size',
    ],

    'actions' => [
        'download' => 'Download',
    ],

    'messages' => [
        'uploaded' => 'Attachment uploaded successfully.',
    ],

    'errors' => [
        'invalid_mime_type' => 'File type is not allowed.',
        'file_too_large' => 'The file exceeds the maximum size of :max KB.',
        'file_not_found' => 'File not found in storage.',
    ],
];
