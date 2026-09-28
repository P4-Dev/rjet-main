<?php

declare(strict_types=1);

return [
    'view_payment_request' => 'View request',
    'view_import_batch' => 'View import batch',
    'view_attachment_batch' => 'View attachment batch',
    'view_payment_settlement' => 'Open settlement',
    'view_analytical_report' => 'View report',

    'approval_assigned' => [
        'subject' => 'New payment request awaiting your approval',
        'body' => 'A payment request is pending your approval.',
    ],
    'approval_reassigned' => [
        'subject' => 'Approval reassigned to you',
        'body' => 'A pending approval was reassigned to your user.',
    ],
    'payment_request_approved' => [
        'subject' => 'Payment request approved',
        'body' => 'Your payment request was approved.',
    ],
    'payment_request_rejected' => [
        'subject' => 'Payment request returned',
        'body' => 'Your payment request was rejected. Check the reason and resubmit.',
    ],
    'approval_sla_breached' => [
        'title' => 'Approval SLA breached',
        'body' => 'A pending approval exceeded its SLA due date.',
    ],
    'payment_request_batch_imported' => [
        'subject' => 'Batch import completed',
        'body' => 'Import completed: :success success(es), :errors error(s).',
    ],

    'attachment_batch_renamed' => [
        'subject' => 'Attachment batch naming completed',
        'body' => ':count file(s) renamed with the YYYYMMDD_HHMMSS_SEQ pattern.',
    ],

    'cnab_file_generated' => [
        'title' => 'CNAB remittance ready',
        'body' => 'File sequence :sequence with :count payment(s), total :total, available for download.',
    ],

    'cnab_file_generation_failed' => [
        'title' => 'CNAB remittance failed',
        'body' => ':reason',
    ],

    'analytical_report_generated' => [
        'title' => 'Analytical report ready',
        'body' => 'The report from :from to :until (:basis) is ready, with :count rows.',
    ],

    'analytical_report_generation_failed' => [
        'title' => 'Analytical report generation failed',
        'body' => 'The report from :from to :until could not be generated. :reason',
    ],
];
