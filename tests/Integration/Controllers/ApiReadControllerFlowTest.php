<?php

namespace Tests\Integration\Controllers;

use Api;
use Appointments_api_v1;
use Availabilities_api_v1;
use DateTimeImmutable;
use Providers_api_v1;
use RuntimeException;
use Services_api_v1;
use Tests\Integration\Support\BookingFlowFixtures;
use Tests\TestCase;

require_once APPPATH . 'libraries/Api.php';
require_once APPPATH . 'controllers/api/v1/Appointments_api_v1.php';
require_once APPPATH . 'controllers/api/v1/Providers_api_v1.php';
require_once APPPATH . 'controllers/api/v1/Services_api_v1.php';
require_once APPPATH . 'controllers/api/v1/Availabilities_api_v1.php';

/**
 * Isolate controller integration tests from Unit test global state during coverage runs.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ApiReadControllerFlowTest extends TestCase
{
    private BookingFlowFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtures = new BookingFlowFixtures();

        $this->resetRuntimeState('GET');
        $this->authenticateAsAdmin();
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeState('GET');
        $this->fixtures->cleanup();

        unset(
            $_SERVER['PHP_AUTH_USER'],
            $_SERVER['PHP_AUTH_PW'],
            $_SERVER['Authorization'],
            $_SERVER['HTTP_AUTHORIZATION'],
        );

        parent::tearDown();
    }

    public function testAppointmentsIndexReturnsJsonArrayForAuthorizedAdmin(): void
    {
        $_GET = [
            'length' => '1',
            'page' => '1',
        ];

        $controller = $this->createAppointmentsController();

        $controller->index();

        $response = $this->decodeJsonOutput();

        $this->assertTrue(array_is_list($response));

        if ($response !== []) {
            $this->assertIsArray($response[0]);
            $this->assertArrayHasKey('id', $response[0]);
        }
    }

    public function testAppointmentsShowReturnsRequestedAppointment(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $customer_id = $this->fixtures->createCustomer();
        $appointment_id = $this->fixtures->createAppointment(
            $pair['provider_id'],
            $customer_id,
            $pair['service_id'],
            new DateTimeImmutable('+3 days 09:00:00'),
        );

        $_GET = [];

        $controller = $this->createAppointmentsController();

        $controller->show($appointment_id);

        $response = $this->decodeJsonOutput();

        $this->assertArrayHasKey('id', $response);
        $this->assertSame($appointment_id, (int) $response['id']);
        $this->assertArrayHasKey('providerId', $response);
        $this->assertArrayHasKey('serviceId', $response);
        $this->assertArrayHasKey('customerId', $response);
    }

    public function testProvidersIndexReturnsJsonArrayForAuthorizedAdmin(): void
    {
        $_GET = [
            'length' => '1',
            'page' => '1',
        ];

        $controller = $this->createProvidersController();

        $controller->index();

        $response = $this->decodeJsonOutput();

        $this->assertTrue(array_is_list($response));

        if ($response !== []) {
            $this->assertIsArray($response[0]);
            $this->assertArrayHasKey('id', $response[0]);
        }
    }

    public function testServicesIndexReturnsJsonArrayForAuthorizedAdmin(): void
    {
        $_GET = [
            'length' => '1',
            'page' => '1',
        ];

        $controller = $this->createServicesController();

        $controller->index();

        $response = $this->decodeJsonOutput();

        $this->assertTrue(array_is_list($response));

        if ($response !== []) {
            $this->assertIsArray($response[0]);
            $this->assertArrayHasKey('id', $response[0]);
        }
    }

    public function testAvailabilitiesGetReturnsJsonArrayForProviderServiceDate(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();

        $_GET = [
            'providerId' => (string) $pair['provider_id'],
            'serviceId' => (string) $pair['service_id'],
            'date' => (new DateTimeImmutable('+2 days'))->format('Y-m-d'),
        ];

        $controller = $this->createAvailabilitiesController();

        $controller->get();

        $response = $this->decodeJsonOutput();

        $this->assertTrue(array_is_list($response));

        foreach ($response as $slot) {
            $this->assertIsString($slot);
            $this->assertMatchesRegularExpression('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $slot);
        }
    }

    private function createAppointmentsController(): Appointments_api_v1
    {
        $controller = new class extends Appointments_api_v1 {
            public function __construct()
            {
            }
        };

        $CI = &get_instance();
        $CI->load->model('appointments_model');
        $CI->load->model('customers_model');
        $CI->load->model('providers_model');
        $CI->load->model('services_model');
        $CI->load->model('settings_model');
        $CI->load->model('unavailabilities_model');
        $CI->load->library('api_request_dto_factory');

        $controller->load = $CI->load;
        $controller->input = $CI->input;
        $controller->output = $CI->output;
        $controller->appointments_model = $CI->appointments_model;
        $controller->customers_model = $CI->customers_model;
        $controller->providers_model = $CI->providers_model;
        $controller->services_model = $CI->services_model;
        $controller->settings_model = $CI->settings_model;
        $controller->unavailabilities_model = $CI->unavailabilities_model;
        $controller->api_request_dto_factory = $CI->api_request_dto_factory;
        $controller->api = $this->createAuthenticatedApi('appointments_model');

        return $controller;
    }

    private function createProvidersController(): Providers_api_v1
    {
        $controller = new class extends Providers_api_v1 {
            public function __construct()
            {
            }
        };

        $CI = &get_instance();
        $CI->load->model('providers_model');
        $CI->load->library('api_request_dto_factory');

        $controller->load = $CI->load;
        $controller->input = $CI->input;
        $controller->output = $CI->output;
        $controller->providers_model = $CI->providers_model;
        $controller->api_request_dto_factory = $CI->api_request_dto_factory;
        $controller->api = $this->createAuthenticatedApi('providers_model');

        return $controller;
    }

    private function createServicesController(): Services_api_v1
    {
        $controller = new class extends Services_api_v1 {
            public function __construct()
            {
            }
        };

        $CI = &get_instance();
        $CI->load->model('services_model');
        $CI->load->library('api_request_dto_factory');

        $controller->load = $CI->load;
        $controller->input = $CI->input;
        $controller->output = $CI->output;
        $controller->services_model = $CI->services_model;
        $controller->api_request_dto_factory = $CI->api_request_dto_factory;
        $controller->api = $this->createAuthenticatedApi('services_model');

        return $controller;
    }

    private function createAvailabilitiesController(): Availabilities_api_v1
    {
        $controller = new class extends Availabilities_api_v1 {
            public function __construct()
            {
            }
        };

        $CI = &get_instance();
        $CI->load->model('appointments_model');
        $CI->load->model('providers_model');
        $CI->load->model('services_model');
        $CI->load->model('settings_model');
        $CI->load->library('availability');
        $CI->load->library('api_request_dto_factory');

        $controller->load = $CI->load;
        $controller->input = $CI->input;
        $controller->output = $CI->output;
        $controller->appointments_model = $CI->appointments_model;
        $controller->providers_model = $CI->providers_model;
        $controller->services_model = $CI->services_model;
        $controller->settings_model = $CI->settings_model;
        $controller->availability = $CI->availability;
        $controller->api_request_dto_factory = $CI->api_request_dto_factory;
        $controller->api = $this->createAuthenticatedApi('appointments_model');

        return $controller;
    }

    private function createAuthenticatedApi(string $model): Api
    {
        $this->authenticateAsAdmin();

        $api = new class extends Api {
            public function request_authentication(): void
            {
                throw new RuntimeException('API authentication failed in controller flow test.');
            }
        };

        $api->model($model);
        $api->auth();

        return $api;
    }

    private function authenticateAsAdmin(): void
    {
        $_SERVER['PHP_AUTH_USER'] = 'administrator';
        $_SERVER['PHP_AUTH_PW'] = 'administrator';
        unset($_SERVER['Authorization'], $_SERVER['HTTP_AUTHORIZATION']);
    }

    /**
     * @return array<mixed>
     */
    private function decodeJsonOutput(): array
    {
        $decoded = json_decode(get_instance()->output->get_output(), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function resetRuntimeState(string $requestMethod): void
    {
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = $requestMethod;

        config([
            'html_vars' => [],
            'script_vars' => [],
            'layout' => [
                'filename' => 'test-layout',
                'sections' => [],
                'tmp' => [],
            ],
        ]);

        get_instance()->output->set_output('');
    }
}
