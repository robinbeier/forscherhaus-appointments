<?php

namespace Tests\Integration\Controllers;

use Booking;
use DateTimeImmutable;
use Tests\Integration\Support\BookingFlowFixtures;
use Tests\TestCase;

require_once APPPATH . 'controllers/Booking.php';

/**
 * Isolate controller integration tests from Unit test global state during coverage runs.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class BookingReadAvailabilityControllerFlowTest extends TestCase
{
    private BookingFlowFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtures = new BookingFlowFixtures();
        $this->fixtures->snapshotSettings(['disable_booking']);
        $this->fixtures->setSetting('disable_booking', '0');

        $this->resetRuntimeState('GET');
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeState('GET');
        $this->fixtures->restoreSettings();
        $this->fixtures->cleanup();

        parent::tearDown();
    }

    public function testIndexExposesBookingBootstrapForPublicFlow(): void
    {
        $controller = $this->createBookingController();

        $controller->index();

        $available_services = script_vars('available_services');
        $available_providers = script_vars('available_providers');

        $this->assertIsArray($available_services);
        $this->assertIsArray($available_providers);
        $this->assertNotEmpty($available_services);
        $this->assertNotEmpty($available_providers);
        $this->assertFalse((bool) script_vars('manage_mode'));
        $this->assertFalse((bool) html_vars('manage_mode'));
    }

    public function testGetAvailableHoursReturnsArrayForSpecificProvider(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $selected_date = (new DateTimeImmutable('+2 days'))->format('Y-m-d');

        $this->setPostPayload([
            'provider_id' => $pair['provider_id'],
            'service_id' => $pair['service_id'],
            'selected_date' => $selected_date,
            'manage_mode' => false,
            'appointment_id' => null,
        ]);

        $controller = $this->createBookingController();

        $controller->get_available_hours();

        $response = $this->decodeJsonOutput();

        $this->assertTrue(array_is_list($response));
        $this->assertSlotsUseHourMinuteFormat($response);
    }

    public function testGetAvailableHoursSupportsAnyProviderSentinel(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $selected_date = (new DateTimeImmutable('+2 days'))->format('Y-m-d');

        $this->setPostPayload([
            'provider_id' => ANY_PROVIDER,
            'service_id' => $pair['service_id'],
            'selected_date' => $selected_date,
            'manage_mode' => false,
            'appointment_id' => null,
        ]);

        $controller = $this->createBookingController();

        $controller->get_available_hours();

        $response = $this->decodeJsonOutput();

        $this->assertTrue(array_is_list($response));
        $this->assertSlotsUseHourMinuteFormat($response);
    }

    public function testGetUnavailableDatesReturnsArrayOrMonthUnavailableFlag(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $selected_date = (new DateTimeImmutable('first day of next month'))->format('Y-m-d');

        $this->setPostPayload([
            'provider_id' => $pair['provider_id'],
            'service_id' => $pair['service_id'],
            'selected_date' => $selected_date,
            'manage_mode' => false,
            'appointment_id' => null,
        ]);

        $controller = $this->createBookingController();

        $controller->get_unavailable_dates();

        $response = $this->decodeJsonOutput();

        if (array_is_list($response)) {
            foreach ($response as $date_value) {
                $this->assertIsString($date_value);
                $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $date_value);
            }

            return;
        }

        $this->assertArrayHasKey('is_month_unavailable', $response);
        $this->assertIsBool($response['is_month_unavailable']);
    }

    private function createBookingController(): Booking
    {
        $controller = new class extends Booking {
            public function __construct()
            {
            }
        };

        $this->wireBookingDependencies($controller);

        $controller->synchronization = BookingFlowFixtures::createNoopSynchronization();
        $controller->notifications = BookingFlowFixtures::createNoopNotifications();
        $controller->webhooks_client = BookingFlowFixtures::createNoopWebhooksClient();

        return $controller;
    }

    private function wireBookingDependencies(Booking $controller): void
    {
        $CI = &get_instance();
        $CI->load->model('appointments_model');
        $CI->load->model('providers_model');
        $CI->load->model('admins_model');
        $CI->load->model('secretaries_model');
        $CI->load->model('service_categories_model');
        $CI->load->model('services_model');
        $CI->load->model('customers_model');
        $CI->load->model('settings_model');
        $CI->load->model('consents_model');
        $CI->load->library('timezones');
        $CI->load->library('availability');
        $CI->load->library('booking_request_dto_factory');

        $controller->load = $CI->load;
        $controller->input = $CI->input;
        $controller->output = $CI->output;
        $controller->cache = new class {
            public function save(...$args): bool
            {
                return true;
            }
        };
        $controller->appointments_model = $CI->appointments_model;
        $controller->providers_model = $CI->providers_model;
        $controller->admins_model = $CI->admins_model;
        $controller->secretaries_model = $CI->secretaries_model;
        $controller->service_categories_model = $CI->service_categories_model;
        $controller->services_model = $CI->services_model;
        $controller->customers_model = $CI->customers_model;
        $controller->settings_model = $CI->settings_model;
        $controller->consents_model = $CI->consents_model;
        $controller->timezones = $CI->timezones;
        $controller->availability = $CI->availability;
        $controller->booking_request_dto_factory = $CI->booking_request_dto_factory;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function setPostPayload(array $payload): void
    {
        $_POST = $payload;
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        get_instance()->output->set_output('');
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

    /**
     * @param array<mixed> $slots
     */
    private function assertSlotsUseHourMinuteFormat(array $slots): void
    {
        foreach ($slots as $slot) {
            $this->assertIsString($slot);
            $this->assertMatchesRegularExpression('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $slot);
        }
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
