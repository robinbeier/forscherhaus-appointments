<?php

namespace Tests\Unit\Libraries;

use Provider_ui_smoke_access_policy;
use Provider_ui_smoke_fixture;
use ReflectionClass;
use Tests\TestCase;

require_once APPPATH . 'core/Provider_ui_smoke_access_policy.php';
require_once APPPATH . 'libraries/Provider_ui_smoke_fixture.php';

class ProviderUiSmokeFixtureContractTest extends TestCase
{
    public function testFixtureConstantsMatchTheProductionGateContract(): void
    {
        $this->assertSame('__ea_provider_ui_smoke_v1', Provider_ui_smoke_access_policy::USERNAME);
        $this->assertSame('prod-provider-ui-smoke-v1', Provider_ui_smoke_access_policy::FIXTURE_KEY);
        $this->assertSame('Synthetic', Provider_ui_smoke_fixture::PROVIDER_FIRST_NAME);
        $this->assertSame('Provider UI Smoke V1', Provider_ui_smoke_fixture::PROVIDER_LAST_NAME);
        $this->assertSame('provider-ui-smoke-v1@synthetic.invalid', Provider_ui_smoke_fixture::PROVIDER_EMAIL);
        $this->assertSame('Synthetic', Provider_ui_smoke_fixture::CUSTOMER_FIRST_NAME);
        $this->assertSame('Parent UI Smoke V1', Provider_ui_smoke_fixture::CUSTOMER_LAST_NAME);
        $this->assertSame('customer-provider-ui-smoke-v1@synthetic.invalid', Provider_ui_smoke_fixture::CUSTOMER_EMAIL);
        $this->assertSame('0000000000', Provider_ui_smoke_fixture::CUSTOMER_PHONE);
        $this->assertSame('PROD_PROVIDER_UI_SMOKE_V1_PRIVATE_NOTE_SENTINEL', Provider_ui_smoke_fixture::CUSTOMER_NOTES);
    }

    public function testFixtureUsesTheThreeFixedFilterAppointments(): void
    {
        $this->assertSame('2099-02-12 10:00:00', Provider_ui_smoke_fixture::BOOKED_INSIDE_START);
        $this->assertSame('2099-02-12 10:30:00', Provider_ui_smoke_fixture::BOOKED_INSIDE_END);
        $this->assertSame('2099-02-12 11:00:00', Provider_ui_smoke_fixture::CANCELLED_INSIDE_START);
        $this->assertSame('2099-02-12 11:30:00', Provider_ui_smoke_fixture::CANCELLED_INSIDE_END);
        $this->assertSame('2099-03-12 12:00:00', Provider_ui_smoke_fixture::BOOKED_OUTSIDE_START);
        $this->assertSame('2099-03-12 12:30:00', Provider_ui_smoke_fixture::BOOKED_OUTSIDE_END);
    }

    public function testLifecycleSourceKeepsTheDormantVerifierAndDirectWriteSafetyContract(): void
    {
        $source = file_get_contents(APPPATH . 'libraries/Provider_ui_smoke_fixture.php');

        $this->assertIsString($source);
        $this->assertStringContainsString("'password' => null", $source);
        $this->assertStringContainsString("'salt' => null", $source);
        $this->assertStringContainsString("'notifications' => 0", $source);
        $this->assertStringContainsString("'google_sync' => 0", $source);
        $this->assertStringContainsString("'caldav_sync' => 0", $source);
        $this->assertStringContainsString('SELECT GET_LOCK(?, 10)', $source);
        $this->assertStringContainsString('/var/lib/fh-deploy-orchestrator/locks/fh-production-change.lock', $source);
        $this->assertStringContainsString('LOCK_EX | LOCK_NB', $source);
        $this->assertStringContainsString("\$action === 'deactivate' ? self::DEACTIVATE_LOCK_WAIT_MS : 0", $source);
        $this->assertStringContainsString('lockWithDeadline($handle, $waitMs)', $source);
        $this->assertStringContainsString("'assert-bound-fd'", $source);
        $this->assertStringContainsString('3 => $this->sharedLockHandle', $source);
        $this->assertStringContainsString('new Provider_ui_smoke_default_admission_guard', $source);
        $this->assertStringContainsString('$this->CI->db->trans_begin()', $source);
        $this->assertStringContainsString('$this->CI->db->trans_commit()', $source);
        $this->assertStringContainsString('$this->CI->db->trans_rollback()', $source);
        $this->assertStringContainsString('fixture_checksum', $source);
        $this->assertStringContainsString('reconstructActiveState', $source);
        $this->assertStringNotContainsString('$this->notifications', $source);
        $this->assertStringNotContainsString('$this->webhooks_client', $source);
        $this->assertStringNotContainsString('$this->synchronization', $source);
    }

