<?php

declare(strict_types=1);

return [
    'label' => 'Approval',
    'plural' => 'Approvals',

    'fields' => [
        'status' => 'Status',
        'approver' => 'Approver',
        'reason' => 'Reason',
        'amount_snapshot' => 'Amount (snapshot)',
        'assigned_at' => 'Assigned at',
        'due_at' => 'SLA due at',
        'decided_at' => 'Decided at',
        'decided_by' => 'Decided by',
        'escalated_at' => 'Escalated at',
        'reassignments' => 'Reassignments',
        'material_fingerprint' => 'Material fingerprint',
    ],

    'errors' => [
        'no_matching_rule' => 'No active approval rule matches this request. Create a range or adjust the amount/branch.',
        'not_pending' => 'This approval is not pending.',
        'unauthorized_approver' => 'You are not allowed to decide this approval.',
        'reason_required' => 'Provide a rejection reason (at least 5 characters).',
        'already_pending' => 'There is already a pending approval for this request.',
        'cannot_resubmit' => 'This request cannot be resubmitted for approval in its current state.',
        'sla_not_configured' => 'The company approval SLA is invalid.',
        'fingerprint_mismatch' => 'Material data changed after approval. Resubmit for approval.',
    ],
];
