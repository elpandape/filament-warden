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

    'explain' => [
        'causes' => [
            'granted-directly' => 'Granted by the permission :permission, held by the role itself.',
            'granted-via-role' => 'Granted by the permission :permission, through the role :role.',
            'granted-to-everyone' => 'Granted by the permission :permission, given to everyone.',
            'forbidden-directly' => 'Explicitly forbidden by the permission :permission, held by the role itself.',
            'forbidden-via-role' => 'Explicitly forbidden by the permission :permission, through the role :role.',
            'forbidden-to-everyone' => 'Explicitly forbidden by the permission :permission, applied to everyone.',
            'no-matching-grant' => 'No grant matches. Warden abstains and your application policies decide.',
            'not-applicable' => 'Not a question for warden: the entity is not a model.',
        ],
        'empty' => 'Click a cell of the grid.',
        'title' => 'Why',
        'loading' => 'Asking the store…',
        'no_permission' => 'no permission',
        'narrowed' => 'There is a narrowed rule for this cell. With conditions, a grant only answers with a record in front of it — and a class check, like the one a listing makes, fails closed.',
        'pending' => 'On screen you have set this to «:stance». Save for the store to say so.',
    ],

    'stances' => [
        'abstain' => 'abstains',
        'granted' => 'granted',
        'forbidden' => 'forbidden',
    ],

    'conditions' => [
        'scope' => 'Rule scope',
        'if' => 'if',
        'and' => 'and',
        'or' => 'or',
        'authority' => 'account',
        'value' => 'value',
        'drop' => 'Remove the condition',
        'add_value' => '+ compare with a value',
        'add_column' => '+ compare with the account',
        'empty' => 'With no conditions this grant is back to every row.',
        'warning' => 'With conditions, this grant only answers with a record in front of it. A class check — the one a listing makes when it asks viewAny — fails closed.',
        'no_model' => 'This permission has no model behind it. A condition on it would be stored, shown, and would never grant anything.',
        'no_ownership' => 'The table :table has no :column column, which is where ownership would resolve.',
        'modes' => [
            'all' => [
                'name' => 'Every row',
                'hint' => 'The grant holds for any record of this entity.',
            ],
            'owned' => [
                'name' => 'Only what it owns',
                'hint' => 'Warden resolves ownership with ownedVia().',
            ],
            'conditions' => [
                'name' => 'With these conditions',
                'hint' => 'Attributes of the row are compared. Stored as a twin permission of its own.',
            ],
        ],
        'locked' => [
            'corrupt' => 'The stored conditions cannot be read. They are shown as they are and left alone: rewriting them would replace a rule nobody can see.',
            'empty' => 'The stored rule carries an empty condition group: it answers only with a record in front of it, and then always.',
            'shape' => 'The stored conditions use a shape this builder cannot draw — a nested group, or a value that is neither text nor a number.',
            'tangled' => 'This cell holds more than one rule for the same action. The grid cannot show that, so it does not touch it.',
        ],
    ],

    'grid' => [
        'panel' => 'The panel',
        'locked' => 'This role is protected: what it can do is fixed here.',
        'read_description' => 'What this role can do, as the store has it today. Click a cell to ask why.',
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
            'locked' => 'the rule cannot be changed here',
        ],
        'shift' => 'Hold shift to walk the cycle backwards.',
    ],
];
