<?php

declare(strict_types=1);

return [
    'view_payment_request' => 'Ver solicitação',
    'view_import_batch' => 'Ver lote de importação',
    'view_attachment_batch' => 'Ver lote de anexos',
    'view_payment_settlement' => 'Abrir baixa',
    'view_analytical_report' => 'Ver relatório',

    'approval_assigned' => [
        'subject' => 'Nova solicitação aguardando sua aprovação',
        'body' => 'Há uma solicitação de pagamento pendente de aprovação.',
    ],
    'approval_reassigned' => [
        'subject' => 'Aprovação reatribuída a você',
        'body' => 'Uma aprovação pendente foi reatribuída ao seu usuário.',
    ],
    'payment_request_approved' => [
        'subject' => 'Solicitação aprovada',
        'body' => 'Sua solicitação de pagamento foi aprovada.',
    ],
    'payment_request_rejected' => [
        'subject' => 'Solicitação devolvida',
        'body' => 'Sua solicitação de pagamento foi rejeitada. Verifique o motivo e reenvie.',
    ],
    'approval_sla_breached' => [
        'title' => 'SLA de aprovação estourado',
        'body' => 'Uma aprovação pendente ultrapassou o prazo de SLA.',
    ],
    'payment_request_batch_imported' => [
        'subject' => 'Importação em lote concluída',
        'body' => 'Importação concluída: :success sucesso(s), :errors erro(s).',
    ],

    'attachment_batch_renamed' => [
        'subject' => 'Nomenclatura do lote de anexos concluída',
        'body' => ':count arquivo(s) renomeado(s) com o padrão AAAAMMDD_HHMMSS_SEQ.',
    ],

    'cnab_file_generated' => [
        'title' => 'Remessa CNAB pronta',
        'body' => 'Arquivo NSA :sequence com :count pagamento(s), total :total, disponível para download.',
    ],

    'cnab_file_generation_failed' => [
        'title' => 'Falha na remessa CNAB',
        'body' => ':reason',
    ],

    'analytical_report_generated' => [
        'title' => 'Relatório analítico pronto',
        'body' => 'O relatório de :from a :until (:basis) está pronto, com :count linhas.',
    ],

    'analytical_report_generation_failed' => [
        'title' => 'Falha ao gerar o relatório analítico',
        'body' => 'Não foi possível gerar o relatório de :from a :until. :reason',
    ],
];
