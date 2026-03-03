<?php

declare(strict_types=1);

return [
    [
        'file' => 'application/controllers/Booking.php',
        'methods' => ['register', 'check_datetime_availability', 'get_available_hours', 'get_unavailable_dates'],
    ],
    [
        'file' => 'application/controllers/Dashboard.php',
        'methods' => ['provider_metrics', 'metrics', 'heatmap', 'threshold'],
    ],
    [
        'file' => 'application/controllers/Dashboard_export.php',
        'methods' => ['principal_pdf', 'teacher_pdf', 'teacher_zip'],
    ],
    [
        'file' => 'application/controllers/api/v1/Appointments_api_v1.php',
        'methods' => ['index', 'show'],
    ],
    [
        'file' => 'application/controllers/api/v1/Providers_api_v1.php',
        'methods' => ['index'],
    ],
    [
        'file' => 'application/controllers/api/v1/Services_api_v1.php',
        'methods' => ['index'],
    ],
    [
        'file' => 'application/controllers/api/v1/Availabilities_api_v1.php',
        'methods' => ['get'],
    ],
];
