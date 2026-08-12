<?php

declare(strict_types=1);

return [
    'user_role' => [
        'cliente' => 'Client',
        'operador' => 'Operator',
        'adm' => 'Administrator',
    ],

    'account_type' => [
        'checking' => 'Checking account',
        'savings' => 'Savings account',
    ],

    'person_type' => [
        'pf' => 'Individual',
        'pj' => 'Company',
    ],

    'payment_method' => [
        'boleto' => 'Boleto',
        'deposit' => 'Deposit',
    ],

    'address_type' => [
        'main' => 'Main',
        'billing' => 'Billing',
        'shipping' => 'Shipping',
    ],

    'contact_type' => [
        'main' => 'Main',
        'billing' => 'Billing',
        'technical' => 'Technical',
    ],

    'payment_request_status' => [
        'requested' => 'Requested',
        'launched' => 'Launched',
        'settled' => 'Settled',
    ],

    'deposit_type' => [
        'pix' => 'Pix',
        'transfer' => 'Bank transfer (wire/account)',
    ],

    'pix_key_type' => [
        'random' => 'Random key',
        'cpf' => 'Tax ID (CPF)',
        'phone' => 'Phone',
        'email' => 'Email',
    ],

    'attachment_type' => [
        'boleto' => 'Bank slip',
        'other' => 'Other',
    ],

    'approval_status' => [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ],
];
