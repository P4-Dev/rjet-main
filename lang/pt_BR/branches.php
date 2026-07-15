<?php

declare(strict_types=1);

return [
    'label' => 'Filial',
    'plural' => 'Filiais',

    'fields' => [
        'company_id' => 'Empresa',
        'name' => 'Nome',
        'legal_name' => 'Razão social',
        'document' => 'CNPJ',
        'bank_accounts_count' => 'Contas bancárias',
    ],

    'sections' => [
        'branch_info' => 'Dados da filial',
    ],

    'filters' => [
        'company' => 'Empresa',
    ],

    'errors' => [
        'cannot_delete_with_bank_accounts' => 'Não é possível excluir uma filial que ainda possui contas bancárias. Remova as contas antes de excluir a filial.',
    ],
];
