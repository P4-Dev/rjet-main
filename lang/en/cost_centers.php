<?php

declare(strict_types=1);

return [
    'label' => 'Cost center',
    'plural' => 'Cost centers',

    'fields' => [
        'branch_id' => 'Branch',
        'branch' => 'Branch',
        'company' => 'Company',
        'code' => 'Code',
        'name' => 'Name',
        'description' => 'Description',
        'sort_order' => 'Sort order',
    ],

    'sections' => [
        'cost_center_info' => 'Cost center details',
    ],

    'errors' => [
        'cannot_delete_with_payment_requests' => 'Cannot delete the cost center while linked payment requests exist.',
    ],
];
