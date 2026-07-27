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

        session([
            'user_id' => null,
            'user_email' => null,
            'username' => null,
            'timezone' => null,
            'language' => null,
            'role_slug' => null,
        ]);
        get_instance()->output->set_output('');

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

    public function testConcurrentActivationsPreserveTheWinningStateFile(): void
    {
        $lock_name = 'fh-provider-ui-smoke-v1';
        $processes = [];
        $lock_held = false;
        $results = [];

        $this->assertSame('dormant', $this->fixture->run('install', $this->credentialFile, $this->stateFile));
        $lock = $this->CI->db->query('SELECT GET_LOCK(?, 10) AS acquired', [$lock_name])->row_array();
        $this->assertSame(1, (int) ($lock['acquired'] ?? 0));
        $lock_held = true;

        try {
            $processes[] = $this->startLifecycleProcess('activate');
            $processes[] = $this->startLifecycleProcess('activate');
            $this->awaitLifecycleLockWaiters($lock_name, 2);

            $release = $this->CI->db->query('SELECT RELEASE_LOCK(?) AS released', [$lock_name])->row_array();
            $this->assertSame(1, (int) ($release['released'] ?? 0));
            $lock_held = false;

            foreach ($processes as $process) {
                $results[] = $this->finishLifecycleProcess($process);
            }

            $processes = [];
        } finally {
            if ($lock_held) {
                $this->CI->db->query('SELECT RELEASE_LOCK(?)', [$lock_name]);
            }

            foreach ($processes as $process) {
                $this->stopLifecycleProcess($process);
            }
        }

        $exit_codes = array_column($results, 'exit_code');
        sort($exit_codes);

        $this->assertSame([0, 1], $exit_codes);
        $this->assertCount(
            1,
            array_filter(
                $results,
                static fn(array $result): bool => str_contains(
                    $result['stdout'],
                    'provider_ui_smoke action=activate state=active result=ok',
                ),
            ),
        );
        $this->assertCount(
            1,
            array_filter(
                $results,
                static fn(array $result): bool => str_contains(
                    $result['stdout'],
                    'provider_ui_smoke action=activate state=error result=error',
                ),
            ),
        );
        $this->assertFileExists($this->stateFile);
        $state = json_decode((string) file_get_contents($this->stateFile), true);
        $this->assertIsArray($state);
        $this->assertSame(Provider_ui_smoke_access_policy::FIXTURE_KEY, $state['fixture_key'] ?? null);
        $this->assertSame(DB_SLUG_PROVIDER, $this->loadPrincipal()['role_slug']);
        $this->assertSame('dormant', $this->fixture->run('deactivate', $this->credentialFile, $this->stateFile));
        $this->assertFileDoesNotExist($this->stateFile);
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

    public function testCaseAndPadSpaceVariantLoginStillMapsToTheReservedIdentityBoundary(): void
    {
        $this->assertSame('dormant', $this->fixture->run('install', $this->credentialFile, $this->stateFile));
        $this->assertSame('active', $this->fixture->run('activate', $this->credentialFile, $this->stateFile));
        $this->CI->load->library('accounts');
        $database_equivalent_variant = strtoupper(Provider_ui_smoke_access_policy::USERNAME) . '   ';
        $session_data = $this->CI->accounts->check_login($database_equivalent_variant, str_repeat('a', 64));

        $this->assertIsArray($session_data);
        $this->assertSame(Provider_ui_smoke_access_policy::USERNAME, $session_data['username']);
        $this->assertTrue(Provider_ui_smoke_access_policy::isReservedUsername($database_equivalent_variant));
        $this->assertTrue(Provider_ui_smoke_access_policy::isReservedUsername($session_data['username']));
        $this->assertTrue(Provider_ui_smoke_access_policy::isReservedIdentity($session_data['username'], null, null));
    }

    public function testAccentInsensitiveVariantCanonicalizesToTheReservedStableIdentity(): void
    {
        $this->assertSame('dormant', $this->fixture->run('install', $this->credentialFile, $this->stateFile));
        $this->assertSame('active', $this->fixture->run('activate', $this->credentialFile, $this->stateFile));
        $this->CI->load->library('accounts');
        $database_equivalent_variant = str_replace('__ea_', '__eá_', Provider_ui_smoke_access_policy::USERNAME);
        $session_data = $this->CI->accounts->check_login($database_equivalent_variant, str_repeat('a', 64));
        $principal = $this->loadPrincipal();

        $this->assertIsArray($session_data);
        $this->assertFalse(Provider_ui_smoke_access_policy::isReservedUsername($database_equivalent_variant));
        $this->assertSame(Provider_ui_smoke_access_policy::USERNAME, $session_data['username']);
        $this->assertTrue(Provider_ui_smoke_access_policy::isReservedUsername($session_data['username']));
        $this->assertSame((int) $principal['id'], (int) $session_data['user_id']);

        $controller = new class extends \EA_Controller {
            public function __construct() {}

            public function matchesProviderUiSmokeUsername(?string $username): bool
            {
                return $this->is_provider_ui_smoke_auth_username($username);
            }
        };
        $controller->db = $this->CI->db;
        $controller->accounts = $this->CI->accounts;

        $this->assertTrue($controller->matchesProviderUiSmokeUsername($database_equivalent_variant));
        $this->assertTrue($controller->matchesProviderUiSmokeUsername(Provider_ui_smoke_access_policy::USERNAME));
        $this->assertFalse($controller->matchesProviderUiSmokeUsername('administrator'));
    }

    public function testAccentInsensitiveReservedLoginWithWrongPasswordNeverCallsLdap(): void
    {
        require_once APPPATH . 'libraries/Auth_request_dto_factory.php';
        require_once APPPATH . 'controllers/Login.php';

        $this->assertSame('dormant', $this->fixture->run('install', $this->credentialFile, $this->stateFile));
        $this->assertSame('active', $this->fixture->run('activate', $this->credentialFile, $this->stateFile));
        $this->CI->load->library('accounts');
        $database_equivalent_variant = str_replace('__ea_', '__eá_', Provider_ui_smoke_access_policy::USERNAME);
        $ldap_client = new class {
            public bool $called = false;

            /**
             * @return array<string, mixed>|null
             */
            public function check_login(string $username, string $password): ?array
            {
                $this->called = true;

                return null;
            }
        };
        $request_factory = new class ($database_equivalent_variant) extends \Auth_request_dto_factory {
            public function __construct(private readonly string $username) {}

            public function buildLoginValidateRequestDto(): \LoginValidateRequestDto
            {
                return new \LoginValidateRequestDto($this->username, 'intentionally-wrong-password');
            }
        };
        $controller = new class extends \Login {
            public \Auth_request_dto_factory $auth_request_dto_factory;

            public function __construct() {}
        };
        $controller->accounts = $this->CI->accounts;
        $controller->db = $this->CI->db;
        $controller->ldap_client = $ldap_client;
        $controller->auth_request_dto_factory = $request_factory;

        $controller->validate();

        $this->assertFalse($ldap_client->called);
    }

    public function testAccentInsensitiveReservedLoginCreatesCanonicalGuardedSession(): void
    {
        require_once APPPATH . 'libraries/Auth_request_dto_factory.php';
        require_once APPPATH . 'controllers/Login.php';

        $this->assertSame('dormant', $this->fixture->run('install', $this->credentialFile, $this->stateFile));
        $this->assertSame('active', $this->fixture->run('activate', $this->credentialFile, $this->stateFile));
        $this->CI->load->library('accounts');
        $database_equivalent_variant = str_replace('__ea_', '__eá_', Provider_ui_smoke_access_policy::USERNAME);
        $ldap_client = new class {
            public bool $called = false;

            /**
             * @return array<string, mixed>|null
             */
            public function check_login(string $username, string $password): ?array
            {
                $this->called = true;

                return null;
            }
        };
        $request_factory = new class ($database_equivalent_variant) extends \Auth_request_dto_factory {
            public function __construct(private readonly string $username) {}

            public function buildLoginValidateRequestDto(): \LoginValidateRequestDto
            {
                return new \LoginValidateRequestDto($this->username, str_repeat('a', 64));
            }
        };
        $controller = new class extends \Login {
            public \Auth_request_dto_factory $auth_request_dto_factory;

            public function __construct() {}
        };
        $controller->accounts = $this->CI->accounts;
        $controller->db = $this->CI->db;
        $controller->session = $this->CI->session;
        $controller->ldap_client = $ldap_client;
        $controller->auth_request_dto_factory = $request_factory;

        $controller->validate();

        $principal = $this->loadPrincipal();
        $response = json_decode((string) get_instance()->output->get_output(), true);
        $this->assertFalse($ldap_client->called);
        $this->assertSame(['success' => true], $response);
        $this->assertSame(Provider_ui_smoke_access_policy::USERNAME, session('username'));
        $this->assertTrue(Provider_ui_smoke_access_policy::isReservedUsername(session('username')));
        $this->assertSame((int) $principal['id'], (int) session('user_id'));
        $this->assertSame(DB_SLUG_PROVIDER, session('role_slug'));
        $this->assertTrue(Provider_ui_smoke_access_policy::isAllowedRoute('dashboard', 'index', 'GET'));
        $this->assertFalse(Provider_ui_smoke_access_policy::isAllowedRoute('customers', 'index', 'GET'));
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

    /**
     * @return array{process: resource, stdout: resource, stderr: resource}
     */
    private function startLifecycleProcess(string $action): array
    {
        $descriptor_spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(
            ['php', 'index.php', 'console', 'provider_ui_smoke', $action, $this->credentialFile, $this->stateFile],
            $descriptor_spec,
            $pipes,
            dirname(__DIR__, 3),
        );

        $this->assertIsResource($process);
        fclose($pipes[0]);

        return [
            'process' => $process,
            'stdout' => $pipes[1],
            'stderr' => $pipes[2],
        ];
    }

    private function awaitLifecycleLockWaiters(string $lock_name, int $expected): void
    {
        $deadline = microtime(true) + 15;

        do {
            $row = $this->CI->db
                ->query(
                    "SELECT COUNT(*) AS waiters
                    FROM information_schema.PROCESSLIST
                    WHERE ID <> CONNECTION_ID()
                    AND COMMAND = 'Query'
                    AND INFO LIKE ?",
                    ['SELECT GET_LOCK(%' . $lock_name . '%'],
                )
                ->row_array();

            if ((int) ($row['waiters'] ?? 0) >= $expected) {
                return;
            }

            usleep(20000);
        } while (microtime(true) < $deadline);

        $this->fail('Concurrent lifecycle commands did not reach the advisory lock.');
    }

    /**
     * @param array{process: resource, stdout: resource, stderr: resource} $process
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function finishLifecycleProcess(array $process): array
    {
        $stdout = stream_get_contents($process['stdout']);
        $stderr = stream_get_contents($process['stderr']);
        fclose($process['stdout']);
        fclose($process['stderr']);

        return [
            'exit_code' => proc_close($process['process']),
            'stdout' => $stdout === false ? '' : $stdout,
            'stderr' => $stderr === false ? '' : $stderr,
        ];
    }

    /**
     * @param array{process: resource, stdout: resource, stderr: resource} $process
     */
    private function stopLifecycleProcess(array $process): void
    {
        foreach (['stdout', 'stderr'] as $pipe) {
            if (is_resource($process[$pipe])) {
                fclose($process[$pipe]);
            }
        }

        if (!is_resource($process['process'])) {
            return;
        }

        $status = proc_get_status($process['process']);

        if (($status['running'] ?? false) === true) {
            proc_terminate($process['process']);
        }

        proc_close($process['process']);
    }
}
