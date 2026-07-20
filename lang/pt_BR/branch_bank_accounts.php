<?php

declare(strict_types=1);

return [
    'label' => 'Conta bancária',
    'plural' => 'Contas bancárias',

    'fields' => [
        'bank_id' => 'Banco',
        'bank_code' => 'Código do banco',
        'bank_name' => 'Banco',
        'agency' => 'Agência',
        'agency_digit' => 'Dígito da agência',
        'account_number' => 'Conta',
        'account_digit' => 'Dígito da conta',
        'account_type' => 'Tipo de conta',
        'holder_name' => 'Titular',
    ],

    'sections' => [
        'bank_account_info' => 'Dados bancários',
    ],
];
