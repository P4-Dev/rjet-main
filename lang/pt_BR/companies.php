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
        'approval_sla_business_days' => 'SLA de aprovação (dias úteis)',
    ],

    'hints' => [
        'is_appropriation_required' => 'Quando ativo, a apropriação passa a ser obrigatória ao criar solicitações desta empresa.',
        'approval_sla_business_days' => 'Prazo em dias úteis (segunda a sexta) para o aprovador decidir. Não recalcula aprovações já pendentes.',
    ],

    'suffixes' => [
        'business_days' => 'dias úteis',
    ],

    'sections' => [
        'company_info' => 'Dados da empresa',
    ],
];
