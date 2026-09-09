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

final class CalendarEventPermissionsTestExceptions extends \EA_Exceptions
{
    public function show_error($heading, $message, $template = 'error_general', $status_code = 500): void
    {
        throw new RuntimeException('HTTP ' . $status_code);
    }
}

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class CalendarEventPermissionsTest extends TestCase
{
    private BookingFlowFixtures $fixtures;
    private int $providerId;
    private int $otherProviderId;
    private int $unassignedProviderId;
    private int $secretaryId;
    /** @var array<int> */
    private array $createdUsers = [];
    /** @var array<int> */
    private array $createdEventIds = [];
    private object $notifications;
    private object $webhooks;
    private object $originalExceptions;
    private ?array $providerRoleSnapshot = null;

    protected function setUp(): void
    {
        parent::setUp();
        $exceptions = &load_class('Exceptions', 'core');
        $this->originalExceptions = $exceptions;
        $exceptions = new CalendarEventPermissionsTestExceptions();
        $this->fixtures = new BookingFlowFixtures();
        $this->fixtures->snapshotSettings(['limit_customer_access']);
        $this->fixtures->setSetting('limit_customer_access', '0');
        $this->notifications = BookingFlowFixtures::createNoopNotifications();
        $this->webhooks = BookingFlowFixtures::createNoopWebhooksClient();

        $pair = $this->fixtures->resolveProviderServicePair();
        $this->providerId = $pair['provider_id'];
        $this->providerRoleSnapshot = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])
            ->row_array();
        $this->otherProviderId = $this->createProvider($pair['service_id']);
        $this->unassignedProviderId = $this->createProvider($pair['service_id']);
        $this->secretaryId = $this->createSecretary([$this->providerId, $this->otherProviderId]);
        get_instance()->load->library('permissions');
        $this->resetRuntimeState();
    }

    protected function tearDown(): void
    {
        $exceptions = &load_class('Exceptions', 'core');
        $exceptions = $this->originalExceptions;
        foreach ($this->createdEventIds as $eventId) {
            get_instance()->db->delete('appointments', ['id' => $eventId]);
        }
        get_instance()->db->delete('secretaries_providers', ['id_users_secretary' => $this->secretaryId]);
        get_instance()->db->where_in('id_users', $this->createdUsers)->delete('services_providers');
        foreach (array_reverse($this->createdUsers) as $id) {
            get_instance()->db->delete('user_settings', ['id_users' => $id]);
            get_instance()->db->delete('users', ['id' => $id]);
        }
        if ($this->providerRoleSnapshot !== null) {
            get_instance()->db->update(
                'roles',
                ['appointments' => $this->providerRoleSnapshot['appointments']],
                ['id' => $this->providerRoleSnapshot['id']],
            );
        }
        $this->fixtures->restoreSettings();
        $this->fixtures->cleanup();
        parent::tearDown();
    }

    public function testProviderCannotTransferStoredForeignAppointmentToOwnProvider(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $customerId = $this->fixtures->createCustomer();
        $appointmentId = $this->fixtures->createAppointment(
            $this->unassignedProviderId,
            $customerId,
            $pair['service_id'],
            new DateTimeImmutable('2035-03-01 09:00:00'),
        );
        $this->authenticate($this->providerId, DB_SLUG_PROVIDER);

        $this->postAppointment($appointmentId, $this->providerId, $pair['service_id'], $customerId);
        $this->controller()->save_appointment();

        $this->assertDenied();
        $this->assertSame($this->unassignedProviderId, $this->storedProvider($appointmentId));
        $this->assertSame(0, $this->notifications->savedCalls);
        $this->assertSame(0, $this->webhooks->calls);
    }

    public function testSecretaryCannotTransferStoredForeignAppointmentToAssignedProvider(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $customerId = $this->fixtures->createCustomer();
        $appointmentId = $this->fixtures->createAppointment(
            $this->unassignedProviderId,
            $customerId,
            $pair['service_id'],
            new DateTimeImmutable('2035-03-02 09:00:00'),
        );
        $this->authenticate($this->secretaryId, DB_SLUG_SECRETARY);

        $this->postAppointment($appointmentId, $this->providerId, $pair['service_id'], $customerId);
        $this->controller()->save_appointment();

        $this->assertDenied();
        $this->assertSame($this->unassignedProviderId, $this->storedProvider($appointmentId));

        $this->postAppointment($appointmentId, $this->unassignedProviderId, $pair['service_id'], $customerId);
        $this->controller()->save_appointment();

        $this->assertDenied();
        $this->assertSame($this->unassignedProviderId, $this->storedProvider($appointmentId));
    }

    public function testSecretaryMayMoveAppointmentBetweenAssignedProviders(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $customerId = $this->fixtures->createCustomer();
        $appointmentId = $this->fixtures->createAppointment(
            $this->providerId,
            $customerId,
            $pair['service_id'],
            new DateTimeImmutable('2035-03-03 09:00:00'),
        );
        $this->authenticate($this->secretaryId, DB_SLUG_SECRETARY);

        $this->postAppointment($appointmentId, $this->otherProviderId, $pair['service_id'], $customerId);
        $this->controller()->save_appointment();

        $response = $this->decode();
        $this->assertTrue($response['success'] ?? false);
        $this->assertSame($this->otherProviderId, $this->storedProvider($appointmentId));
    }

    public function testAdminMayReassignAppointmentAndProviderCannotRequestForeignProvider(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $customerId = $this->fixtures->createCustomer();
        $appointmentId = $this->fixtures->createAppointment(
            $this->otherProviderId,
            $customerId,
            $pair['service_id'],
            new DateTimeImmutable('2035-03-04 09:00:00'),
        );

        $this->authenticate($this->userIdForRole(DB_SLUG_ADMIN), DB_SLUG_ADMIN);
        $this->postAppointment($appointmentId, $this->providerId, $pair['service_id'], $customerId);
        $this->controller()->save_appointment();
        $this->assertTrue($this->decode()['success'] ?? false);
        $this->assertSame($this->providerId, $this->storedProvider($appointmentId));

        $this->authenticate($this->providerId, DB_SLUG_PROVIDER);
        $this->postAppointment($appointmentId, $this->otherProviderId, $pair['service_id'], $customerId);
        $this->controller()->save_appointment();
        $this->assertDenied();
        $this->assertSame($this->providerId, $this->storedProvider($appointmentId));
    }

    public function testProviderCannotTransferStoredForeignUnavailability(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $id = $this->createUnavailability($this->otherProviderId, '2035-03-05 09:00:00');
        $this->authenticate($this->providerId, DB_SLUG_PROVIDER);
        $_POST = [
            'unavailability' => [
                'id' => $id,
                'start_datetime' => '2035-03-05 09:00:00',
                'end_datetime' => '2035-03-05 09:30:00',
                'id_users_provider' => $this->providerId,
            ],
        ];
        $this->controller()->save_unavailability();

        $this->assertDenied();
        $this->assertSame($this->otherProviderId, $this->storedProvider($id));
    }

    public function testProviderMayEditOwnUnavailability(): void
    {
        $id = $this->createUnavailability($this->providerId, '2035-03-06 09:00:00');
        $this->authenticate($this->providerId, DB_SLUG_PROVIDER);
        $_POST = [
            'unavailability' => [
                'id' => $id,
                'start_datetime' => '2035-03-06 09:00:00',
                'end_datetime' => '2035-03-06 09:30:00',
                'id_users_provider' => $this->providerId,
            ],
        ];
        $this->controller()->save_unavailability();

        $this->assertTrue($this->decode()['success'] ?? false);
        $this->assertSame($this->providerId, $this->storedProvider($id));
    }

    public function testSecretaryMayEditUnavailabilityForAssignedProvider(): void
    {
        $id = $this->createUnavailability($this->providerId, '2035-03-07 09:00:00');
        $this->authenticate($this->secretaryId, DB_SLUG_SECRETARY);
        $_POST = [
            'unavailability' => [
                'id' => $id,
                'start_datetime' => '2035-03-07 09:00:00',
                'end_datetime' => '2035-03-07 09:30:00',
                'id_users_provider' => $this->otherProviderId,
            ],
        ];
        $this->controller()->save_unavailability();

        $this->assertTrue($this->decode()['success'] ?? false);
        $this->assertSame($this->otherProviderId, $this->storedProvider($id));
    }

    public function testSecretaryCannotEditStoredUnassignedOrRequestedForeignUnavailability(): void
    {
        $id = $this->createUnavailability($this->unassignedProviderId, '2035-03-07 10:00:00');
        $this->authenticate($this->secretaryId, DB_SLUG_SECRETARY);
        $_POST = [
            'unavailability' => [
                'id' => $id,
                'start_datetime' => '2035-03-07 10:00:00',
                'end_datetime' => '2035-03-07 10:30:00',
                'id_users_provider' => $this->providerId,
            ],
        ];
        $this->controller()->save_unavailability();
        $this->assertDenied();

        $id = $this->createUnavailability($this->providerId, '2035-03-07 11:00:00');
        $_POST['unavailability']['id'] = $id;
        $_POST['unavailability']['start_datetime'] = '2035-03-07 11:00:00';
        $_POST['unavailability']['end_datetime'] = '2035-03-07 11:30:00';
        $_POST['unavailability']['id_users_provider'] = $this->unassignedProviderId;
        $this->controller()->save_unavailability();
        $this->assertDenied();
        $this->assertSame($this->providerId, $this->storedProvider($id));
    }

    public function testProviderAppointmentRoleBitsSeparateExistingEditFromNewAdd(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $customerId = $this->fixtures->createCustomer();
        $existingId = $this->fixtures->createAppointment(
            $this->providerId,
            $customerId,
            $pair['service_id'],
            new DateTimeImmutable('2035-03-08 09:00:00'),
        );
        $this->authenticate($this->providerId, DB_SLUG_PROVIDER);

        $this->setProviderAppointmentPrivileges(PRIV_VIEW | PRIV_EDIT);
        $this->postAppointment($existingId, $this->providerId, $pair['service_id'], $customerId);
        $this->controller()->save_appointment();
        $this->assertTrue($this->decode()['success'] ?? false);

        $this->postNewAppointment($this->providerId, $pair['service_id'], $customerId, '2035-03-08 10:00:00');
        $this->controller()->save_appointment();
        $this->assertDenied('You do not have the required permissions for this task.');
        $this->assertSame(0, $this->appointmentCountAtStart('2035-03-08 10:00:00'));

        $this->setProviderAppointmentPrivileges(PRIV_VIEW | PRIV_ADD);
        $this->postAppointment($existingId, $this->providerId, $pair['service_id'], $customerId);
        $this->controller()->save_appointment();
        $this->assertDenied('You do not have the required permissions for this task.');

        $this->postNewAppointment($this->providerId, $pair['service_id'], $customerId, '2035-03-08 11:00:00');
        $this->controller()->save_appointment();
        $this->trackEventAtStart('2035-03-08 11:00:00');
        $this->assertTrue($this->decode()['success'] ?? false);
    }

    public function testUnavailabilityEmptyIdUsesAddPermission(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $this->authenticate($this->providerId, DB_SLUG_PROVIDER);
        $this->setProviderAppointmentPrivileges(PRIV_VIEW | PRIV_EDIT);
        $this->postNewUnavailability($this->providerId, '2035-03-09 09:00:00');
        $this->controller()->save_unavailability();
        $this->assertDenied('You do not have the required permissions for this task.');
        $this->assertSame(0, $this->appointmentCountAtStart('2035-03-09 09:00:00'));

        $this->setProviderAppointmentPrivileges(PRIV_VIEW | PRIV_ADD);
        $this->postNewUnavailability($this->providerId, '2035-03-09 10:00:00');
        $this->controller()->save_unavailability();
        $this->assertTrue($this->decode()['success'] ?? false);
        $this->trackEventAtStart('2035-03-09 10:00:00');
    }

    private function createProvider(int $serviceId): int
    {
        $base = get_instance()
            ->db->get_where('users', ['id' => $this->providerId])
            ->row_array();
        $role = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])
            ->row_array();
        unset($base['id']);
        $base['first_name'] = 'Synthetic';
        $base['last_name'] = 'Provider';
        $base['email'] = 'calendar-provider-' . bin2hex(random_bytes(4)) . '@example.org';
        $base['id_roles'] = (int) $role['id'];
        get_instance()->db->insert('users', $base);
        $id = (int) get_instance()->db->insert_id();
        $this->createdUsers[] = $id;
        $settings = get_instance()
            ->db->get_where('user_settings', ['id_users' => $this->providerId])
            ->row_array();
        unset($settings['id']);
        $settings['id_users'] = $id;
        get_instance()->db->insert('user_settings', $settings);
        get_instance()->db->insert('services_providers', ['id_users' => $id, 'id_services' => $serviceId]);
        return $id;
    }

    /** @param array<int> $providerIds */
    private function createSecretary(array $providerIds): int
    {
        $base = get_instance()
            ->db->get_where('users', ['id' => $this->providerId])
            ->row_array();
        $role = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_SECRETARY])
            ->row_array();
        unset($base['id']);
        $base['first_name'] = 'Synthetic';
        $base['last_name'] = 'Secretary';
        $base['email'] = 'calendar-secretary-' . bin2hex(random_bytes(4)) . '@example.org';
        $base['id_roles'] = (int) $role['id'];
        get_instance()->db->insert('users', $base);
        $id = (int) get_instance()->db->insert_id();
        $this->createdUsers[] = $id;
        $settings = get_instance()
            ->db->get_where('user_settings', ['id_users' => $this->providerId])
            ->row_array();
        unset($settings['id']);
        $settings['id_users'] = $id;
        get_instance()->db->insert('user_settings', $settings);
        foreach ($providerIds as $providerId) {
            get_instance()->db->insert('secretaries_providers', [
                'id_users_secretary' => $id,
                'id_users_provider' => $providerId,
            ]);
        }
        return $id;
    }

    private function createUnavailability(int $providerId, string $start): int
    {
        $now = date('Y-m-d H:i:s');
        get_instance()->db->insert('appointments', [
            'book_datetime' => $now,
            'start_datetime' => $start,
            'end_datetime' => date('Y-m-d H:i:s', strtotime($start . ' +30 minutes')),
            'notes' => 'Event ownership regression',
            'hash' => 'calendar-unavailability-' . bin2hex(random_bytes(4)),
            'is_unavailability' => true,
            'id_users_provider' => $providerId,
            'id_users_customer' => null,
            'id_services' => null,
            'create_datetime' => $now,
            'update_datetime' => $now,
        ]);
        $id = (int) get_instance()->db->insert_id();
        $this->createdEventIds[] = $id;
        return $id;
    }

    private function controller(): Calendar
    {
        $CI = &get_instance();
        foreach (
            [
                'appointments_model',
                'customers_model',
                'providers_model',
                'services_model',
                'secretaries_model',
                'unavailabilities_model',
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
        $controller->customers_model = $CI->customers_model;
        $controller->providers_model = $CI->providers_model;
        $controller->services_model = $CI->services_model;
        $controller->secretaries_model = $CI->secretaries_model;
        $controller->unavailabilities_model = $CI->unavailabilities_model;
        $controller->permissions = $CI->permissions;
        $controller->notifications = $this->notifications;
        $controller->webhooks_client = $this->webhooks;
        return $controller;
    }

    private function postAppointment(int $id, int $providerId, int $serviceId, int $customerId): void
    {
        $row = get_instance()
            ->db->get_where('appointments', ['id' => $id])
            ->row_array();
        $_POST = [
            'customer_data' => [],
            'appointment_data' => [
                'id' => $id,
                'start_datetime' => $row['start_datetime'],
                'end_datetime' => $row['end_datetime'],
                'id_users_provider' => $providerId,
                'id_users_customer' => $customerId,
                'id_services' => $serviceId,
                'location' => '',
                'notes' => 'Event ownership regression',
                'color' => '',
            ],
        ];
        get_instance()->output->set_output('');
    }

    private function postNewAppointment(int $providerId, int $serviceId, int $customerId, string $start): void
    {
        $_POST = [
            'customer_data' => [],
            'appointment_data' => [
                'start_datetime' => $start,
                'end_datetime' => date('Y-m-d H:i:s', strtotime($start . ' +25 minutes')),
                'id_users_provider' => $providerId,
                'id_users_customer' => $customerId,
                'id_services' => $serviceId,
                'location' => '',
                'notes' => 'Event permission regression',
                'color' => '',
            ],
        ];
        get_instance()->output->set_output('');
    }

    private function postNewUnavailability(int $providerId, string $start): void
    {
        $_POST = [
            'unavailability' => [
                'id' => 0,
                'start_datetime' => $start,
                'end_datetime' => date('Y-m-d H:i:s', strtotime($start . ' +30 minutes')),
                'id_users_provider' => $providerId,
            ],
        ];
        get_instance()->output->set_output('');
    }

    private function setProviderAppointmentPrivileges(int $privileges): void
    {
        get_instance()->db->update(
            'roles',
            ['appointments' => $privileges],
            ['id' => $this->providerRoleSnapshot['id']],
        );
    }

    private function appointmentCountAtStart(string $start): int
    {
        return (int) get_instance()->db->from('appointments')->where('start_datetime', $start)->count_all_results();
    }

    private function trackEventAtStart(string $start): void
    {
        $row = get_instance()
            ->db->select('id')
            ->get_where('appointments', ['start_datetime' => $start])
            ->row_array();
        $this->assertNotEmpty($row);
        $this->createdEventIds[] = (int) $row['id'];
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

    private function userIdForRole(string $role): int
    {
        return (int) get_instance()
            ->db->select('users.id')
            ->from('users')
            ->join('roles', 'roles.id = users.id_roles')
            ->where('roles.slug', $role)
            ->limit(1)
            ->get()
            ->row_array()['id'];
    }

    private function storedProvider(int $id): int
    {
        return (int) get_instance()
            ->db->get_where('appointments', ['id' => $id])
            ->row_array()['id_users_provider'];
    }

    private function assertDenied(string $message = 'HTTP 403'): void
    {
        $response = $this->decode();
        $this->assertFalse($response['success'] ?? true);
        $this->assertSame($message, $response['message'] ?? null);
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        $response = json_decode(get_instance()->output->get_output(), true);
        $this->assertIsArray($response);
        return $response;
    }

    private function resetRuntimeState(): void
    {
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        get_instance()->output->set_output('');
        http_response_code(200);
    }
}
