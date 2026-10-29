<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once APPPATH . 'libraries/Zero_surprise_canary_fixture.php';
require_once __DIR__ . '/Support/DefenseCycleHttpServer.php';

final class ZeroSurpriseCanaryFixtureTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('FH_CANARY_INTEGRATION') !== '1' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires the explicitly isolated root Docker fixture run.');
        }
        self::assertSame('testing', ENVIRONMENT);
        self::assertSame('clean', (new Zero_surprise_canary_fixture())->run('verify'));
    }

    public function testActivateVerifyAndCompleteCleanup(): void
    {
        $fixture = new Zero_surprise_canary_fixture();
        try {
            self::assertSame('active', $fixture->run('activate'));
            self::assertSame('active', $fixture->run('verify'));
            $state = json_decode(
                file_get_contents(Zero_surprise_canary_fixture::DEFAULT_STATE_FILE),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $ci = &get_instance();
            $settings = $ci->db->get_where('user_settings', ['id_users' => $state['provider_id']])->row_array();
            self::assertSame(
                ['start' => '08:00', 'end' => '18:00', 'breaks' => []],
                json_decode($settings['working_plan'], true)['monday'],
            );
            self::assertSame(0, (int) $settings['notifications']);
            self::assertSame(0, (int) $settings['caldav_sync']);
            self::assertSame(600, $state['expires_at'] - $state['created_at']);
        } finally {
            self::assertSame('clean', $fixture->run('deactivate'));
        }
        self::assertSame('clean', $fixture->run('verify'));
        self::assertFileDoesNotExist(Zero_surprise_canary_fixture::DEFAULT_STATE_FILE);
    }

    public function testFailedCommitLeavesRecoverableJournalAndNoRows(): void
    {
        $ci = &get_instance();
        $database = $ci->db;
        $ci->db = new class ($database) {
            public function __construct(private object $database) {}
            public function trans_commit(): bool
            {
                return false;
            }
            public function __call(string $name, array $args): mixed
            {
                return $this->database->$name(...$args);
            }
        };
        $fixture = new Zero_surprise_canary_fixture();
        $failure = null;
        try {
            $fixture->run('activate');
        } catch (RuntimeException $e) {
            $failure = $e;
        } finally {
            $ci->db = $database;
        }
        try {
            self::assertNotNull($failure);
            self::assertStringContainsString('commit', $failure->getMessage());
            self::assertSame('cleanup_pending', $fixture->run('verify'));
        } finally {
            self::assertSame('clean', $fixture->run('deactivate'));
        }
        self::assertSame('clean', $fixture->run('verify'));
        self::assertFileDoesNotExist(Zero_surprise_canary_fixture::DEFAULT_STATE_FILE);
    }

    public function testCleanupRemovesOnlyConsentsForMarkedCanaryCustomers(): void
    {
        $fixture = new Zero_surprise_canary_fixture();
        $db = get_instance()->db;
        $foreignEmail = 'zero-surprise-unrelated-' . bin2hex(random_bytes(8)) . '@synthetic.invalid';
        $ownedEmail = null;

        try {
            self::assertSame('active', $fixture->run('activate'));
            $state = json_decode(
                file_get_contents(Zero_surprise_canary_fixture::DEFAULT_STATE_FILE),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $ownedRunCustomerMarker = 'booking-customer-' . $state['run_id'] . '-01';
            $normalizedMarker = preg_replace('/[^a-z0-9]+/', '-', strtolower($ownedRunCustomerMarker)) ?: 'ci-write';
            $ownedEmail = 'ci-' . substr(hash('sha256', $normalizedMarker), 0, 20) . '@synthetic.invalid';
            $ownedLastName = strtoupper(substr($ownedRunCustomerMarker, -6));
            $customerRole = $db->get_where('roles', ['slug' => 'customer'])->row_array();
            self::assertIsArray($customerRole);
            self::assertTrue(
                $db->insert('users', [
                    'first_name' => 'Synthetic',
                    'last_name' => $ownedLastName,
                    'email' => $ownedEmail,
                    'phone_number' => '000000000',
                    'notes' => 'run:' . $state['run_id'],
                    'timezone' => 'UTC',
                    'language' => 'english',
                    'is_private' => 1,
                    'id_roles' => (int) $customerRole['id'],
                ]),
            );
            self::assertTrue(
                $db->insert('consents', [
                    'first_name' => 'CI',
                    'last_name' => $ownedLastName,
                    'email' => $ownedEmail,
                    'ip' => '127.0.0.1',
                    'type' => 'privacy-policy',
                ]),
            );
            self::assertTrue(
                $db->insert('consents', [
                    'first_name' => 'Unrelated',
                    'last_name' => 'Synthetic Consent',
                    'email' => $foreignEmail,
                    'ip' => '127.0.0.1',
                    'type' => 'privacy-policy',
                ]),
            );
            self::assertTrue(
                $db->insert('consents', [
                    'first_name' => 'Foreign',
                    'last_name' => 'Same Email',
                    'email' => $ownedEmail,
                    'ip' => '127.0.0.1',
                    'type' => 'privacy-policy',
                ]),
            );
            self::assertTrue($db->delete('users', ['email' => $ownedEmail, 'notes' => 'run:' . $state['run_id']]));

            try {
                $fixture->run('deactivate');
                self::fail('Same-email foreign consent must block ambiguous cleanup.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('ambiguous', $error->getMessage());
            }
            self::assertFileExists(Zero_surprise_canary_fixture::DEFAULT_STATE_FILE);
            self::assertSame(2, $db->get_where('consents', ['email' => $ownedEmail])->num_rows());
            self::assertSame('active', $fixture->run('verify'));
            $db->delete('consents', ['email' => $ownedEmail, 'first_name' => 'Foreign']);
            self::assertSame('clean', $fixture->run('deactivate'));
            self::assertSame(0, $db->get_where('consents', ['email' => $ownedEmail])->num_rows());
            self::assertSame(1, $db->get_where('consents', ['email' => $foreignEmail])->num_rows());
        } finally {
            if (is_file(Zero_surprise_canary_fixture::DEFAULT_STATE_FILE)) {
                $fixture->run('deactivate');
            }
            $db->delete('consents', ['email' => $foreignEmail]);
        }
        self::assertSame('clean', $fixture->run('verify'));
    }

    public function testCleanupPreservesAConsentBelowTheCanaryFloorEvenWithTheSameEmail(): void
    {
        $fixture = new Zero_surprise_canary_fixture();
        $db = get_instance()->db;
        $baselineId = null;
        $newId = null;
        try {
            self::assertTrue(
                $db->insert('consents', [
                    'first_name' => 'Earlier',
                    'last_name' => 'Synthetic',
                    'email' => 'earlier-' . bin2hex(random_bytes(8)) . '@synthetic.invalid',
                    'ip' => '127.0.0.1',
                    'type' => 'privacy-policy',
                ]),
            );
            $baselineId = (int) $db->insert_id();
            self::assertSame('active', $fixture->run('activate'));
            $state = json_decode(
                file_get_contents(Zero_surprise_canary_fixture::DEFAULT_STATE_FILE),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            self::assertSame($baselineId, $state['consent_floor']);
            $marker = 'booking-customer-' . $state['run_id'] . '-01';
            $normalized = preg_replace('/[^a-z0-9]+/', '-', strtolower($marker)) ?: 'ci-write';
            $email = 'ci-' . substr(hash('sha256', $normalized), 0, 20) . '@synthetic.invalid';
            $lastName = strtoupper(substr($marker, -6));
            self::assertTrue(
                $db->update(
                    'consents',
                    [
                        'first_name' => 'CI',
                        'last_name' => $lastName,
                        'email' => $email,
                    ],
                    ['id' => $baselineId],
                ),
            );
            self::assertTrue(
                $db->insert('consents', [
                    'first_name' => 'CI',
                    'last_name' => $lastName,
                    'email' => $email,
                    'ip' => '127.0.0.1',
                    'type' => 'privacy-policy',
                ]),
            );
            $newId = (int) $db->insert_id();

            self::assertSame('clean', $fixture->run('deactivate'));
            self::assertSame(1, $db->get_where('consents', ['id' => $baselineId])->num_rows());
            self::assertSame(0, $db->get_where('consents', ['id' => $newId])->num_rows());
        } finally {
            if (is_file(Zero_surprise_canary_fixture::DEFAULT_STATE_FILE)) {
                $fixture->run('deactivate');
            }
            if ($baselineId !== null) {
                $db->delete('consents', ['id' => $baselineId]);
            }
            if ($newId !== null) {
                $db->delete('consents', ['id' => $newId]);
            }
        }
        self::assertSame('clean', $fixture->run('verify'));
    }

    public function testRealBookingSmokeUsesCanaryLeaseAndCleansEveryOwnedResource(): void
    {
        $fixture = new Zero_surprise_canary_fixture();
        $server = null;
        $reportPath = sys_get_temp_dir() . '/zero-surprise-booking-' . bin2hex(random_bytes(8)) . '.json';
        $db = get_instance()->db;
        $sentinelEmail = 'zero-surprise-sentinel-' . bin2hex(random_bytes(8)) . '@synthetic.invalid';
        $sentinelId = null;
        $settings = [];
        $originalIsolated = getenv('FH_DEFENSE_ISOLATED');
        $baselineMarkers = $db->order_by('customer_id', 'ASC')->get('public_booking_customers')->result_array();

        try {
            foreach (['display_terms_and_conditions', 'display_privacy_policy'] as $name) {
                $setting = $db->get_where('settings', ['name' => $name])->row_array();
                self::assertNotEmpty($setting);
                $settings[] = $setting;
                self::assertTrue($db->update('settings', ['value' => '1'], ['name' => $name]));
            }
            self::assertSame('active', $fixture->run('activate'));
            $state = json_decode(
                file_get_contents(Zero_surprise_canary_fixture::DEFAULT_STATE_FILE),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $customerRole = $db->get_where('roles', ['slug' => 'customer'])->row_array();
            self::assertIsArray($customerRole);
            self::assertTrue(
                $db->insert('users', [
                    'first_name' => 'Unrelated',
                    'last_name' => 'Sentinel',
                    'email' => $sentinelEmail,
                    'phone_number' => '000000000',
                    'notes' => 'foreign-zero-surprise-sentinel',
                    'timezone' => 'UTC',
                    'language' => 'english',
                    'is_private' => 1,
                    'id_roles' => (int) $customerRole['id'],
                ]),
            );
            $sentinelId = (int) $db->insert_id();
            $sentinelBefore = $db->get_where('users', ['id' => $sentinelId])->row_array();
            self::assertIsArray($sentinelBefore);
            self::assertTrue(
                $db->insert('consents', [
                    'first_name' => 'Unrelated',
                    'last_name' => 'Sentinel',
                    'email' => $sentinelEmail,
                    'ip' => '127.0.0.1',
                    'type' => 'privacy-policy',
                ]),
            );
            $sentinelConsentBefore = $db->get_where('consents', ['email' => $sentinelEmail])->result_array();
            $baselineConsents = $db->order_by('id', 'ASC')->get('consents')->result_array();
            $baselineAppointments = $db->order_by('id', 'ASC')->get('appointments')->result_array();

            // The existing canary CI job owns a fresh root Docker stack.
            // This flag only admits its HTTP-server helper and is restored below.
            putenv('FH_DEFENSE_ISOLATED=1');
            $server = new DefenseCycleHttpServer();
            $command = [
                PHP_BINARY,
                dirname(__DIR__, 2) . '/scripts/ci/booking_write_contract_smoke.php',
                '--base-url=' . $server->baseUrl,
                '--index-page=index.php',
                '--booking-search-days=1',
                '--booking-start-date=' . date('Y-m-d', strtotime('+1 day')),
                '--retry-count=0',
                '--timezone=UTC',
                '--run-id=' . $state['run_id'],
                '--canary-context-file=' . Zero_surprise_canary_fixture::DEFAULT_STATE_FILE,
                '--output-json=' . $reportPath,
            ];
            $pipes = [];
            $process = proc_open(
                $command,
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                dirname(__DIR__, 2),
                getenv(),
            );
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            self::assertSame(0, $exitCode, $stdout . $stderr);
            self::assertFileExists($reportPath);
            $report = json_decode(file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(['total' => 6, 'passed' => 6, 'failed' => 0], $report['summary']);
            self::assertContains('booking_cancel_unknown_hash_contract', array_column($report['checks'], 'name'));
            foreach ($report['checks'] as $check) {
                self::assertSame('pass', $check['status'], $check['name']);
            }
            self::assertSame([], $report['state']['cleanup']['failures'] ?? null);
            self::assertSame($state['run_id'], $report['run_id']);

            self::assertSame($sentinelBefore, $db->get_where('users', ['id' => $sentinelId])->row_array());
            self::assertSame(
                $sentinelConsentBefore,
                $db->get_where('consents', ['email' => $sentinelEmail])->result_array(),
            );
            self::assertSame($baselineConsents, $db->order_by('id', 'ASC')->get('consents')->result_array());
            self::assertSame($baselineAppointments, $db->order_by('id', 'ASC')->get('appointments')->result_array());
            self::assertSame(0, $db->get_where('users', ['notes' => 'run:' . $state['run_id']])->num_rows());
            self::assertSame(
                $baselineMarkers,
                $db->order_by('customer_id', 'ASC')->get('public_booking_customers')->result_array(),
            );
            self::assertSame(
                0,
                $db
                    ->like('notes', 'run:' . $state['run_id'], 'after')
                    ->get('appointments')
                    ->num_rows(),
            );
            foreach ([1, 2] as $counter) {
                $marker =
                    'booking-customer-' . $state['run_id'] . '-' . str_pad((string) $counter, 2, '0', STR_PAD_LEFT);
                $normalized = preg_replace('/[^a-z0-9]+/', '-', strtolower($marker)) ?: 'ci-write';
                $email = 'ci-' . substr(hash('sha256', $normalized), 0, 20) . '@synthetic.invalid';
                self::assertSame(0, $db->get_where('consents', ['email' => $email])->num_rows());
            }
        } finally {
            $server?->close();
            $originalIsolated === false
                ? putenv('FH_DEFENSE_ISOLATED')
                : putenv('FH_DEFENSE_ISOLATED=' . $originalIsolated);
            foreach ($settings as $setting) {
                $db->update('settings', ['value' => $setting['value']], ['id' => $setting['id']]);
            }
            if (is_file(Zero_surprise_canary_fixture::DEFAULT_STATE_FILE)) {
                $fixture->run('deactivate');
            }
            if ($sentinelId !== null) {
                $db->delete('consents', ['email' => $sentinelEmail]);
                $db->delete('users', ['id' => $sentinelId]);
            }
            if (is_file($reportPath)) {
                unlink($reportPath);
            }
        }
        self::assertSame('clean', $fixture->run('verify'));
        self::assertFileDoesNotExist(Zero_surprise_canary_fixture::DEFAULT_STATE_FILE);
    }
}
