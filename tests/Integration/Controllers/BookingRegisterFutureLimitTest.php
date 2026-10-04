<?php

namespace Tests\Integration\Controllers;

use Availability;
use Booking;
use DateInterval;
use DateTimeImmutable;
use Tests\TestCase;

require_once APPPATH . 'controllers/Booking.php';
require_once APPPATH . 'libraries/Availability.php';

/**
 * ROB-728 regression: a public register request beyond the booking horizon
 * must fail before performing per-provider availability work.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class BookingRegisterFutureLimitTest extends TestCase
{
    /** @var array<int> */
    private array $providerIds = [];

    private ?int $serviceId = null;

    /** @var array<string, array{exists:bool,value:?string}> */
    private array $settingSnapshots = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->snapshotSetting('disable_booking');
        $this->snapshotSetting('require_captcha');
        $this->snapshotSetting('future_booking_limit');
        $this->setSetting('disable_booking', '0');
        $this->setSetting('require_captcha', '1');
        $this->setSetting('future_booking_limit', '30');

        try {
            $this->createSyntheticFixture();
        } catch (\Throwable $e) {
            $this->cleanupSyntheticFixture();
            $this->restoreSettings();
            throw $e;
        }
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        session(['captcha_phrase' => null]);
        $this->cleanupSyntheticFixture();
        $this->restoreSettings();
        parent::tearDown();
    }

    public function testRegisterFutureLimitSkipsFarAvailabilityWithoutPartialMutation(): void
    {
        $nearDate = (new DateTimeImmutable('+7 days'))->format('Y-m-d');
        $boundaryDate = (new DateTimeImmutable('+30 days'))->format('Y-m-d');
        $farDate = (new DateTimeImmutable('+10 years'))->format('Y-m-d');

        $nearSpecific = $this->register($nearDate, (string) $this->providerIds[0], 'near-specific');
        $nearAny = $this->register($nearDate, ANY_PROVIDER, 'near-any');
        $boundarySpecific = $this->register($boundaryDate, (string) $this->providerIds[0], 'boundary-specific');
        $boundaryAny = $this->register($boundaryDate, ANY_PROVIDER, 'boundary-any');
        $farSpecific = $this->register($farDate, (string) $this->providerIds[0], 'far-specific');
        $farAny = $this->register($farDate, ANY_PROVIDER, 'far-any');

        fwrite(
            STDOUT,
            'ROB-728 register future-limit regression: ' .
                json_encode(
                    [
                        'future_booking_limit_days' => 30,
                        'provider_count' => count($this->providerIds),
                        'measurements' => [
                            'near_specific' => $nearSpecific,
                            'near_any' => $nearAny,
                            'boundary_specific' => $boundarySpecific,
                            'boundary_any' => $boundaryAny,
                            'far_specific' => $farSpecific,
                            'far_any' => $farAny,
                        ],
                    ],
                    JSON_THROW_ON_ERROR,
                ) .
                PHP_EOL,
        );

        self::assertGreaterThan(0, $nearSpecific['availability_invocations']);
        self::assertGreaterThanOrEqual($nearSpecific['availability_invocations'], $nearAny['availability_invocations']);
        self::assertGreaterThan(0, $boundarySpecific['availability_invocations']);
        self::assertGreaterThan(0, $boundaryAny['availability_invocations']);

        self::assertFalse($farSpecific['response']['success'] ?? true);
        self::assertFalse($farAny['response']['success'] ?? true);
        self::assertSame(lang('requested_hour_is_unavailable'), $farSpecific['response']['message'] ?? null);
        self::assertSame(lang('requested_hour_is_unavailable'), $farAny['response']['message'] ?? null);
        self::assertSame(0, $farSpecific['availability_invocations']);
        self::assertSame(0, $farAny['availability_invocations']);
        foreach ([$nearSpecific, $nearAny, $boundarySpecific, $boundaryAny, $farSpecific, $farAny] as $measurement) {
            self::assertSame(0, $measurement['customer_delta']);
            self::assertSame(0, $measurement['appointment_delta']);
        }
    }

    public function testFarFutureStillRejectsInvalidServiceAndProviderWithoutAvailabilityWork(): void
    {
        $farDate = (new DateTimeImmutable('+10 years'))->format('Y-m-d');
        $invalidProvider = $this->register($farDate, (string) PHP_INT_MAX, 'invalid-provider');
        $invalidService = $this->register($farDate, ANY_PROVIDER, 'invalid-service', PHP_INT_MAX);

        foreach ([$invalidProvider, $invalidService] as $measurement) {
            self::assertFalse($measurement['response']['success'] ?? true);
            self::assertSame(0, $measurement['availability_invocations']);
            self::assertSame(0, $measurement['customer_delta']);
            self::assertSame(0, $measurement['appointment_delta']);
        }
    }

    /** @return array{availability_invocations:int,response:array,customer_delta:int,appointment_delta:int} */
    private function register(string $date, string $providerId, string $case, ?int $serviceId = null): array
    {
        $customerEmail = 'rob728-' . $case . '-' . bin2hex(random_bytes(6)) . '@synthetic.invalid';
        $start = new DateTimeImmutable($date . ' 09:00:00');
        $end = $start->add(new DateInterval('PT30M'));
        $beforeCustomers = $this->countUsers();
        $beforeAppointments = $this->countAppointments();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'post_data' => [
                'appointment' => [
                    'start_datetime' => $start->format('Y-m-d H:i:s'),
                    'end_datetime' => $end->format('Y-m-d H:i:s'),
                    'id_services' => $serviceId ?? $this->serviceId,
                    'id_users_provider' => $providerId,
                    'location' => '',
                    'notes' => 'ROB728 ' . $case,
                    'color' => '',
                ],
                'customer' => [
                    'first_name' => 'Synthetic',
                    'last_name' => 'ROB728 ' . $case,
                    'email' => $customerEmail,
                    'phone_number' => '000000000',
                    'address' => '',
                    'city' => '',
                    'zip_code' => '',
                    'timezone' => 'UTC',
                    'notes' => 'ROB728 ' . $case,
                ],
                'manage_mode' => false,
            ],
            'captcha' => 'ROB728-CAPTCHA',
        ];
        session(['captcha_phrase' => 'ROB728-CAPTCHA']);
        get_instance()->output->set_output('');

        $availability = new class extends Availability {
            public int $invocations = 0;

            public function get_offered_hours_for_analysis(
                string $date,
                array $service,
                array $provider,
                ?int $exclude_appointment_id = null,
            ): array {
                $this->invocations++;

                return ['09:00'];
            }
        };
        $controller = $this->createBookingController($availability);
        $controller->register();
        $response = json_decode(get_instance()->output->get_output(), true);
        self::assertIsArray($response);

        return [
            'availability_invocations' => $availability->invocations,
            'response' => $response,
            'customer_delta' => $this->countUsers() - $beforeCustomers,
            'appointment_delta' => $this->countAppointments() - $beforeAppointments,
        ];
    }

    private function createBookingController(Availability $availability): Booking
    {
        $CI = &get_instance();
        foreach (
            [
                'appointments_model',
                'providers_model',
                'admins_model',
                'secretaries_model',
                'service_categories_model',
                'services_model',
                'customers_model',
                'consents_model',
            ]
            as $model
        ) {
            $CI->load->model($model);
        }
        foreach (['timezones', 'booking_request_dto_factory', 'reschedule_authority'] as $library) {
            $CI->load->library($library);
        }

        require_once APPPATH . 'core/Zero_surprise_canary.php';
        $controller = new class extends Booking {
            public function __construct() {}

            // This test measures the pre-transaction availability decision.
            // An accepted near-date control stops before it can write a booking.
            protected function begin_public_booking_transaction(bool $reschedule): bool
            {
                return false;
            }
        };
        $controller->zero_surprise_canary = new \Zero_surprise_canary();
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
        foreach (
            [
                'appointments_model',
                'providers_model',
                'admins_model',
                'secretaries_model',
                'service_categories_model',
                'services_model',
                'customers_model',
                'consents_model',
                'timezones',
                'booking_request_dto_factory',
                'reschedule_authority',
            ]
            as $property
        ) {
            $controller->{$property} = $CI->{$property};
        }
        $controller->availability = $availability;

        return $controller;
    }

    private function createSyntheticFixture(): void
    {
        $CI = &get_instance();
        $providerRole = $CI->db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])->row_array();
        $sourceProvider = $CI->db->get_where('users', ['id_roles' => $providerRole['id']])->row_array();
        self::assertNotEmpty($providerRole);
        self::assertNotEmpty($sourceProvider);

        $this->serviceId = $this->insertService();
        $settings = $CI->db->get_where('user_settings', ['id_users' => $sourceProvider['id']])->row_array();
        for ($index = 0; $index < 2; $index++) {
            $provider = $sourceProvider;
            unset($provider['id']);
            $provider['first_name'] = 'Synthetic';
            $provider['last_name'] = 'ROB728 Provider-' . $index;
            $provider['email'] = 'rob728-' . bin2hex(random_bytes(8)) . '-' . $index . '@synthetic.invalid';
            $provider['id_roles'] = (int) $providerRole['id'];
            $CI->db->insert('users', $provider);
            $id = (int) $CI->db->insert_id();
            $this->providerIds[] = $id;
            if ($settings) {
                $copy = $settings;
                unset($copy['id']);
                $copy['id_users'] = $id;
                $CI->db->insert('user_settings', $copy);
            }
            $CI->db->insert('services_providers', ['id_users' => $id, 'id_services' => $this->serviceId]);
        }
    }

    private function insertService(): int
    {
        $CI = &get_instance();
        $CI->db->insert('services', [
            'name' => 'ROB728-' . bin2hex(random_bytes(8)),
            'duration' => 30,
            'price' => 0,
            'currency' => 'EUR',
            'description' => 'ROB728 synthetic register future-limit baseline',
            'location' => 'Synthetic',
            'is_private' => 0,
            'attendants_number' => 1,
            'buffer_before' => 0,
            'buffer_after' => 0,
        ]);

        return (int) $CI->db->insert_id();
    }

    private function countUsers(): int
    {
        return (int) get_instance()->db->count_all('users');
    }

    private function countAppointments(): int
    {
        return (int) get_instance()->db->count_all('appointments');
    }

    private function cleanupSyntheticFixture(): void
    {
        $CI = &get_instance();
        if ($this->providerIds !== []) {
            $CI->db->where_in('id_users', $this->providerIds)->delete('services_providers');
            $CI->db->where_in('id_users', $this->providerIds)->delete('user_settings');
            $CI->db->where_in('id', $this->providerIds)->delete('users');
        }
        if ($this->serviceId !== null) {
            $CI->db->delete('services', ['id' => $this->serviceId]);
        }
        $this->providerIds = [];
        $this->serviceId = null;
    }

    private function snapshotSetting(string $name): void
    {
        $row = get_instance()
            ->db->get_where('settings', ['name' => $name])
            ->row_array();
        $this->settingSnapshots[$name] = ['exists' => !empty($row), 'value' => $row['value'] ?? null];
    }

    private function setSetting(string $name, string $value): void
    {
        $db = get_instance()->db;
        if ($db->get_where('settings', ['name' => $name])->row_array()) {
            $db->update('settings', ['value' => $value], ['name' => $name]);
        } else {
            $db->insert('settings', ['name' => $name, 'value' => $value]);
        }
    }

    private function restoreSettings(): void
    {
        $db = get_instance()->db;
        foreach ($this->settingSnapshots as $name => $snapshot) {
            if ($snapshot['exists']) {
                $db->update('settings', ['value' => $snapshot['value']], ['name' => $name]);
            } else {
                $db->delete('settings', ['name' => $name]);
            }
        }
        $this->settingSnapshots = [];
    }
}
