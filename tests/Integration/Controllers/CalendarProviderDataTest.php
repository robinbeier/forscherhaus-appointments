<?php

namespace Tests\Integration\Controllers;

use Calendar;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Integration\Support\BookingFlowFixtures;
use Tests\TestCase;

require_once APPPATH . 'controllers/Calendar.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CalendarProviderDataTest extends TestCase
{
    private BookingFlowFixtures $fixtures;
    private int $providerId;
    private int $serviceId;
    private int $appointmentId;
    private int $unavailabilityId;
    private array $providerSettings = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtures = new BookingFlowFixtures();
        $pair = $this->fixtures->resolveProviderServicePair();
        $this->providerId = $pair['provider_id'];
        $this->serviceId = $pair['service_id'];
        $this->providerSettings = get_instance()
            ->db->get_where('user_settings', ['id_users' => $this->providerId])
            ->row_array();

        get_instance()->db->update(
            'user_settings',
            [
                'google_token' => 'synthetic-google-secret',
                'caldav_password' => 'synthetic-caldav-secret',
                'working_plan' => '{"monday":{"start":"09:00","end":"17:00"}}',
                'working_plan_exceptions' => '{"2035-04-02":{"start":"10:00","end":"11:00"}}',
            ],
            ['id_users' => $this->providerId],
        );

        $customerId = $this->fixtures->createCustomer();
        $this->appointmentId = $this->fixtures->createAppointment(
            $this->providerId,
            $customerId,
            $this->serviceId,
            new DateTimeImmutable('2035-04-01 09:00:00'),
        );

        $now = date('Y-m-d H:i:s');
        get_instance()->db->insert('appointments', [
            'book_datetime' => $now,
            'start_datetime' => '2035-04-01 11:00:00',
            'end_datetime' => '2035-04-01 12:00:00',
            'notes' => 'Synthetic unavailability',
            'hash' => 'calendar-provider-data-' . bin2hex(random_bytes(4)),
            'is_unavailability' => true,
            'id_users_provider' => $this->providerId,
            'id_users_customer' => null,
            'id_services' => null,
            'create_datetime' => $now,
            'update_datetime' => $now,
        ]);
        $this->unavailabilityId = (int) get_instance()->db->insert_id();
        session([
            'user_id' => $this->providerId,
            'role_slug' => DB_SLUG_PROVIDER,
            'language' => setting('default_language') ?: 'english',
            'timezone' => setting('default_timezone') ?: 'UTC',
        ]);
    }

    protected function tearDown(): void
    {
        get_instance()->db->delete('appointments', ['id' => $this->unavailabilityId]);
        get_instance()->db->update('user_settings', $this->providerSettings, ['id_users' => $this->providerId]);
        $this->fixtures->cleanup();
        config(['script_vars' => [], 'html_vars' => []]);
        session(['user_id' => null, 'role_slug' => null]);

        parent::tearDown();
    }

    public function testProviderSettingsAreMinimizedInInitialAndNestedCalendarData(): void
    {
        $indexController = $this->indexController();
        $indexController->index();

        $availableProviders = script_vars('available_providers');
        $this->assertIsArray($availableProviders);
        $initialProvider = $this->providerById($availableProviders);
        $this->assertSafeProvider($initialProvider);

        foreach (['table', 'filtered'] as $endpoint) {
            $response = $this->readCalendarEndpoint($endpoint);
            $this->assertSafeProvider($this->providerFromEvent($response, 'appointments'));
            $this->assertSafeProvider($this->providerFromEvent($response, 'unavailabilities'));
        }
    }

    private function readCalendarEndpoint(string $endpoint): array
    {
        $_POST = [
            'start_date' => '2035-04-01',
            'end_date' => '2035-04-01',
            'record_id' => FILTER_TYPE_ALL,
            'filter_type' => '',
            'is_all' => '1',
        ];
        get_instance()->output->set_output('');
        $controller = $this->calendarController();

        if ($endpoint === 'table') {
            $controller->get_calendar_appointments_for_table_view();
        } else {
            $controller->get_calendar_appointments();
        }

        $response = json_decode(get_instance()->output->get_output(), true);
        $this->assertIsArray($response);

        return $response;
    }

    private function assertSafeProvider(array $provider): void
    {
        $this->assertArrayHasKey('id', $provider);
        $this->assertArrayHasKey('first_name', $provider);
        $this->assertArrayHasKey('last_name', $provider);
        $this->assertArrayHasKey('settings', $provider);
        $this->assertSame(
            [
                'working_plan' => '{"monday":{"start":"09:00","end":"17:00"}}',
                'working_plan_exceptions' => '{"2035-04-02":{"start":"10:00","end":"11:00"}}',
            ],
            $provider['settings'],
        );
        $this->assertArrayNotHasKey('google_token', $provider['settings']);
        $this->assertArrayNotHasKey('caldav_password', $provider['settings']);
    }

    private function providerFromEvent(array $response, string $key): array
    {
        $event = $response[$key][0] ?? [];
        $this->assertNotEmpty($event);

        return $event['provider'] ?? [];
    }

    private function providerById(array $providers): array
    {
        foreach ($providers as $provider) {
            if ((int) ($provider['id'] ?? 0) === $this->providerId) {
                return $provider;
            }
        }

        $this->fail('Synthetic provider missing from available providers.');
    }

    private function calendarController(): Calendar
    {
        $CI = &get_instance();
        foreach (
            [
                'appointments_model',
                'unavailabilities_model',
                'blocked_periods_model',
                'providers_model',
                'services_model',
                'customers_model',
            ]
            as $model
        ) {
            $CI->load->model($model);
        }

        $controller = new class extends Calendar {
            public function __construct() {}
        };
        $controller->db = $CI->db;
        $controller->input = $CI->input;
        $controller->output = $CI->output;
        $controller->load = $CI->load;
        $controller->appointments_model = $CI->appointments_model;
        $controller->unavailabilities_model = $CI->unavailabilities_model;
        $controller->blocked_periods_model = $CI->blocked_periods_model;
        $controller->providers_model = $CI->providers_model;
        $controller->services_model = $CI->services_model;
        $controller->customers_model = $CI->customers_model;

        return $controller;
    }

    private function indexController(): Calendar
    {
        $CI = &get_instance();
        $CI->load->model([
            'users_model',
            'roles_model',
            'providers_model',
            'services_model',
            'customers_model',
            'appointments_model',
        ]);
        $CI->load->library(['accounts', 'timezones']);

        $controller = new class extends Calendar {
            public function __construct() {}
        };
        $controller->db = $CI->db;
        $controller->input = $CI->input;
        $controller->output = $CI->output;
        $controller->load = new class {
            public function view(string $view): void {}
        };
        $controller->users_model = $CI->users_model;
        $controller->roles_model = $CI->roles_model;
        $controller->providers_model = $CI->providers_model;
        $controller->services_model = $CI->services_model;
        $controller->customers_model = $CI->customers_model;
        $controller->appointments_model = $CI->appointments_model;
        $controller->accounts = $CI->accounts;
        $controller->timezones = $CI->timezones;

        return $controller;
    }
}
