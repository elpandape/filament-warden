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
    */

    'guard' => [
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
        'custom' => [],   // loose permissions declared by the application
        'scopes' => [
            'read' => ['viewAny', 'view'],
            'write' => ['create', 'update'],
            'withdraw' => ['delete', 'restore'],
            'irreversible' => ['forceDelete'],
        ],
    ],

];
