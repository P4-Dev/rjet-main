<?php

declare(strict_types=1);

return [
    'label' => 'Bank',
    'plural' => 'Banks',

    'fields' => [
        'code' => 'COMPE code',
        'name' => 'Name',
        'ispb' => 'ISPB',
        'branch_bank_accounts_count' => 'Accounts',
    ],

    'sections' => [
        'bank_info' => 'Bank details',
    ],

    'hints' => [
        'code' => 'COMPE/BACEN code with 3 digits (e.g. 001, 341)',
    ],

    'errors' => [
        'cannot_delete_with_accounts' => 'Cannot delete the bank while linked bank accounts exist.',
        'code_already_exists' => 'A bank with this code already exists.',
    ],
];
