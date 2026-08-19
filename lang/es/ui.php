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
            'sections' => [
                'identity' => 'El rol',
            ],
            'fields' => [
                'name' => 'Nombre',
                'name_help' => 'Como lo nombra tu código. Las concesiones apuntan aquí.',
                'title_help' => 'Lo que la gente lee en pantalla.',
                'title' => 'Título',
            ],
            'columns' => [
                'name' => 'Nombre',
                'title' => 'Título',
            ],
        ],
    ],

    'tabs' => [
        'resources' => 'Recursos',
        'pages' => 'Páginas',
        'widgets' => 'Widgets',
        'loose' => 'Sueltos',
    ],

    'scopes' => [
        'read' => 'Lectura',
        'write' => 'Escritura',
        'withdraw' => 'Retirada',
        'irreversible' => 'Sin vuelta',
    ],

    'actions' => [
        'viewAny' => 'Listar',
        'view' => 'Ver',
        'create' => 'Crear',
        'update' => 'Editar',
        'delete' => 'Borrar',
        'deleteAny' => 'Borrar cualquiera',
        'restore' => 'Restaurar',
        'restoreAny' => 'Restaurar cualquiera',
        'forceDelete' => 'Borrar del todo',
        'forceDeleteAny' => 'Borrar del todo cualquiera',
        'reorder' => 'Reordenar',
        'replicate' => 'Duplicar',
    ],

    'explain' => [
        'causes' => [
            'granted-directly' => 'Concedido por el permiso :permission, en poder del propio rol.',
            'granted-via-role' => 'Concedido por el permiso :permission, a través del rol :role.',
            'granted-to-everyone' => 'Concedido por el permiso :permission, dado a todo el mundo.',
            'forbidden-directly' => 'Prohibido explícitamente por el permiso :permission, en poder del propio rol.',
            'forbidden-via-role' => 'Prohibido explícitamente por el permiso :permission, a través del rol :role.',
            'forbidden-to-everyone' => 'Prohibido explícitamente por el permiso :permission, aplicado a todo el mundo.',
            'no-matching-grant' => 'Ninguna concesión casa. Warden se abstiene y deciden las Policies de tu aplicación.',
            'not-applicable' => 'No es una pregunta para warden: la entidad no es un modelo.',
        ],
        'no_permission' => 'ningún permiso',
        'narrowed' => 'Hay una regla estrechada para esta celda. Con condiciones, la concesión solo responde con un registro delante — y una comprobación de clase, como la que hace un listado, falla cerrada.',
        'pending' => 'En pantalla lo has puesto en «:stance». Guarda para que la tienda lo diga.',
    ],

    'stances' => [
        'abstain' => 'se abstiene',
        'granted' => 'concedido',
        'forbidden' => 'prohibido',
    ],

    'grid' => [
        'panel' => 'El panel',
        'locked' => 'Este rol está protegido: lo que puede hacer no se toca desde aquí.',
        'description' => 'Una fila por entidad y una columna por acción que su Policy declara. Pulsa una celda para ciclarla; con Shift, hacia atrás.',
        'label' => 'Permisos',
        'entity' => 'Entidad',
        'manage' => 'Todo',
        'undeclared' => 'La Policy no declara esta acción',
        'presets' => [
            'read' => 'leer',
            'all' => 'todo',
            'clear' => 'nada',
        ],
        'wider' => 'Este rol tiene una regla más amplia de lo que la rejilla puede enseñar, así que cada celda de abajo ya está contestada:',
        'legend' => [
            'abstains' => 'el rol se abstiene',
            'granted' => 'concedido',
            'forbidden' => 'prohibido',
            'broader' => 'lo alcanza una regla más amplia',
            'undeclared' => 'la Policy no la declara',
            'narrowed' => 'la regla está estrechada',
        ],
        'shift' => 'Con Shift el ciclo va hacia atrás.',
    ],
];
