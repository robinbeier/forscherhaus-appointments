<?php

declare(strict_types=1);

return [
    'recent_window_size' => 5,
    'baseline_window_size' => 10,
    'min_recent_samples' => 3,
    'min_baseline_samples' => 5,
    'alert_threshold_ratio' => 0.15,
    'min_absolute_increase_seconds' => 300.0,
    'jobs' => [
        [
            'job_name' => 'deep-runtime-suite',
            'min_baseline_median_seconds' => 180.0,
        ],
        [
            'job_name' => 'coverage-shard-unit',
            'min_baseline_median_seconds' => 0.0,
        ],
        [
            'job_name' => 'coverage-shard-integration',
            'min_baseline_median_seconds' => 180.0,
        ],
        [
            'job_name' => 'coverage-delta',
            'min_baseline_median_seconds' => 0.0,
        ],
    ],
];
