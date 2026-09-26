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

    'import_file_format' => [
        'csv' => 'CSV',
        'xlsx' => 'Excel (XLSX)',
    ],

    'import_batch_status' => [
        'pending' => 'Pending',
        'processing' => 'Processing',
        'completed' => 'Completed',
        'failed' => 'Failed',
    ],

    'import_target_field' => [
        'branch_document' => 'Branch CNPJ',
        'supplier_document' => 'Supplier CPF/CNPJ',
        'cost_center_code' => 'Cost center code',
        'appropriation_code' => 'Appropriation code',
        'payment_method' => 'Payment method',
        'gross_amount' => 'Gross amount',
        'discount_amount' => 'Discount',
        'due_date' => 'Due date',
        'notes' => 'Notes',
        'deposit_type' => 'Deposit type',
        'digitable_line' => 'Digitable line',
        'pix_key_type' => 'Pix key type',
        'pix_key' => 'Pix key',
        'pix_qr_code' => 'Pix QR code',
        'holder_name' => 'Holder name',
        'holder_document' => 'Holder document',
        'bank_code' => 'Bank code',
        'agency' => 'Agency',
        'agency_digit' => 'Agency digit',
        'account_number' => 'Account number',
        'account_digit' => 'Account digit',
        'account_type' => 'Account type',
    ],
];
