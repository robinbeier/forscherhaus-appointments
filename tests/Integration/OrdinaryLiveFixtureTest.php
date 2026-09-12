<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\OrdinaryLiveFixture;

require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryLiveFixture.php';

final class OrdinaryLiveFixtureTest extends TestCase
{
    private string $stateDirectory;
    private ?OrdinaryLiveFixture $fixture = null;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires the explicitly isolated root Docker fixture run.');
        }
        self::assertSame('testing', ENVIRONMENT);
        $this->stateDirectory = '/var/lib/fh-ordinary-tests-' . bin2hex(random_bytes(8));
        $this->fixture = new OrdinaryLiveFixture($this->stateDirectory);
        self::assertSame('clean', $this->fixture->verify());
    }

    protected function tearDown(): void
    {
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

    public function testOrdinaryProviderLifecycleIsExactAndIdempotentlyCleaned(): void
    {
        $state = $this->fixture->activate();
        self::assertSame('active', $this->fixture->verify());
        self::assertSame($state, $this->fixture->read());
        self::assertSame(64, strlen((string) $state['password']));

        $db = &get_instance()->db;
        $user = $db->get_where('users', ['id' => $state['user_id']])->row_array();
        $settings = $db->get_where('user_settings', ['id_users' => $state['user_id']])->row_array();
        self::assertSame($state['marker'], $user['notes']);
        self::assertSame($state['email'], $user['email']);
        self::assertSame($state['username'], $settings['username']);
        self::assertSame(0, $db->get_where('appointments', ['id_users_provider' => $state['user_id']])->num_rows());
        self::assertSame(0, $db->get_where('services_providers', ['id_users' => $state['user_id']])->num_rows());
        self::assertSame(0, $db->get_where('services', ['description' => $state['marker']])->num_rows());

        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
        $this->fixture->deactivate();
        self::assertSame(0, $db->get_where('users', ['id' => $state['user_id']])->num_rows());
        self::assertSame(0, $db->get_where('user_settings', ['id_users' => $state['user_id']])->num_rows());
    }

    public function testSecondActivationIsRefused(): void
    {
        $this->fixture->activate();
        self::expectException(RuntimeException::class);
        $this->fixture->activate();
    }

    public function testPreparedJournalRecoversRowsCommittedBeforeFinalStateWrite(): void
    {
        $state = $this->fixture->activate();
        $prepared = $state;
        $prepared['phase'] = 'prepared';
        $prepared['user_id'] = 0;
        file_put_contents($this->stateDirectory . '/state.json', json_encode($prepared, JSON_THROW_ON_ERROR));
        self::assertSame('cleanup_pending', $this->fixture->verify());
        $refused = false;
        try {
            $this->fixture->read();
        } catch (RuntimeException) {
            $refused = true;
        }
        self::assertTrue($refused, 'An unfinished context must not start HTTP requests.');
        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
        self::assertSame(
            0,
            get_instance()
                ->db->get_where('users', ['id' => $state['user_id']])
                ->num_rows(),
        );
    }

    public function testCleanupCanResumeAfterDatabaseCommitBeforeJournalRemoval(): void
    {
        $state = $this->fixture->activate();
        $db = &get_instance()->db;
        $db->delete('user_settings', ['id_users' => $state['user_id'], 'username' => $state['username']]);
        $db->delete('users', ['id' => $state['user_id'], 'notes' => $state['marker']]);
        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
    }

    public function testChangedCredentialCannotReachNormalLoginOrLdapFallback(): void
    {
        $state = $this->fixture->activate();
        $db = &get_instance()->db;
        $settings = $db->get_where('user_settings', ['id_users' => $state['user_id']])->row_array();
        $db->update('user_settings', ['password' => 'changed'], ['id_users' => $state['user_id']]);
        $refused = false;
        try {
            $this->fixture->read();
        } catch (RuntimeException) {
            $refused = true;
        } finally {
            $db->update('user_settings', ['password' => $settings['password']], ['id_users' => $state['user_id']]);
        }
        self::assertTrue($refused);
    }

    public function testOwnershipDriftRefusesCleanupUntilRestored(): void
    {
        $state = $this->fixture->activate();
        $db = &get_instance()->db;
        $db->update('users', ['notes' => 'foreign'], ['id' => $state['user_id']]);
        try {
            $this->fixture->deactivate();
            self::fail('Cleanup must refuse a drifted identity.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('ambiguous', strtolower($e->getMessage()));
        }
        $db->update('users', ['notes' => $state['marker']], ['id' => $state['user_id']]);
        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
    }
}
