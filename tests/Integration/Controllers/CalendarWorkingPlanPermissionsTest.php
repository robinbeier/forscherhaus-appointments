<?php

namespace Tests\Integration\Controllers;

use Calendar;
use Tests\Integration\Support\BookingFlowFixtures;
use Tests\TestCase;

require_once APPPATH . 'controllers/Calendar.php';

/**
 * Isolate controller integration tests from Unit test global state during coverage runs.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CalendarWorkingPlanPermissionsTest extends TestCase
{
    private BookingFlowFixtures $fixtures;

    private int $providerId;

    private ?string $originalWorkingPlanExceptions = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtures = new BookingFlowFixtures();
        $this->resetRuntimeState();

        $pair = $this->fixtures->resolveProviderServicePair();
        $this->providerId = $pair['provider_id'];
        $settings = get_instance()
            ->db->get_where('user_settings', ['id_users' => $this->providerId])
            ->row_array();
        $this->assertNotEmpty($settings);
        $this->originalWorkingPlanExceptions = $settings['working_plan_exceptions'] ?? '{}';
    }

    protected function tearDown(): void
    {
        if ($this->originalWorkingPlanExceptions !== null) {
            get_instance()->db->update(
                'user_settings',
                ['working_plan_exceptions' => $this->originalWorkingPlanExceptions],
                ['id_users' => $this->providerId],
            );
        }

        $this->resetRuntimeState();
        $this->fixtures->cleanup();

        parent::tearDown();
    }

    public function testProviderAndSecretaryMayNotDeleteWorkingPlanExceptionWithoutUsersEdit(): void
    {
        foreach ([DB_SLUG_PROVIDER, DB_SLUG_SECRETARY] as $roleSlug) {
            $date = '2035-01-' . ($roleSlug === DB_SLUG_PROVIDER ? '15' : '16');
            $this->createWorkingPlanException($date);
            $this->authenticateAsRole($roleSlug);

            $this->assertTrue(can('edit', PRIV_CUSTOMERS));
            $this->assertFalse(can('edit', PRIV_USERS));

            $this->postWorkingPlanException($date);
            $this->calendarController()->delete_working_plan_exception();

            $response = $this->decodeJsonOutput();
            $this->assertFalse($response['success'] ?? true, $roleSlug);
            $this->assertSame('You do not have the required permissions for this task.', $response['message'] ?? null);
            $this->assertArrayHasKey($date, $this->workingPlanExceptions());
        }
    }

    public function testAdminWithUsersEditCanDeleteWorkingPlanException(): void
    {
        $date = '2035-01-17';
        $this->createWorkingPlanException($date);
        $this->authenticateAsRole(DB_SLUG_ADMIN);

        $this->assertTrue(can('edit', PRIV_USERS));

        $this->postWorkingPlanException($date);
        $this->calendarController()->delete_working_plan_exception();

        $response = $this->decodeJsonOutput();
        $this->assertTrue($response['success'] ?? false);
        $this->assertArrayNotHasKey($date, $this->workingPlanExceptions());
    }

    private function calendarController(): Calendar
    {
        $CI = &get_instance();
        $CI->load->model('providers_model');
        $controller = new class extends Calendar {
            public function __construct() {}
        };
        $controller->input = $CI->input;
        $controller->load = $CI->load;
        $controller->providers_model = $CI->providers_model;

        return $controller;
    }

    private function createWorkingPlanException(string $date): void
    {
        get_instance()->load->model('providers_model');
        get_instance()->providers_model->save_working_plan_exception($this->providerId, $date, [
            'start' => '09:00',
            'end' => '10:00',
            'breaks' => [],
        ]);
    }

    private function postWorkingPlanException(string $date): void
    {
        $_POST = [
            'provider_id' => (string) $this->providerId,
            'date' => $date,
            'original_date' => '',
            'working_plan_exception' => '{}',
        ];
        get_instance()->output->set_output('');
        http_response_code(200);
    }

    /**
     * @return array<string, mixed>
     */
    private function workingPlanExceptions(): array
    {
        $value =
            get_instance()
                ->db->select('working_plan_exceptions')
                ->get_where('user_settings', ['id_users' => $this->providerId])
                ->row_array()['working_plan_exceptions'] ?? '{}';

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function authenticateAsRole(string $roleSlug): void
    {
        $role = get_instance()
            ->db->get_where('roles', ['slug' => $roleSlug])
            ->row_array();

        $this->assertNotEmpty($role, 'Missing role ' . $roleSlug);
        $userId = $this->fixtures->createCustomer(['id_roles' => (int) $role['id']]);

        session([
            'user_id' => $userId,
            'role_slug' => $roleSlug,
            'language' => setting('default_language') ?: 'english',
            'timezone' => setting('default_timezone') ?: 'UTC',
        ]);
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
