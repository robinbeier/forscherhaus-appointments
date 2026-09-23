<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\DefenseVerificationFixture;
use ReleaseGate\OrdinaryLiveFixture;
use ReleaseGate\OrdinaryProbeEvidence;
use ReleaseGate\UnavailabilitiesApiWriteProbe;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/GateHttpClient.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryLiveFixture.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/DefenseVerificationFixture.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/UnavailabilitiesApiWriteProbe.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryProbeEvidence.php';
require_once __DIR__ . '/Support/DefenseCycleHttpServer.php';

final class UnavailabilitiesApiWriteProbeTest extends TestCase
{
    public function testBoundedUnavailabilitiesApiMatrixUsesOwnedRowsAndCleansExactly(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires the isolated root defense stack.');
        }

        $directory = '/var/lib/fh-unavailabilities-api-probe-tests-' . bin2hex(random_bytes(8));
        $ordinary = new OrdinaryLiveFixture($directory);
        $fixture = new DefenseVerificationFixture($directory);
        $server = null;
        try {
            $actor = $ordinary->activate(roleSlug: 'admin');
            $fixture->activate('unavailabilities_api', $actor);
            self::assertCount(2, $fixture->unavailabilitiesApiSnapshot()['buffers']);
            $server = new DefenseCycleHttpServer();
            $evidence = new OrdinaryProbeEvidence($directory);
            $evidence->begin('ea_unavailabilities_api_test');
            $result = UnavailabilitiesApiWriteProbe::forApp(
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
                    ['unavailabilities_api_conflict_put', 'started'],
                    ['unavailabilities_api_conflict_put', 'passed'],
                    ['unavailabilities_api_matching_put', 'started'],
                    ['unavailabilities_api_matching_put', 'passed'],
                    ['unavailabilities_api_wrong_verb_destroy', 'started'],
                    ['unavailabilities_api_wrong_verb_destroy', 'passed'],
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

    public function testCleanupRejectsDriftedGeneratedBufferBeforeDeletion(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires the isolated root defense stack.');
        }

        $directory = '/var/lib/fh-unavailabilities-api-drift-tests-' . bin2hex(random_bytes(8));
        $ordinary = new OrdinaryLiveFixture($directory);
        $fixture = new DefenseVerificationFixture($directory);
        try {
            $actor = $ordinary->activate(roleSlug: 'admin');
            $state = $fixture->activate('unavailabilities_api', $actor);
            $parent = (int) $state['ids']['appointment'];
            $child = get_instance()
                ->db->get_where('appointments', ['id_parent_appointment' => $parent])
                ->row_array();
            self::assertNotEmpty($child);
            get_instance()->db->update('appointments', ['notes' => 'drifted-buffer'], ['id' => $child['id']]);

            $this->expectException(RuntimeException::class);
            try {
                $fixture->deactivate();
            } finally {
                get_instance()->db->update(
                    'appointments',
                    ['notes' => lang('buffer_block_note')],
                    ['id' => $child['id']],
                );
                if (is_file($directory . '/defense-verification.json')) {
                    $fixture->deactivate();
                }
                if (is_file($directory . '/state.json')) {
                    $ordinary->deactivate();
                }
                $this->removePrivateDirectory($directory);
            }
        } finally {
            if (is_dir($directory)) {
                $this->removePrivateDirectory($directory);
            }
        }
    }

    public function testCleanupRejectsManualRowAttachedToAnotherAppointmentBeforeDeletion(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires the isolated root defense stack.');
        }

        $directory = '/var/lib/fh-unavailabilities-api-manual-drift-tests-' . bin2hex(random_bytes(8));
        $ordinary = new OrdinaryLiveFixture($directory);
        $fixture = new DefenseVerificationFixture($directory);
        $driftedId = null;
        try {
            $actor = $ordinary->activate(roleSlug: 'admin');
            $state = $fixture->activate('unavailabilities_api', $actor);
            $driftedId = (int) $state['ids']['unavailability_a'];
            $parentId = (int) $state['ids']['unavailability_b'];
            $row = get_instance()
                ->db->get_where('appointments', ['id' => $driftedId])
                ->row_array();
            self::assertNotEmpty($row);
            get_instance()->db->update('appointments', ['id_parent_appointment' => $parentId], ['id' => $driftedId]);

            try {
                try {
                    $fixture->deactivate();
                    self::fail('Cleanup deleted a manual row after its parent link drifted.');
                } catch (RuntimeException $error) {
                    self::assertSame('Fixture identity drift detected.', $error->getMessage());
                    $stillOwned = get_instance()
                        ->db->get_where('appointments', ['id' => $driftedId])
                        ->row_array();
                    self::assertNotEmpty($stillOwned);
                    self::assertSame($parentId, (int) $stillOwned['id_parent_appointment']);
                }
            } finally {
                get_instance()->db->update('appointments', ['id_parent_appointment' => null], ['id' => $driftedId]);
                if (is_file($directory . '/defense-verification.json')) {
                    $fixture->deactivate();
                }
                if (is_file($directory . '/state.json')) {
                    $ordinary->deactivate();
                }
                $this->removePrivateDirectory($directory);
            }
        } finally {
            if (is_dir($directory)) {
                $this->removePrivateDirectory($directory);
            }
        }
    }

    public function testSnapshotRejectsMissingBufferConfiguration(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires the isolated root defense stack.');
        }

        $directory = '/var/lib/fh-unavailabilities-api-buffer-tests-' . bin2hex(random_bytes(8));
        $ordinary = new OrdinaryLiveFixture($directory);
        $fixture = new DefenseVerificationFixture($directory);
        try {
            $actor = $ordinary->activate(roleSlug: 'admin');
            $state = $fixture->activate('unavailabilities_api', $actor);
            self::assertCount(2, $fixture->unavailabilitiesApiSnapshot()['buffers']);
            $serviceId = (int) $state['ids']['service'];
            get_instance()->db->update('services', ['buffer_before' => 0], ['id' => $serviceId]);
            try {
                $fixture->unavailabilitiesApiSnapshot();
                self::fail('Snapshot accepted a service without the bound buffer configuration.');
            } catch (RuntimeException $error) {
                self::assertSame(
                    'Synthetic buffer configuration is not the bound 10/10 profile.',
                    $error->getMessage(),
                );
            } finally {
                get_instance()->db->update('services', ['buffer_before' => 10], ['id' => $serviceId]);
            }
        } finally {
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
