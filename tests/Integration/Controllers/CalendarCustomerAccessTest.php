<?php

namespace Tests\Integration\Controllers;

use Calendar;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\Integration\Support\BookingFlowFixtures;
use Tests\TestCase;

require_once APPPATH . 'controllers/Calendar.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class CalendarCustomerAccessTest extends TestCase
{
    private BookingFlowFixtures $fixtures;

    private object $notifications;

    private ?int $syntheticSecretaryId = null;

    /** @var array<string> */
    private array $cleanupEmails = [];

    private ?array $providerRole = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtures = new BookingFlowFixtures();
        $this->fixtures->snapshotSettings(['limit_customer_access']);
        get_instance()->load->library('permissions');
        $this->notifications = BookingFlowFixtures::createNoopNotifications();
        $this->resetRuntimeState();
    }

    protected function tearDown(): void
    {
        if ($this->providerRole !== null) {
            get_instance()->db->update(
                'roles',
                ['customers' => $this->providerRole['customers']],
                ['id' => $this->providerRole['id']],
            );
        }

        if ($this->syntheticSecretaryId !== null) {
            get_instance()->db->delete('secretaries_providers', ['id_users_secretary' => $this->syntheticSecretaryId]);
            get_instance()->db->delete('user_settings', ['id_users' => $this->syntheticSecretaryId]);
        }

        foreach ($this->cleanupEmails as $email) {
            $customer = get_instance()
                ->db->select('id')
                ->get_where('users', ['email' => $email])
                ->row_array();
            if (!empty($customer['id'])) {
                get_instance()->db->delete('appointments', ['id_users_customer' => (int) $customer['id']]);
                get_instance()->db->delete('users', ['id' => (int) $customer['id']]);
            }
        }
        $this->cleanupEmails = [];

        $this->fixtures->restoreSettings();
        $this->fixtures->cleanup();
        $this->resetRuntimeState();

        parent::tearDown();
    }

    public function testProviderCannotUseForeignCustomerIdToMutateCustomerOrAppointment(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $providerId = $pair['provider_id'];
        $linkedCustomerId = $this->fixtures->createCustomer(['last_name' => 'Linked']);
        $foreignCustomerId = $this->fixtures->createCustomer(['last_name' => 'Foreign']);
        $appointmentId = $this->fixtures->createAppointment(
            $providerId,
            $linkedCustomerId,
            $pair['service_id'],
            new DateTimeImmutable('2035-02-10 09:00:00'),
        );

        $this->fixtures->setSetting('limit_customer_access', '1');
        $this->authenticateAsUser($providerId, DB_SLUG_PROVIDER);

        $this->assertFalse(get_instance()->permissions->has_customer_access($providerId, $foreignCustomerId));

        // An explicit foreign customer_data id must not be paired with a linked appointment.
        $this->postCalendarSavePayload(
            $this->customerPayload($foreignCustomerId, 'Foreign Changed'),
            $this->appointmentPayload($appointmentId, $providerId, $pair['service_id'], $linkedCustomerId),
        );
        $this->createCalendarController()->save_appointment();

        $this->assertDeniedResponse();
        $this->assertSame('Foreign', $this->customerLastName($foreignCustomerId));
        $this->assertSame($linkedCustomerId, $this->appointmentCustomerId($appointmentId));
        $this->assertSame(0, $this->notifications->savedCalls);

        // An appointment-only customer assignment must be checked as well.
        $this->postCalendarSavePayload(
            [],
            $this->appointmentPayload($appointmentId, $providerId, $pair['service_id'], $foreignCustomerId),
        );
        $this->createCalendarController()->save_appointment();

        $this->assertDeniedResponse();
        $this->assertSame($linkedCustomerId, $this->appointmentCustomerId($appointmentId));
        $this->assertSame(0, $this->notifications->savedCalls);

        // The reverse mismatch must also be rejected.
        $this->postCalendarSavePayload(
            $this->customerPayload($linkedCustomerId, 'Linked Changed'),
            $this->appointmentPayload($appointmentId, $providerId, $pair['service_id'], $foreignCustomerId),
        );
        $this->createCalendarController()->save_appointment();

        $this->assertDeniedResponse();
        $this->assertSame('Linked', $this->customerLastName($linkedCustomerId));
        $this->assertSame($linkedCustomerId, $this->appointmentCustomerId($appointmentId));
        $this->assertSame(0, $this->notifications->savedCalls);
    }

    public function testProviderCanEditLinkedCustomerAndKeepAppointmentLinked(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $providerId = $pair['provider_id'];
        $customerId = $this->fixtures->createCustomer(['last_name' => 'Before']);
        $appointmentId = $this->fixtures->createAppointment(
            $providerId,
            $customerId,
            $pair['service_id'],
            new DateTimeImmutable('2035-02-11 09:00:00'),
        );

        $this->fixtures->setSetting('limit_customer_access', '1');
        $this->authenticateAsUser($providerId, DB_SLUG_PROVIDER);

        $this->assertTrue(get_instance()->permissions->has_customer_access($providerId, $customerId));
        $this->postCalendarSavePayload(
            $this->customerPayload($customerId, 'After'),
            $this->appointmentPayload($appointmentId, $providerId, $pair['service_id'], $customerId),
        );
        $this->setProviderCustomerPrivileges(PRIV_ADD);
        $this->createCalendarController()->save_appointment();
        $this->assertDeniedResponse();
        $this->assertSame('Before', $this->customerLastName($customerId));

        $this->setProviderCustomerPrivileges(PRIV_EDIT);
        $this->createCalendarController()->save_appointment();

        $response = $this->decodeJsonOutput();
        $this->assertTrue($response['success'] ?? false);
        $this->assertSame('After', $this->customerLastName($customerId));
        $this->assertSame($customerId, $this->appointmentCustomerId($appointmentId));
    }

    public function testSecretaryUsesAssignedProviderForCustomerAccess(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $providerId = $pair['provider_id'];
        $secretaryId = $this->createSecretaryForProvider($providerId);
        $linkedCustomerId = $this->fixtures->createCustomer(['last_name' => 'Secretary Linked']);
        $foreignCustomerId = $this->fixtures->createCustomer(['last_name' => 'Secretary Foreign']);
        $appointmentId = $this->fixtures->createAppointment(
            $providerId,
            $linkedCustomerId,
            $pair['service_id'],
            new DateTimeImmutable('2035-02-12 09:00:00'),
        );

        $this->fixtures->setSetting('limit_customer_access', '1');
        $this->authenticateAsUser($secretaryId, DB_SLUG_SECRETARY);

        $this->assertFalse(get_instance()->permissions->has_customer_access($secretaryId, $foreignCustomerId));
        $this->assertTrue(get_instance()->permissions->has_customer_access($secretaryId, $linkedCustomerId));

        $this->postCalendarSavePayload(
            $this->customerPayload($foreignCustomerId, 'Foreign Changed'),
            $this->appointmentPayload($appointmentId, $providerId, $pair['service_id'], $linkedCustomerId),
        );
        $this->createCalendarController()->save_appointment();

        $this->assertDeniedResponse();
        $this->assertSame('Secretary Foreign', $this->customerLastName($foreignCustomerId));
        $this->assertSame($linkedCustomerId, $this->appointmentCustomerId($appointmentId));
        $this->assertSame(0, $this->notifications->savedCalls);

        $this->postCalendarSavePayload(
            [],
            $this->appointmentPayload($appointmentId, $providerId, $pair['service_id'], $foreignCustomerId),
        );
        $this->createCalendarController()->save_appointment();

        $this->assertDeniedResponse();
        $this->assertSame($linkedCustomerId, $this->appointmentCustomerId($appointmentId));
        $this->assertSame(0, $this->notifications->savedCalls);

        $this->postCalendarSavePayload(
            $this->customerPayload($linkedCustomerId, 'Secretary Changed'),
            $this->appointmentPayload($appointmentId, $providerId, $pair['service_id'], $linkedCustomerId),
        );
        $this->createCalendarController()->save_appointment();

        $response = $this->decodeJsonOutput();
        $this->assertTrue($response['success'] ?? false);
        $this->assertSame('Secretary Changed', $this->customerLastName($linkedCustomerId));
    }

    #[DataProvider('unrestrictedCustomerAccessCases')]
    public function testAdminAndUnlimitedProviderCanAccessForeignCustomer(string $roleSlug, string $limit): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $linkedCustomerId = $this->fixtures->createCustomer();
        $foreignCustomerId = $this->fixtures->createCustomer(['last_name' => 'Foreign']);
        $appointmentId = $this->fixtures->createAppointment(
            $pair['provider_id'],
            $linkedCustomerId,
            $pair['service_id'],
            new DateTimeImmutable('2035-02-15 09:00:00'),
        );
        $userId = $roleSlug === DB_SLUG_ADMIN ? $this->userIdForRole($roleSlug) : $pair['provider_id'];
        $this->fixtures->setSetting('limit_customer_access', $limit);
        $this->authenticateAsUser($userId, $roleSlug);
        $this->postCalendarSavePayload(
            $this->customerPayload($foreignCustomerId, 'Allowed'),
            $this->appointmentPayload($appointmentId, $pair['provider_id'], $pair['service_id'], $foreignCustomerId),
        );
        $this->createCalendarController()->save_appointment();

        $response = $this->decodeJsonOutput();
        $this->assertTrue($response['success'] ?? false);
        $this->assertSame('Allowed', $this->customerLastName($foreignCustomerId));
        $this->assertSame($foreignCustomerId, $this->appointmentCustomerId($appointmentId));
    }

    public static function unrestrictedCustomerAccessCases(): array
    {
        return ['admin' => [DB_SLUG_ADMIN, '1'], 'unlimited provider' => [DB_SLUG_PROVIDER, '0']];
    }

    #[DataProvider('unrestrictedCustomerAccessCases')]
    public function testStaffRowsAreNeverAcceptedAsCustomers(string $roleSlug, string $limit): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $customerId = $this->fixtures->createCustomer(['last_name' => 'Customer']);
        $providerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])
            ->row_array();
        $this->assertNotEmpty($providerRole);
        $staffId = $this->fixtures->createCustomer([
            'id_roles' => (int) $providerRole['id'],
            'last_name' => 'Staff',
            'email' => 'calendar-staff-' . bin2hex(random_bytes(4)) . '@example.org',
        ]);
        $appointmentId = $this->fixtures->createAppointment(
            $pair['provider_id'],
            $customerId,
            $pair['service_id'],
            new DateTimeImmutable('2035-02-16 09:00:00'),
        );
        $userId = $roleSlug === DB_SLUG_ADMIN ? $this->userIdForRole($roleSlug) : $pair['provider_id'];

        $this->fixtures->setSetting('limit_customer_access', $limit);
        $this->authenticateAsUser($userId, $roleSlug);

        $this->assertFalse(get_instance()->permissions->has_customer_access($userId, $staffId));
        $this->postCalendarSavePayload(
            $this->customerPayload($staffId, 'Changed'),
            $this->appointmentPayload($appointmentId, $pair['provider_id'], $pair['service_id'], $customerId),
        );
        $this->createCalendarController()->save_appointment();

        $this->assertDeniedResponse();
        $this->assertSame('Staff', $this->customerLastName($staffId));
        $this->assertSame($customerId, $this->appointmentCustomerId($appointmentId));
        $this->assertSame(0, $this->notifications->savedCalls);

        get_instance()->load->model('customers_model');
        $this->assertRejectsStaffRow(fn() => get_instance()->customers_model->find($staffId));
        $this->assertRejectsStaffRow(
            fn() => get_instance()->customers_model->save($this->customerPayload($staffId, 'Changed')),
        );
        $this->assertRejectsStaffRow(fn() => get_instance()->customers_model->delete($staffId));
        $this->assertSame('Staff', $this->customerLastName($staffId));
    }

    public function testCustomerDeleteRollsBackIfRoleChangesInsideTransaction(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $customerId = $this->fixtures->createCustomer();
        $appointmentId = $this->fixtures->createAppointment(
            $pair['provider_id'],
            $customerId,
            $pair['service_id'],
            new DateTimeImmutable('2035-02-17 09:00:00'),
        );
        $providerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])
            ->row_array();
        $this->assertNotEmpty($providerRole);

        get_instance()->load->model('customers_model');
        $model = new class ((int) $providerRole['id']) extends \Customers_model {
            public function __construct(private readonly int $replacementRoleId)
            {
                parent::__construct();
            }

            protected function delete_buffer_blocks_for_customer(int $customer_id): void
            {
                $this->db->update('users', ['id_roles' => $this->replacementRoleId], ['id' => $customer_id]);
                parent::delete_buffer_blocks_for_customer($customer_id);
            }
        };

        try {
            $model->delete($customerId);
            $this->fail('Customer deletion should fail when the role changes during the transaction.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Customer role changed during deletion.', $exception->getMessage());
        }

        $customerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();
        $this->assertSame(
            (int) $customerRole['id'],
            (int) get_instance()
                ->db->get_where('users', ['id' => $customerId])
                ->row_array()['id_roles'],
        );
        $this->assertSame(
            1,
            get_instance()
                ->db->get_where('appointments', ['id' => $appointmentId])
                ->num_rows(),
        );
    }

    public function testCustomerUpdateFailsIfRoleChangesAfterValidation(): void
    {
        $customerId = $this->fixtures->createCustomer(['last_name' => 'Before']);
        $providerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])
            ->row_array();
        $this->assertNotEmpty($providerRole);
        $secondaryDatabase = get_instance()->load->database('', true);

        get_instance()->load->model('customers_model');
        $model = new class ($secondaryDatabase, (int) $providerRole['id']) extends \Customers_model {
            public function __construct(
                private readonly \CI_DB_query_builder $secondaryDatabase,
                private readonly int $replacementRoleId,
            ) {
                parent::__construct();
            }

            public function validate(array $customer): void
            {
                parent::validate($customer);
                $this->secondaryDatabase->update(
                    'users',
                    ['id_roles' => $this->replacementRoleId],
                    ['id' => $customer['id']],
                );
            }
        };

        $this->assertTrue(get_instance()->db->trans_begin());

        try {
            $this->assertRejectsStaffRow(fn() => $model->save($this->customerPayload($customerId, 'After')));
            $this->assertSame('Before', $this->customerLastName($customerId));
        } finally {
            get_instance()->db->trans_rollback();
            $secondaryDatabase->close();
        }
    }

    public function testLimitedProviderCanCreateUniqueCustomerThroughCalendarSave(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $providerId = $pair['provider_id'];
        $email = 'calendar-new-' . bin2hex(random_bytes(4)) . '@example.org';
        $this->cleanupEmails[] = $email;

        $this->fixtures->setSetting('limit_customer_access', '1');
        $this->authenticateAsUser($providerId, DB_SLUG_PROVIDER);
        $this->postCalendarSavePayload(
            $this->newCustomerPayload($email),
            $this->newAppointmentPayload($providerId, $pair['service_id']),
        );
        $this->setProviderCustomerPrivileges(PRIV_EDIT);
        $this->createCalendarController()->save_appointment();
        $this->assertDeniedResponse();
        $this->assertFalse($this->fixtures->customerExistsByEmail($email));

        $this->setProviderCustomerPrivileges(PRIV_ADD);
        $this->createCalendarController()->save_appointment();

        $response = $this->decodeJsonOutput();
        $this->assertTrue($response['success'] ?? false);
        $this->assertTrue($this->fixtures->customerExistsByEmail($email));
    }

    public function testDuplicateEmailIsRejectedBeforeAppointmentMutation(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $providerId = $pair['provider_id'];
        $customerId = $this->fixtures->createCustomer(['last_name' => 'Original']);
        $appointmentId = $this->fixtures->createAppointment(
            $providerId,
            $customerId,
            $pair['service_id'],
            new DateTimeImmutable('2035-02-13 09:00:00'),
        );
        $email = (string) get_instance()
            ->db->get_where('users', ['id' => $customerId])
            ->row_array()['email'];

        $this->fixtures->setSetting('limit_customer_access', '0');
        $this->authenticateAsUser($providerId, DB_SLUG_PROVIDER);
        $this->postCalendarSavePayload(
            $this->newCustomerPayload($email),
            $this->appointmentPayload($appointmentId, $providerId, $pair['service_id'], $customerId),
        );
        $this->createCalendarController()->save_appointment();

        $response = $this->decodeJsonOutput();
        $this->assertFalse($response['success'] ?? true);
        $this->assertStringContainsString('already in use', $response['message'] ?? '');
        $this->assertSame('Original', $this->customerLastName($customerId));
        $this->assertSame($customerId, $this->appointmentCustomerId($appointmentId));
    }

    /**
     * @param array<string, mixed> $customerData
     * @param array<string, mixed> $appointmentData
     */
    private function postCalendarSavePayload(array $customerData, array $appointmentData): void
    {
        $_POST = [
            'customer_data' => $customerData,
            'appointment_data' => $appointmentData,
        ];
        get_instance()->output->set_output('');
        http_response_code(200);
    }

    private function createCalendarController(): Calendar
    {
        $CI = &get_instance();

        $CI->load->model('appointments_model');
        $CI->load->model('customers_model');
        $CI->load->model('providers_model');
        $CI->load->model('services_model');
        $CI->load->model('secretaries_model');

        $controller = new class extends Calendar {
            public function __construct() {}
        };

        $controller->db = $CI->db;
        $controller->input = $CI->input;
        $controller->output = $CI->output;
        $controller->load = $CI->load;
        $controller->customers_model = $CI->customers_model;
        $controller->providers_model = $CI->providers_model;
        $controller->services_model = $CI->services_model;
        $controller->appointments_model = $CI->appointments_model;
        $controller->secretaries_model = $CI->secretaries_model;
        $controller->permissions = $CI->permissions;
        $controller->notifications = $this->notifications;

        return $controller;
    }

    /**
     * @return array<string, mixed>
     */
    private function customerPayload(int $customerId, string $lastName): array
    {
        return [
            'id' => $customerId,
            'first_name' => 'Access',
            'last_name' => $lastName,
            'email' => 'access-' . $customerId . '@example.org',
            'phone_number' => '+49123456789',
            'address' => 'Test Street 1',
            'city' => 'Test City',
            'zip_code' => '12345',
            'timezone' => setting('default_timezone') ?: 'UTC',
            'language' => setting('default_language') ?: 'english',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function newCustomerPayload(string $email): array
    {
        return [
            'first_name' => 'New',
            'last_name' => 'Calendar Customer',
            'email' => $email,
            'phone_number' => '+49123456789',
            'address' => 'Test Street 1',
            'city' => 'Test City',
            'zip_code' => '12345',
            'timezone' => setting('default_timezone') ?: 'UTC',
            'language' => setting('default_language') ?: 'english',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function newAppointmentPayload(int $providerId, int $serviceId): array
    {
        return [
            'start_datetime' => '2035-02-14 09:00:00',
            'end_datetime' => '2035-02-14 09:25:00',
            'id_users_provider' => $providerId,
            'id_services' => $serviceId,
            'location' => '',
            'notes' => 'Customer access regression',
            'color' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function appointmentPayload(int $id, int $providerId, int $serviceId, int $customerId): array
    {
        $appointment = get_instance()
            ->db->get_where('appointments', ['id' => $id])
            ->row_array();

        return [
            'id' => $id,
            'start_datetime' => $appointment['start_datetime'],
            'end_datetime' => $appointment['end_datetime'],
            'id_users_provider' => $providerId,
            'id_users_customer' => $customerId,
            'id_services' => $serviceId,
            'location' => '',
            'notes' => 'Customer access regression',
            'color' => '',
        ];
    }

    private function authenticateAsUser(int $userId, string $roleSlug): void
    {
        session([
            'user_id' => $userId,
            'role_slug' => $roleSlug,
            'language' => setting('default_language') ?: 'english',
            'timezone' => setting('default_timezone') ?: 'UTC',
        ]);
    }

    private function setProviderCustomerPrivileges(int $privileges): void
    {
        $this->providerRole ??= get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])
            ->row_array();
        get_instance()->db->update('roles', ['customers' => $privileges], ['id' => $this->providerRole['id']]);
    }

    private function userIdForRole(string $roleSlug): int
    {
        $row = get_instance()
            ->db->select('users.id')
            ->from('users')
            ->join('roles', 'roles.id = users.id_roles', 'inner')
            ->where('roles.slug', $roleSlug)
            ->limit(1)
            ->get()
            ->row_array();

        $this->assertNotEmpty($row);

        return (int) $row['id'];
    }

    private function createSecretaryForProvider(int $providerId): int
    {
        $role = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_SECRETARY])
            ->row_array();
        $this->assertNotEmpty($role);

        $secretaryId = $this->fixtures->createCustomer([
            'id_roles' => (int) $role['id'],
            'email' => 'calendar-secretary-' . bin2hex(random_bytes(4)) . '@example.org',
        ]);
        get_instance()->db->insert('secretaries_providers', [
            'id_users_secretary' => $secretaryId,
            'id_users_provider' => $providerId,
        ]);
        get_instance()->db->insert('user_settings', ['id_users' => $secretaryId]);
        $this->syntheticSecretaryId = $secretaryId;

        return $secretaryId;
    }

    private function assertDeniedResponse(): void
    {
        $response = $this->decodeJsonOutput();
        $this->assertFalse($response['success'] ?? true);
        $this->assertSame('You do not have the required permissions for this task.', $response['message'] ?? null);
    }

    private function assertRejectsStaffRow(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Staff rows must not be accepted by the customer model.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    private function customerLastName(int $customerId): string
    {
        return (string) get_instance()
            ->db->get_where('users', ['id' => $customerId])
            ->row_array()['last_name'];
    }

    private function appointmentCustomerId(int $appointmentId): int
    {
        return (int) get_instance()
            ->db->get_where('appointments', ['id' => $appointmentId])
            ->row_array()['id_users_customer'];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonOutput(): array
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
        config([
            'html_vars' => [],
            'script_vars' => [],
            'layout' => ['filename' => 'test-layout', 'sections' => [], 'tmp' => []],
        ]);
        get_instance()->output->set_output('');
        http_response_code(200);
    }
}
