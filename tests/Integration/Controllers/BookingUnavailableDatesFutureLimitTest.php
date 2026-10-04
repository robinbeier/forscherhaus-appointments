<?php

namespace Tests\Integration\Controllers;

use Availability;
use Booking;
use DateTimeImmutable;
use Tests\TestCase as ApplicationTestCase;

require_once APPPATH . 'controllers/Booking.php';
require_once APPPATH . 'libraries/Availability.php';

/**
 * ROB-726 regression: far-future months avoid day-by-provider availability
 * work while boundary months use the ordinary path.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class BookingUnavailableDatesFutureLimitTest extends ApplicationTestCase
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
        $this->snapshotSetting('future_booking_limit');
        $this->setSetting('disable_booking', '0');
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
        $this->cleanupSyntheticFixture();
        $this->restoreSettings();
        parent::tearDown();
    }

    public function testEnforcesFutureLimitBeforeAvailabilityWorkAndPreservesNearMonthBehavior(): void
    {
        $nearMonth = (new DateTimeImmutable('first day of next month'))->format('Y-m-d');
        $boundaryMonth = (new DateTimeImmutable('+30 days'))->modify('first day of this month')->format('Y-m-d');
        $distantMonth = (new DateTimeImmutable('+10 years'))->modify('first day of this month')->format('Y-m-d');

        $measurements = [
            'near_single' => $this->measure($nearMonth, (string) $this->providerIds[0]),
            'near_any' => $this->measure($nearMonth, ANY_PROVIDER),
            'boundary_any' => $this->measure($boundaryMonth, ANY_PROVIDER),
            'distant_single' => $this->measure($distantMonth, (string) $this->providerIds[0]),
            'distant_any' => $this->measure($distantMonth, ANY_PROVIDER),
        ];

        $nearDays = (int) (new DateTimeImmutable($nearMonth))->format('t');

        self::assertSame($nearDays, $measurements['near_single']['availability_invocations']);
        self::assertSame($nearDays * count($this->providerIds), $measurements['near_any']['availability_invocations']);
        self::assertGreaterThan(0, $measurements['boundary_any']['availability_invocations']);
        self::assertSame(0, $measurements['distant_single']['availability_invocations']);
        self::assertSame(0, $measurements['distant_any']['availability_invocations']);

        self::assertSame(['is_month_unavailable' => true], $measurements['near_any']['response']);
        self::assertSame(['is_month_unavailable' => true], $measurements['boundary_any']['response']);
        self::assertSame(['is_month_unavailable' => true], $measurements['distant_single']['response']);
        self::assertSame(['is_month_unavailable' => true], $measurements['distant_any']['response']);

        $invalidProvider = $this->measure($distantMonth, (string) PHP_INT_MAX);
        self::assertSame(0, $invalidProvider['availability_invocations']);
        self::assertSame(false, $invalidProvider['response']['success'] ?? null);
        self::assertArrayNotHasKey('is_month_unavailable', $invalidProvider['response']);

        fwrite(
            STDOUT,
            'ROB-726 future-limit regression: ' .
                json_encode(
                    [
                        'future_booking_limit_days' => 30,
                        'provider_count' => count($this->providerIds),
                        'measurements' => $measurements,
                    ],
                    JSON_THROW_ON_ERROR,
                ) .
                PHP_EOL,
        );
    }

    /** @return array{availability_invocations:int,query_delta:int,response:array} */
    private function measure(string $selectedDate, string $providerId): array
    {
        $_POST = [];
        $_GET = [
            'provider_id' => $providerId,
            'service_id' => $this->serviceId,
            'selected_date' => $selectedDate,
            'manage_mode' => false,
            'appointment_id' => null,
        ];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        get_instance()->output->set_output('');

        $availability = new class extends Availability {
            public int $invocations = 0;

            public function get_available_hours(
                string $date,
                array $service,
                array $provider,
                ?int $exclude_appointment_id = null,
            ): array {
                $this->invocations++;

                return [];
            }
        };

        $controller = $this->createBookingController($availability);
        $db = get_instance()->db;
        $queryStart = count($db->queries);
        $controller->get_unavailable_dates();
        $queryDelta = count($db->queries) - $queryStart;
        $response = json_decode(get_instance()->output->get_output(), true);

        self::assertIsArray($response);

        return [
            'availability_invocations' => $availability->invocations,
            'query_delta' => $queryDelta,
            'response' => $response,
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
        $role = $CI->db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])->row_array();
        $source = $CI->db->get_where('users', ['id_roles' => $role['id']])->row_array();
        self::assertNotEmpty($source);
        self::assertNotEmpty($role);

        $this->serviceId = $this->insertService();
        $settings = $CI->db->get_where('user_settings', ['id_users' => $source['id']])->row_array();

        for ($index = 0; $index < 3; $index++) {
            $provider = $source;
            unset($provider['id']);
            $provider['first_name'] = 'ROB726';
            $provider['last_name'] = 'Provider-' . $index;
            $provider['email'] = 'rob726-' . bin2hex(random_bytes(8)) . '-' . $index . '@synthetic.invalid';
            $provider['id_roles'] = (int) $role['id'];
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
            'name' => 'ROB726-' . bin2hex(random_bytes(8)),
            'duration' => 30,
            'price' => 0,
            'currency' => 'EUR',
            'description' => 'ROB726 synthetic future-limit regression',
            'location' => 'Synthetic',
            'is_private' => 0,
            'attendants_number' => 1,
            'buffer_before' => 0,
            'buffer_after' => 0,
        ]);

        return (int) $CI->db->insert_id();
    }

    private function cleanupSyntheticFixture(): void
    {
        $CI = &get_instance();
        if ($this->providerIds) {
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
