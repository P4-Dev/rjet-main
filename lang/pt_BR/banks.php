<?php

declare(strict_types=1);

return [
    'label' => 'Banco',
    'plural' => 'Bancos',

    'fields' => [
        'code' => 'Código COMPE',
        'name' => 'Nome',
        'ispb' => 'ISPB',
        'branch_bank_accounts_count' => 'Contas',
    ],

    'sections' => [
        'bank_info' => 'Dados do banco',
    ],

    'hints' => [
        'code' => 'Código COMPE/BACEN com 3 dígitos (ex.: 001, 341)',
    ],

    'errors' => [
        'cannot_delete_with_accounts' => 'Não é possível excluir o banco enquanto houver contas bancárias vinculadas.',
        'cannot_delete_with_payment_request_bank_details' => 'Não é possível excluir o banco enquanto houver dados bancários de solicitações vinculados.',
        'cannot_delete_with_supplier_bank_details' => 'Não é possível excluir o banco enquanto houver dados bancários de fornecedores vinculados.',
        'code_already_exists' => 'Já existe um banco com este código.',
    ],
];
