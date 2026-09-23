<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\BlockedPeriodsApiWriteProbe;
use ReleaseGate\DefenseVerificationFixture;
use ReleaseGate\OrdinaryLiveFixture;
use ReleaseGate\OrdinaryProbeEvidence;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/GateHttpClient.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryLiveFixture.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/DefenseVerificationFixture.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/BlockedPeriodsApiWriteProbe.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryProbeEvidence.php';
require_once __DIR__ . '/Support/DefenseCycleHttpServer.php';

final class BlockedPeriodsApiWriteProbeTest extends TestCase
{
    public function testBoundedBlockedPeriodsApiMatrixUsesOwnedRowsAndCleansExactly(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires the isolated root defense stack.');
        }
        $directory = '/var/lib/fh-blocked-periods-api-probe-tests-' . bin2hex(random_bytes(8));
        $ordinary = new OrdinaryLiveFixture($directory);
        $fixture = new DefenseVerificationFixture($directory);
        $server = null;
        try {
            $actor = $ordinary->activate(roleSlug: 'admin');
            $fixture->activate('blocked_periods_api', $actor);
            foreach ($fixture->blockedPeriodsApiSnapshot() as $row) {
                $start = strtotime((string) ($row['start_datetime'] ?? ''));
                $end = strtotime((string) ($row['end_datetime'] ?? ''));
                self::assertIsInt($start);
                self::assertIsInt($end);
                self::assertLessThan($end, $start);
                self::assertLessThan(time() - 30 * 86400, $end);
            }
            $server = new DefenseCycleHttpServer();
            $evidence = new OrdinaryProbeEvidence($directory);
            $evidence->begin('ea_blocked_periods_api_test');
            $result = BlockedPeriodsApiWriteProbe::forApp(
                $server->baseUrl,
                $actor['username'],
                $actor['password'],
                $fixture,
            )->run($evidence->step(...));
            self::assertSame('verified', $result['status']);
            self::assertSame(400, $result['conflict_status']);
            self::assertSame(200, $result['matching_status']);
            self::assertSame(405, $result['wrong_verb_status']);
            self::assertSame(
                [
                    ['blocked_periods_api_conflict_put', 'started'],
                    ['blocked_periods_api_conflict_put', 'passed'],
                    ['blocked_periods_api_matching_put', 'started'],
                    ['blocked_periods_api_matching_put', 'passed'],
                    ['blocked_periods_api_wrong_verb_destroy', 'started'],
                    ['blocked_periods_api_wrong_verb_destroy', 'passed'],
                ],
                array_map(
                    static fn(array $event): array => [$event['phase'], $event['outcome']],
                    $evidence->read()['events'],
                ),
            );
        } finally {
            $server?->close();
            if (is_file($directory . '/defense-verification.json')) {
                $fixture->deactivate();
            }
            if (is_file($directory . '/state.json')) {
                $ordinary->deactivate();
            }
            $this->removePrivateDirectory($directory);
        }
        self::assertSame('clean', $fixture->verify());
    }

    public function testCleanupRefusesBlockedPeriodIdentityDrift(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires the isolated root defense stack.');
        }
        $directory = '/var/lib/fh-blocked-periods-api-drift-tests-' . bin2hex(random_bytes(8));
        $ordinary = new OrdinaryLiveFixture($directory);
        $fixture = new DefenseVerificationFixture($directory);
        $driftedId = 0;
        try {
            $actor = $ordinary->activate(roleSlug: 'admin');
            $state = $fixture->activate('blocked_periods_api', $actor);
            $driftedId = (int) $state['ids']['blocked_period_a'];
            get_instance()->db->update('blocked_periods', ['name' => 'foreign-name'], ['id' => $driftedId]);
            try {
                $fixture->blockedPeriodsApiSnapshot();
                self::fail('Snapshot accepted a drifted blocked period.');
            } catch (RuntimeException $error) {
                self::assertSame('Blocked period identity drift detected.', $error->getMessage());
            }
            try {
                $fixture->deactivate();
                self::fail('Cleanup deleted a drifted blocked period.');
            } catch (RuntimeException $error) {
                self::assertSame('Blocked period identity drift detected.', $error->getMessage());
                self::assertNotEmpty(
                    get_instance()
                        ->db->get_where('blocked_periods', ['id' => $driftedId])
                        ->row_array(),
                );
            }
        } finally {
            if ($driftedId > 0) {
                get_instance()->db->delete('blocked_periods', ['id' => $driftedId, 'name' => 'foreign-name']);
            }
            if (is_file($directory . '/defense-verification.json')) {
                $fixture->deactivate();
            }
            if (is_file($directory . '/state.json')) {
                $ordinary->deactivate();
            }
            $this->removePrivateDirectory($directory);
        }
    }

    public function testPreparedRecoveryReconstructsMissingIdAndCleansPartialActivation(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires the isolated root defense stack.');
        }
        $directory = '/var/lib/fh-blocked-periods-api-recovery-tests-' . bin2hex(random_bytes(8));
        $ordinary = new OrdinaryLiveFixture($directory);
        $fixture = new DefenseVerificationFixture($directory);
        try {
            $actor = $ordinary->activate(roleSlug: 'admin');
            $state = $fixture->activate('blocked_periods_api', $actor);
            unset($state['ids']['blocked_period_a']);
            $state['phase'] = 'prepared';
            $this->writeState($directory, $state);
            $fixture->deactivate();
            self::assertSame('clean', $fixture->verify());

            $state = $fixture->activate('blocked_periods_api', $actor);
            $missingId = (int) $state['ids']['blocked_period_b'];
            unset($state['ids']['blocked_period_b']);
            $state['phase'] = 'prepared';
            self::assertTrue(get_instance()->db->delete('blocked_periods', ['id' => $missingId]));
            $this->writeState($directory, $state);
            $fixture->deactivate();
            self::assertSame('clean', $fixture->verify());
            self::assertSame(
                0,
                get_instance()
                    ->db->get_where('blocked_periods', ['id' => $missingId])
                    ->num_rows(),
            );
        } finally {
            if (is_file($directory . '/defense-verification.json')) {
                $fixture->deactivate();
            }
            if (is_file($directory . '/state.json')) {
                $ordinary->deactivate();
            }
            $this->removePrivateDirectory($directory);
        }
    }

    public function testPreparedRecoveryRefusesUnjournaledMarkerDriftAndRetainsJournal(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires the isolated root defense stack.');
        }
        $directory = '/var/lib/fh-blocked-periods-api-recovery-drift-tests-' . bin2hex(random_bytes(8));
        $ordinary = new OrdinaryLiveFixture($directory);
        $fixture = new DefenseVerificationFixture($directory);
        $driftedId = 0;
        try {
            $actor = $ordinary->activate(roleSlug: 'admin');
            $state = $fixture->activate('blocked_periods_api', $actor);
            $driftedId = (int) $state['ids']['blocked_period_a'];
            unset($state['ids']['blocked_period_a']);
            $state['phase'] = 'prepared';
            $this->writeState($directory, $state);
            $driftedEnd = date(
                'Y-m-d H:i:s',
                strtotime((string) $state['intents']['blocked_periods']['a']['end']) + 3600,
            );
            self::assertTrue(
                get_instance()->db->update('blocked_periods', ['end_datetime' => $driftedEnd], ['id' => $driftedId]),
            );
            try {
                $fixture->deactivate();
                self::fail('Recovery accepted an unjournaled marker row with drifted times.');
            } catch (RuntimeException $error) {
                self::assertSame('Blocked period identity drift detected; refusing cleanup.', $error->getMessage());
                self::assertFileExists($directory . '/defense-verification.json');
                self::assertNotEmpty(
                    get_instance()
                        ->db->get_where('blocked_periods', ['id' => $driftedId])
                        ->row_array(),
                );
            }
        } finally {
            if ($driftedId > 0) {
                get_instance()->db->delete('blocked_periods', ['id' => $driftedId]);
            }
            if (is_file($directory . '/defense-verification.json')) {
                $fixture->deactivate();
            }
            if (is_file($directory . '/state.json')) {
                $ordinary->deactivate();
            }
            $this->removePrivateDirectory($directory);
        }
    }

    public function testPreparedRecoveryRejectsDuplicateJournaledIds(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires the isolated root defense stack.');
        }
        $directory = '/var/lib/fh-blocked-periods-api-duplicate-id-tests-' . bin2hex(random_bytes(8));
        $ordinary = new OrdinaryLiveFixture($directory);
        $fixture = new DefenseVerificationFixture($directory);
        try {
            $actor = $ordinary->activate(roleSlug: 'admin');
            $state = $fixture->activate('blocked_periods_api', $actor);
            $originalB = (int) $state['ids']['blocked_period_b'];
            $state['ids']['blocked_period_b'] = (int) $state['ids']['blocked_period_a'];
            $state['phase'] = 'cleaning';
            $state['cleanup_origin_phase'] = 'prepared';
            $this->writeState($directory, $state);
            try {
                $fixture->deactivate();
                self::fail('Cleanup accepted duplicate journaled blocked-period IDs.');
            } catch (RuntimeException $error) {
                self::assertSame('Blocked period cleanup IDs are ambiguous.', $error->getMessage());
                self::assertFileExists($directory . '/defense-verification.json');
                self::assertNotEmpty(
                    get_instance()
                        ->db->get_where('blocked_periods', ['id' => (int) $state['ids']['blocked_period_a']])
                        ->row_array(),
                );
            }
            $state['ids']['blocked_period_b'] = $originalB;
            $this->writeState($directory, $state);
            $fixture->deactivate();
            self::assertSame('clean', $fixture->verify());
        } finally {
            if (is_file($directory . '/defense-verification.json')) {
                $fixture->deactivate();
            }
            if (is_file($directory . '/state.json')) {
                $ordinary->deactivate();
            }
            $this->removePrivateDirectory($directory);
        }
    }

    private function writeState(string $directory, array $state): void
    {
        self::assertTrue(
            file_put_contents(
                $directory . '/defense-verification.json',
                json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ) !== false,
        );
        chmod($directory . '/defense-verification.json', 0600);
    }

    private function removePrivateDirectory(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($directory . '/' . $entry);
            }
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}
