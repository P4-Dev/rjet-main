<?php

declare(strict_types=1);

return [
    'label' => 'User',
    'plural' => 'Users',

    'fields' => [
        'name' => 'Name',
        'email' => 'Email',
        'password' => 'Password',
        'role' => 'Role',
        'can_approve' => 'Can approve',
        'branches' => 'Branches',
        'default_branch' => 'Default branch',
    ],

    'sections' => [
        'user_info' => 'User details',
        'branches' => 'Branches',
    ],

    'errors' => [
        'cannot_delete_self' => 'An administrator cannot delete or deactivate their own account.',
        'cannot_delete_last_admin' => 'The last active administrator cannot be deleted or deactivated.',
    ],
];
