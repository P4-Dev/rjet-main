<?php

declare(strict_types=1);

return [
    'label' => 'Centro de custo',
    'plural' => 'Centros de custo',

    'fields' => [
        'branch_id' => 'Filial',
        'branch' => 'Filial',
        'company' => 'Empresa',
        'code' => 'Código',
        'name' => 'Nome',
        'description' => 'Descrição',
        'sort_order' => 'Ordem',
    ],

    'sections' => [
        'cost_center_info' => 'Dados do centro de custo',
    ],

    'errors' => [
        'cannot_delete_with_payment_requests' => 'Não é possível excluir o centro de custo enquanto houver solicitações de pagamento vinculadas.',
    ],
];
