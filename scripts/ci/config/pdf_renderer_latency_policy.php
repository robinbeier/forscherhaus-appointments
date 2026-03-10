<?php

declare(strict_types=1);

return [
    'min_samples' => 5,
    'warn' => [
        'p50_ms' => 3000.0,
        'p95_ms' => 4500.0,
    ],
    'fail' => [
        'p50_ms' => 3500.0,
        'p95_ms' => 6500.0,
    ],
    'max_stddev_ms' => 1200.0,
];
