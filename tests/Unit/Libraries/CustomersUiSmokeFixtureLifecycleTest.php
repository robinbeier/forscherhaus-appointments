<?php

namespace Tests\Unit\Libraries;

use Customers_ui_smoke_access_policy;
use Customers_ui_smoke_fixture;
use RuntimeException;
use Tests\TestCase;

require_once APPPATH . 'core/Customers_ui_smoke_access_policy.php';
require_once APPPATH . 'libraries/Customers_ui_smoke_fixture.php';

final class CustomersUiSmokeFixtureLifecycleTest extends TestCase
{
    private Customers_ui_smoke_fixture $fixture;

    private string $temporaryDirectory;

    private string $credentialFile;

    private string $stateFile;

    /** @var EA_Controller|\CI_Controller */
    private $CI;

    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            $this->markTestSkipped('The root-only lifecycle test runs inside the PHP Docker service.');
        }

        $this->CI = &get_instance();
        $this->CI->load->library('customers_ui_smoke_fixture');
        $this->fixture = $this->CI->customers_ui_smoke_fixture;
        $this->temporaryDirectory = sys_get_temp_dir() . '/fh-customers-ui-smoke-lifecycle-' . bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($this->temporaryDirectory, 0700));
        $this->assertTrue(chmod($this->temporaryDirectory, 0700));
        $this->credentialFile = $this->temporaryDirectory . '/credentials.env';
        $this->stateFile = $this->temporaryDirectory . '/active.json';
        $lines = [];

        foreach (Customers_ui_smoke_access_policy::USERNAMES_BY_ROLE as $role => $username) {
            $lines[] = 'CUSTOMERS_UI_SMOKE_' . strtoupper($role) . '_USERNAME=' . $username;
        }

        $lines[] = 'CUSTOMERS_UI_SMOKE_PASSWORD=' . str_repeat('a', 64);
        $this->assertNotFalse(file_put_contents($this->credentialFile, implode(PHP_EOL, $lines) . PHP_EOL));
        $this->assertTrue(chmod($this->credentialFile, 0600));
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture, $this->credentialFile, $this->stateFile)) {
            try {
                if (is_file($this->stateFile)) {
                    unlink($this->stateFile);
                }
                $this->fixture->run('deactivate', $this->credentialFile, $this->stateFile);
                $this->fixture->run('remove', $this->credentialFile, $this->stateFile);
            } catch (\Throwable) {
                // Preserve the tested failure while continuing best-effort cleanup.
            }
        }

        foreach ([$this->credentialFile ?? '', $this->stateFile ?? ''] as $path) {
            if ($path !== '' && is_file($path)) {
                unlink($path);
            }
        }

        if (isset($this->temporaryDirectory) && is_dir($this->temporaryDirectory)) {
            rmdir($this->temporaryDirectory);
        }

        parent::tearDown();
    }

    public function testFullLifecycleAndMissingStateRecoveryEndDormantAndClean(): void
    {
        $this->assertSame('dormant', $this->fixture->run('install', $this->credentialFile, $this->stateFile));
        $this->assertSame('dormant', $this->fixture->run('install', $this->credentialFile, $this->stateFile));
        $this->assertSame('dormant', $this->fixture->run('verify', $this->credentialFile, $this->stateFile));
        $this->assertDormantPrincipals();

        $this->assertSame('active', $this->fixture->run('activate', $this->credentialFile, $this->stateFile));
        $this->assertFileExists($this->stateFile);
        $this->assertSame(0600, fileperms($this->stateFile) & 0777);

        foreach ($this->loadPrincipals() as $role => $principal) {
            $this->assertSame($role, $principal['role_slug']);
            $this->assertNotEmpty($principal['password']);
            $this->assertNotEmpty($principal['salt']);
            $this->assertTrue(Customers_ui_smoke_access_policy::hasActiveLease((string) $principal['notes'], $role));
        }

        try {
            $this->fixture->run('verify', $this->credentialFile, $this->stateFile);
            $this->fail('An active lease must not pass dormant verification.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertTrue(unlink($this->stateFile));
        $this->assertSame('dormant', $this->fixture->run('deactivate', $this->credentialFile, $this->stateFile));
        $this->assertFileDoesNotExist($this->stateFile);
        $this->assertDormantPrincipals();
        $this->assertSame('dormant', $this->fixture->run('deactivate', $this->credentialFile, $this->stateFile));
        $this->assertSame('removed', $this->fixture->run('remove', $this->credentialFile, $this->stateFile));
        $this->assertSame('removed', $this->fixture->run('remove', $this->credentialFile, $this->stateFile));
        $this->assertSame('removed', $this->fixture->run('verify', $this->credentialFile, $this->stateFile));
        $this->assertSame([], $this->loadPrincipals());
    }

    public function testActivationFailsClosedWhenPermissionMatrixDrifts(): void
    {
        $this->assertSame('dormant', $this->fixture->run('install', $this->credentialFile, $this->stateFile));
        $providerRole = $this->CI->db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])->row_array();
        $original = (int) $providerRole['customers'];
        $this->assertTrue($this->CI->db->update('roles', ['customers' => 0], ['id' => $providerRole['id']]));

        try {
            $this->fixture->run('activate', $this->credentialFile, $this->stateFile);
            $this->fail('Permission drift must stop activation.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        } finally {
            $this->CI->db->update('roles', ['customers' => $original], ['id' => $providerRole['id']]);
        }

        $this->assertFileDoesNotExist($this->stateFile);
        $this->assertDormantPrincipals();
    }

    public function testCleanupRefusesUnexpectedRelationsWithoutDeletingThem(): void
    {
        $this->assertSame('dormant', $this->fixture->run('install', $this->credentialFile, $this->stateFile));
        $this->assertSame('active', $this->fixture->run('activate', $this->credentialFile, $this->stateFile));
        $provider = $this->loadPrincipals()[DB_SLUG_PROVIDER];
        $service = $this->CI->db->limit(1)->get('services')->row_array();

        if ($service === null || $service === []) {
            $serviceId = (int) ($this->CI->db->select_max('id')->get('services')->row()->id ?? 0) + 1;
            $this->assertTrue(
                $this->CI->db->insert('services', [
                    'id' => $serviceId,
                    'create_datetime' => '2026-08-08 00:00:00',
                    'update_datetime' => '2026-08-08 00:00:00',
                    'name' => '__EA_CUSTOMERS_UI_SMOKE_V1_TEST_SERVICE__',
                    'duration' => 30,
                    'buffer_before' => 0,
                    'buffer_after' => 0,
                    'price' => 0,
                    'currency' => 'EUR',
                    'description' => 'Synthetic service for Customers UI smoke cleanup coverage.',
                    'location' => null,
                    'color' => '#6c757d',
                    'availabilities_type' => AVAILABILITIES_TYPE_FLEXIBLE,
                    'attendants_number' => 1,
                    'is_private' => 1,
                    'id_service_categories' => null,
                ]),
            );

            $service = [
                'id' => $serviceId,
                'name' => '__EA_CUSTOMERS_UI_SMOKE_V1_TEST_SERVICE__',
            ];
        }

        $this->assertNotEmpty($service['id']);
        $this->assertTrue(
            $this->CI->db->insert('services_providers', [
                'id_users' => $provider['id'],
                'id_services' => $service['id'],
            ]),
        );

        try {
            $this->fixture->run('deactivate', $this->credentialFile, $this->stateFile);
            $this->fail('Unexpected relations must stop cleanup scope expansion.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(
            1,
            $this->CI->db
                ->get_where('services_providers', ['id_users' => $provider['id'], 'id_services' => $service['id']])
                ->num_rows(),
        );
        $this->assertSame(DB_SLUG_PROVIDER, $this->loadPrincipals()[DB_SLUG_PROVIDER]['role_slug']);
        $this->CI->db->delete('services_providers', [
            'id_users' => $provider['id'],
            'id_services' => $service['id'],
        ]);
        $this->assertSame('dormant', $this->fixture->run('deactivate', $this->credentialFile, $this->stateFile));

        if (($service['name'] ?? null) === '__EA_CUSTOMERS_UI_SMOKE_V1_TEST_SERVICE__') {
            $this->CI->db->delete('services', ['id' => $service['id']]);
        }
    }

    public function testTamperedStateFailsClosedThenMarkerRecoveryCleansUp(): void
    {
        $this->assertSame('dormant', $this->fixture->run('install', $this->credentialFile, $this->stateFile));
        $this->assertSame('active', $this->fixture->run('activate', $this->credentialFile, $this->stateFile));
        $this->assertNotFalse(file_put_contents($this->stateFile, "{}\n"));
        $this->assertTrue(chmod($this->stateFile, 0600));

        try {
            $this->fixture->run('deactivate', $this->credentialFile, $this->stateFile);
            $this->fail('A malformed state file must not widen cleanup.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertTrue(unlink($this->stateFile));
        $this->assertSame('dormant', $this->fixture->run('deactivate', $this->credentialFile, $this->stateFile));
        $this->assertDormantPrincipals();
    }

    public function testExpiredLeaseWithoutStateStillDeactivates(): void
    {
        $this->assertSame('dormant', $this->fixture->run('install', $this->credentialFile, $this->stateFile));
        $this->assertSame('active', $this->fixture->run('activate', $this->credentialFile, $this->stateFile));
        $issuedAt = new \DateTimeImmutable('-20 minutes', new \DateTimeZone('UTC'));
        $expiresAt = $issuedAt->modify('+10 minutes');

        foreach ($this->loadPrincipals() as $role => $principal) {
            $this->assertTrue(
                $this->CI->db->update(
                    'users',
                    ['notes' => Customers_ui_smoke_access_policy::buildActiveNotes($role, $issuedAt, $expiresAt)],
                    ['id' => $principal['id']],
                ),
            );
        }

        $this->assertTrue(unlink($this->stateFile));
        $this->assertSame('dormant', $this->fixture->run('deactivate', $this->credentialFile, $this->stateFile));
        $this->assertDormantPrincipals();
    }

    public function testPostActivationPermissionDriftDoesNotBlockCleanup(): void
    {
        $this->assertSame('dormant', $this->fixture->run('install', $this->credentialFile, $this->stateFile));
        $this->assertSame('active', $this->fixture->run('activate', $this->credentialFile, $this->stateFile));
        $providerRole = $this->CI->db->get_where('roles', ['slug' => DB_SLUG_PROVIDER])->row_array();
        $original = (int) $providerRole['customers'];
        $this->assertTrue($this->CI->db->update('roles', ['customers' => 0], ['id' => $providerRole['id']]));

        try {
            $this->assertSame('dormant', $this->fixture->run('deactivate', $this->credentialFile, $this->stateFile));
            $this->assertDormantPrincipals();
        } finally {
            $this->CI->db->update('roles', ['customers' => $original], ['id' => $providerRole['id']]);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadPrincipals(): array
    {
        $rows = $this->CI->db
            ->select(
                'users.*, roles.slug AS role_slug, user_settings.username, user_settings.password, user_settings.salt',
            )
            ->from('users')
            ->join('roles', 'roles.id = users.id_roles', 'inner')
            ->join('user_settings', 'user_settings.id_users = users.id', 'inner')
            ->where_in('user_settings.username', array_values(Customers_ui_smoke_access_policy::USERNAMES_BY_ROLE))
            ->get()
            ->result_array();
        $principals = [];

        foreach ($rows as $row) {
            $role = Customers_ui_smoke_access_policy::roleForUsername($row['username']);
            $this->assertNotNull($role);
            $principals[$role] = $row;
        }

        return $principals;
    }

    private function assertDormantPrincipals(): void
    {
        $principals = $this->loadPrincipals();
        $this->assertCount(4, $principals);

        foreach ($principals as $role => $principal) {
            $this->assertSame(Customers_ui_smoke_access_policy::DORMANT_ROLE_SLUG, $principal['role_slug']);
            $this->assertSame(Customers_ui_smoke_access_policy::dormantNotes($role), $principal['notes']);
            $this->assertNull($principal['password']);
            $this->assertNull($principal['salt']);
        }
    }
}
