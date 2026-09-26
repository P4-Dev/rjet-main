<?php

declare(strict_types=1);

return [
    'label' => 'Import batch',
    'plural' => 'Import batches',
    'navigation_label' => 'Batch import',

    'fields' => [
        'import_template_version_id' => 'Template',
        'template' => 'Template',
        'spreadsheet' => 'Spreadsheet',
        'status' => 'Status',
        'original_filename' => 'File',
        'total_rows' => 'Rows',
        'success_count' => 'Successes',
        'error_count' => 'Errors',
        'failure_reason' => 'Failure reason',
        'started_at' => 'Started at',
        'finished_at' => 'Finished at',
        'row_number' => 'Row',
        'target_field' => 'Field',
        'message' => 'Message',
    ],

    'sections' => [
        'upload' => 'Upload',
        'status' => 'Processing',
        'errors' => 'Row errors',
        'payment_requests' => 'Generated payment requests',
    ],

    'actions' => [
        'download' => 'Download spreadsheet',
        'refresh' => 'Refresh status',
    ],

    'messages' => [
        'started' => 'Import queued.',
        'completed' => 'Import completed: :success success(es), :errors error(s).',
        'failed' => 'Import failed: :reason',
    ],

    'errors' => [
        'incompatible_format' => 'The file does not match the template format.',
        'unreadable' => 'The spreadsheet could not be read.',
        'row_limit_exceeded' => 'The spreadsheet exceeds the limit of :max rows.',
        'empty' => 'The spreadsheet has no data rows.',
        'not_pending' => 'This batch is not pending processing.',
        'unauthorized' => 'You are not allowed to import.',
        'supplier_not_found' => 'Supplier not found for document :document.',
        'invalid_document' => 'Invalid CPF/CNPJ: :document.',
        'invalid_amount' => 'Invalid monetary amount: :value.',
        'missing_creator' => 'The import batch has no creator.',
        'processing_failed' => 'Failed to process the import batch.',
        'cost_center_not_found' => 'Cost center not found: :code.',
        'appropriation_required' => 'Appropriation is required for this company.',
        'appropriation_not_found' => 'Appropriation not found: :code.',
        'boleto_not_supported' => 'Boleto is not supported in batch — use individual entry or attachment batch (Phase 6).',
        'duplicate_row' => 'Possible duplicate in the batch (row :row).',
    ],
];
