<?php

namespace Tests\Integration\Controllers;

use Booking;
use DateInterval;
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
class BookingControllerFlowTest extends TestCase
{
    private BookingFlowFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtures = new BookingFlowFixtures();
        $this->fixtures->snapshotSettings([
            'disable_booking',
            'require_captcha',
            'display_terms_and_conditions',
            'display_privacy_policy',
            'appointment_status_options',
            'book_advance_timeout',
        ]);

        $this->fixtures->setSetting('disable_booking', '0');
        $this->fixtures->setSetting('require_captcha', '0');
        $this->fixtures->setSetting('display_terms_and_conditions', '0');
        $this->fixtures->setSetting('display_privacy_policy', '0');
        $this->fixtures->setSetting('appointment_status_options', json_encode(['Booked', 'Cancelled']));
        $this->fixtures->setSetting('book_advance_timeout', '60');

        $this->resetRuntimeState('POST');
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeState('POST');
        $this->fixtures->restoreSettings();
        $this->fixtures->cleanup();

        parent::tearDown();
    }

    public function testRegisterSuccessCreatesAppointmentAndReturnsHash(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $customerEmail = 'register-success-' . bin2hex(random_bytes(4)) . '@example.org';
        $startAt = new DateTimeImmutable('tomorrow 09:00:00');
        $endAt = $startAt->add(new DateInterval('PT' . EVENT_MINIMUM_DURATION . 'M'));

        $_POST['post_data'] = [
            'appointment' => [
                'start_datetime' => $startAt->format('Y-m-d H:i:s'),
                'end_datetime' => $endAt->format('Y-m-d H:i:s'),
                'id_services' => $pair['service_id'],
                'id_users_provider' => $pair['provider_id'],
                'location' => '',
                'notes' => 'Flow register success',
                'color' => '',
            ],
            'customer' => [
                'first_name' => 'Flow',
                'last_name' => 'Register Success',
                'email' => $customerEmail,
                'phone_number' => '+49123456789',
                'address' => 'Teststrasse 1',
                'city' => 'Berlin',
                'zip_code' => '10115',
                'timezone' => setting('default_timezone') ?: 'UTC',
                'notes' => '',
            ],
            'manage_mode' => false,
        ];

        $controller = $this->createBookingControllerWithForcedAvailability($pair['provider_id']);

        $controller->register();

        $response = json_decode(get_instance()->output->get_output(), true);

        $this->assertIsArray($response);
        $this->assertArrayHasKey('appointment_id', $response);
        $this->assertArrayHasKey('appointment_hash', $response);

        $appointment = $this->fixtures->findAppointmentById((int) $response['appointment_id']);

        $this->assertNotNull($appointment);
        $this->assertSame((int) $response['appointment_id'], (int) $appointment['id']);
        $this->assertSame((string) $response['appointment_hash'], (string) $appointment['hash']);
        $this->assertSame($pair['provider_id'], (int) $appointment['id_users_provider']);
        $this->assertSame($pair['service_id'], (int) $appointment['id_services']);
        $this->assertTrue($this->fixtures->customerExistsByEmail($customerEmail));
    }

    public function testRegisterManageModeUpdatesExistingAppointment(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $customerEmail = 'register-manage-' . bin2hex(random_bytes(4)) . '@example.org';

        $customerId = $this->fixtures->createCustomer([
            'first_name' => 'Manage',
            'last_name' => 'Mode',
            'email' => $customerEmail,
            'timezone' => setting('default_timezone') ?: 'UTC',
        ]);

        $initialStart = new DateTimeImmutable('+2 days 09:00:00');
        $appointmentId = $this->fixtures->createAppointment(
            $pair['provider_id'],
            $customerId,
            $pair['service_id'],
            $initialStart,
        );

        $existing = $this->fixtures->findAppointmentById($appointmentId);
        $this->assertNotNull($existing);

        $updatedStart = new DateTimeImmutable('+2 days 11:00:00');
        $updatedEnd = $updatedStart->add(new DateInterval('PT' . EVENT_MINIMUM_DURATION . 'M'));

        $_POST['post_data'] = [
            'appointment' => [
                'id' => $appointmentId,
                'start_datetime' => $updatedStart->format('Y-m-d H:i:s'),
                'end_datetime' => $updatedEnd->format('Y-m-d H:i:s'),
                'id_services' => $pair['service_id'],
                'id_users_provider' => $pair['provider_id'],
                'location' => '',
                'notes' => 'Flow manage update',
                'color' => '',
            ],
            'customer' => [
                'first_name' => 'Manage',
                'last_name' => 'Mode',
                'email' => $customerEmail,
                'phone_number' => '+49123456789',
                'address' => 'Teststrasse 1',
                'city' => 'Berlin',
                'zip_code' => '10115',
                'timezone' => setting('default_timezone') ?: 'UTC',
                'notes' => '',
            ],
            'manage_mode' => true,
        ];

        $controller = $this->createBookingControllerWithForcedAvailability($pair['provider_id']);

        $controller->register();

        $response = json_decode(get_instance()->output->get_output(), true);

        $this->assertIsArray($response);
        $this->assertSame($appointmentId, (int) $response['appointment_id']);

        $updated = $this->fixtures->findAppointmentById($appointmentId);

        $this->assertNotNull($updated);
        $this->assertSame($existing['hash'], $updated['hash']);
        $this->assertSame($updatedStart->format('Y-m-d H:i:s'), $updated['start_datetime']);
        $this->assertSame(1, $this->fixtures->countAppointmentsByHash($existing['hash']));
    }

    public function testRegisterReturnsErrorWhenDateTimeUnavailable(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $customerEmail = 'register-unavailable-' . bin2hex(random_bytes(4)) . '@example.org';
        $startAt = new DateTimeImmutable('tomorrow 10:00:00');
        $endAt = $startAt->add(new DateInterval('PT' . EVENT_MINIMUM_DURATION . 'M'));

        $_POST['post_data'] = [
            'appointment' => [
                'start_datetime' => $startAt->format('Y-m-d H:i:s'),
                'end_datetime' => $endAt->format('Y-m-d H:i:s'),
                'id_services' => $pair['service_id'],
                'id_users_provider' => $pair['provider_id'],
                'location' => '',
                'notes' => 'Flow unavailable',
                'color' => '',
            ],
            'customer' => [
                'first_name' => 'Flow',
                'last_name' => 'Unavailable',
                'email' => $customerEmail,
                'phone_number' => '+49123456789',
                'address' => 'Teststrasse 1',
                'city' => 'Berlin',
                'zip_code' => '10115',
                'timezone' => setting('default_timezone') ?: 'UTC',
                'notes' => '',
            ],
            'manage_mode' => false,
        ];

        $controller = $this->createBookingControllerWithForcedAvailability(null);

        $controller->register();

        $response = json_decode(get_instance()->output->get_output(), true);

        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertArrayHasKey('message', $response);
        $this->assertFalse($response['success'] ?? true);
        $this->assertIsString($response['message']);
        $this->assertNotSame('', trim($response['message']));
        $this->assertSame(lang('requested_hour_is_unavailable'), $response['message']);
        $this->assertArrayNotHasKey('trace', $response);
        $this->assertFalse($this->fixtures->customerExistsByEmail($customerEmail));
    }

    public function testRescheduleSetsManageModeForValidHash(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $customerId = $this->fixtures->createCustomer();
        $appointmentId = $this->fixtures->createAppointment(
            $pair['provider_id'],
            $customerId,
            $pair['service_id'],
            new DateTimeImmutable('+2 days 10:00:00'),
        );

        $appointment = $this->fixtures->findAppointmentById($appointmentId);
        $this->assertNotNull($appointment);

        $controller = $this->createBookingControllerWithForcedAvailability($pair['provider_id']);
        $controller->reschedule($appointment['hash']);

        $this->assertTrue((bool) script_vars('manage_mode'));
        $this->assertTrue((bool) html_vars('manage_mode'));

        $appointmentData = script_vars('appointment_data');
        $providerData = script_vars('provider_data');
        $customerData = script_vars('customer_data');

        $this->assertIsArray($appointmentData);
        $this->assertIsArray($providerData);
        $this->assertIsArray($customerData);
        $this->assertSame($appointmentId, (int) $appointmentData['id']);
        $this->assertSame($pair['provider_id'], (int) $providerData['id']);
        $this->assertSame($customerId, (int) $customerData['id']);
    }

    public function testRescheduleBootstrapsCacheWhenRateLimitBypassSkippedIt(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $customerId = $this->fixtures->createCustomer();
        $appointmentId = $this->fixtures->createAppointment(
            $pair['provider_id'],
            $customerId,
            $pair['service_id'],
            new DateTimeImmutable('+2 days 11:00:00'),
        );

        $appointment = $this->fixtures->findAppointmentById($appointmentId);
        $this->assertNotNull($appointment);

        $controller = $this->createBookingControllerWithForcedAvailability($pair['provider_id'], false);
        $controller->reschedule($appointment['hash']);

        $this->assertTrue((bool) script_vars('manage_mode'));
        $this->assertTrue((bool) html_vars('manage_mode'));
        $this->assertIsString(script_vars('customer_token'));
        $this->assertNotSame('', trim((string) script_vars('customer_token')));
    }

    public function testRescheduleShowsLockedMessageWhenInsideAdvanceTimeout(): void
    {
        $this->fixtures->setSetting('book_advance_timeout', '120');

        $pair = $this->fixtures->resolveProviderServicePair();
        $customerId = $this->fixtures->createCustomer();
        $appointmentId = $this->fixtures->createAppointment(
            $pair['provider_id'],
            $customerId,
            $pair['service_id'],
            new DateTimeImmutable('+30 minutes'),
        );

        $appointment = $this->fixtures->findAppointmentById($appointmentId);
        $this->assertNotNull($appointment);

        $controller = $this->createBookingControllerWithForcedAvailability($pair['provider_id']);
        $controller->reschedule($appointment['hash']);

        $this->assertTrue((bool) html_vars('show_message'));
        $this->assertSame(lang('appointment_locked'), html_vars('message_title'));
        $this->assertStringContainsString('02:00', (string) html_vars('message_text'));
    }

    private function createBookingControllerWithForcedAvailability(?int $providerId, bool $injectCache = true): Booking
    {
        $controller = new class ($providerId) extends Booking {
            private ?int $forcedProviderId;

            public function __construct(?int $forcedProviderId)
            {
                $this->forcedProviderId = $forcedProviderId;
            }

            protected function check_datetime_availability(\BookingRegisterRequestDto $register_request): ?int
            {
                return $this->forcedProviderId;
            }
        };

        $this->wireBookingDependencies($controller, $injectCache);

        $controller->synchronization = BookingFlowFixtures::createNoopSynchronization();
        $controller->notifications = BookingFlowFixtures::createNoopNotifications();
        $controller->webhooks_client = BookingFlowFixtures::createNoopWebhooksClient();

        return $controller;
    }

    private function wireBookingDependencies(Booking $controller, bool $injectCache = true): void
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

        $controller->load = $CI->load;
        $controller->input = $CI->input;
        $controller->output = $CI->output;
        if ($injectCache) {
            $controller->cache = new class {
                public function save(...$args): bool
                {
                    return true;
                }
            };
        }
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
        http_response_code(200);
    }
}