    public function testDeactivationLockWaitSurvivesBriefContention(): void
    {
        $lockPath = tempnam(sys_get_temp_dir(), 'provider-ui-lock-');
        $readyPath = tempnam(sys_get_temp_dir(), 'provider-ui-ready-');
        $this->assertIsString($lockPath);
        $this->assertIsString($readyPath);
        unlink($readyPath);

        $script = <<<'PHP'
        $handle = fopen($argv[1], 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) { exit(2); }
        file_put_contents($argv[2], 'ready');
        usleep(1000000);
        flock($handle, LOCK_UN);
        fclose($handle);
        PHP;
        $process = proc_open(
            [PHP_BINARY, '-r', $script, $lockPath, $readyPath],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        $handle = null;

        try {
            $deadline = hrtime(true) + 2000000000;
            while (!file_exists($readyPath) && hrtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($readyPath, 'Lock holder did not start.');

            $handle = fopen($lockPath, 'c+');
            $this->assertIsResource($handle);
            $reflection = new ReflectionClass(Provider_ui_smoke_fixture::class);
            $fixture = $reflection->newInstanceWithoutConstructor();
            $lockWithDeadline = $reflection->getMethod('lockWithDeadline');

            $this->assertFalse($lockWithDeadline->invoke($fixture, $handle, 0));
            $this->assertTrue($lockWithDeadline->invoke($fixture, $handle, 2000));
        } finally {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
            unlink($lockPath);
            if (file_exists($readyPath)) {
                unlink($readyPath);
            }
        }
    }

    public function testActivationSnapshotsStateOnlyAfterTheLifecycleLock(): void
    {
        $source = file_get_contents(APPPATH . 'libraries/Provider_ui_smoke_fixture.php');

        $this->assertIsString($source);
        $lock_position = strpos($source, '$this->acquireLock();');
        $snapshot_position = strpos(
            $source,
            '$state_file_existed_before = file_exists($state_file) || is_link($state_file);',
        );
        $transaction_position = strpos($source, '$this->CI->db->trans_begin()');

        $this->assertIsInt($lock_position);
        $this->assertIsInt($snapshot_position);
        $this->assertIsInt($transaction_position);
        $this->assertTrue($lock_position < $snapshot_position);
        $this->assertTrue($snapshot_position < $transaction_position);
    }

    public function testAdmissionIsRequiredBeforeCredentialsOrDatabaseWork(): void
    {
        $source = file_get_contents(APPPATH . 'libraries/Provider_ui_smoke_fixture.php');

        $this->assertIsString($source);
        $admission_position = strpos($source, '$this->admissionGuard->enter($action, $credential_file, $state_file);');
        $credential_position = strpos($source, '$this->readCredentials($credential_file);');
        $transaction_position = strpos($source, '$this->CI->db->trans_begin()');

        $this->assertIsInt($admission_position);
        $this->assertIsInt($credential_position);
        $this->assertIsInt($transaction_position);
        $this->assertTrue($admission_position < $credential_position);
        $this->assertTrue($credential_position < $transaction_position);
        $this->assertStringContainsString("getenv('FH_PROVIDER_UI_SMOKE_RUN_ID')", $source);
        $this->assertStringContainsString("'--lifecycle-action'", $source);
    }

    public function testVerifyIsTheReadOnlyExceptionForTheLockHeldFinalizer(): void
    {
        $source = file_get_contents(APPPATH . 'libraries/Provider_ui_smoke_fixture.php');

        $this->assertIsString($source);
        $run_position = strpos($source, 'public function run(');
        $verify_position = strpos($source, 'private function verify(');

        $this->assertIsInt($run_position);
        $this->assertIsInt($verify_position);
        $run_source = substr($source, $run_position, $verify_position - $run_position);
        $this->assertIsString($run_source);
        $this->assertStringContainsString("\$requiresAdmission = \$action !== 'verify';", $run_source);
        $this->assertStringContainsString('if ($requiresAdmission) {', $run_source);

        $verify_end = strpos($source, '    /**', $verify_position + 1);
        $this->assertIsInt($verify_end);
        $verify_source = substr($source, $verify_position, $verify_end - $verify_position);
        $this->assertIsString($verify_source);
        $this->assertStringContainsString('assertStateFileAbsent', $verify_source);
        $this->assertStringContainsString('assertDormantClean', $verify_source);
        $this->assertStringNotContainsString('insertOrFail', $verify_source);
        $this->assertStringNotContainsString('writeStateFile', $verify_source);
        $this->assertStringNotContainsString('unlink(', $verify_source);
    }

    public function testConsoleExposesOnlyTheFiveBoundedLifecycleActions(): void
    {
        $source = file_get_contents(APPPATH . 'controllers/Console.php');

        $this->assertIsString($source);
        $this->assertStringContainsString("['install', 'verify', 'activate', 'deactivate', 'remove']", $source);
        $this->assertStringContainsString(
            "'provider_ui_smoke action=' . \$action . ' state=' . \$state . ' result=ok'",
            $source,
        );
        $this->assertStringContainsString("\$GLOBALS['argv'][4]", $source);
        $this->assertStringContainsString("\$GLOBALS['argv'][5]", $source);
        $this->assertStringContainsString("isset(\$GLOBALS['argv'][6])", $source);
        $this->assertStringContainsString(
            "fwrite(STDOUT, 'provider_ui_smoke action=' . \$action . ' state=error result=error'",
            $source,
        );
    }

    public function testLifecycleRejectsBroadOrNonNormalizedProtectedPaths(): void
    {
        $reflection = new ReflectionClass(Provider_ui_smoke_fixture::class);
        $fixture = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('assertSafeAbsolutePath');

        $method->invoke($fixture, '/tmp/provider-ui-smoke/active.json');
        $this->addToAssertionCount(1);

        foreach (
            [
                '',
                '/',
                'relative/path',
                '/tmp/provider-ui-smoke/',
                '/tmp//provider-ui-smoke/active.json',
                '/tmp/./provider-ui-smoke/active.json',
                '/tmp/provider-ui-smoke/../active.json',
            ]
            as $invalid_path
        ) {
            try {
                $method->invoke($fixture, $invalid_path);
                $this->fail('An unsafe lifecycle path was accepted.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testDirectProductionCliWithoutCapabilityStopsBeforeStateMutation(): void
    {
        $directory = sys_get_temp_dir() . '/fh-provider-ui-smoke-negative-' . bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($directory, 0700, true));
        $credentialFile = $directory . '/credentials.env';
        $stateFile = $directory . '/active.json';
        $environment = $_ENV;
        unset($environment['FH_PROVIDER_UI_SMOKE_RUN_ID']);
        $process = proc_open(
            ['php', 'index.php', 'console', 'provider_ui_smoke', 'install', $credentialFile, $stateFile],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
            $environment,
        );

        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertNotSame(0, $exitCode);
        $this->assertIsString($stdout);
        $this->assertIsString($stderr);
        $this->assertFileDoesNotExist($stateFile);
        $this->assertFileDoesNotExist($credentialFile);
        rmdir($directory);
    }
}
