<?php

declare(strict_types=1);

return [
    'school-day-default' => [
        'timezone' => 'Europe/Berlin',
        'window' => [
            'type' => 'completed_school_week',
        ],
        'booking_search_days' => 14,
        'retry_count' => 1,
        'max_pdf_duration_ms' => 30000,
    ],
];
