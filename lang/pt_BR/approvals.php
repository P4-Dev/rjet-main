<?php

declare(strict_types=1);

return [
    'label' => 'Aprovação',
    'plural' => 'Aprovações',

    'fields' => [
        'status' => 'Status',
        'approver' => 'Aprovador',
        'reason' => 'Motivo',
        'amount_snapshot' => 'Valor (snapshot)',
        'assigned_at' => 'Atribuída em',
        'due_at' => 'Prazo SLA',
        'decided_at' => 'Decidida em',
        'decided_by' => 'Decidida por',
        'escalated_at' => 'Escalada em',
        'reassignments' => 'Reatribuições',
        'material_fingerprint' => 'Fingerprint material',
    ],

    'errors' => [
        'no_matching_rule' => 'Nenhuma regra de alçada ativa corresponde a esta solicitação. Cadastre uma faixa ou ajuste o valor/filial.',
        'not_pending' => 'Esta aprovação não está pendente.',
        'unauthorized_approver' => 'Você não tem permissão para decidir esta aprovação.',
        'reason_required' => 'Informe o motivo da rejeição (mínimo de 5 caracteres).',
        'already_pending' => 'Já existe uma aprovação pendente para esta solicitação.',
        'cannot_resubmit' => 'Não é possível reenviar esta solicitação para aprovação no estado atual.',
        'sla_not_configured' => 'O SLA de aprovação da empresa é inválido.',
        'fingerprint_mismatch' => 'Os dados materiais mudaram após a aprovação. Reenvie para aprovação.',
    ],
];
