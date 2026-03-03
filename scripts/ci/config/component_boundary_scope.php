<?php

declare(strict_types=1);

return [
    'diff_env_var' => 'COMPONENT_BOUNDARY_DIFF_RANGE',
    'scope_prefixes' => ['application/controllers/', 'application/libraries/', 'application/models/'],
    'loader_roots' => [
        'model' => 'application/models/',
        'library' => 'application/libraries/',
        'helper' => 'application/helpers/',
    ],
];
