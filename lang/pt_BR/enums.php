<?php

declare(strict_types=1);

return [
    'user_role' => [
        'cliente' => 'Cliente',
        'operador' => 'Operador',
        'adm' => 'Administrador',
    ],

    'account_type' => [
        'checking' => 'Conta corrente',
        'savings' => 'Conta poupança',
    ],

    'person_type' => [
        'pf' => 'Pessoa física',
        'pj' => 'Pessoa jurídica',
    ],

    'payment_method' => [
        'boleto' => 'Boleto',
        'deposit' => 'Depósito',
    ],

    'address_type' => [
        'main' => 'Principal',
        'billing' => 'Cobrança',
        'shipping' => 'Entrega',
    ],

    'contact_type' => [
        'main' => 'Principal',
        'billing' => 'Financeiro',
        'technical' => 'Técnico',
    ],

    'payment_request_status' => [
        'requested' => 'Solicitada',
        'launched' => 'Lançada',
        'settled' => 'Liquidada',
    ],

    'deposit_type' => [
        'pix' => 'Pix',
        'transfer' => 'Transferência (TED/DOC/conta)',
    ],

    'pix_key_type' => [
        'random' => 'Chave aleatória',
        'cpf' => 'CPF',
        'phone' => 'Telefone',
        'email' => 'E-mail',
    ],

    'attachment_type' => [
        'boleto' => 'Boleto',
        'other' => 'Outro',
    ],

    'approval_status' => [
        'pending' => 'Pendente',
        'approved' => 'Aprovada',
        'rejected' => 'Rejeitada',
    ],
];
