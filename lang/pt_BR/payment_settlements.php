<?php

declare(strict_types=1);

return [
    'label' => 'Baixa',
    'plural' => 'Baixas',
    'navigation_label' => 'Baixas',

    'pages' => [
        'create' => ['title' => 'Nova baixa'],
    ],

    'fields' => [
        'branch' => 'Filial',
        'branch_bank_account' => 'Conta pagadora',
        'bank_code' => 'Banco',
        'status' => 'Status',
        'settlement_date' => 'Data de baixa',
        'items_count' => 'Pagamentos',
        'total_amount' => 'Valor total',
        'notes' => 'Observações',
        'cnab_status' => 'Remessa CNAB',
        'settled_at' => 'Confirmada em',
        'settled_by' => 'Confirmada por',
        'cancelled_at' => 'Cancelada em',
        'cancelled_by' => 'Cancelada por',
        'cancellation_reason' => 'Motivo do cancelamento',
        'amount' => 'Valor',
        'released_at' => 'Liberado em',
        'reason' => 'Motivo',
        'deposit_type' => 'Tipo de depósito',
        'request_status' => 'Status da solicitação',
    ],

    'sections' => [
        'summary' => 'Resumo da baixa',
        'cnab' => 'Remessa CNAB',
        'items' => 'Pagamentos da baixa',
        'cnab_files' => 'Arquivos de remessa',
    ],

    'filters' => [
        'branch' => 'Filial',
        'due_from' => 'Vencimento de',
        'due_until' => 'Vencimento até',
        'settlement_from' => 'Baixa de',
        'settlement_until' => 'Baixa até',
        'payment_method' => 'Forma de pagamento',
        'released' => 'Item liberado',
    ],

    'actions' => [
        'create' => 'Nova baixa',
        'settle_selected' => 'Baixar selecionados',
        'validate_cnab' => 'Validar remessa',
        'generate_cnab' => 'Gerar CNAB',
        'confirm' => 'Confirmar baixa',
        'cancel' => 'Cancelar baixa',
        'release_item' => 'Remover da baixa',
        'open_request' => 'Abrir solicitação',
    ],

    'help' => [
        'settlement_date' => 'Data do pagamento no banco. Para gerar CNAB, deve ser hoje ou futura; para confirmar, hoje ou passada.',
    ],

    'hints' => [
        'cnab_available' => 'CNAB disponível para esta conta.',
        'cnab_unavailable' => 'Sem configuração CNAB ativa: baixa manual.',
    ],

    'messages' => [
        'created' => 'Baixa registrada.',
        'selection_summary' => ':count pagamento(s) selecionado(s), total :total.',
        'no_eligible' => 'Nenhuma solicitação lançada disponível para baixa.',
        'no_cnab' => 'Nenhuma remessa gerada para esta baixa.',
        'no_cnab_short' => 'Sem remessa',
        'confirm_description' => 'Todas as solicitações desta baixa passarão para "Pagamento efetivado".',
        'confirm_without_cnab' => 'Nenhuma remessa gerada para esta baixa. Confirme apenas se o pagamento já foi feito no banco.',
        'confirmed' => 'Baixa confirmada.',
        'cancel_with_generated_file' => 'Existe um arquivo gerado. Ele pode já ter sido enviado ao banco.',
        'cancelled' => 'Baixa cancelada.',
        'item_released' => 'Pagamento removido da baixa.',
        'item_active' => 'Ativo',
        'history_note' => 'Baixa de :date (ref. :id)',
    ],

    'errors' => [
        'empty_selection' => 'Selecione ao menos uma solicitação.',
        'mixed_branches' => 'Selecione solicitações de uma única filial.',
        'not_eligible' => ':count solicitação(ões) não está(ão) mais disponível(is) para baixa.',
        'already_in_settlement' => 'Uma ou mais solicitações já estão em outra baixa.',
        'bank_account_not_allowed' => 'A conta pagadora não pertence à filial.',
        'bank_account_inactive' => 'A conta pagadora está inativa ou sem banco.',
        'too_many_items' => 'Uma baixa aceita no máximo :max pagamentos.',
        'total_amount_overflow' => 'O valor total excede o limite permitido.',
        'not_draft' => 'Esta baixa não está em rascunho.',
        'settlement_date_in_future' => 'Não é possível confirmar uma baixa com data futura.',
        'cnab_in_progress' => 'Há uma remessa CNAB em geração. Aguarde a conclusão.',
        'cnab_already_generated' => 'Já existe remessa gerada. Cancele a baixa ou regenere o arquivo.',
        'items_changed' => ':count pagamento(s) mudou(aram) desde a seleção. Remova-os ou cancele a baixa.',
        'cannot_delete_active' => 'Só é possível excluir baixas canceladas.',
        'unauthorized' => 'Você não tem permissão para esta operação na baixa.',
    ],
];
