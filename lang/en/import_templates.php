<?php

declare(strict_types=1);

return [
    'label' => 'Import template',
    'plural' => 'Import templates',
    'navigation_label' => 'Import templates',

    'fields' => [
        'name' => 'Name',
        'company_id' => 'Target company',
        'branch_id' => 'Target branch',
        'accepted_format' => 'Accepted format',
        'current_version' => 'Current version',
        'mappings' => 'Mappings',
        'source_column' => 'Spreadsheet column',
        'target_field' => 'Target field',
        'default_value' => 'Default value',
        'version' => 'Version',
        'is_current' => 'Current',
        'published_at' => 'Published at',
    ],

    'sections' => [
        'general' => 'General information',
        'mappings' => 'Column mappings',
        'versions' => 'Versions',
    ],

    'hints' => [
        'branch_default' => 'Default branch used when the spreadsheet has no branch column.',
    ],

    'actions' => [
        'add_mapping' => 'Add mapping',
        'publish_version' => 'Publish new version',
    ],

    'messages' => [
        'created' => 'Import template created.',
        'updated' => 'Template updated.',
        'deleted' => 'Template deleted.',
        'version_published' => 'New version published.',
    ],

    'errors' => [
        'inactive' => 'Template is inactive or deleted.',
        'missing_required_mappings' => 'Map or set a default for all required fields.',
        'version_immutable' => 'Published version mappings cannot be edited. Publish a new version.',
        'version_in_use' => 'This version has linked batches and cannot be deleted.',
    ],
];
