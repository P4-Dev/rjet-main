<?php

declare(strict_types=1);

return [
    'title' => 'Painel',

    'filters' => [
        'company' => 'Empresa',
        'branch' => 'Filial',
        'period_start' => 'Início do período',
        'period_end' => 'Fim do período',
        'all' => 'Todas',
    ],

    'stats' => [
        'paid_month' => 'Total pago no mês',
        'paid_period' => 'Total pago no período',
        'paid_description' => ':from – :until · :count pagamentos',
        'pending' => 'Pendentes',
        'pending_description' => ':requested solicitadas · :launched lançadas',
        'upcoming' => 'A vencer',
        'upcoming_description' => ':count até :until',
        'period_closed' => 'Período encerrado',
        'overdue' => 'Vencidos',
        'overdue_description' => ':count vencidos',
    ],

    'charts' => [
        'by_branch' => 'Pagamentos por filial',
        'by_cost_center' => 'Pagamentos por centro de custo',
        'paid' => 'Pago',
        'upcoming' => 'A vencer',
        'overdue' => 'Vencidos',
        'others' => 'Outros',
        'empty' => 'Nenhum pagamento no filtro selecionado',
    ],
];
