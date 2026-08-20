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
        'permissions' => [
            'model' => 'Permission',
            'models' => 'Permissions',
            'sections' => [
                'identity' => 'The permission',
                'reach' => 'How far it reaches',
                'holders' => 'Who holds it',
            ],
            'entity' => [
                'none' => 'None: a loose permission',
                'any' => 'Any entity',
            ],
            'columns' => [
                'title' => 'Title',
                'entity' => 'Entity',
                'provenance' => 'Provenance',
                'reach' => 'Reach',
            ],
            'filters' => [
                'held' => 'Held by somebody',
                'any' => 'Any',
                'held_yes' => 'Held',
                'held_no' => 'Orphaned',
            ],
            'fields' => [
                'name' => 'Name',
                'name_help_derived' => 'The policy method that declares it writes this. Changing it does not break anything loudly — it disconnects the row from the code that asks for it.',
                'name_help_loose' => 'You choose it. It is what can() will ask for.',
                'taken' => 'The catalogue already has a permission with this name and entity.',
                'title' => 'Title',
                'title_help' => 'Only for reading. Warden writes it when the permission is created, and never again — a rename leaves the old one in place.',
                'entity' => 'Entity',
                'entity_help' => 'With no entity the permission is asked loose, with nothing in front of it.',
                'only_owned' => 'Only what it owns',
                'only_owned_help' => 'Warden resolves ownership with ownedVia().',
                'only_owned_no_model' => 'There is no ownership to resolve where there is no entity.',
                'conditions' => 'Conditions',
                'conditions_shared' => 'This row is held :count times over. It is one row and one rule, so editing it here changes the rule for every one of them.',
            ],
            'holders' => [
                'description' => 'Counted, not named. A permission can be held by every account in the installation.',
                'roles' => 'Roles',
                'accounts' => 'Accounts',
                'everyone' => 'Everyone',
                'forbidden' => 'Explicitly forbidden',
                'yes' => 'yes',
                'no' => 'no',
            ],
            'delete' => [
                'nobody' => 'Nobody holds this permission, so nothing goes with it.',
                'holders' => 'This takes the grants of :roles role(s) and :accounts account(s) with it, in the database and with no trace afterwards: :names.',
            ],
            'probe' => [
                'label' => 'Test it',
                'submit' => 'Ask the store',
                'account' => 'Account',
                'record' => 'Record',
                'record_help' => 'The key of the row to put in front of it. Leave it empty to ask about the class — a narrowed rule can never match that way.',
            ],
        ],
    ],

    'provenance' => [
        'wildcard' => 'Wildcard',
        'policy' => 'From a policy',
        'loose' => 'Loose',
        'unknown' => 'Nothing declares it',
    ],

    'reach' => [
        'all' => 'Every row',
        'owned' => 'Only what it owns',
        'conditions' => 'With conditions',
        'unreadable' => 'Cannot be read',
        'tangled' => 'More than one rule',
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

    'probe' => [
        'reach' => [
            'no_model' => 'This permission has no model behind it, so it does not fall on rows at all.',
            'no_trait' => 'Cannot be counted: [:model] does not compose ElPandaPe\\Warden\\Concerns\\QueriesByPermission, and without it warden has no way to ask which rows a permission reaches. Add the trait to the model.',
            'failed' => 'Could not be counted: :message',
            'counted' => 'It falls on :matched of :total rows.',
            'partial' => 'It falls on at least :matched of :total rows. This account holds a role in a context, and the query cannot see those — the panel itself will answer for more rows than this.',
        ],
        'narrowed' => 'This rule needs a record in front of it. Asked about the class, warden skips it and answers that nothing matched — which reads exactly like the rule not being there. Choose a record to ask properly.',
        'no_record' => 'No row of that entity has that key, so nothing was asked. This is not the same answer as nothing matching.',
        'no_model' => 'This permission has no model behind it, so there is no row to put in front of it. It is asked without one.',
        'unresolved' => 'The entity this permission points at no longer resolves to a model. It does not fail loudly: it simply stops matching anything.',
        'unreadable' => 'This row carries no name, so there is no question to ask of it.',
    ],

    'relations' => [
        'roles' => [
            'label' => 'Roles',
            'help' => 'What this account is. A role hands out whatever its permissions say, the moment it is given.',
            'protected' => 'You cannot edit this role, so you cannot hand it out either.',
            'restricted' => 'This account holds this role in a context. Taking it back from here would take every context with it, so it is left alone.',
        ],
    ],

    'console' => [
        'audit' => [
            'open' => 'Screens that do not decide who gets in. Filament answers true for these, so they are open to anybody who reaches the panel.',
            'unpoliced' => 'Resources whose model has no policy. This is the case Filament fails open on.',
            'orphans' => 'Permissions no grant points at. Nothing consults them. `warden:clean` is what removes them.',
            'strays' => 'Grants for actions nothing declares any more — a renamed policy method, a typo in a seeder, a screen that was deleted.',
            'drifted' => 'Entity types nothing declares at all. A whole morph alias stopped matching: the map moved, and every row of it went quiet.',
            'unwalkable' => 'Models only a relation manager reaches, which cannot be walked without running the relationship.',
            'clean' => 'Nothing to report.',
        ],
        'assign' => [
            'missing_role' => 'No role named [:role]. Nothing was created: assigning by name would have minted one.',
            'missing_authority' => 'Could not read [:authority]. Give it as Class:id, for example "App\\Models\\User:1".',
            'done' => 'The role [:role] now belongs to [:authority].',
        ],
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
            'elsewhere' => 'This grant belongs to another tenant, and a write targets one tenant at a time. Switching it off here would delete nothing and still report success, so it is left alone. Change tenant to touch it.',
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
