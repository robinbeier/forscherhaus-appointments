<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\BackofficeRoleRevocationProbe;
use ReleaseGate\OrdinaryLiveFixture;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/GateHttpClient.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryLiveFixture.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/BackofficeRoleRevocationProbe.php';
require_once __DIR__ . '/Support/DefenseCycleHttpServer.php';

final class BackofficeRoleRevocationProbeTest extends TestCase
{
    private string $stateDirectory;
    private ?OrdinaryLiveFixture $fixture = null;
    private ?DefenseCycleHttpServer $server = null;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires the explicitly isolated root Docker fixture run.');
        }
        $this->stateDirectory = '/var/lib/fh-role-revocation-' . bin2hex(random_bytes(8));
        try {
            $this->fixture = new OrdinaryLiveFixture($this->stateDirectory);
            $this->fixture->activate(roleSlug: 'admin');
            $this->server = new DefenseCycleHttpServer();
        } catch (Throwable $error) {
            $this->server?->close();
            $this->fixture?->deactivate();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->server?->close();
        } finally {
            if ($this->fixture !== null && file_exists($this->stateDirectory . '/state.json')) {
                $this->fixture->deactivate();
            }
            foreach (['state.json', 'lifecycle.lock'] as $file) {
                $path = $this->stateDirectory . '/' . $file;
                if (is_file($path) && !is_link($path)) {
                    unlink($path);
                }
            }
            if (is_dir($this->stateDirectory) && !is_link($this->stateDirectory)) {
                rmdir($this->stateDirectory);
            }
        }
    }

    public function testAdminSessionLosesBackofficeReadsAfterOwnedTransition(): void
    {
        self::assertNotNull($this->fixture);
        self::assertNotNull($this->server);
        $password = (string) $this->fixture->read()['password'];
        $sessions = [];
        $result = (new BackofficeRoleRevocationProbe($this->server->client(), $this->fixture, static function (
            ?string $session,
        ) use (&$sessions): void {
            if ($session !== null && $session !== '') {
                $sessions[] = $session;
            }
        }))->run();
        self::assertSame('verified', $result['status']);
        self::assertSame('bounded_admin_reads', $result['coverage']);
        self::assertNotEmpty($sessions, 'Authenticated session must be captured for independent cleanup.');
        self::assertSame('cleanup_pending', $this->fixture->verify());
        self::assertSame(array_fill_keys(array_keys($result['before_statuses']), 200), $result['before_statuses']);
        self::assertSame(array_fill_keys(array_keys($result['after_statuses']), 403), $result['after_statuses']);
        self::assertSame(403, $result['session_status']);
        self::assertSame(200, $result['logout_status']);
        self::assertStringNotContainsString($password, $result['observed']);
    }

    public function testOwnershipDriftAbortsBeforeHttpAndFixtureCleanupRemainsControlled(): void
    {
        self::assertNotNull($this->fixture);
        self::assertNotNull($this->server);
        $state = $this->fixture->read();
        $db = &get_instance()->db;
        $settings = $db->get_where('user_settings', ['id_users' => $state['user_id']])->row_array();
        $db->update('user_settings', ['password' => 'drifted'], ['id_users' => $state['user_id']]);
        try {
            (new BackofficeRoleRevocationProbe($this->server->client(), $this->fixture))->run();
            self::fail('Ownership drift must abort before the HTTP probe.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('ownership', $error->getMessage());
        } finally {
            $db->update('user_settings', ['password' => $settings['password']], ['id_users' => $state['user_id']]);
            self::assertSame('active', $this->fixture->verify());
            $this->fixture->deactivate();
            self::assertSame('clean', $this->fixture->verify());
        }
    }
}
