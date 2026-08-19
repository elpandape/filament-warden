<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | The permissions screen
    |--------------------------------------------------------------------------
    |
    | Conservative by default: a permission no policy declares is one nothing
    | consults, so a fresh install cannot create orphans until somebody decides
    | to let it. Opening this up later is one line; closing it later means
    | cleaning up whatever was already created.
    |
    */

    'permissions' => [
        'create' => false,          // manual creation of permissions
        'update' => 'loose',        // false | 'title' | 'loose' | 'all'
        'delete' => 'orphaned',     // false | 'orphaned' | 'all'
        'constraints' => true,      // the condition builder
        'only_owned' => true,       // the ownership checkbox
        'probe' => true,            // the test bench, built on explain()
    ],

    /*
    |--------------------------------------------------------------------------
    | Navigation
    |--------------------------------------------------------------------------
    |
    | Left null, the group falls back to this package's own translated one and
    | the icon to a shield. The slug is what the URL says.
    |
    */

    'navigation' => [
        'group' => null,
        'roles' => [
            'slug' => 'roles',
            'icon' => null,
            'sort' => null,
        ],
    ],

    'roles' => [
        'create' => true,
        'delete' => 'unassigned',   // false | 'unassigned' | 'all'
        'protected' => ['super-admin'],
    ],

    /*
    |--------------------------------------------------------------------------
    | The grid
    |--------------------------------------------------------------------------
    |
    | `constraints` appears here as well as above on purpose: they are two
    | separate decisions. Conditions can be defined only on a permission's own
    | screen, where they are seen whole, leaving the grid to hand things out.
    |
    */

    'grid' => [
        'explain' => true,
        'constraints' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | The guard
    |--------------------------------------------------------------------------
    |
    | Filament's own `canAccess()` and `canView()` return true, and
    | `strictAuthorization()` only reaches resources.
    |
    | `panel` overrides the permission that opens a panel, keyed by panel id.
    | Left empty, the name is derived from the id: a panel called `admin` is
    | opened by `panel:admin`. An installation that already stores another name
    | maps it here instead of renaming rows.
    |
    */

    'guard' => [
        'panel' => [],
        'pages' => true,
        'widgets' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | The catalogue
    |--------------------------------------------------------------------------
    */

    'catalog' => [
        'models' => [],   // models with a policy and no resource
        'custom' => [],   // loose permissions, as name => scope
        'scopes' => [
            'read' => ['viewAny', 'view'],
            'write' => ['create', 'update'],
            'withdraw' => ['delete', 'deleteAny', 'restore', 'restoreAny'],
            'irreversible' => ['forceDelete', 'forceDeleteAny'],
        ],
    ],

];
