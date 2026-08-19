<?php

declare(strict_types=1);

return [
    'navigation' => [
        'group' => 'Security',
    ],

    'resources' => [
        'roles' => [
            'model' => 'Role',
            'models' => 'Roles',
            'sections' => [
                'identity' => 'The role',
            ],
            'fields' => [
                'name' => 'Name',
                'name_help' => 'How your code names it. Grants point at it.',
                'title_help' => 'What people read on screen.',
                'title' => 'Title',
            ],
            'columns' => [
                'name' => 'Name',
                'title' => 'Title',
            ],
        ],
    ],

    'tabs' => [
        'resources' => 'Resources',
        'pages' => 'Pages',
        'widgets' => 'Widgets',
        'loose' => 'Loose',
    ],

    'scopes' => [
        'read' => 'Read',
        'write' => 'Write',
        'withdraw' => 'Withdraw',
        'irreversible' => 'No way back',
    ],

    'actions' => [
        'viewAny' => 'List',
        'view' => 'View',
        'create' => 'Create',
        'update' => 'Edit',
        'delete' => 'Delete',
        'deleteAny' => 'Delete any',
        'restore' => 'Restore',
        'restoreAny' => 'Restore any',
        'forceDelete' => 'Delete for good',
        'forceDeleteAny' => 'Delete any for good',
        'reorder' => 'Reorder',
        'replicate' => 'Duplicate',
    ],

    'grid' => [
        'panel' => 'The panel',
        'locked' => 'This role is protected: what it can do is fixed here.',
        'description' => 'One row per entity, one column per action its policy declares. Click a cell to cycle it; hold shift to go backwards.',
        'label' => 'Permissions',
        'entity' => 'Entity',
        'manage' => 'Everything',
        'undeclared' => 'The policy does not declare this action',
        'presets' => [
            'read' => 'read',
            'all' => 'all',
            'clear' => 'none',
        ],
        'wider' => 'This role holds a rule wider than the grid can show, so every cell below is already answered:',
        'legend' => [
            'abstains' => 'the role abstains',
            'granted' => 'granted',
            'forbidden' => 'forbidden',
            'broader' => 'reached by a broader rule',
            'undeclared' => 'the policy does not declare it',
            'narrowed' => 'the rule is narrowed',
        ],
        'shift' => 'Hold shift to walk the cycle backwards.',
    ],
];
