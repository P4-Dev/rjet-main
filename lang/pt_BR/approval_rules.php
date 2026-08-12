<?php

declare(strict_types=1);

return [
    'label' => 'Regra de alçada',
    'plural' => 'Regras de alçada',
    'navigation_label' => 'Alçadas',

    'fields' => [
        'company' => 'Empresa',
        'branch_id' => 'Filial',
        'min_amount' => 'Valor mínimo',
        'max_amount' => 'Valor máximo',
        'approver_user_id' => 'Aprovador',
    ],

    'sections' => [
        'rule' => 'Faixa e aprovador',
    ],

    'hints' => [
        'max_amount_null' => 'Deixe em branco para faixa sem teto (ilimitado).',
    ],

    'messages' => [
        'overlap_warning' => 'Existe outra regra ativa com faixa sobreposta nesta filial. O desempate em runtime escolherá a mais específica.',
        'created' => 'Regra de alçada criada.',
        'updated' => 'Regra de alçada atualizada.',
        'deleted' => 'Regra de alçada excluída.',
    ],

    'errors' => [
        'invalid_amount_range' => 'O valor máximo deve ser maior ou igual ao mínimo.',
        'approver_not_eligible' => 'O aprovador deve estar ativo, com permissão de aprovar e perfil Operador ou Administrador.',
        'branch_required' => 'Selecione uma filial válida.',
    ],
];
