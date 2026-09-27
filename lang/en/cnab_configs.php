<?php

declare(strict_types=1);

return [
    'label' => 'CNAB configuration',
    'plural' => 'CNAB configurations',
    'navigation_label' => 'CNAB configurations',

    'fields' => [
        'branch' => 'Branch',
        'branch_bank_account' => 'Bank account',
        'bank_code' => 'Bank',
        'account' => 'Agency / Account',
        'layout' => 'Layout',
        'company_name' => 'Company name in file',
        'agreement_code' => 'Agreement',
        'wallet_code' => 'Wallet',
        'payment_type_code' => 'Payment type',
        'last_file_sequence' => 'Last file sequence',
        'is_active' => 'Active',
    ],

    'sections' => [
        'account' => 'Account',
        'parameters' => 'Remittance parameters',
        'files' => 'Issued files',
    ],

    'filters' => [
        'branch' => 'Branch',
        'layout' => 'Layout',
        'is_active' => 'Active',
    ],

    'formats' => [
        'account' => ':bank — Ag :agency / Acc :account-:digit',
        'agency_account' => 'Ag :agency / Acc :account-:digit',
    ],

    'help' => [
        'layout' => 'Must match the account bank.',
        'company_name_fallback' => 'Up to 30 characters. Empty uses the branch legal name.',
        'payment_type_code' => '20 = supplier payments.',
        'last_file_sequence' => 'Sequence number of the last file sent. Locked after the first issue.',
        'is_active' => 'Deactivating pauses generation. To replace the configuration, delete it and create another.',
    ],

    'messages' => [
        'created' => 'CNAB configuration created.',
        'updated' => 'CNAB configuration updated.',
        'deleted' => 'CNAB configuration deleted.',
        'delete_replaces' => 'Deleting frees the account for a new configuration, which continues the file numbering.',
    ],

    'errors' => [
        'already_exists' => 'This account already has a CNAB configuration.',
        'locked_after_issue' => 'Account, layout and sequence cannot change after files were issued.',
        'layout_not_supported' => 'Unsupported CNAB layout.',
        'bank_mismatch' => 'The layout requires bank :expected, but the account bank is :actual.',
        'sequence_below_issued' => 'The last file sequence cannot be lower than :max, already issued for this account.',
        'has_active_generation' => 'A remittance is being generated with this configuration.',
    ],
];
