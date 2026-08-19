<?php

declare(strict_types=1);

return [
    'navigation' => [
        'group' => 'Seguridad',
    ],

    'resources' => [
        'roles' => [
            'model' => 'Rol',
            'models' => 'Roles',
            'fields' => [
                'name' => 'Nombre',
                'title' => 'Título',
            ],
            'columns' => [
                'name' => 'Nombre',
                'title' => 'Título',
            ],
        ],
    ],

    'grid' => [
        'label' => 'Permisos',
        'entity' => 'Entidad',
        'manage' => 'Todo',
        'undeclared' => 'La Policy no declara esta acción',
        'presets' => [
            'read' => 'leer',
            'all' => 'todo',
            'clear' => 'nada',
        ],
        'legend' => [
            'abstains' => 'caja vacía: el rol se abstiene',
            'granted' => 'tick: concedido',
            'forbidden' => 'aspa: prohibido',
            'broader' => 'trazo discontinuo: lo alcanza una regla más amplia',
            'undeclared' => 'un punto: la Policy no la declara',
            'narrowed' => 'marca ámbar: la regla está estrechada',
        ],
        'shift' => 'Con Shift el ciclo va hacia atrás.',
    ],
];
