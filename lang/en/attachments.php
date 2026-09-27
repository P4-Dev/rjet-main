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
        'standardized_name' => 'Standardized name',
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
        'batch_classification_incomplete' => 'Classify every item before concluding.',
        'batch_not_classifiable' => 'This batch is not pending classification.',
        'batch_not_retryable' => 'This batch has no failed renames to retry.',
        'invalid_destination' => 'Invalid or incomplete classification destination.',
        'naming_collision_unresolved' => 'Could not generate a unique name for the file.',
        'storage_move_failed' => 'Failed to move the file in storage.',
        'batch_empty' => 'Upload at least one file to create the batch.',
        'unauthorized_batch_operation' => 'You are not allowed to perform this attachment batch operation.',
    ],
];
