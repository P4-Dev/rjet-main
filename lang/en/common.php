<?php

declare(strict_types=1);

return [
    'fields' => [
        'name' => 'Name',
        'legal_name' => 'Legal name',
        'document' => 'Tax ID',
        'email' => 'Email',
        'password' => 'Password',
        'is_active' => 'Active',
        'is_default' => 'Default',
        'status' => 'Status',
        'type' => 'Type',
        'created_at' => 'Created at',
        'updated_at' => 'Updated at',
        'deleted_at' => 'Deleted at',
        'created_by' => 'Created by',
        'updated_by' => 'Updated by',
    ],

    'actions' => [
        'create' => 'Create',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'view' => 'View',
        'save' => 'Save',
        'cancel' => 'Cancel',
        'activate' => 'Activate',
        'deactivate' => 'Deactivate',
    ],

    'sections' => [
        'general' => 'General Information',
        'audit' => 'Audit',
    ],

    'messages' => [
        'deleted' => 'Record deleted successfully.',
    ],
];
