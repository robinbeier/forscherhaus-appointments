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

final class CalendarCombinedAuthorizationTestExceptions extends \EA_Exceptions
{
    public function show_error($heading, $message, $template = 'error_general', $status_code = 500): void
    {
        throw new RuntimeException('HTTP ' . $status_code);
    }
}

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CalendarCombinedAuthorizationTest extends TestCase
{
    private BookingFlowFixtures $fixtures;
    private object $notifications;
    private object $originalExceptions;
    private \EA_Output $originalOutput;
    private ?array $providerRoleSnapshot = null;
    /** @var array<int> */
    private array $createdUsers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $exceptions = &load_class('Exceptions', 'core');
        $this->originalExceptions = $exceptions;
        $exceptions = new CalendarCombinedAuthorizationTestExceptions();
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
        $this->fixtures->snapshotSettings(['limit_customer_access']);
        $this->fixtures->setSetting('limit_customer_access', '1');
        get_instance()->load->library('permissions');
        $this->providerRoleSnapshot = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])
            ->row_array();
        $this->notifications = BookingFlowFixtures::createNoopNotifications();
        $this->resetRequest();
    }

    protected function tearDown(): void
    {
        $exceptions = &load_class('Exceptions', 'core');
        $exceptions = $this->originalExceptions;
        if ($this->createdUsers !== []) {
            get_instance()->db->where_in('id_users_secretary', $this->createdUsers)->delete('secretaries_providers');
        }
        $this->fixtures->restoreSettings();
        $this->fixtures->cleanup();
        if ($this->createdUsers !== []) {
            get_instance()->db->where_in('id_users', $this->createdUsers)->delete('services_providers');
        }
        foreach (array_reverse($this->createdUsers) as $userId) {
            get_instance()->db->delete('user_settings', ['id_users' => $userId]);
            get_instance()->db->delete('users', ['id' => $userId]);
        }
        if ($this->providerRoleSnapshot !== null) {
            get_instance()->db->update(
                'roles',
                [
                    'customers' => $this->providerRoleSnapshot['customers'],
                    'appointments' => $this->providerRoleSnapshot['appointments'],
                ],
                ['id' => $this->providerRoleSnapshot['id']],
            );
        }
        get_instance()->output = $this->originalOutput;
        parent::tearDown();
    }

    public function testForeignStoredAppointmentOwnTargetAndAccessibleEditHaveNoPartialWrite(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $actor = $pair['provider_id'];
        $foreignProvider = $this->createProvider($pair['service_id']);
        $customer = $this->fixtures->createCustomer(['last_name' => 'Before']);
        $appointment = $this->fixtures->createAppointment(
            $foreignProvider,
            $customer,
            $pair['service_id'],
            new DateTimeImmutable('2035-05-01 09:00:00'),
        );
        $this->authenticate($actor, DB_SLUG_PROVIDER);
        $this->post(
            $this->customerPayload($customer, 'Changed'),
            $this->appointmentPayload($appointment, $actor, $pair['service_id'], $customer, '2035-05-01 10:00:00'),
        );

        $this->controller()->save_appointment();

        $this->assertDenied('HTTP 403');
        $this->assertSame('Before', $this->customerLastName($customer));
        $this->assertSame($foreignProvider, (int) $this->storedAppointment($appointment)['id_users_provider']);
        $this->assertSame('2035-05-01 09:00:00', $this->storedAppointment($appointment)['start_datetime']);
        $this->assertSame(0, $this->notifications->savedCalls);
    }

    public function testOwnAppointmentForeignTargetAndCustomerMismatchAreDeniedAtomically(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $customer = $this->fixtures->createCustomer(['last_name' => 'Linked']);
        $foreign = $this->fixtures->createCustomer(['last_name' => 'Foreign']);
        $appointment = $this->fixtures->createAppointment(
            $pair['provider_id'],
            $customer,
            $pair['service_id'],
            new DateTimeImmutable('2035-05-02 09:00:00'),
        );
        $this->authenticate($pair['provider_id'], DB_SLUG_PROVIDER);

        $this->post(
            $this->customerPayload($foreign, 'Foreign Changed'),
            $this->appointmentPayload($appointment, $pair['provider_id'], $pair['service_id'], $customer),
        );
        $this->controller()->save_appointment();
        $this->assertDenied();
        $this->assertSame('Foreign', $this->customerLastName($foreign));
        $this->assertSame($customer, (int) $this->storedAppointment($appointment)['id_users_customer']);

        $this->post(
            $this->customerPayload($customer, 'Changed'),
            $this->appointmentPayload($appointment, $pair['provider_id'], $pair['service_id'], $foreign),
        );
        $this->controller()->save_appointment();
        $this->assertDenied();
        $this->assertSame('Linked', $this->customerLastName($customer));
        $this->assertSame($customer, (int) $this->storedAppointment($appointment)['id_users_customer']);
        $this->assertSame(0, $this->notifications->savedCalls);
    }

    public function testAllowedOwnCustomerAndDateChangeCommitsTogether(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $customer = $this->fixtures->createCustomer(['last_name' => 'Before']);
        $appointment = $this->fixtures->createAppointment(
            $pair['provider_id'],
            $customer,
            $pair['service_id'],
            new DateTimeImmutable('2035-05-03 09:00:00'),
        );
        $this->setRolePrivileges(DB_SLUG_PROVIDER, 'customers', PRIV_VIEW | PRIV_EDIT);
        $this->setRolePrivileges(DB_SLUG_PROVIDER, 'appointments', PRIV_VIEW | PRIV_EDIT);
        $this->authenticate($pair['provider_id'], DB_SLUG_PROVIDER);
        $this->post(
            $this->customerPayload($customer, 'After'),
            $this->appointmentPayload(
                $appointment,
                $pair['provider_id'],
                $pair['service_id'],
                $customer,
                '2035-05-03 10:00:00',
            ),
        );
        $this->controller()->save_appointment();

        $this->assertTrue($this->decode()['success'] ?? false);
        $this->assertSame('After', $this->customerLastName($customer));
        $this->assertSame('2035-05-03 10:00:00', $this->storedAppointment($appointment)['start_datetime']);
    }

    public function testProviderMayEditOneOwnCustomerWhileAssigningAppointmentToAnotherOwnCustomer(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $customerA = $this->fixtures->createCustomer(['last_name' => 'Customer A']);
        $customerB = $this->fixtures->createCustomer(['last_name' => 'Customer B']);
        $appointment = $this->fixtures->createAppointment(
            $pair['provider_id'],
            $customerA,
            $pair['service_id'],
            new DateTimeImmutable('2035-05-03 09:00:00'),
        );
        $this->fixtures->createAppointment(
            $pair['provider_id'],
            $customerB,
            $pair['service_id'],
            new DateTimeImmutable('2035-05-03 11:00:00'),
        );
        $this->setRolePrivileges(DB_SLUG_PROVIDER, 'customers', PRIV_VIEW | PRIV_EDIT);
        $this->setRolePrivileges(DB_SLUG_PROVIDER, 'appointments', PRIV_VIEW | PRIV_EDIT);
        $this->authenticate($pair['provider_id'], DB_SLUG_PROVIDER);
        $this->post(
            $this->customerPayload($customerA, 'Customer A Changed'),
            $this->appointmentPayload($appointment, $pair['provider_id'], $pair['service_id'], $customerB),
        );

        $this->controller()->save_appointment();

        $this->assertTrue($this->decode()['success'] ?? false);
        $this->assertSame('Customer A Changed', $this->customerLastName($customerA));
        $this->assertSame($customerB, (int) $this->storedAppointment($appointment)['id_users_customer']);
    }

    public function testCustomerEditAllowedButAppointmentEditDeniedRollsBackCustomer(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $customer = $this->fixtures->createCustomer(['last_name' => 'Before']);
        $appointment = $this->fixtures->createAppointment(
            $pair['provider_id'],
            $customer,
            $pair['service_id'],
            new DateTimeImmutable('2035-05-04 09:00:00'),
        );
        $this->setRolePrivileges(DB_SLUG_PROVIDER, 'customers', PRIV_VIEW | PRIV_EDIT);
        $this->setRolePrivileges(DB_SLUG_PROVIDER, 'appointments', PRIV_VIEW);
        $this->authenticate($pair['provider_id'], DB_SLUG_PROVIDER);
        $this->post(
            $this->customerPayload($customer, 'Must Roll Back'),
            $this->appointmentPayload($appointment, $pair['provider_id'], $pair['service_id'], $customer),
        );
        $this->controller()->save_appointment();

        $this->assertDenied();
        $this->assertSame('Before', $this->customerLastName($customer));
        $this->assertSame(0, $this->notifications->savedCalls);
    }

    public function testSecretaryAndAdminMayUseCrossProviderCombination(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $otherProvider = $this->createProvider($pair['service_id']);
        $secretary = $this->createSecretary($pair['provider_id']);
        get_instance()->db->insert('secretaries_providers', [
            'id_users_secretary' => $secretary,
            'id_users_provider' => $otherProvider,
        ]);
        $customer = $this->fixtures->createCustomer(['last_name' => 'Before']);
        $appointment = $this->fixtures->createAppointment(
            $pair['provider_id'],
            $customer,
            $pair['service_id'],
            new DateTimeImmutable('2035-05-05 09:00:00'),
        );

        foreach (
            [[$secretary, DB_SLUG_SECRETARY], [$this->userIdForRole(DB_SLUG_ADMIN), DB_SLUG_ADMIN]]
            as [$user, $role]
        ) {
            $this->authenticate((int) $user, $role);
            $this->post(
                $this->customerPayload($customer, 'Changed'),
                $this->appointmentPayload($appointment, $otherProvider, $pair['service_id'], $customer),
            );
            $this->controller()->save_appointment();
            $this->assertTrue($this->decode()['success'] ?? false, $role);
            $this->assertSame($otherProvider, (int) $this->storedAppointment($appointment)['id_users_provider']);
            get_instance()->db->update(
                'appointments',
                ['id_users_provider' => $pair['provider_id']],
                ['id' => $appointment],
            );
        }
    }

    public function testProviderRaceReassignmentIsDeniedAndForeignRowStaysUnchanged(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $foreignProvider = $this->createProvider($pair['service_id']);
        $customer = $this->fixtures->createCustomer(['last_name' => 'Before']);
        $appointment = $this->fixtures->createAppointment(
            $pair['provider_id'],
            $customer,
            $pair['service_id'],
            new DateTimeImmutable('2035-05-06 09:00:00'),
        );
        $secondary = get_instance()->load->database('', true);
        $this->fixtures->setSetting('limit_customer_access', '0');
        $this->setRolePrivileges(DB_SLUG_PROVIDER, 'appointments', PRIV_VIEW | PRIV_EDIT);
        $this->authenticate($pair['provider_id'], DB_SLUG_PROVIDER);
        $permissions = new class (get_instance()->permissions, $secondary, $appointment, $foreignProvider) {
            public bool $reassigned = false;

            public function __construct(
                private object $permissions,
                private object $secondary,
                private int $appointmentId,
                private int $foreignProvider,
            ) {}

            public function has_customer_access(int $userId, int $customerId): bool
            {
                $allowed = $this->permissions->has_customer_access($userId, $customerId);

                if (!$this->reassigned) {
                    $this->reassigned = $this->secondary->update(
                        'appointments',
                        ['id_users_provider' => $this->foreignProvider],
                        ['id' => $this->appointmentId],
                    );
                }

                return $allowed;
            }
        };
        $controller = $this->controller();
        $controller->permissions = $permissions;
        $this->post(
            [],
            $this->appointmentPayload(
                $appointment,
                $pair['provider_id'],
                $pair['service_id'],
                $customer,
                '2035-05-06 10:00:00',
            ),
        );
        $controller->save_appointment();
        $secondary->close();

        $this->assertSame(403, get_instance()->output->statusCode);
        $response = $this->decode();
        $stored = $this->storedAppointment($appointment);
        $this->assertSame(
            [
                'parallel_reassignment' => true,
                'success' => false,
                'message' => 'You do not have the required permissions for this task.',
                'stored_provider' => $foreignProvider,
                'stored_customer' => $customer,
                'stored_start' => '2035-05-06 09:00:00',
                'notifications' => 0,
            ],
            [
                'parallel_reassignment' => $permissions->reassigned,
                'success' => $response['success'] ?? null,
                'message' => $response['message'] ?? null,
                'stored_provider' => (int) $stored['id_users_provider'],
                'stored_customer' => (int) $stored['id_users_customer'],
                'stored_start' => $stored['start_datetime'],
                'notifications' => $this->notifications->savedCalls,
            ],
        );
    }

    private function controller(): Calendar
    {
        $CI = &get_instance();
        foreach (
            ['appointments_model', 'customers_model', 'providers_model', 'services_model', 'secretaries_model']
            as $model
        ) {
            $CI->load->model($model);
        }
        $controller = new class extends Calendar {
            public function __construct() {}
        };
        foreach (
            [
                'db',
                'input',
                'output',
                'load',
                'customers_model',
                'providers_model',
                'services_model',
                'secretaries_model',
                'permissions',
            ]
            as $property
        ) {
            $controller->{$property} = $CI->{$property};
        }
        $controller->appointments_model = $CI->appointments_model;
        $controller->notifications = $this->notifications;
        return $controller;
    }

    private function post(array $customer, array $appointment): void
    {
        $_POST = ['customer_data' => $customer, 'appointment_data' => $appointment];
        get_instance()->output->set_output('');
    }
    private function customerPayload(int $id, string $lastName): array
    {
        return [
            'id' => $id,
            'first_name' => 'Access',
            'last_name' => $lastName,
            'email' => 'access-' . $id . '@example.org',
            'phone_number' => '+49123456789',
            'address' => 'Test Street 1',
            'city' => 'Test City',
            'zip_code' => '12345',
            'timezone' => 'UTC',
            'language' => 'english',
        ];
    }
    private function appointmentPayload(
        int $id,
        int $provider,
        int $service,
        int $customer,
        ?string $start = null,
    ): array {
        $row = $this->storedAppointment($id);
        return [
            'id' => $id,
            'start_datetime' => $start ?? $row['start_datetime'],
            'end_datetime' => $start ? date('Y-m-d H:i:s', strtotime($start . ' +25 minutes')) : $row['end_datetime'],
            'id_users_provider' => $provider,
            'id_users_customer' => $customer,
            'id_services' => $service,
            'location' => '',
            'notes' => 'combined authorization',
            'color' => '',
        ];
    }
    private function authenticate(int $id, string $role): void
    {
        session(['user_id' => $id, 'role_slug' => $role, 'language' => 'english', 'timezone' => 'UTC']);
    }
    private function storedAppointment(int $id): array
    {
        return get_instance()
            ->db->get_where('appointments', ['id' => $id])
            ->row_array();
    }
    private function customerLastName(int $id): string
    {
        return (string) get_instance()
            ->db->get_where('users', ['id' => $id])
            ->row_array()['last_name'];
    }
    private function decode(): array
    {
        return json_decode(get_instance()->output->get_output(), true) ?: [];
    }
    private function assertDenied(string $message = 'You do not have the required permissions for this task.'): void
    {
        $response = $this->decode();
        $this->assertFalse($response['success'] ?? true);
        $this->assertSame($message, $response['message'] ?? null);
    }
    private function resetRequest(): void
    {
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        get_instance()->output->set_output('');
        get_instance()->output->statusCode = 200;
        http_response_code(200);
    }
    private function setRolePrivileges(string $role, string $column, int $value): void
    {
        $row = get_instance()
            ->db->get_where('roles', ['slug' => $role])
            ->row_array();
        get_instance()->db->update('roles', [$column => $value], ['id' => $row['id']]);
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
    private function createProvider(int $service): int
    {
        $role = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])
            ->row_array();
        $base = get_instance()
            ->db->get_where('users', ['id_roles' => $role['id']])
            ->row_array();
        $sourceId = (int) $base['id'];
        unset($base['id']);
        $base['email'] = 'combined-provider-' . bin2hex(random_bytes(4)) . '@example.org';
        $base['id_roles'] = $role['id'];
        get_instance()->db->insert('users', $base);
        $id = (int) get_instance()->db->insert_id();
        $this->createdUsers[] = $id;
        $settings = get_instance()
            ->db->get_where('user_settings', ['id_users' => $sourceId])
            ->row_array();
        if ($settings) {
            unset($settings['id']);
            $settings['id_users'] = $id;
            get_instance()->db->insert('user_settings', $settings);
        }
        get_instance()->db->insert('services_providers', ['id_users' => $id, 'id_services' => $service]);
        return $id;
    }
    private function createSecretary(int $provider): int
    {
        $role = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_SECRETARY])
            ->row_array();
        $base = get_instance()
            ->db->get_where('users', ['id' => $provider])
            ->row_array();
        unset($base['id']);
        $base['email'] = 'combined-secretary-' . bin2hex(random_bytes(4)) . '@example.org';
        $base['id_roles'] = $role['id'];
        get_instance()->db->insert('users', $base);
        $id = (int) get_instance()->db->insert_id();
        $this->createdUsers[] = $id;
        $settings = get_instance()
            ->db->get_where('user_settings', ['id_users' => $provider])
            ->row_array();
        unset($settings['id']);
        $settings['id_users'] = $id;
        get_instance()->db->insert('user_settings', $settings);
        get_instance()->db->insert('secretaries_providers', [
            'id_users_secretary' => $id,
            'id_users_provider' => $provider,
        ]);
        return $id;
    }
}
