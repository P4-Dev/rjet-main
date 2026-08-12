<?php

declare(strict_types=1);

return [
    'view_payment_request' => 'View request',

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
];
