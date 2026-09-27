<?php

declare(strict_types=1);

return [
    'label' => 'Attachment batch',
    'plural' => 'Attachment batches',
    'navigation_label' => 'Attachment batches',

    'fields' => [
        'files' => 'Files',
        'status' => 'Status',
        'items_count' => 'Items',
        'classified_count' => 'Classified',
        'renamed_count' => 'Renamed',
        'failed_count' => 'Failures',
        'failure_reason' => 'Failure reason',
        'classified_at' => 'Classified at',
        'classified_by' => 'Classified by',
        'renaming_started_at' => 'Naming started at',
        'renamed_at' => 'Naming finished at',
        'naming_generated_at' => 'Date/time prefix',
        'sort_order' => 'Order',
        'destination_type' => 'Destination',
        'payment_request_id' => 'Payment request',
        'supplier_id' => 'Supplier',
        'operational_label' => 'Operational category',
        'rename_error' => 'Naming error',
        'display_name' => 'Name',
        'mime_type' => 'MIME type',
    ],

    'sections' => [
        'upload' => 'File upload',
        'status' => 'Batch status',
        'items' => 'Items',
        'classifications' => 'Classification history',
        'classify' => 'Classify attachments',
        'preview' => 'Preview',
    ],

    'actions' => [
        'classify' => 'Classify',
        'conclude_classification' => 'Conclude classification',
        'retry_failed_renames' => 'Retry failed renames',
        'download' => 'Download',
        'save_classification' => 'Save classification',
        'back_to_view' => 'Back to batch',
    ],

    'messages' => [
        'created' => 'Attachment batch created.',
        'classification_saved' => 'Classification saved.',
        'classification_concluded' => 'Classification concluded. Naming queued.',
        'reordered' => 'Order updated.',
        'retry_queued' => 'Naming retry queued.',
        'renamed' => 'Batch naming completed.',
        'partially_failed' => 'Naming completed with partial failures.',
        'rename_failed' => 'No file in the batch could be renamed.',
        'rename_interrupted' => 'Batch naming was interrupted by an unexpected error. Retry the failed renames.',
    ],

    'hints' => [
        'upload' => 'PDF, JPEG, PNG or WebP. Same limits as payment request attachments.',
        'operational_label' => 'Free operational label (no catalog in this phase).',
        'seq_order' => 'The order defines the naming SEQ (001, 002, …). Drag to reorder.',
        'conclude' => 'All items must be classified. Once concluded, classification cannot be reopened.',
        'preview_unavailable' => 'Preview unavailable for this storage. Download the file to check it.',
        'select_item' => 'Select an item from the list to classify.',
    ],
];
