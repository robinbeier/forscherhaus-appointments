<?php

namespace Tests\Unit\Libraries;

use Booking;
use Provider_ui_smoke_access_policy;
use Provider_ui_smoke_fixture;
use RuntimeException;
use Tests\TestCase;

require_once APPPATH . 'controllers/Booking.php';
require_once APPPATH . 'core/Provider_ui_smoke_access_policy.php';
require_once APPPATH . 'libraries/Provider_ui_smoke_fixture.php';

class ProviderUiSmokeFixtureLifecycleTest extends TestCase
{
    private Provider_ui_smoke_fixture $fixture;

    private string $temporaryDirectory;

    private string $credentialFile;

    private string $stateFile;

    /**
     * @var EA_Controller|\CI_Controller
     */
    private $CI;

    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            $this->markTestSkipped('The root-only lifecycle test runs inside the PHP Docker service.');
        }

        $this->CI = &get_instance();
        $this->CI->load->library('provider_ui_smoke_fixture');
        $this->fixture = $this->CI->provider_ui_smoke_fixture;
        $this->temporaryDirectory = sys_get_temp_dir() . '/fh-provider-ui-smoke-lifecycle-' . bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($this->temporaryDirectory, 0700));
        $this->assertTrue(chmod($this->temporaryDirectory, 0700));
        $this->credentialFile = $this->temporaryDirectory . '/credentials.env';
        $this->stateFile = $this->temporaryDirectory . '/active.json';
        $credentials =
            'PROVIDER_UI_SMOKE_USERNAME=' .
            Provider_ui_smoke_access_policy::USERNAME .
            PHP_EOL .
            'PROVIDER_UI_SMOKE_PASSWORD=' .
            str_repeat('a', 64) .
            PHP_EOL;
        $this->assertNotFalse(file_put_contents($this->credentialFile, $credentials));
        $this->assertTrue(chmod($this->credentialFile, 0600));
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture, $this->credentialFile, $this->stateFile)) {
            try {
                $this->fixture->run('deactivate', $this->credentialFile, $this->stateFile);
            } catch (\Throwable) {
                // The tested invariant failure remains visible; cleanup continues best-effort.
            }

            try {
                $this->fixture->run('remove', $this->credentialFile, $this->stateFile);
            } catch (\Throwable) {
                // The tested invariant failure remains visible; cleanup continues best-effort.
            }
        }

        if (isset($this->credentialFile) && is_file($this->credentialFile)) {
            unlink($this->credentialFile);
        }

        if (isset($this->stateFile) && is_file($this->stateFile)) {
            unlink($this->stateFile);
        }

        if (isset($this->temporaryDirectory) && is_dir($this->temporaryDirectory)) {
            rmdir($this->temporaryDirectory);
        }

        parent::tearDown();
    }

    public function testFullLifecycleIsIdempotentAndLeavesNoSyntheticRows(): void
    {
        $this->assertSame('dormant', $this->fixture->run('install', $this->credentialFile, $this->stateFile));
        $this->assertSame('dormant', $this->fixture->run('install', $this->credentialFile, $this->stateFile));
        $this->assertSame('dormant', $this->fixture->run('verify', $this->credentialFile, $this->stateFile));

        $dormant = $this->loadPrincipal();
        $this->assertSame(Provider_ui_smoke_access_policy::DORMANT_ROLE_SLUG, $dormant['role_slug']);
        $this->assertNull($dormant['password']);
        $this->assertNull($dormant['salt']);

        $this->assertSame('active', $this->fixture->run('activate', $this->credentialFile, $this->stateFile));
        $this->assertFileExists($this->stateFile);
        $this->assertSame(0600, fileperms($this->stateFile) & 0777);

        $active = $this->loadPrincipal();
        $this->assertSame(DB_SLUG_PROVIDER, $active['role_slug']);
        $this->assertNotEmpty($active['password']);
        $this->assertNotEmpty($active['salt']);
        $this->assertTrue(Provider_ui_smoke_access_policy::hasActiveLease((string) $active['notes']));
        $this->assertSame(1, $this->markerCount('services', 'name', Provider_ui_smoke_fixture::SERVICE_NAME));
        $this->assertSame(1, $this->markerCount('users', 'email', Provider_ui_smoke_fixture::CUSTOMER_EMAIL));
        $this->assertSame(
            3,
            $this->CI->db
                ->where_in('notes', [
                    Provider_ui_smoke_fixture::APPOINTMENT_BOOKED_INSIDE_NOTES,
                    Provider_ui_smoke_fixture::APPOINTMENT_CANCELLED_INSIDE_NOTES,
                    Provider_ui_smoke_fixture::APPOINTMENT_BOOKED_OUTSIDE_NOTES,
                ])
                ->get('appointments')
                ->num_rows(),
        );

        try {
            $this->fixture->run('verify', $this->credentialFile, $this->stateFile);
            $this->fail('An active fixture must not pass the dormant verification action.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertTrue(
            $this->CI->db->update(
                'user_settings',
                [
                    'dashboard_range_start' => '2099-05-07',
                    'dashboard_range_end' => '2099-05-09',
                ],
                ['id_users' => $active['id']],
            ),
        );
        $this->assertSame('dormant', $this->fixture->run('deactivate', $this->credentialFile, $this->stateFile));
        $this->assertFileDoesNotExist($this->stateFile);
        $this->assertSame('dormant', $this->fixture->run('deactivate', $this->credentialFile, $this->stateFile));
        $this->assertSame('dormant', $this->fixture->run('verify', $this->credentialFile, $this->stateFile));
        $this->assertSame(0, $this->markerCount('services', 'name', Provider_ui_smoke_fixture::SERVICE_NAME));
        $this->assertSame(0, $this->markerCount('users', 'email', Provider_ui_smoke_fixture::CUSTOMER_EMAIL));

        $dormant = $this->loadPrincipal();
        $this->assertSame(Provider_ui_smoke_access_policy::DORMANT_ROLE_SLUG, $dormant['role_slug']);
        $this->assertNull($dormant['password']);
        $this->assertNull($dormant['salt']);

        $this->assertSame('active', $this->fixture->run('activate', $this->credentialFile, $this->stateFile));
        $active = $this->loadPrincipal();
        $this->assertTrue(
            $this->CI->db->update(
                'user_settings',
                [
                    'dashboard_range_start' => '2099-04-01',
                    'dashboard_range_end' => '2099-04-02',
                ],
                ['id_users' => $active['id']],
            ),
        );
        $this->assertTrue(unlink($this->stateFile));
        $this->assertSame('dormant', $this->fixture->run('deactivate', $this->credentialFile, $this->stateFile));
        $this->assertSame('dormant', $this->fixture->run('verify', $this->credentialFile, $this->stateFile));

        $this->assertSame('removed', $this->fixture->run('remove', $this->credentialFile, $this->stateFile));
        $this->assertSame('removed', $this->fixture->run('remove', $this->credentialFile, $this->stateFile));
        $this->assertSame('removed', $this->fixture->run('verify', $this->credentialFile, $this->stateFile));
        $this->assertSame(
            0,
            $this->CI->db
                ->get_where('user_settings', ['username' => Provider_ui_smoke_access_policy::USERNAME])
                ->num_rows(),
        );
        $this->assertSame(
            0,
            $this->CI->db
                ->get_where('roles', ['slug' => Provider_ui_smoke_access_policy::DORMANT_ROLE_SLUG])
                ->num_rows(),
        );
    }

    public function testInstallFailsClosedOnAnExistingSyntheticMarkerWithoutLeavingAPrincipal(): void
    {
        $this->assertTrue(
            $this->CI->db->insert('services', [
                'name' => Provider_ui_smoke_fixture::SERVICE_NAME,
                'description' => 'collision',
            ]),
        );

        try {
            $this->fixture->run('install', $this->credentialFile, $this->stateFile);
            $this->fail('A pre-existing marker must stop installation.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        } finally {
            $this->CI->db->delete('services', ['name' => Provider_ui_smoke_fixture::SERVICE_NAME]);
        }

        $this->assertSame(
            0,
            $this->CI->db
                ->get_where('user_settings', ['username' => Provider_ui_smoke_access_policy::USERNAME])
                ->num_rows(),
        );
        $this->assertSame(
            0,
            $this->CI->db
                ->get_where('roles', ['slug' => Provider_ui_smoke_access_policy::DORMANT_ROLE_SLUG])
                ->num_rows(),
        );
    }

    public function testDeactivateRefusesAnUnexpectedChildInsteadOfExpandingCleanupScope(): void
    {
        $this->assertSame('dormant', $this->fixture->run('install', $this->credentialFile, $this->stateFile));
        $this->assertSame('active', $this->fixture->run('activate', $this->credentialFile, $this->stateFile));
        $principal = $this->loadPrincipal();
        $parent = $this->CI->db
            ->get_where('appointments', [
                'notes' => Provider_ui_smoke_fixture::APPOINTMENT_BOOKED_INSIDE_NOTES,
            ])
            ->row_array();
        $now = date('Y-m-d H:i:s');

        $this->assertTrue(
            $this->CI->db->insert('appointments', [
                'create_datetime' => $now,
                'update_datetime' => $now,
                'book_datetime' => $now,
                'start_datetime' => '2099-02-12 09:50:00',
                'end_datetime' => '2099-02-12 10:00:00',
                'notes' => 'unexpected-child',
                'hash' => str_repeat('b', 64),
                'is_unavailability' => 1,
                'id_users_provider' => $principal['id'],
                'id_users_customer' => null,
                'id_services' => null,
                'id_parent_appointment' => $parent['id'],
            ]),
        );
        $child_id = (int) $this->CI->db->insert_id();

        try {
            $this->fixture->run('deactivate', $this->credentialFile, $this->stateFile);
            $this->fail('Unexpected reverse dependencies must stop cleanup.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, $this->CI->db->get_where('appointments', ['id' => $child_id])->num_rows());
        $this->assertSame(DB_SLUG_PROVIDER, $this->loadPrincipal()['role_slug']);
        $this->CI->db->delete('appointments', ['id' => $child_id]);
        $this->assertSame('dormant', $this->fixture->run('deactivate', $this->credentialFile, $this->stateFile));
    }

    public function testInstallFailsClosedWhenTheDormantRoleNameBelongsToAnotherSlug(): void
    {
        $this->assertTrue(
            $this->CI->db->insert('roles', [
                'name' => 'Provider UI Smoke Dormant',
                'slug' => 'unrelated-role',
                'is_admin' => 0,
                'appointments' => 0,
                'customers' => 0,
                'services' => 0,
                'users' => 0,
                'system_settings' => 0,
                'user_settings' => 0,
                'webhooks' => 0,
                'blocked_periods' => 0,
            ]),
        );

        try {
            $this->fixture->run('install', $this->credentialFile, $this->stateFile);
            $this->fail('A dormant role name collision must stop installation.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        } finally {
            $this->CI->db->delete('roles', ['slug' => 'unrelated-role']);
        }

        $this->assertSame(
            0,
            $this->CI->db
                ->get_where('roles', ['slug' => Provider_ui_smoke_access_policy::DORMANT_ROLE_SLUG])
                ->num_rows(),
        );
    }

    public function testInstallFailsClosedWhenAnAdditionalRoleCopiesTheDormantRoleName(): void
    {
        $this->assertSame('dormant', $this->fixture->run('install', $this->credentialFile, $this->stateFile));
        $this->assertTrue(
            $this->CI->db->insert('roles', [
                'name' => 'Provider UI Smoke Dormant',
                'slug' => 'unrelated-duplicate-name-role',
                'is_admin' => 0,
                'appointments' => 0,
                'customers' => 0,
                'services' => 0,
                'users' => 0,
                'system_settings' => 0,
                'user_settings' => 0,
                'webhooks' => 0,
                'blocked_periods' => 0,
            ]),
        );

        try {
            $this->fixture->run('install', $this->credentialFile, $this->stateFile);
            $this->fail('A second role with the dormant role name must stop installation.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        } finally {
            $this->CI->db->delete('roles', ['slug' => 'unrelated-duplicate-name-role']);
        }

        $this->assertSame('dormant', $this->fixture->run('verify', $this->credentialFile, $this->stateFile));
    }

    public function testIdempotentRemoveFailsClosedOnAReservedPrincipalMarkerCollision(): void
    {
        $this->assertSame('dormant', $this->fixture->run('install', $this->credentialFile, $this->stateFile));
        $this->assertSame('removed', $this->fixture->run('remove', $this->credentialFile, $this->stateFile));
        $customer_role = $this->CI->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])->row_array();
        $now = date('Y-m-d H:i:s');

        $this->assertNotEmpty($customer_role['id']);
        $this->assertTrue(
            $this->CI->db->insert('users', [
                'first_name' => 'Unrelated',
                'last_name' => 'Marker Collision',
                'email' => Provider_ui_smoke_fixture::PROVIDER_EMAIL,
                'id_roles' => $customer_role['id'],
                'create_datetime' => $now,
                'update_datetime' => $now,
            ]),
        );
        $collision_id = (int) $this->CI->db->insert_id();

        try {
            $this->fixture->run('remove', $this->credentialFile, $this->stateFile);
            $this->fail('A reserved principal marker collision must stop idempotent removal.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        } finally {
            $this->CI->db->delete('users', ['id' => $collision_id]);
        }

        $this->assertSame('removed', $this->fixture->run('remove', $this->credentialFile, $this->stateFile));
    }

    public function testPublicBookingTargetDetectionRejectsTheReservedProviderAndServiceIds(): void
    {
        $this->assertSame('dormant', $this->fixture->run('install', $this->credentialFile, $this->stateFile));
        $this->assertSame('active', $this->fixture->run('activate', $this->credentialFile, $this->stateFile));
        $principal = $this->loadPrincipal();
        $smoke_service = $this->CI->db
            ->get_where('services', ['name' => Provider_ui_smoke_fixture::SERVICE_NAME])
            ->row_array();
        $normal_pair = $this->CI->db
            ->select('services_providers.id_users AS provider_id, services_providers.id_services AS service_id')
            ->from('services_providers')
            ->where('services_providers.id_users !=', $principal['id'])
            ->where('services_providers.id_services !=', $smoke_service['id'])
            ->limit(1)
            ->get()
            ->row_array();

        $this->assertNotEmpty($smoke_service['id']);
        $this->assertNotEmpty($normal_pair['provider_id']);
        $this->assertNotEmpty($normal_pair['service_id']);

        $controller = new class extends Booking {
            public function __construct() {}

            public function detectsProviderUiSmokeTarget(string|int|null $provider_id, ?int $service_id): bool
            {
                return $this->isProviderUiSmokeBookingTarget($provider_id, $service_id);
            }
        };
        $controller->db = $this->CI->db;

        $this->assertTrue(
            $controller->detectsProviderUiSmokeTarget((int) $principal['id'], (int) $normal_pair['service_id']),
        );
        $this->assertTrue(
            $controller->detectsProviderUiSmokeTarget((string) $principal['id'], (int) $normal_pair['service_id']),
        );
        $this->assertTrue(
            $controller->detectsProviderUiSmokeTarget((int) $normal_pair['provider_id'], (int) $smoke_service['id']),
        );
        $this->assertTrue($controller->detectsProviderUiSmokeTarget(ANY_PROVIDER, (int) $smoke_service['id']));
        $this->assertFalse(
            $controller->detectsProviderUiSmokeTarget(
                (int) $normal_pair['provider_id'],
                (int) $normal_pair['service_id'],
            ),
        );
    }

    public function testCaseVariantLoginStillMapsToTheReservedIdentityBoundary(): void
    {
        $this->assertSame('dormant', $this->fixture->run('install', $this->credentialFile, $this->stateFile));
        $this->assertSame('active', $this->fixture->run('activate', $this->credentialFile, $this->stateFile));
        $this->CI->load->library('accounts');
        $case_variant = strtoupper(Provider_ui_smoke_access_policy::USERNAME);
        $session_data = $this->CI->accounts->check_login($case_variant, str_repeat('a', 64));

        $this->assertIsArray($session_data);
        $this->assertSame($case_variant, $session_data['username']);
        $this->assertTrue(Provider_ui_smoke_access_policy::isReservedUsername($session_data['username']));
        $this->assertTrue(Provider_ui_smoke_access_policy::isReservedIdentity($session_data['username'], null, null));
    }

    /**
     * @return array<string, mixed>
     */
    private function loadPrincipal(): array
    {
        $query = $this->CI->db
            ->select('users.*, roles.slug AS role_slug, user_settings.password, user_settings.salt')
            ->from('users')
            ->join('roles', 'roles.id = users.id_roles', 'inner')
            ->join('user_settings', 'user_settings.id_users = users.id', 'inner')
            ->where('user_settings.username', Provider_ui_smoke_access_policy::USERNAME)
            ->get();

        $this->assertSame(1, $query->num_rows());

        return $query->row_array();
    }

    private function markerCount(string $table, string $field, string $value): int
    {
        return $this->CI->db->get_where($table, [$field => $value])->num_rows();
    }
}
