<?php

declare(strict_types=1);

return [
    'title' => 'Dashboard',

    'filters' => [
        'company' => 'Company',
        'branch' => 'Branch',
        'period_start' => 'Period start',
        'period_end' => 'Period end',
        'all' => 'All',
    ],

    'stats' => [
        'paid_month' => 'Total paid this month',
        'paid_period' => 'Total paid in the period',
        'paid_description' => ':from – :until · :count payments',
        'pending' => 'Pending',
        'pending_description' => ':requested requested · :launched launched',
        'upcoming' => 'Upcoming',
        'upcoming_description' => ':count until :until',
        'period_closed' => 'Period closed',
        'overdue' => 'Overdue',
        'overdue_description' => ':count overdue',
    ],

    'charts' => [
        'by_branch' => 'Payments by branch',
        'by_cost_center' => 'Payments by cost center',
        'paid' => 'Paid',
        'upcoming' => 'Upcoming',
        'overdue' => 'Overdue',
        'others' => 'Others',
        'empty' => 'No payments for the selected filter',
    ],
];
