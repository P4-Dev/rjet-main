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
];
