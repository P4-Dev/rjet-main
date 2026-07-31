<?php

declare(strict_types=1);

return [
    'label' => 'Appropriation',
    'plural' => 'Appropriations',

    'fields' => [
        'company_id' => 'Company',
        'company' => 'Company',
        'code' => 'Code',
        'name' => 'Name',
        'description' => 'Description',
        'sort_order' => 'Sort order',
    ],

    'sections' => [
        'appropriation_info' => 'Appropriation details',
    ],

    'errors' => [
        'cannot_delete_with_payment_requests' => 'Cannot delete the appropriation while linked payment requests exist.',
    ],
];
