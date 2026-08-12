<?php

declare(strict_types=1);

return [
    'view_payment_request' => 'Ver solicitação',

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
];
