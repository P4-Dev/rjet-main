<?php

declare(strict_types=1);

return [
    'label' => 'Bank account',
    'plural' => 'Bank accounts',

    'fields' => [
        'bank_id' => 'Bank',
        'bank_code' => 'Bank code',
        'bank_name' => 'Bank',
        'agency' => 'Agency',
        'agency_digit' => 'Agency digit',
        'account_number' => 'Account',
        'account_digit' => 'Account digit',
        'account_type' => 'Account type',
        'holder_name' => 'Holder',
    ],

    'sections' => [
        'bank_account_info' => 'Bank details',
    ],

    'errors' => [
        'has_cnab_config' => 'This account has a live CNAB configuration. Delete the configuration first.',
        'branch_locked' => 'The branch of this account cannot change because it was already used in settlements or CNAB configurations.',
    ],
];
