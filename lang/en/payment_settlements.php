<?php

declare(strict_types=1);

return [
    'label' => 'Settlement',
    'plural' => 'Settlements',
    'navigation_label' => 'Settlements',

    'pages' => [
        'create' => ['title' => 'New settlement'],
    ],

    'fields' => [
        'branch' => 'Branch',
        'branch_bank_account' => 'Paying account',
        'bank_code' => 'Bank',
        'status' => 'Status',
        'settlement_date' => 'Settlement date',
        'items_count' => 'Payments',
        'total_amount' => 'Total amount',
        'notes' => 'Notes',
        'cnab_status' => 'CNAB remittance',
        'settled_at' => 'Confirmed at',
        'settled_by' => 'Confirmed by',
        'cancelled_at' => 'Cancelled at',
        'cancelled_by' => 'Cancelled by',
        'cancellation_reason' => 'Cancellation reason',
        'amount' => 'Amount',
        'released_at' => 'Released at',
        'reason' => 'Reason',
        'deposit_type' => 'Deposit type',
        'request_status' => 'Request status',
    ],

    'sections' => [
        'summary' => 'Settlement summary',
        'cnab' => 'CNAB remittance',
        'items' => 'Settlement payments',
        'cnab_files' => 'Remittance files',
    ],

    'filters' => [
        'branch' => 'Branch',
        'due_from' => 'Due from',
        'due_until' => 'Due until',
        'settlement_from' => 'Settled from',
        'settlement_until' => 'Settled until',
        'payment_method' => 'Payment method',
        'released' => 'Item released',
    ],

    'actions' => [
        'create' => 'New settlement',
        'settle_selected' => 'Settle selected',
        'validate_cnab' => 'Validate remittance',
        'generate_cnab' => 'Generate CNAB',
        'confirm' => 'Confirm settlement',
        'cancel' => 'Cancel settlement',
        'release_item' => 'Remove from settlement',
        'open_request' => 'Open request',
    ],

    'help' => [
        'settlement_date' => 'Bank payment date. To generate CNAB it must be today or later; to confirm, today or earlier.',
    ],

    'hints' => [
        'cnab_available' => 'CNAB available for this account.',
        'cnab_unavailable' => 'No active CNAB configuration: manual settlement.',
    ],

    'messages' => [
        'created' => 'Settlement registered.',
        'selection_summary' => ':count payment(s) selected, total :total.',
        'no_eligible' => 'No launched requests available for settlement.',
        'no_cnab' => 'No remittance generated for this settlement.',
        'no_cnab_short' => 'No remittance',
        'confirm_description' => 'All requests in this settlement will move to "Payment completed".',
        'confirm_without_cnab' => 'No remittance was generated for this settlement. Confirm only if the payment was already made at the bank.',
        'confirmed' => 'Settlement confirmed.',
        'cancel_with_generated_file' => 'A generated file exists. It may already have been sent to the bank.',
        'cancelled' => 'Settlement cancelled.',
        'item_released' => 'Payment removed from the settlement.',
        'item_active' => 'Active',
        'history_note' => 'Settlement on :date (ref. :id)',
    ],

    'errors' => [
        'empty_selection' => 'Select at least one request.',
        'mixed_branches' => 'Select requests from a single branch.',
        'not_eligible' => ':count request(s) are no longer available for settlement.',
        'already_in_settlement' => 'One or more requests are already in another settlement.',
        'bank_account_not_allowed' => 'The paying account does not belong to the branch.',
        'bank_account_inactive' => 'The paying account is inactive or has no bank.',
        'too_many_items' => 'A settlement accepts at most :max payments.',
        'total_amount_overflow' => 'The total amount exceeds the allowed limit.',
        'not_draft' => 'This settlement is not a draft.',
        'settlement_date_in_future' => 'A settlement with a future date cannot be confirmed.',
        'cnab_in_progress' => 'A CNAB remittance is being generated. Wait for it to finish.',
        'cnab_already_generated' => 'A remittance was already generated. Cancel the settlement or regenerate the file.',
        'items_changed' => ':count payment(s) changed since selection. Remove them or cancel the settlement.',
        'cannot_delete_active' => 'Only cancelled settlements can be deleted.',
        'unauthorized' => 'You are not allowed to perform this settlement operation.',
    ],
];
