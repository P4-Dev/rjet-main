<?php

declare(strict_types=1);

return [
    'label' => 'Approval rule',
    'plural' => 'Approval rules',
    'navigation_label' => 'Approval levels',

    'fields' => [
        'company' => 'Company',
        'branch_id' => 'Branch',
        'min_amount' => 'Minimum amount',
        'max_amount' => 'Maximum amount',
        'approver_user_id' => 'Approver',
    ],

    'sections' => [
        'rule' => 'Range and approver',
    ],

    'hints' => [
        'max_amount_null' => 'Leave blank for an open-ended (unlimited) range.',
    ],

    'messages' => [
        'overlap_warning' => 'Another active rule overlaps this range for the branch. Runtime tie-break will pick the most specific one.',
        'created' => 'Approval rule created.',
        'updated' => 'Approval rule updated.',
        'deleted' => 'Approval rule deleted.',
    ],

    'errors' => [
        'invalid_amount_range' => 'Maximum amount must be greater than or equal to the minimum.',
        'approver_not_eligible' => 'The approver must be active, allowed to approve, and have Operator or Administrator role.',
        'branch_required' => 'Select a valid branch.',
    ],
];
