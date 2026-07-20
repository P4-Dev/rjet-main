<?php

declare(strict_types=1);

return [
    'label' => 'Fornecedor',
    'plural' => 'Fornecedores',

    'fields' => [
        'person_type' => 'Tipo de pessoa',
        'document' => 'CPF/CNPJ',
        'name' => 'Nome',
        'legal_name' => 'Razão social',
        'email' => 'E-mail',
        'phone' => 'Telefone',
        'default_payment_method' => 'Forma de pagamento padrão',
        'notes' => 'Observações',
        'company_payment_methods_count' => 'Overrides',
    ],

    'sections' => [
        'identity' => 'Identificação',
        'contact_quick' => 'Contato',
        'payment' => 'Pagamento',
        'flags' => 'Status',
    ],

    'errors' => [
        'document_inconsistent_with_person_type' => 'O documento informado é inconsistente com o tipo de pessoa.',
        'duplicate_document' => 'Já existe um fornecedor com este documento.',
    ],
];
