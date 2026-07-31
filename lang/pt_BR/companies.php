<?php

declare(strict_types=1);

return [
    'label' => 'Empresa',
    'plural' => 'Empresas',

    'fields' => [
        'name' => 'Nome',
        'legal_name' => 'Razão social',
        'document' => 'CNPJ',
        'branches_count' => 'Filiais',
        'is_appropriation_required' => 'Exigir apropriação na solicitação',
    ],

    'hints' => [
        'is_appropriation_required' => 'Quando ativo, a apropriação passa a ser obrigatória ao criar solicitações desta empresa.',
    ],

    'sections' => [
        'company_info' => 'Dados da empresa',
    ],
];
