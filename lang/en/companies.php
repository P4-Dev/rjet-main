<?php

declare(strict_types=1);

return [
    'label' => 'Company',
    'plural' => 'Companies',

    'fields' => [
        'name' => 'Name',
        'legal_name' => 'Legal name',
        'document' => 'Tax ID',
        'branches_count' => 'Branches',
        'is_appropriation_required' => 'Require appropriation on payment requests',
        'approval_sla_business_days' => 'Approval SLA (business days)',
    ],

    'hints' => [
        'is_appropriation_required' => 'When enabled, appropriation becomes required when creating payment requests for this company.',
        'approval_sla_business_days' => 'Deadline in business days (Mon–Fri) for the approver to decide. Does not recalculate already pending approvals.',
    ],

    'suffixes' => [
        'business_days' => 'business days',
    ],

    'sections' => [
        'company_info' => 'Company details',
    ],
];
