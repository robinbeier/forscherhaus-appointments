<?php

namespace Tests\Integration\Controllers;

use Customers;
use DateTimeImmutable;
use Tests\Integration\Support\BookingFlowFixtures;
use Tests\TestCase;

require_once APPPATH . 'controllers/Customers.php';

/**
 * Isolate controller integration tests from Unit test global state during coverage runs.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CustomersSearchProviderFilterTest extends TestCase
{
    private BookingFlowFixtures $fixtures;

    /**
     * @var array<int>
     */
    private array $createdProviderIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtures = new BookingFlowFixtures();

        $this->resetRuntimeState();
        $this->authenticateAsAdmin();
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeState();
        $this->fixtures->cleanup();
        $this->cleanupCreatedProviders();

        parent::tearDown();
    }

    public function testProviderFilterLimitsAttachedCustomerAppointments(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $otherProviderId = $this->createProviderForService($pair['service_id']);
        $unique = bin2hex(random_bytes(4));
        $customerId = $this->fixtures->createCustomer([
            'first_name' => 'ProviderFilter',
            'last_name' => 'Details-' . $unique,
        ]);

        $matchingAppointmentId = $this->fixtures->createAppointment(
            $pair['provider_id'],
            $customerId,
            $pair['service_id'],
            new DateTimeImmutable('2031-01-17 10:00:00'),
        );
        $this->fixtures->createAppointment(
            $otherProviderId,
            $customerId,
            $pair['service_id'],
            new DateTimeImmutable('2031-01-17 11:00:00'),
        );

        $this->postCustomerSearchPayload($unique, $pair['provider_id']);

        $controller = $this->createCustomersController();

        $controller->search();

        $response = json_decode(get_instance()->output->get_output(), true);

        $this->assertIsArray($response);

        $matchingCustomer = $this->findCustomerResult($response, $customerId);

        $this->assertNotNull($matchingCustomer);
        $this->assertCount(1, $matchingCustomer['appointments']);
        $this->assertSame($matchingAppointmentId, (int) $matchingCustomer['appointments'][0]['id']);
        $this->assertSame($pair['provider_id'], (int) $matchingCustomer['appointments'][0]['id_users_provider']);
    }

    private function postCustomerSearchPayload(string $keyword, int $providerId): void
    {
        $_POST = [
            'keyword' => $keyword,
            'limit' => '20',
            'offset' => '0',
            'order_by' => 'update_datetime DESC',
            'provider_id' => (string) $providerId,
        ];
    }

    private function createCustomersController(): Customers
    {
        $CI = &get_instance();

        $CI->load->model('appointments_model');
        $CI->load->model('customers_model');
        $CI->load->library('backoffice_request_dto_factory');
        $CI->load->library('permissions');

        $controller = new class extends Customers {
            public function __construct() {}
        };

        $controller->db = $CI->db;
        $controller->input = $CI->input;
        $controller->output = $CI->output;
        $controller->load = $CI->load;
        $controller->appointments_model = $CI->appointments_model;
        $controller->customers_model = $CI->customers_model;
        $controller->backoffice_request_dto_factory = $CI->backoffice_request_dto_factory;
        $controller->permissions = $CI->permissions;

        return $controller;
    }

    private function authenticateAsAdmin(): void
    {
        $admin = get_instance()
            ->db->select('users.id')
            ->from('users')
            ->join('roles', 'roles.id = users.id_roles', 'inner')
            ->where('roles.slug', DB_SLUG_ADMIN)
            ->limit(1)
            ->get()
            ->row_array();

        $this->assertNotEmpty($admin);

        session([
            'user_id' => (int) $admin['id'],
            'role_slug' => DB_SLUG_ADMIN,
            'language' => setting('default_language') ?: 'english',
            'timezone' => setting('default_timezone') ?: 'UTC',
        ]);
    }

    private function createProviderForService(int $serviceId): int
    {
        $CI = &get_instance();
        $role = $CI->db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])->row_array();

        $this->assertNotEmpty($role);

        $unique = bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');

        $CI->db->insert('users', [
            'first_name' => 'Other',
            'last_name' => 'Provider-' . $unique,
            'email' => 'provider-filter-other-' . $unique . '@example.org',
            'timezone' => setting('default_timezone') ?: 'UTC',
            'language' => setting('default_language') ?: 'english',
            'id_roles' => (int) $role['id'],
            'create_datetime' => $now,
            'update_datetime' => $now,
        ]);

        $providerId = (int) $CI->db->insert_id();

        $CI->db->insert('services_providers', [
            'id_users' => $providerId,
            'id_services' => $serviceId,
        ]);

        $this->createdProviderIds[] = $providerId;

        return $providerId;
    }

    private function cleanupCreatedProviders(): void
    {
        $CI = &get_instance();

        foreach ($this->createdProviderIds as $providerId) {
            $CI->db->delete('services_providers', ['id_users' => $providerId]);
            $CI->db->delete('users', ['id' => $providerId]);
        }

        $this->createdProviderIds = [];
    }

    /**
     * @param array<int, array<string, mixed>> $customers
     *
     * @return array<string, mixed>|null
     */
    private function findCustomerResult(array $customers, int $customerId): ?array
    {
        foreach ($customers as $customer) {
            if ((int) $customer['id'] === $customerId) {
                return $customer;
            }
        }

        return null;
    }

    private function resetRuntimeState(): void
    {
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';

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
