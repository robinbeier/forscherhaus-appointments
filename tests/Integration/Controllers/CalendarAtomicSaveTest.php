<?php

namespace Tests\Integration\Controllers;

use Calendar;
use RuntimeException;
use Tests\Integration\Support\BookingFlowFixtures;
use Tests\TestCase;

require_once APPPATH . 'controllers/Calendar.php';

/**
 * Isolate controller integration tests from Unit test global state during coverage runs.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CalendarAtomicSaveTest extends TestCase
{
    private BookingFlowFixtures $fixtures;

    /**
     * @var array<string>
     */
    private array $cleanupEmails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtures = new BookingFlowFixtures();

        $this->resetRuntimeState();
        $this->authenticateAsAdmin();
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanupEmails as $email) {
            get_instance()->db->delete('users', ['email' => $email]);
        }

        $this->cleanupEmails = [];

        $this->resetRuntimeState();
        $this->fixtures->cleanup();

        parent::tearDown();
    }

    public function testNewCustomerIsRolledBackWhenBackendAppointmentSaveFails(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $customerEmail = 'calendar-atomic-new-' . bin2hex(random_bytes(4)) . '@example.org';

        $this->cleanupEmails[] = $customerEmail;

        $this->postCalendarSavePayload($pair['provider_id'], $pair['service_id'], [
            'first_name' => 'Atomic',
            'last_name' => 'Rollback',
            'email' => $customerEmail,
            'phone_number' => '+49123456789',
            'timezone' => setting('default_timezone') ?: 'UTC',
            'language' => setting('default_language') ?: 'english',
        ]);

        $controller = $this->createCalendarControllerWithFailingAppointmentSave();

        $controller->save_appointment();

        $response = json_decode(get_instance()->output->get_output(), true);

        $this->assertIsArray($response);
        $this->assertFalse($response['success'] ?? true);
        $this->assertSame(lang('buffer_conflict_error'), $response['message'] ?? null);
        $this->assertFalse($this->fixtures->customerExistsByEmail($customerEmail));
    }

    public function testExistingCustomerIsPreservedWhenBackendAppointmentSaveFails(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $customerEmail = 'calendar-atomic-existing-' . bin2hex(random_bytes(4)) . '@example.org';

        $customerId = $this->fixtures->createCustomer([
            'first_name' => 'Existing',
            'last_name' => 'Original',
            'email' => $customerEmail,
            'timezone' => setting('default_timezone') ?: 'UTC',
            'language' => setting('default_language') ?: 'english',
        ]);

        $this->postCalendarSavePayload($pair['provider_id'], $pair['service_id'], [
            'id' => $customerId,
            'first_name' => 'Existing',
            'last_name' => 'Changed',
            'email' => $customerEmail,
            'phone_number' => '+49123456789',
            'timezone' => setting('default_timezone') ?: 'UTC',
            'language' => setting('default_language') ?: 'english',
        ]);

        $controller = $this->createCalendarControllerWithFailingAppointmentSave();

        $controller->save_appointment();

        $customer = get_instance()
            ->db->get_where('users', ['id' => $customerId])
            ->row_array();

        $this->assertNotEmpty($customer);
        $this->assertSame('Original', $customer['last_name']);
        $this->assertSame($customerEmail, $customer['email']);
    }

    /**
     * @param array<string, mixed> $customerData
     */
    private function postCalendarSavePayload(int $providerId, int $serviceId, array $customerData): void
    {
        $_POST['customer_data'] = $customerData;
        $_POST['appointment_data'] = [
            'start_datetime' => '2030-01-15 10:00:00',
            'end_datetime' => '2030-01-15 10:25:00',
            'id_users_provider' => $providerId,
            'id_services' => $serviceId,
            'location' => '',
            'notes' => 'Calendar atomic save regression',
            'color' => '',
        ];
    }

    private function createCalendarControllerWithFailingAppointmentSave(): Calendar
    {
        $CI = &get_instance();

        $CI->load->model('customers_model');
        $CI->load->model('providers_model');
        $CI->load->model('services_model');

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
        $controller->appointments_model = new class {
            public function only(array &$record, array $fields): void {}

            public function optional(array &$record, array $fields): void {}

            public function save(array $appointment): int
            {
                throw new RuntimeException(lang('buffer_conflict_error'));
            }
        };
        $controller->synchronization = BookingFlowFixtures::createNoopSynchronization();
        $controller->notifications = BookingFlowFixtures::createNoopNotifications();
        $controller->webhooks_client = BookingFlowFixtures::createNoopWebhooksClient();

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
