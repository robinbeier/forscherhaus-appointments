<?php

declare(strict_types=1);

return [
    [
        'file' => 'application/controllers/Account.php',
        'methods' => ['save', 'validate_username'],
    ],
    [
        'file' => 'application/controllers/Login.php',
        'methods' => ['validate'],
    ],
    [
        'file' => 'application/controllers/Recovery.php',
        'methods' => ['perform'],
    ],
    [
        'file' => 'application/controllers/Localization.php',
        'methods' => ['change_language'],
    ],
    [
        'file' => 'application/controllers/Booking.php',
        'methods' => [
            'index',
            'register',
            'check_datetime_availability',
            'get_available_hours',
            'get_unavailable_dates',
        ],
    ],
    [
        'file' => 'application/controllers/Booking_cancellation.php',
        'methods' => ['of'],
    ],
    [
        'file' => 'application/controllers/Appointments.php',
        'methods' => ['search', 'store', 'find', 'update', 'destroy'],
    ],
    [
        'file' => 'application/controllers/Blocked_periods.php',
        'methods' => ['search', 'store', 'find', 'update', 'destroy'],
    ],
    [
        'file' => 'application/controllers/Unavailabilities.php',
        'methods' => ['search', 'store', 'find', 'update', 'destroy'],
    ],
    [
        'file' => 'application/controllers/Calendar.php',
        'methods' => [
            'index',
            'save_appointment',
            'delete_appointment',
            'save_unavailability',
            'delete_unavailability',
            'save_working_plan_exception',
            'delete_working_plan_exception',
            'get_calendar_appointments_for_table_view',
            'get_calendar_appointments',
        ],
    ],
    [
        'file' => 'application/controllers/Admins.php',
        'methods' => ['search', 'store', 'find', 'update', 'destroy'],
    ],
    [
        'file' => 'application/controllers/Providers.php',
        'methods' => ['search', 'store', 'find', 'update', 'destroy'],
    ],
    [
        'file' => 'application/controllers/Customers.php',
        'methods' => ['find', 'search', 'store', 'update', 'destroy'],
    ],
    [
        'file' => 'application/controllers/Secretaries.php',
        'methods' => ['search', 'store', 'find', 'update', 'destroy'],
    ],
    [
        'file' => 'application/controllers/Services.php',
        'methods' => ['search', 'store', 'find', 'update', 'destroy'],
    ],
    [
        'file' => 'application/controllers/Service_categories.php',
        'methods' => ['search', 'store', 'find', 'update', 'destroy'],
    ],
    [
        'file' => 'application/controllers/Api_settings.php',
        'methods' => ['save'],
    ],
    [
        'file' => 'application/controllers/Booking_settings.php',
        'methods' => ['save'],
    ],
    [
        'file' => 'application/controllers/Business_settings.php',
        'methods' => ['save', 'apply_global_working_plan'],
    ],
    [
        'file' => 'application/controllers/General_settings.php',
        'methods' => ['save'],
    ],
    [
        'file' => 'application/controllers/Google_analytics_settings.php',
        'methods' => ['save'],
    ],
    [
        'file' => 'application/controllers/Matomo_analytics_settings.php',
        'methods' => ['save'],
    ],
    [
        'file' => 'application/controllers/Legal_settings.php',
        'methods' => ['save'],
    ],
    [
        'file' => 'application/controllers/Consents.php',
        'methods' => ['save'],
    ],
    [
        'file' => 'application/controllers/Privacy.php',
        'methods' => ['delete_personal_information'],
    ],
    [
        'file' => 'application/controllers/Ldap_settings.php',
        'methods' => ['save', 'search'],
    ],
    [
        'file' => 'application/controllers/Caldav.php',
        'methods' => ['connect_to_server', 'disable_provider_sync'],
    ],
    [
        'file' => 'application/controllers/Google.php',
        'methods' => ['oauth_callback', 'get_google_calendars', 'select_google_calendar', 'disable_provider_sync'],
    ],
    [
        'file' => 'application/controllers/Webhooks.php',
        'methods' => ['search', 'store', 'update', 'destroy', 'find'],
    ],
    [
        'file' => 'application/controllers/api/v1/Admins_api_v1.php',
        'methods' => ['index', 'show', 'store', 'update', 'destroy'],
    ],
    [
        'file' => 'application/controllers/api/v1/Appointments_api_v1.php',
        'methods' => ['index', 'show', 'store', 'update', 'destroy'],
    ],
    [
        'file' => 'application/controllers/api/v1/Availabilities_api_v1.php',
        'methods' => ['get'],
    ],
    [
        'file' => 'application/controllers/api/v1/Blocked_periods_api_v1.php',
        'methods' => ['index', 'show', 'store', 'update', 'destroy'],
    ],
    [
        'file' => 'application/controllers/api/v1/Customers_api_v1.php',
        'methods' => ['index', 'show', 'store', 'update', 'destroy'],
    ],
    [
        'file' => 'application/controllers/api/v1/Providers_api_v1.php',
        'methods' => ['index', 'show', 'store', 'update', 'destroy'],
    ],
    [
        'file' => 'application/controllers/api/v1/Secretaries_api_v1.php',
        'methods' => ['index', 'show', 'store', 'update', 'destroy'],
    ],
    [
        'file' => 'application/controllers/api/v1/Service_categories_api_v1.php',
        'methods' => ['index', 'show', 'store', 'update', 'destroy'],
    ],
    [
        'file' => 'application/controllers/api/v1/Services_api_v1.php',
        'methods' => ['index', 'show', 'store', 'update', 'destroy'],
    ],
    [
        'file' => 'application/controllers/api/v1/Settings_api_v1.php',
        'methods' => ['index', 'show', 'update'],
    ],
    [
        'file' => 'application/controllers/api/v1/Unavailabilities_api_v1.php',
        'methods' => ['index', 'show', 'store', 'update', 'destroy'],
    ],
    [
        'file' => 'application/controllers/api/v1/Webhooks_api_v1.php',
        'methods' => ['index', 'show', 'store', 'update', 'destroy'],
    ],
];
