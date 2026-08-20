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
        'permissions' => [
            'model' => 'Permiso',
            'models' => 'Permisos',
            'sections' => [
                'identity' => 'El permiso',
                'reach' => 'Hasta dónde llega',
                'holders' => 'Quién lo tiene',
            ],
            'entity' => [
                'none' => 'Ninguna: permiso suelto',
                'any' => 'Cualquier entidad',
            ],
            'columns' => [
                'title' => 'Título',
                'entity' => 'Entidad',
                'provenance' => 'Procedencia',
                'reach' => 'Alcance',
            ],
            'filters' => [
                'held' => 'En poder de alguien',
                'any' => 'Cualquiera',
                'held_yes' => 'En poder de alguien',
                'held_no' => 'Huérfano',
            ],
            'fields' => [
                'name' => 'Nombre',
                'name_help_derived' => 'Lo escribe el método de la Policy que lo declara. Cambiarlo no rompe nada con ruido: desconecta la fila del código que la pregunta.',
                'name_help_loose' => 'Lo eliges tú. Es lo que preguntará can().',
                'taken' => 'El catálogo ya tiene un permiso con este nombre y esta entidad.',
                'title' => 'Título',
                'title_help' => 'Solo para leerlo. Warden lo escribe al crear el permiso y nunca más: renombrarlo deja el viejo en su sitio.',
                'entity' => 'Entidad',
                'entity_help' => 'Sin entidad, el permiso se pregunta suelto, sin nada delante.',
                'only_owned' => 'Solo lo que posee',
                'only_owned_help' => 'Warden resuelve la propiedad con ownedVia().',
                'only_owned_no_model' => 'No hay propiedad que resolver donde no hay entidad.',
                'conditions' => 'Condiciones',
                'conditions_shared' => 'Esta fila está en poder de :count. Es una fila y una regla, así que editarla aquí cambia la regla para todos ellos.',
            ],
            'holders' => [
                'description' => 'Contados, no nombrados. Un permiso puede estar en poder de todas las cuentas de la instalación.',
                'roles' => 'Roles',
                'accounts' => 'Cuentas',
                'everyone' => 'Todo el mundo',
                'forbidden' => 'Prohibido explícitamente',
                'yes' => 'sí',
                'no' => 'no',
            ],
            'delete' => [
                'nobody' => 'Nadie tiene este permiso, así que no se lleva nada por delante.',
                'holders' => 'Esto se lleva las concesiones de :roles rol(es) y :accounts cuenta(s), en la base de datos y sin rastro después: :names.',
            ],
            'probe' => [
                'label' => 'Probarlo',
                'submit' => 'Preguntar al almacén',
                'account' => 'Cuenta',
                'record' => 'Registro',
                'record_help' => 'La clave de la fila que ponerle delante. Déjalo vacío para preguntar por la clase — una regla estrechada no puede casar así.',
            ],
        ],
    ],

    'provenance' => [
        'wildcard' => 'Comodín',
        'policy' => 'De una Policy',
        'loose' => 'Suelto',
        'unknown' => 'Nada lo declara',
    ],

    'reach' => [
        'all' => 'Todas las filas',
        'owned' => 'Solo lo que posee',
        'conditions' => 'Con condiciones',
        'unreadable' => 'No se puede leer',
        'tangled' => 'Más de una regla',
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
        'empty' => 'Pulsa una celda de la rejilla.',
        'title' => 'Por qué',
        'loading' => 'Preguntando a la tienda…',
        'no_permission' => 'ningún permiso',
        'narrowed' => 'Hay una regla estrechada para esta celda. Con condiciones, la concesión solo responde con un registro delante — y una comprobación de clase, como la que hace un listado, falla cerrada.',
        'pending' => 'En pantalla lo has puesto en «:stance». Guarda para que la tienda lo diga.',
    ],

    'stances' => [
        'abstain' => 'se abstiene',
        'granted' => 'concedido',
        'forbidden' => 'prohibido',
    ],

    'probe' => [
        'reach' => [
            'no_model' => 'Este permiso no tiene ningún modelo detrás, así que no cae sobre filas.',
            'no_trait' => 'No se puede contar: [:model] no compone ElPandaPe\\Warden\\Concerns\\QueriesByPermission, y sin él warden no sabe preguntar sobre qué filas cae un permiso. Añade el trait al modelo.',
            'failed' => 'No se pudo contar: :message',
            'counted' => 'Cae sobre :matched de :total filas.',
            'partial' => 'Cae sobre al menos :matched de :total filas. Esta cuenta tiene un rol asignado en un contexto, y la consulta no los ve — el panel contestará por más filas que estas.',
        ],
        'narrowed' => 'Esta regla necesita una fila delante. Preguntada por la clase, warden se la salta y contesta que no casó nada — que se lee exactamente igual que si la regla no existiera. Elige un registro para preguntar de verdad.',
        'no_record' => 'Ninguna fila de esa entidad tiene esa clave, así que no se preguntó nada. No es la misma respuesta que «no casa».',
        'no_model' => 'Este permiso no tiene ningún modelo detrás, así que no hay fila que ponerle delante. Se pregunta sin ella.',
        'unresolved' => 'La entidad a la que apunta este permiso ya no resuelve a ningún modelo. No falla con ruido: simplemente deja de casar con nada.',
        'unreadable' => 'Esta fila no tiene nombre, así que no hay pregunta que hacerle.',
    ],

    'relations' => [
        'roles' => [
            'label' => 'Roles',
            'help' => 'Lo que esta cuenta es. Un rol reparte lo que digan sus permisos en cuanto se da.',
            'protected' => 'No puedes editar este rol, así que tampoco puedes repartirlo.',
            'restricted' => 'Esta cuenta tiene este rol en un contexto. Quitarlo desde aquí se llevaría todos los contextos, así que se deja en paz.',
        ],
    ],

    'console' => [
        'audit' => [
            'open' => 'Pantallas que no deciden quién entra. Filament contesta que sí por ellas, así que están abiertas a cualquiera que llegue al panel.',
            'unpoliced' => 'Recursos cuyo modelo no tiene Policy. Es el caso en el que Filament falla abierto.',
            'orphans' => 'Permisos a los que no apunta ninguna concesión. Nada los consulta. Quien los borra es `warden:clean`.',
            'strays' => 'Concesiones a acciones que ya no declara nadie: un método de Policy renombrado, una errata en un seeder, una pantalla borrada.',
            'drifted' => 'Tipos de entidad que nadie declara. Un alias de morph entero dejó de casar: el mapa se movió y todas sus filas se callaron.',
            'unwalkable' => 'Modelos a los que solo llega un relation manager, y que no se pueden recorrer sin ejecutar la relación.',
            'clean' => 'Nada que informar.',
        ],
        'assign' => [
            'missing_role' => 'No hay ningún rol llamado [:role]. No se creó nada: asignar por nombre habría acuñado uno.',
            'missing_authority' => 'No se pudo leer [:authority]. Se escribe como Clase:id, por ejemplo "App\\Models\\User:1".',
            'done' => 'El rol [:role] ya es de [:authority].',
        ],
    ],

    'conditions' => [
        'scope' => 'Alcance de la regla',
        'if' => 'si',
        'and' => 'y',
        'or' => 'o',
        'authority' => 'cuenta',
        'value' => 'valor',
        'drop' => 'Quitar la condición',
        'add_value' => '+ comparar con un valor',
        'add_column' => '+ comparar con la cuenta',
        'empty' => 'Sin condiciones, esta concesión vuelve a valer para todas las filas.',
        'warning' => 'Con condiciones, esta concesión solo responde con una fila delante. Una comprobación de clase —la que hace un listado al preguntar viewAny— falla cerrado.',
        'no_model' => 'Este permiso no tiene ningún modelo detrás. Una condición aquí quedaría guardada, visible, y no concedería nada nunca.',
        'no_ownership' => 'La tabla :table no tiene la columna :column, que es por donde se resolvería la propiedad.',
        'modes' => [
            'all' => [
                'name' => 'Todas las filas',
                'hint' => 'La concesión vale para cualquier registro de esta entidad.',
            ],
            'owned' => [
                'name' => 'Solo lo que posee',
                'hint' => 'Warden resuelve la propiedad con ownedVia().',
            ],
            'conditions' => [
                'name' => 'Con estas condiciones',
                'hint' => 'Se comparan atributos de la fila. Se guarda como un permiso gemelo propio.',
            ],
        ],
        'locked' => [
            'corrupt' => 'Las condiciones guardadas no se pueden leer. Se enseñan como están y se dejan en paz: reescribirlas sustituiría una regla que nadie puede ver.',
            'empty' => 'La regla guardada lleva un grupo de condiciones vacío: solo responde con una fila delante, y entonces siempre.',
            'shape' => 'Las condiciones guardadas usan una forma que este constructor no sabe dibujar: un grupo anidado, o un valor que no es ni texto ni número.',
            'tangled' => 'Esta celda tiene más de una regla para la misma acción. La rejilla no sabe enseñar eso, así que no la toca.',
        ],
    ],

    'grid' => [
        'panel' => 'El panel',
        'locked' => 'Este rol está protegido: lo que puede hacer no se toca desde aquí.',
        'read_description' => 'Lo que este rol puede hacer, tal como lo tiene la tienda hoy. Pulsa una celda para preguntar por qué.',
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
            'locked' => 'la regla no se cambia desde aquí',
        ],
        'shift' => 'Con Shift el ciclo va hacia atrás.',
    ],
];
