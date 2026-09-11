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
        // A permission handed straight to an account belongs to no role, so no
        // role's grid draws it and nothing but this screen names it again. Off
        // out of the box because turning it on does not only SHOW those grants
        // — it hands them out.
        'direct' => false,          // the direct-grants relation manager
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
        'permissions' => [
            'slug' => 'permissions',
            'icon' => null,
            'sort' => null,
        ],
    ],

    'roles' => [
        'create' => true,
        'delete' => 'unassigned',   // false | 'unassigned' | 'all'
        // A protected role keeps its name and its permissions: both are shown
        // and neither can be edited, and it cannot be deleted. Its title is left
        // editable — nothing resolves by it.
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
    | `expiry` decides whether the grid may SET an end date. It never decides
    | whether one is honoured: warden stops reading a row past its date whatever
    | this says, so a grid with this off still draws a lapsed cell as the
    | abstention it is. Switching it off makes the screen answer "no opinion"
    | rather than "no date", so the store keeps every end date whatever the
    | browser sends back.
    |
    */

    'grid' => [
        'explain' => true,
        'constraints' => true,
        'expiry' => true,
        // The class name under each entity — `App\Models\Post` under "Posts".
        // Off by default: in most installations the namespace repeats on every
        // row and tells none apart, so it is noise in the most-read column.
        //
        // Turning it off loses nothing, it only moves to the hover: the class
        // stays in the row's `title`. Turn it on where two models share a label
        // — `App\Models\User` and `App\Models\Security\User` are both "Users" —
        // because a permission is stored against the CLASS, and without it
        // those two rows read the same.
        'class_names' => false,
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
