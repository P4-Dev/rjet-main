<?php

declare(strict_types=1);

return [
    'label' => 'Supplier',
    'plural' => 'Suppliers',

    'fields' => [
        'person_type' => 'Person type',
        'document' => 'CPF/CNPJ',
        'name' => 'Name',
        'legal_name' => 'Legal name',
        'email' => 'Email',
        'phone' => 'Phone',
        'default_payment_method' => 'Default payment method',
        'notes' => 'Notes',
        'company_payment_methods_count' => 'Overrides',
    ],

    'sections' => [
        'identity' => 'Identity',
        'contact_quick' => 'Contact',
        'payment' => 'Payment',
        'flags' => 'Status',
    ],

    'hints' => [
        'bank_details' => 'The fields shown depend on the deposit type (Pix or TED). These details are filled automatically on payment requests.',
    ],

    'errors' => [
        'document_inconsistent_with_person_type' => 'The document is inconsistent with the person type.',
        'duplicate_document' => 'A supplier with this document already exists.',
        'cannot_delete_with_payment_requests' => 'Cannot delete the supplier while linked payment requests exist.',
    ],
];
