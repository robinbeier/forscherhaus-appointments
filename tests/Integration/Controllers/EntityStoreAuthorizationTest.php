<?php

namespace Tests\Integration\Controllers;

use Customers;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Services;
use Tests\Integration\Support\BookingFlowFixtures;
use Tests\TestCase;

require_once APPPATH . 'controllers/Customers.php';
require_once APPPATH . 'controllers/Services.php';

/** Exercise the back-office create boundary against the real models and test database. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EntityStoreAuthorizationTest extends TestCase
{
    private BookingFlowFixtures $fixtures;

    private \EA_Output $originalOutput;

    private ?array $providerRole = null;

    /** @var list<int> */
    private array $createdServiceIds = [];

    /** @var list<int> */
    private array $createdCustomerIds = [];

    /** @var array<string, mixed>|null */
    private ?array $originalEditedService = null;

    protected function setUp(): void
    {
        parent::setUp();

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
        $this->fixtures->snapshotSettings(['limit_customer_visibility']);
        $this->providerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])
            ->row_array();
        $this->assertNotEmpty($this->providerRole);

        get_instance()->db->update(
            'roles',
            [
                'customers' => PRIV_VIEW | PRIV_ADD,
                'services' => PRIV_VIEW | PRIV_ADD,
            ],
            ['id' => $this->providerRole['id']],
        );
        get_instance()->load->model('customers_model');
        get_instance()->load->model('services_model');
        get_instance()->load->library('backoffice_request_dto_factory');
        $this->fixtures->setSetting('limit_customer_visibility', '0');
        get_instance()->load->library('permissions');

        $this->resetRuntimeState();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdServiceIds as $serviceId) {
            get_instance()->db->delete('services_providers', ['id_services' => $serviceId]);
            get_instance()->db->delete('services', ['id' => $serviceId]);
        }

        foreach ($this->createdCustomerIds as $customerId) {
            get_instance()->db->delete('users', ['id' => $customerId]);
        }

        if ($this->originalEditedService !== null) {
            get_instance()->db->update('services', $this->originalEditedService, [
                'id' => $this->originalEditedService['id'],
            ]);
        }

        if ($this->providerRole !== null) {
            get_instance()->db->update(
                'roles',
                [
                    'customers' => $this->providerRole['customers'],
                    'services' => $this->providerRole['services'],
                ],
                ['id' => $this->providerRole['id']],
            );
        }

        $this->resetRuntimeState();
        $this->fixtures->cleanup();

        get_instance()->output = $this->originalOutput;
        parent::tearDown();
    }

    public function testProviderMayCreateCustomerAndServiceWithoutAnId(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $this->authenticateAsProvider($pair['provider_id']);

        $customerEmail = 'store-auth-' . bin2hex(random_bytes(4)) . '@example.org';
        $_POST['customer'] = $this->customerPayload($customerEmail, 'Created');
        $customerController = $this->customersController();
        $customerWebhook = BookingFlowFixtures::createNoopWebhooksClient();
        $customerController->webhooks_client = $customerWebhook;
        $customerController->store();

        $customerResponse = $this->decodeJsonOutput();
        self::assertTrue($customerResponse['success'] ?? false);
        $customerId = (int) ($customerResponse['id'] ?? 0);
        self::assertGreaterThan(0, $customerId);
        $this->createdCustomerIds[] = $customerId;
        self::assertSame(
            'Created',
            (string) get_instance()
                ->db->get_where('users', ['id' => $customerId])
                ->row_array()['last_name'],
        );
        self::assertSame(1, $customerWebhook->calls);

        $this->resetRuntimeState();
        $serviceName = 'Store auth ' . bin2hex(random_bytes(4));
        $_POST['service'] = [
            'name' => $serviceName,
            'duration' => EVENT_MINIMUM_DURATION,
            'price' => 0,
            'currency' => 'EUR',
            'description' => '',
            'color' => '#000000',
            'location' => '',
            'availabilities_type' => 'flexible',
            'attendants_number' => 1,
            'buffer_before' => 0,
            'buffer_after' => 0,
            'is_private' => 0,
        ];
        $serviceController = $this->servicesController();
        $serviceWebhook = BookingFlowFixtures::createNoopWebhooksClient();
        $serviceController->webhooks_client = $serviceWebhook;
        $serviceController->store();

        $serviceResponse = $this->decodeJsonOutput();
        self::assertTrue($serviceResponse['success'] ?? false);
        $serviceId = (int) ($serviceResponse['id'] ?? 0);
        self::assertGreaterThan(0, $serviceId);
        $this->createdServiceIds[] = $serviceId;
        self::assertSame(
            $serviceName,
            (string) get_instance()
                ->db->get_where('services', ['id' => $serviceId])
                ->row_array()['name'],
        );
        self::assertSame(1, $serviceWebhook->calls);
    }

    public function testExistingIdsAreRejectedWithoutMutationOrWebhook(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $this->authenticateAsProvider($pair['provider_id']);

        $customerEmail = 'store-existing-' . bin2hex(random_bytes(4)) . '@example.org';
        $customerId = $this->fixtures->createCustomer([
            'email' => $customerEmail,
            'last_name' => 'Original',
        ]);

        $_POST['customer'] = $this->customerPayload($customerEmail, 'Changed') + ['id' => $customerId];
        $customerController = $this->customersController();
        $customerWebhook = BookingFlowFixtures::createNoopWebhooksClient();
        $customerController->webhooks_client = $customerWebhook;
        $customerController->store();

        $customerResponse = $this->decodeJsonOutput();
        self::assertFalse($customerResponse['success'] ?? true);
        self::assertSame(403, get_instance()->output->statusCode);
        self::assertSame(
            'Original',
            (string) get_instance()
                ->db->get_where('users', ['id' => $customerId])
                ->row_array()['last_name'],
        );
        self::assertSame(0, $customerWebhook->calls);

        $service = get_instance()
            ->db->get_where('services', ['id' => $pair['service_id']])
            ->row_array();
        self::assertNotEmpty($service);
        $originalName = (string) $service['name'];

        $this->resetRuntimeState();
        $_POST['service'] = [
            'id' => $pair['service_id'],
            'name' => $originalName . ' changed',
            'duration' => $service['duration'],
            'price' => $service['price'],
            'currency' => $service['currency'],
            'description' => $service['description'],
            'color' => $service['color'],
            'location' => $service['location'],
            'availabilities_type' => $service['availabilities_type'],
            'attendants_number' => $service['attendants_number'],
            'buffer_before' => $service['buffer_before'],
            'buffer_after' => $service['buffer_after'],
            'is_private' => $service['is_private'],
        ];
        $serviceController = $this->servicesController();
        $serviceWebhook = BookingFlowFixtures::createNoopWebhooksClient();
        $serviceController->webhooks_client = $serviceWebhook;
        $serviceController->store();

        $serviceResponse = $this->decodeJsonOutput();
        self::assertFalse($serviceResponse['success'] ?? true);
        self::assertSame(403, get_instance()->output->statusCode);
        self::assertSame(
            $originalName,
            (string) get_instance()
                ->db->get_where('services', ['id' => $pair['service_id']])
                ->row_array()['name'],
        );
        self::assertSame(0, $serviceWebhook->calls);
    }

    public function testExistingCustomerEmailWithoutIdCannotChangeExistingRecord(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $this->authenticateAsProvider($pair['provider_id']);

        $email = 'store-duplicate-' . bin2hex(random_bytes(4)) . '@example.org';
        $customerId = $this->fixtures->createCustomer([
            'email' => $email,
            'last_name' => 'Original',
        ]);

        $_POST['customer'] = $this->customerPayload($email, 'Changed');
        $controller = $this->customersController();
        $webhook = BookingFlowFixtures::createNoopWebhooksClient();
        $controller->webhooks_client = $webhook;
        $controller->store();

        $response = $this->decodeJsonOutput();
        self::assertFalse($response['success'] ?? true);
        self::assertSame(
            'Original',
            (string) get_instance()
                ->db->get_where('users', ['id' => $customerId])
                ->row_array()['last_name'],
        );
        self::assertSame(0, $webhook->calls);
    }

    public function testAdminEditPathsRemainAvailable(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $adminId = $this->userIdForRole(DB_SLUG_ADMIN);
        $this->authenticateAs($adminId, DB_SLUG_ADMIN);

        $customerEmail = 'store-edit-' . bin2hex(random_bytes(4)) . '@example.org';
        $customerId = $this->fixtures->createCustomer(['email' => $customerEmail, 'last_name' => 'Before']);
        $_POST['customer'] = $this->customerPayload($customerEmail, 'After') + ['id' => $customerId];
        $customerController = $this->customersController();
        $customerController->webhooks_client = BookingFlowFixtures::createNoopWebhooksClient();
        $customerController->update();
        $response = $this->decodeJsonOutput();
        self::assertTrue($response['success'] ?? false, json_encode($response, JSON_THROW_ON_ERROR));
        self::assertSame(
            'After',
            (string) get_instance()
                ->db->get_where('users', ['id' => $customerId])
                ->row_array()['last_name'],
        );

        $this->resetRuntimeState();
        $service = get_instance()
            ->db->get_where('services', ['id' => $pair['service_id']])
            ->row_array();
        $this->originalEditedService = $service;
        $_POST['service'] = [
            'id' => $pair['service_id'],
            'name' => (string) $service['name'] . ' edited',
            'duration' => $service['duration'],
            'price' => $service['price'],
            'currency' => $service['currency'],
            'description' => $service['description'],
            'color' => $service['color'],
            'location' => $service['location'],
            'availabilities_type' => $service['availabilities_type'],
            'attendants_number' => $service['attendants_number'],
            'buffer_before' => $service['buffer_before'],
            'buffer_after' => $service['buffer_after'],
            'is_private' => $service['is_private'],
        ];
        $serviceController = $this->servicesController();
        $serviceController->webhooks_client = BookingFlowFixtures::createNoopWebhooksClient();
        $serviceController->update();
        $response = $this->decodeJsonOutput();
        self::assertTrue($response['success'] ?? false, json_encode($response, JSON_THROW_ON_ERROR));
    }

    /** @return array<string,string> */
    private function customerPayload(string $email, string $lastName): array
    {
        return [
            'first_name' => 'Store',
            'last_name' => $lastName,
            'email' => $email,
            'phone_number' => '+49123456789',
            'address' => 'Test Street 1',
            'city' => 'Test City',
            'zip_code' => '12345',
            'timezone' => setting('default_timezone') ?: 'UTC',
            'language' => setting('default_language') ?: 'english',
        ];
    }

    private function customersController(): Customers
    {
        $CI = &get_instance();
        $controller = new class extends Customers {
            public function __construct() {}
        };
        $controller->load = $CI->load;
        $controller->customers_model = $CI->customers_model;
        $controller->permissions = $CI->permissions;

        return $controller;
    }

    private function servicesController(): Services
    {
        $CI = &get_instance();
        $controller = new class extends Services {
            public function __construct() {}
        };
        $controller->load = $CI->load;
        $controller->services_model = $CI->services_model;
        $controller->db = $CI->db;

        return $controller;
    }

    private function authenticateAsProvider(int $providerId): void
    {
        $this->authenticateAs($providerId, DB_SLUG_PROVIDER);
        self::assertTrue(cannot('edit', PRIV_CUSTOMERS));
        self::assertTrue(cannot('edit', PRIV_SERVICES));
    }

    private function authenticateAs(int $userId, string $roleSlug): void
    {
        session([
            'user_id' => $userId,
            'role_slug' => $roleSlug,
            'language' => setting('default_language') ?: 'english',
            'timezone' => setting('default_timezone') ?: 'UTC',
        ]);
    }

    private function userIdForRole(string $roleSlug): int
    {
        $row = get_instance()
            ->db->select('users.id')
            ->from('users')
            ->join('roles', 'roles.id = users.id_roles')
            ->where('roles.slug', $roleSlug)
            ->limit(1)
            ->get()
            ->row_array();
        self::assertNotEmpty($row);
        return (int) $row['id'];
    }

    /** @return array<string,mixed> */
    private function decodeJsonOutput(): array
    {
        $response = json_decode(get_instance()->output->get_output(), true);
        self::assertIsArray($response);
        return $response;
    }

    private function resetRuntimeState(): void
    {
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        get_instance()->output->set_output('');
        get_instance()->output->statusCode = 200;
    }
}
