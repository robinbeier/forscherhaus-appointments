<?php

declare(strict_types=1);

return [
    'cohort_size' => 7,
    'minimum_samples' => 5,
    'percentile_method' => 'nearest_rank',
    'required_success_jobs' => [
        'changes',
        'deep-check-bootstrap',
        'deep-check-seed-snapshot',
        'deep-runtime-suite',
        'coverage-shard-unit',
        'coverage-shard-integration',
        'coverage-delta',
    ],
    'tracked_jobs' => [
        'changes',
        'deep-check-bootstrap',
        'deep-check-seed-snapshot',
        'deep-runtime-suite',
        'coverage-shard-unit',
        'coverage-shard-integration',
        'coverage-delta',
    ],
    'critical_path_jobs' => [
        'changes',
        'deep-check-bootstrap',
        'deep-check-seed-snapshot',
        'coverage-shard-integration',
        'coverage-delta',
    ],
];
