<?php

declare(strict_types=1);

return [
    'label' => 'Apropriação',
    'plural' => 'Apropriações',

    'fields' => [
        'company_id' => 'Empresa',
        'company' => 'Empresa',
        'code' => 'Código',
        'name' => 'Nome',
        'description' => 'Descrição',
        'sort_order' => 'Ordem',
    ],

    'sections' => [
        'appropriation_info' => 'Dados da apropriação',
    ],

    'errors' => [
        'cannot_delete_with_payment_requests' => 'Não é possível excluir a apropriação enquanto houver solicitações de pagamento vinculadas.',
    ],
];
