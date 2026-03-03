<?php

declare(strict_types=1);

return [
    'read_only' => true,
    'checks' => [
        [
            'id' => 'appointments_unauthorized',
            'method' => 'GET',
            'openapi_path' => '/appointments',
            'request_path' => 'api/v1/appointments',
            'query' => [
                'length' => 1,
                'page' => 1,
            ],
            'auth' => 'none',
            'expected_status' => 401,
            'require_www_authenticate' => true,
        ],
        [
            'id' => 'appointments_index_contract',
            'method' => 'GET',
            'openapi_path' => '/appointments',
            'request_path' => 'api/v1/appointments',
            'query' => [
                'length' => 1,
                'page' => 1,
            ],
            'auth' => 'basic',
            'expected_status' => 200,
            'allow_empty_items' => true,
            'required_fields' => ['id', 'start', 'end', 'providerId', 'serviceId', 'customerId', 'status'],
            'item_schema_ref' => '#/components/schemas/AppointmentRecord',
            'capture_id_to' => 'appointment_id',
        ],
        [
            'id' => 'appointments_show_contract',
            'method' => 'GET',
            'openapi_path' => '/appointments/{appointmentId}',
            'request_path_template' => 'api/v1/appointments/{appointmentId}',
            'path_params' => [
                'appointmentId' => '@state.appointment_id',
            ],
            'skip_if_state_missing' => ['appointment_id'],
            'auth' => 'basic',
            'expected_status' => 200,
            'required_fields' => ['id', 'start', 'end', 'providerId', 'serviceId', 'customerId', 'status'],
            'object_schema_ref' => '#/components/schemas/AppointmentRecord',
        ],
        [
            'id' => 'providers_index_contract',
            'method' => 'GET',
            'openapi_path' => '/providers',
            'request_path' => 'api/v1/providers',
            'query' => [
                'length' => 1,
                'page' => 1,
            ],
            'auth' => 'basic',
            'expected_status' => 200,
            'required_fields' => ['id', 'firstName', 'lastName', 'email', 'isPrivate'],
            'item_schema_ref' => '#/components/schemas/ProviderRecord',
            'capture_id_to' => 'provider_id',
        ],
        [
            'id' => 'services_index_contract',
            'method' => 'GET',
            'openapi_path' => '/services',
            'request_path' => 'api/v1/services',
            'query' => [
                'length' => 1,
                'page' => 1,
            ],
            'auth' => 'basic',
            'expected_status' => 200,
            'required_fields' => ['id', 'name', 'duration', 'attendantsNumber', 'availabilitiesType'],
            'item_schema_ref' => '#/components/schemas/ServiceRecord',
            'capture_id_to' => 'service_id',
        ],
        [
            'id' => 'availabilities_contract',
            'method' => 'GET',
            'openapi_path' => '/availabilities',
            'request_path' => 'api/v1/availabilities',
            'query' => [
                'providerId' => '@state.provider_id',
                'serviceId' => '@state.service_id',
                'date' => '@tomorrow',
            ],
            'auth' => 'basic',
            'expected_status' => 200,
            'items_pattern' => '/^(?:[01]\d|2[0-3]):[0-5]\d$/',
        ],
    ],
];
