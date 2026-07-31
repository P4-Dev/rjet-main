<?php

declare(strict_types=1);

return [
    'label' => 'Branch',
    'plural' => 'Branches',

    'fields' => [
        'company_id' => 'Company',
        'name' => 'Name',
        'legal_name' => 'Legal name',
        'document' => 'Tax ID',
        'bank_accounts_count' => 'Bank accounts',
    ],

    'sections' => [
        'branch_info' => 'Branch details',
    ],

    'filters' => [
        'company' => 'Company',
    ],

    'errors' => [
        'cannot_delete_with_bank_accounts' => 'A branch that still has bank accounts cannot be deleted. Remove the accounts before deleting the branch.',
        'cannot_delete_with_payment_requests' => 'Cannot delete the branch while linked payment requests exist.',
    ],
];
