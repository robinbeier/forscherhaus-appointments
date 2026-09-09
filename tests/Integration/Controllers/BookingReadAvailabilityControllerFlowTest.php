<?php

namespace Tests\Integration\Controllers;

use Booking;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->fixtures->snapshotSettings(['disable_booking', 'book_advance_timeout']);
        $this->fixtures->setSetting('disable_booking', '0');
        $this->fixtures->setSetting('book_advance_timeout', '0');

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

    public function testIndexDoesNotRenderWebMcpAdapterWhenPilotIsDisabled(): void
    {
        config(['webmcp_booking_pilot_enabled' => false]);

        $controller = $this->createBookingController();

        $controller->index();

        $this->assertSame('0', script_vars('webmcp_booking_pilot_enabled'));
        $this->assertStringNotContainsString('assets/js/pages/booking_webmcp', $this->renderedBookingScripts());
    }

    public function testIndexRendersWebMcpAdapterOnlyWhenPilotIsEnabled(): void
    {
        config(['webmcp_booking_pilot_enabled' => true]);

        $controller = $this->createBookingController();

        $controller->index();

        $this->assertSame('1', script_vars('webmcp_booking_pilot_enabled'));
        $this->assertStringContainsString('assets/js/pages/booking_webmcp', $this->renderedBookingScripts());
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

    public function testGetAvailableHoursKeepsBookedSlotUnavailableWithoutRescheduleAuthority(): void
    {
        $scenario = $this->createAvailabilityScenario();

        $this->setAvailabilityPayload($scenario, true);
        $controller = $this->createBookingController();
        $controller->get_available_hours();

        $this->assertNotContains($scenario['hour'], $this->decodeJsonOutput());
    }

    public function testGetAvailableHoursIncludesAppointmentWithAuthenticatedRescheduleAuthority(): void
    {
        $scenario = $this->createAvailabilityScenario();
        $controller = $this->createBookingController();
        $this->issueRescheduleAuthority($controller, $scenario['hash']);
        $before_authority = $this->authorityRow($scenario['appointment_id']);

        $this->setAvailabilityPayload($scenario, true);
        $controller->get_available_hours();

        $this->assertContains($scenario['hour'], $this->decodeJsonOutput());
        $this->setAvailabilityPayload($scenario, true);
        $controller->get_available_hours();
        $this->assertContains($scenario['hour'], $this->decodeJsonOutput());
        $this->assertSame($before_authority, $this->authorityRow($scenario['appointment_id']));
        $claim = $controller->reschedule_authority->claim($scenario['appointment_id'], $scenario['customer_id']);
        $this->assertSame($scenario['appointment_id'], $claim->appointmentId);
    }

    public function testGetAvailableHoursRejectsAppointmentIdMismatchedWithAuthenticatedSession(): void
    {
        $canonical = $this->createAvailabilityScenario();
        $service = get_instance()->services_model->find($canonical['service_id']);
        $provider = get_instance()->providers_model->find($canonical['provider_id']);
        $hours = get_instance()->availability->get_available_hours($canonical['date'], $service, $provider);
        $this->assertGreaterThan(1, count($hours));
        $foreign_customer_id = $this->fixtures->createCustomer();
        $foreign_appointment_id = $this->fixtures->createAppointment(
            $canonical['provider_id'],
            $foreign_customer_id,
            $canonical['service_id'],
            new DateTimeImmutable($canonical['date'] . ' ' . $hours[1] . ':00'),
        );
        $controller = $this->createBookingController();
        $this->issueRescheduleAuthority($controller, $canonical['hash']);

        $this->setPostPayload([
            'provider_id' => $canonical['provider_id'],
            'service_id' => $canonical['service_id'],
            'selected_date' => $canonical['date'],
            'manage_mode' => true,
            'appointment_id' => $foreign_appointment_id,
        ]);
        $controller->get_available_hours();

        $this->assertNotContains((string) $hours[1], $this->decodeJsonOutput());
    }

    public function testGetAvailableHoursSupportsAnyProviderWithAuthenticatedAuthority(): void
    {
        $scenario = $this->createAvailabilityScenario();
        $controller = $this->createBookingController();
        $this->issueRescheduleAuthority($controller, $scenario['hash']);
        $this->useSoleSlotAvailability($controller, $scenario);

        $this->setPostPayload([
            'provider_id' => ANY_PROVIDER,
            'service_id' => $scenario['service_id'],
            'selected_date' => $scenario['date'],
            'manage_mode' => true,
            'appointment_id' => $scenario['appointment_id'],
        ]);
        $controller->get_available_hours();

        $this->assertContains($scenario['hour'], $this->decodeJsonOutput());
    }

    public function testGetAvailableHoursIgnoresRawRequestAuthorityToken(): void
    {
        $scenario = $this->createAvailabilityScenario();
        $this->setPostPayload([
            'provider_id' => $scenario['provider_id'],
            'service_id' => $scenario['service_id'],
            'selected_date' => $scenario['date'],
            'manage_mode' => true,
            'appointment_id' => $scenario['appointment_id'],
            'reschedule_authority' => str_repeat('A', 43),
        ]);
        $controller = $this->createBookingController();
        $controller->get_available_hours();

        $this->assertNotContains($scenario['hour'], $this->decodeJsonOutput());
    }

    #[DataProvider('invalidAuthorityProvider')]
    public function testGetAvailableHoursKeepsBookedSlotUnavailableForInvalidRescheduleAuthority(string $variant): void
    {
        $scenario = $this->createAvailabilityScenario();
        $controller = $this->createBookingController();

        if ($variant !== 'missing') {
            $this->issueRescheduleAuthority($controller, $scenario['hash']);
        }

        if ($variant === 'malformed') {
            session(['public_reschedule_authority' => 'malformed']);
        } elseif ($variant === 'expired') {
            $this->fixtures->expireRescheduleAuthority($scenario['appointment_id']);
        } elseif ($variant === 'consumed') {
            get_instance()->db->update(
                'reschedule_authorities',
                ['consumed_at' => date('Y-m-d H:i:s')],
                ['appointment_id' => $scenario['appointment_id']],
            );
        } elseif ($variant === 'mismatched') {
            session([
                'public_reschedule_authority_context' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
            ]);
        } elseif ($variant === 'stale') {
            $this->fixtures->updateAppointment($scenario['appointment_id'], [
                'notes' => 'authority snapshot drift',
            ]);
        }

        $this->setAvailabilityPayload($scenario, true);
        $controller->get_available_hours();

        $this->assertNotContains($scenario['hour'], $this->decodeJsonOutput());
    }

    public static function invalidAuthorityProvider(): array
    {
        return [
            'missing' => ['missing'],
            'malformed' => ['malformed'],
            'expired' => ['expired'],
            'consumed' => ['consumed'],
            'mismatched context' => ['mismatched'],
            'stale snapshot' => ['stale'],
        ];
    }

    public function testGetUnavailableDatesIncludesSoleAppointmentDateWithAuthenticatedRescheduleAuthority(): void
    {
        $scenario = $this->createAvailabilityScenario(true);
        $controller = $this->createBookingController();
        $this->issueRescheduleAuthority($controller, $scenario['hash']);
        $this->useSoleSlotAvailability($controller, $scenario);

        $this->setUnavailableDatesPayload($scenario, true);
        $controller->get_unavailable_dates();

        $response = $this->decodeJsonOutput();
        $this->assertTrue(array_is_list($response));
        $this->assertNotContains($scenario['date'], $response);
        $this->assertAuthorityWasNotConsumedOrRefreshed($scenario['appointment_id']);
    }

    public function testGetUnavailableDatesKeepsSoleAppointmentDateUnavailableWithoutRescheduleAuthority(): void
    {
        $scenario = $this->createAvailabilityScenario(true);

        $this->setUnavailableDatesPayload($scenario, true);
        $controller = $this->createBookingController();
        $this->useSoleSlotAvailability($controller, $scenario);
        $controller->get_unavailable_dates();

        $response = $this->decodeJsonOutput();
        if (($response['is_month_unavailable'] ?? false) === true) {
            return;
        }

        $this->assertTrue(array_is_list($response));
        $this->assertContains($scenario['date'], $response);
    }

    private function createBookingController(): Booking
    {
        $controller = new class extends Booking {
            public function __construct() {}
        };

        $this->wireBookingDependencies($controller);

        $controller->notifications = BookingFlowFixtures::createNoopNotifications();

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
        $CI->load->library('reschedule_authority');

        $controller->load = $CI->load;
        $controller->db = $CI->db;
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
        $controller->reschedule_authority = $CI->reschedule_authority;
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
     * @return array{provider_id:int,service_id:int,customer_id:int,appointment_id:int,hash:string,date:string,hour:string}
     */
    private function createAvailabilityScenario(bool $occupyOtherHours = false): array
    {
        $CI = &get_instance();
        $CI->load->model('services_model');
        $CI->load->model('providers_model');
        $CI->load->library('availability');
        $pair = $this->fixtures->resolveProviderServicePair();
        $date = (new DateTimeImmutable('next monday'))->format('Y-m-d');
        $service = $CI->services_model->find($pair['service_id']);
        $provider = $CI->providers_model->find($pair['provider_id']);
        $hours = $CI->availability->get_available_hours($date, $service, $provider);
        $this->assertNotEmpty($hours);
        $hour = (string) $hours[0];
        $customer_id = $this->fixtures->createCustomer();
        $appointment_id = $this->fixtures->createAppointment(
            $pair['provider_id'],
            $customer_id,
            $pair['service_id'],
            new DateTimeImmutable($date . ' ' . $hour . ':00'),
        );

        if ($occupyOtherHours) {
            foreach (array_slice($hours, 1) as $blocked_hour) {
                $this->fixtures->createAppointment(
                    $pair['provider_id'],
                    $customer_id,
                    $pair['service_id'],
                    new DateTimeImmutable($date . ' ' . $blocked_hour . ':00'),
                );
            }
        }

        $appointment = $this->fixtures->findAppointmentById($appointment_id);
        $this->assertNotNull($appointment);

        return [
            'provider_id' => $pair['provider_id'],
            'service_id' => $pair['service_id'],
            'customer_id' => $customer_id,
            'appointment_id' => $appointment_id,
            'hash' => (string) $appointment['hash'],
            'date' => $date,
            'hour' => $hour,
        ];
    }

    /**
     * @param array{provider_id:int,service_id:int,appointment_id:int,date:string,hour:string} $scenario
     */
    private function setAvailabilityPayload(array $scenario, bool $manage_mode): void
    {
        $this->setPostPayload([
            'provider_id' => $scenario['provider_id'],
            'service_id' => $scenario['service_id'],
            'selected_date' => $scenario['date'],
            'manage_mode' => $manage_mode,
            'appointment_id' => $scenario['appointment_id'],
        ]);
    }

    /**
     * @param array{provider_id:int,service_id:int,appointment_id:int,date:string} $scenario
     */
    private function setUnavailableDatesPayload(array $scenario, bool $manage_mode): void
    {
        $_POST = [];
        $_GET = [
            'provider_id' => $scenario['provider_id'],
            'service_id' => $scenario['service_id'],
            'selected_date' => (new DateTimeImmutable($scenario['date']))
                ->modify('first day of this month')
                ->format('Y-m-d'),
            'manage_mode' => $manage_mode,
            'appointment_id' => $scenario['appointment_id'],
        ];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        get_instance()->output->set_output('');
    }

    private function issueRescheduleAuthority(Booking $controller, string $appointment_hash): void
    {
        $this->resetRuntimeState('GET');
        $_GET['appointment_hash'] = $appointment_hash;
        $controller->reschedule($appointment_hash);
        $this->resetRuntimeState('POST');
    }

    /**
     * Make the date endpoint's distinction independent of the seeded provider
     * schedule: the appointment date has exactly one candidate slot.
     *
     * @param array{appointment_id:int,date:string,hour:string} $scenario
     */
    private function useSoleSlotAvailability(Booking $controller, array $scenario): void
    {
        $date = $scenario['date'];
        $hour = $scenario['hour'];
        $appointment_id = $scenario['appointment_id'];
        $controller->availability = new class ($date, $hour, $appointment_id) extends \Availability {
            public function __construct(
                private readonly string $date,
                private readonly string $hour,
                private readonly int $appointmentId,
            ) {}

            public function get_available_hours(
                string $date,
                array $service,
                array $provider,
                ?int $exclude_appointment_id = null,
            ): array {
                return $date === $this->date && $exclude_appointment_id === $this->appointmentId ? [$this->hour] : [];
            }
        };
    }

    private function assertAuthorityWasNotConsumedOrRefreshed(int $appointment_id): void
    {
        $authority = $this->authorityRow($appointment_id);

        $this->assertNotEmpty($authority);
        $this->assertNull($authority['consumed_at']);
        $this->assertSame($authority['update_datetime'], $authority['create_datetime']);
    }

    /** @return array<string, mixed> */
    private function authorityRow(int $appointment_id): array
    {
        return get_instance()
            ->db->get_where('reschedule_authorities', ['appointment_id' => $appointment_id])
            ->row_array();
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

    private function renderedBookingScripts(): string
    {
        $layout = config('layout');

        $this->assertIsArray($layout);
        $this->assertIsArray($layout['sections']['scripts'] ?? null);

        return implode('', $layout['sections']['scripts']);
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
