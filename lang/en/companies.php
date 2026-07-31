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
    ],

    'hints' => [
        'is_appropriation_required' => 'When enabled, appropriation becomes required when creating payment requests for this company.',
    ],

    'sections' => [
        'company_info' => 'Company details',
    ],
];
