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
            'fields' => [
                'name' => 'Name',
                'title' => 'Title',
            ],
            'columns' => [
                'name' => 'Name',
                'title' => 'Title',
            ],
        ],
    ],

    'grid' => [
        'label' => 'Permissions',
        'entity' => 'Entity',
        'manage' => 'Everything',
        'undeclared' => 'The policy does not declare this action',
        'presets' => [
            'read' => 'read',
            'all' => 'all',
            'clear' => 'none',
        ],
        'legend' => [
            'abstains' => 'empty box: the role abstains',
            'granted' => 'tick: granted',
            'forbidden' => 'cross: forbidden',
            'broader' => 'dashed: reached by a broader rule',
            'undeclared' => 'a dot: the policy does not declare it',
            'narrowed' => 'amber mark: the rule is narrowed',
        ],
        'shift' => 'Hold shift to walk the cycle backwards.',
    ],
];
