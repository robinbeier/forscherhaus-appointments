<?php

namespace Tests\Integration\Controllers;

use Calendar;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;
use Tests\Integration\Support\BookingFlowFixtures;
use Tests\TestCase;

require_once APPPATH . 'controllers/Calendar.php';
load_class('Exceptions', 'core');

final class CalendarUnavailabilityRaceTestExceptions extends \EA_Exceptions
{
    public function show_error($heading, $message, $template = 'error_general', $status_code = 500): void
    {
        throw new RuntimeException('HTTP ' . $status_code);
    }
}

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CalendarUnavailabilityRaceTest extends TestCase
{
    private BookingFlowFixtures $fixtures;
    private object $originalExceptions;
    private \EA_Output $originalOutput;
    private int $providerId;
    private int $foreignProviderId;
    private int $unavailabilityId;
    private array $providerRoleSnapshot;

    protected function setUp(): void
    {
        parent::setUp();
        $exceptions = &load_class('Exceptions', 'core');
        $this->originalExceptions = $exceptions;
        $exceptions = new CalendarUnavailabilityRaceTestExceptions();
        $this->originalOutput = get_instance()->output;
        get_instance()->output = new class extends \EA_Output {
            public int $statusCode = 200;

            public function set_status_header($code = 200, $text = '')
            {
                $this->statusCode = (int) $code;
                return parent::set_status_header($code, $text);
            }
        };
        $this->fixtures = new BookingFlowFixtures();
        $pair = $this->fixtures->resolveProviderServicePair();
        $this->providerId = $pair['provider_id'];
        $this->foreignProviderId = $this->createProvider($this->providerId);
        $this->unavailabilityId = $this->createUnavailability($this->providerId);
        $this->providerRoleSnapshot = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])
            ->row_array();
        get_instance()->db->update(
            'roles',
            ['appointments' => PRIV_VIEW | PRIV_EDIT | PRIV_DELETE],
            ['id' => $this->providerRoleSnapshot['id']],
        );
        get_instance()->load->library('permissions');
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        get_instance()->output->set_output('');
    }

    protected function tearDown(): void
    {
        $exceptions = &load_class('Exceptions', 'core');
        $exceptions = $this->originalExceptions;
        get_instance()->output = $this->originalOutput;
        get_instance()->db->delete('appointments', ['id' => $this->unavailabilityId]);
        get_instance()->db->delete('user_settings', ['id_users' => $this->foreignProviderId]);
        get_instance()->db->delete('services_providers', ['id_users' => $this->foreignProviderId]);
        get_instance()->db->delete('users', ['id' => $this->foreignProviderId]);
        get_instance()->db->update(
            'roles',
            ['appointments' => $this->providerRoleSnapshot['appointments']],
            ['id' => $this->providerRoleSnapshot['id']],
        );
        parent::tearDown();
    }

    public function testProviderReassignmentAfterAuthorizationCannotOverwriteForeignOwner(): void
    {
        $secondary = get_instance()->load->database('', true);
        $controller = $this->controller($secondary, $this->unavailabilityId, $this->foreignProviderId);
        $this->authenticate($this->providerId, DB_SLUG_PROVIDER);
        $_POST = [
            'unavailability' => [
                'id' => $this->unavailabilityId,
                'start_datetime' => '2035-06-01 09:00:00',
                'end_datetime' => '2035-06-01 09:30:00',
                'id_users_provider' => $this->providerId,
            ],
        ];

        $controller->save_unavailability();

        $response = json_decode(get_instance()->output->get_output(), true);
        $stored = get_instance()
            ->db->get_where('appointments', ['id' => $this->unavailabilityId])
            ->row_array();
        $this->assertTrue($controller->reassigned);
        $this->assertIsArray($response);
        $this->assertFalse($response['success'] ?? true);
        $this->assertSame(403, get_instance()->output->statusCode);
        $this->assertSame($this->foreignProviderId, (int) $stored['id_users_provider']);
        $this->assertSame('2035-06-01 08:00:00', $stored['start_datetime']);
    }

    public function testProviderReassignmentAfterDeleteAuthorizationCannotDeleteForeignOwner(): void
    {
        $secondary = get_instance()->load->database('', true);
        $before = get_instance()
            ->db->get_where('appointments', ['id' => $this->unavailabilityId])
            ->row_array();
        $race = new class ($secondary, $this->unavailabilityId, $this->foreignProviderId) {
            public bool $reassigned = false;

            public function __construct(
                private object $secondary,
                private int $unavailabilityId,
                private int $foreignProviderId,
            ) {}

            public function find(int $providerId): array
            {
                if (!$this->reassigned) {
                    $this->reassigned = $this->secondary->update(
                        'appointments',
                        ['id_users_provider' => $this->foreignProviderId],
                        ['id' => $this->unavailabilityId],
                    );
                }

                return ['id' => $providerId];
            }
        };
        $controller = $this->controller();
        $controller->providers_model = $race;
        $this->authenticate($this->providerId, DB_SLUG_PROVIDER);
        $_POST = ['unavailability_id' => $this->unavailabilityId];

        $controller->delete_unavailability();

        $response = json_decode(get_instance()->output->get_output(), true);
        $stored = get_instance()
            ->db->get_where('appointments', ['id' => $this->unavailabilityId])
            ->row_array();
        $this->assertTrue($race->reassigned);
        $this->assertIsArray($response);
        $this->assertFalse($response['success'] ?? true);
        $this->assertSame(403, get_instance()->output->statusCode);
        $expected = $before;
        $expected['id_users_provider'] = (string) $this->foreignProviderId;
        $this->assertSame($expected, $stored);
    }

    private function controller(
        ?object $secondary = null,
        int $unavailabilityId = 0,
        int $foreignProviderId = 0,
    ): Calendar {
        $CI = &get_instance();
        foreach (
            [
                'appointments_model',
                'customers_model',
                'providers_model',
                'services_model',
                'secretaries_model',
                'unavailabilities_model',
                'blocked_periods_model',
            ]
            as $model
        ) {
            $CI->load->model($model);
        }
        $controller = new class ($secondary, $unavailabilityId, $foreignProviderId) extends Calendar {
            public bool $reassigned = false;

            public function __construct(
                private ?object $secondary,
                private int $unavailabilityId,
                private int $foreignProviderId,
            ) {}

            protected function lock_calendar_update_parents(
                array $current_appointment,
                array $requested_appointment,
                array $additional_user_ids = [],
            ): void {
                if ($this->secondary !== null && !$this->reassigned) {
                    $this->reassigned = $this->secondary->update(
                        'appointments',
                        ['id_users_provider' => $this->foreignProviderId],
                        ['id' => $this->unavailabilityId],
                    );
                }

                parent::lock_calendar_update_parents(
                    $current_appointment,
                    $requested_appointment,
                    $additional_user_ids,
                );
            }
        };
        $controller->db = $CI->db;
        $controller->input = $CI->input;
        $controller->output = $CI->output;
        $controller->load = $CI->load;
        $controller->appointments_model = $CI->appointments_model;
        $controller->customers_model = $CI->customers_model;
        $controller->providers_model = $CI->providers_model;
        $controller->services_model = $CI->services_model;
        $controller->secretaries_model = $CI->secretaries_model;
        $controller->unavailabilities_model = $CI->unavailabilities_model;
        $controller->blocked_periods_model = $CI->blocked_periods_model;
        $controller->permissions = $CI->permissions;
        return $controller;
    }

    private function createProvider(int $sourceId): int
    {
        $CI = &get_instance();
        $source = $CI->db->get_where('users', ['id' => $sourceId])->row_array();
        $role = $CI->db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])->row_array();
        $settings = $CI->db->get_where('user_settings', ['id_users' => $sourceId])->row_array();
        unset($source['id'], $settings['id']);
        $source['email'] = 'calendar-race-' . bin2hex(random_bytes(5)) . '@example.org';
        $source['id_roles'] = (int) $role['id'];
        $CI->db->insert('users', $source);
        $id = (int) $CI->db->insert_id();
        $settings['id_users'] = $id;
        $CI->db->insert('user_settings', $settings);
        return $id;
    }

    private function createUnavailability(int $providerId): int
    {
        $now = date('Y-m-d H:i:s');
        get_instance()->db->insert('appointments', [
            'book_datetime' => $now,
            'start_datetime' => '2035-06-01 08:00:00',
            'end_datetime' => '2035-06-01 08:30:00',
            'notes' => 'Calendar race fixture',
            'hash' => 'calendar-race-' . bin2hex(random_bytes(5)),
            'is_unavailability' => true,
            'id_users_provider' => $providerId,
            'create_datetime' => $now,
            'update_datetime' => $now,
        ]);
        return (int) get_instance()->db->insert_id();
    }

    private function authenticate(int $id, string $role): void
    {
        session([
            'user_id' => $id,
            'role_slug' => $role,
            'language' => setting('default_language') ?: 'english',
            'timezone' => setting('default_timezone') ?: 'UTC',
        ]);
    }
}
