<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

defined('CSP_ACTIVATION_LOAD_ONLY') || define('CSP_ACTIVATION_LOAD_ONLY', true);
require_once dirname(__DIR__, 3) . '/scripts/ops/csp_report_only_activation.php';

final class CspReportOnlyActivationScriptTest extends TestCase
{
    public function testInstallAndRemoveAreBoundToTheCandidateIdentity(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Root-owned activation identity is verified in the CI container.');
        }
        $directory = '/var/lib/fh-csp-activation-test-' . bin2hex(random_bytes(6));
        mkdir($directory, 0755, true);
        chmod($directory, 0755);
        chown($directory, 0);
        chgrp($directory, 0);
        $target = $directory . '/csp-report-only.json';
        $candidate = \readActivationCandidate(
            $this->repoRoot() . '/scripts/ops/config/csp_report_only.production.v1.json',
        );
        self::assertIsArray($candidate);

        try {
            self::assertSame(
                ['status' => 'passed', 'result_class' => 'activation_installed'],
                \installActivation($target, $candidate['bytes'], $candidate['sha256']),
            );
            self::assertSame($candidate['sha256'], hash_file('sha256', $target));

            file_put_contents($target, "{}\n");
            self::assertSame(
                ['status' => 'failed', 'result_class' => 'activation_identity_mismatch'],
                \removeActivation($target, $candidate['sha256']),
            );
            self::assertFileExists($target);

            file_put_contents($target, $candidate['bytes']);
            chmod($target, 0644);
            self::assertSame(
                ['status' => 'passed', 'result_class' => 'activation_removed'],
                \removeActivation($target, $candidate['sha256']),
            );
            self::assertFileDoesNotExist($target);
        } finally {
            @unlink($target);
            rmdir($directory);
        }
    }

    public function testRunStateLeaseIsRootOwnedExactAndRunBound(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Root-owned run-state lease is verified in the CI container.');
        }
        $directory = '/var/lib/fh-csp-run-state-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        chmod($directory, 0700);
        chown($directory, 0);
        chgrp($directory, 0);
        $path = $directory . '/state.json';
        $runId = str_repeat('a', 32);
        $binding = str_repeat('b', 64);
        $candidateHash = str_repeat('c', 64);

        try {
            self::assertSame(
                ['status' => 'passed', 'result_class' => 'run_state_recorded'],
                \writeRunState($path, $runId, $candidateHash, $binding),
            );
            $identity = lstat($path);
            self::assertIsArray($identity);
            self::assertSame(0, $identity['uid']);
            self::assertSame(0, $identity['gid']);
            self::assertSame(0600, $identity['mode'] & 0777);
            self::assertSame(
                [
                    'schema' => 'csp_report_only_activation.v2',
                    'run_id' => $runId,
                    'candidate_sha256' => $candidateHash,
                    'release_binding' => $binding,
                ],
                \readRunState($path),
            );
            self::assertSame(
                ['status' => 'passed', 'result_class' => 'run_state_removed'],
                \removeRunState($path, ['run_id' => $runId]),
            );
            self::assertFileDoesNotExist($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    public function testFailedInstallKeepsLeaseUntilActivationAbsenceIsDurablyVerified(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Root-owned failed-install cleanup is verified in the CI container.');
        }
        $stateDirectory = '/var/lib/fh-csp-failed-install-state-' . bin2hex(random_bytes(6));
        $targetDirectory = '/var/lib/fh-csp-failed-install-target-' . bin2hex(random_bytes(6));
        foreach ([[$stateDirectory, 0700], [$targetDirectory, 0755]] as [$directory, $mode]) {
            mkdir($directory, $mode, true);
            chmod($directory, $mode);
            chown($directory, 0);
            chgrp($directory, 0);
        }
        $statePath = $stateDirectory . '/state.json';
        $target = $targetDirectory . '/csp-report-only.json';
        $runId = str_repeat('a', 32);
        $binding = str_repeat('b', 64);
        $candidateHash = str_repeat('c', 64);
        $installFailure = ['status' => 'failed', 'result_class' => 'activation_install_failed'];

        try {
            self::assertSame(
                ['status' => 'passed', 'result_class' => 'run_state_recorded'],
                \writeRunState($statePath, $runId, $candidateHash, $binding),
            );
            file_put_contents($target, "partial activation\n");
            chmod($target, 0644);
            chown($target, 0);
            chgrp($target, 0);

            self::assertSame(
                ['status' => 'failed', 'result_class' => 'activation_install_cleanup_unverified'],
                \cleanupFailedInstallState($target, $statePath, $installFailure),
            );
            self::assertFileExists($target);
            self::assertFileExists($statePath);

            unlink($target);
            self::assertSame($installFailure, \cleanupFailedInstallState($target, $statePath, $installFailure));
            self::assertFileDoesNotExist($statePath);
        } finally {
            @unlink($target);
            @unlink($statePath);
            rmdir($targetDirectory);
            rmdir($stateDirectory);
        }
    }

    public function testFailedInstallKeepsLeaseWhenEitherDirectorySnapshotIsUnavailable(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Root-owned failed-install snapshot failures are verified in the CI container.');
        }
        $directory = '/var/lib/fh-csp-failed-install-snapshot-' . bin2hex(random_bytes(6));
        mkdir($directory, 0755, true);
        chmod($directory, 0755);
        chown($directory, 0);
        chgrp($directory, 0);
        $target = $directory . '/csp-report-only.json';

        try {
            self::assertFalse(
                \activationTargetDurablyAbsent($target, static fn(string $_directory, string $_entry): ?bool => null),
            );

            $calls = 0;
            self::assertFalse(
                \activationTargetDurablyAbsent($target, static function (string $_directory, string $_entry) use (
                    &$calls,
                ): ?bool {
                    $calls++;

                    return $calls === 1 ? false : null;
                }),
            );
            self::assertSame(2, $calls);
        } finally {
            rmdir($directory);
        }
    }

    public function testRunIdAndReleaseBindingContractsAreClosed(): void
    {
        self::assertTrue(\validRunId(str_repeat('a', 32)));
        self::assertFalse(\validRunId(str_repeat('a', 31)));
        self::assertFalse(\validRunId(str_repeat('A', 32)));
        self::assertTrue(\validReleaseBinding(str_repeat('b', 64)));
        self::assertFalse(\validReleaseBinding(str_repeat('b', 63)));
        self::assertFalse(\validReleaseBinding(str_repeat('B', 64)));
    }

    public function testProductionLockMustPreexistWithExactRootIdentityAndMode(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Root-owned production lock identity is verified in the CI container.');
        }
        $directory = '/var/lib/fh-csp-lock-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        chmod($directory, 0700);
        chown($directory, 0);
        chgrp($directory, 0);
        $path = $directory . '/production.lock';

        try {
            self::assertNull(\openProductionLock($path));
            file_put_contents($path, '');
            chmod($path, 0644);
            self::assertNull(\openProductionLock($path));
            chmod($path, 0600);
            $handle = \openProductionLock($path);
            self::assertIsResource($handle);
            flock($handle, LOCK_UN);
            fclose($handle);
        } finally {
            @unlink($path);
            rmdir($directory);
        }
    }

    public function testPilotRunsOneActivationAndThreeObservationsThenRemoves(): void
    {
        $fixture = $this->createPilotFixture(false);
        try {
            $result = $this->runPilot($fixture, 'pilot');
            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertSame(
                "preflight\nactive\nactive\nactive\npreflight\n",
                file_get_contents($fixture . '/status-calls'),
            );
            self::assertSame("install\nremove\n", file_get_contents($fixture . '/activation-calls'));
            $sleeps = array_map('intval', file($fixture . '/sleep-calls', FILE_IGNORE_NEW_LINES));
            self::assertGreaterThanOrEqual(899, $sleeps[0] ?? 0);
            self::assertLessThanOrEqual(900, $sleeps[0] ?? 0);
            self::assertGreaterThanOrEqual(3590, $sleeps[1] ?? 0);
            self::assertLessThanOrEqual(3600, $sleeps[1] ?? 0);
            self::assertStringContainsString('csp_pilot.observation=0m', $result['stdout']);
            self::assertStringContainsString('csp_pilot.observation=15m', $result['stdout']);
            self::assertStringContainsString('csp_pilot.observation=60m', $result['stdout']);
            self::assertStringContainsString('csp_pilot.status=passed', $result['stdout']);
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    public function testPilotStopsAfterFirstFailedEvidenceAndRollsBackOnce(): void
    {
        $fixture = $this->createPilotFixture(true);
        try {
            $result = $this->runPilot($fixture, 'pilot');
            self::assertSame(1, $result['exit_code'], $result['stdout'] . $result['stderr']);
            self::assertSame("preflight\nactive\n", file_get_contents($fixture . '/status-calls'));
            self::assertSame("install\nremove\n", file_get_contents($fixture . '/activation-calls'));
            self::assertSame('', file_get_contents($fixture . '/sleep-calls'));
            self::assertStringContainsString('csp_pilot.rollback.status=passed', $result['stdout']);
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    public function testPilotStopsOnReleaseDriftAndRollsBackOwnedActivationOnce(): void
    {
        $fixture = $this->createPilotFixture(false, false, true);
        try {
            $result = $this->runPilot($fixture, 'pilot');
            self::assertSame(1, $result['exit_code']);
            self::assertSame("preflight\nactive\n", file_get_contents($fixture . '/status-calls'));
            self::assertSame("install\nremove\n", file_get_contents($fixture . '/activation-calls'));
            self::assertSame('', file_get_contents($fixture . '/sleep-calls'));
            self::assertStringContainsString('csp_pilot.rollback.status=passed', $result['stdout']);
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    public function testInterruptedCheckpointIsPersistedAndNeverBlindlyRepeatedOnRestart(): void
    {
        $fixture = $this->createPilotFixture(false);
        file_put_contents($fixture . '/interrupt-active', '1');
        try {
            $first = $this->runPilot($fixture, 'pilot');
            self::assertNotSame(0, $first['exit_code']);
            self::assertSame("preflight\nactive\n", file_get_contents($fixture . '/status-calls'));
            self::assertSame("install\nremove\n", file_get_contents($fixture . '/activation-calls'));
            self::assertFileExists($fixture . '/pilot-state.json');
            $state = json_decode((string) file_get_contents($fixture . '/pilot-state.json'), true);
            self::assertSame(str_repeat('a', 64), $state['release_binding'] ?? null);
            self::assertSame('activation', $state['completed'] ?? null);
            self::assertSame('activation=activation_install_verified', $state['results'] ?? null);

            file_put_contents($fixture . '/interrupt-active', '0');
            $second = $this->runPilot($fixture, 'pilot');
            self::assertNotSame(0, $second['exit_code']);
            self::assertSame("preflight\nactive\n", file_get_contents($fixture . '/status-calls'));
            self::assertSame("install\nremove\n", file_get_contents($fixture . '/activation-calls'));
            self::assertStringContainsString('checkpoint_outcome_unknown_cleanup_verified', $second['stdout']);
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    public function testInterruptionBetweenCheckpointsLeavesTerminalCleanupJournal(): void
    {
        $fixture = $this->createPilotFixture(false);
        file_put_contents($fixture . '/interrupt-sleep', '1');
        try {
            $first = $this->runPilot($fixture, 'pilot');
            self::assertNotSame(0, $first['exit_code']);
            $state = json_decode((string) file_get_contents($fixture . '/pilot-state.json'), true);
            self::assertSame('activation,0m', $state['completed'] ?? null);
            self::assertSame('checkpoint_outcome_unknown_cleanup_verified', $state['terminal'] ?? null);
            self::assertSame(str_repeat('a', 64), $state['release_binding'] ?? null);
            self::assertSame("install\nremove\n", file_get_contents($fixture . '/activation-calls'));

            file_put_contents($fixture . '/interrupt-sleep', '0');
            $second = $this->runPilot($fixture, 'pilot');
            self::assertNotSame(0, $second['exit_code']);
            self::assertStringContainsString('checkpoint_outcome_unknown_cleanup_verified', $second['stdout']);
            self::assertSame("install\nremove\n", file_get_contents($fixture . '/activation-calls'));
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    public function testActiveResumeSkipsInactivePreflightAndActivation(): void
    {
        $fixture = $this->createPilotFixture(false);
        file_put_contents($fixture . '/activation-present', '1');
        $state = [
            'schema' => 'csp_report_only_pilot.v2',
            'run_id' => str_repeat('a', 32),
            'release_binding' => str_repeat('a', 64),
            'production_target_binding' => hash('sha256', 'root@example.test'),
            'activation_at' => '1700000000',
            'completed' => 'activation',
            'checkpoint' => '',
            'results' => 'activation=activation_installed_verified',
            'terminal' => '',
        ];
        file_put_contents($fixture . '/pilot-state.json', json_encode($state));
        try {
            $result = $this->runPilot($fixture, 'pilot');
            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertSame("active\nactive\nactive\npreflight\n", file_get_contents($fixture . '/status-calls'));
            self::assertSame('', file_get_contents($fixture . '/sleep-calls'));
            self::assertSame("remove\n", file_get_contents($fixture . '/activation-calls'));
            self::assertFileDoesNotExist($fixture . '/pilot-state.json');
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    public function testCompletedRemovalResumesOnlyAfterInactiveReadOnlyCheck(): void
    {
        $fixture = $this->createPilotFixture(false);
        $state = [
            'schema' => 'csp_report_only_pilot.v2',
            'run_id' => str_repeat('a', 32),
            'release_binding' => str_repeat('a', 64),
            'production_target_binding' => hash('sha256', 'root@example.test'),
            'activation_at' => '1700000000',
            'completed' => 'activation,0m,15m,60m,remove',
            'checkpoint' => '',
            'results' =>
                'activation=activation_installed_verified,0m=evidence_verified,15m=evidence_verified,60m=evidence_verified,remove=activation_remove_verified',
            'terminal' => '',
        ];
        file_put_contents($fixture . '/pilot-state.json', json_encode($state));
        try {
            $result = $this->runPilot($fixture, 'pilot');
            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertSame("preflight\n", file_get_contents($fixture . '/status-calls'));
            self::assertSame('', file_get_contents($fixture . '/activation-calls'));
            self::assertFileDoesNotExist($fixture . '/pilot-state.json');
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    public function testCompletedRemovalWithUnavailableInactiveCheckStopsWithoutRepeatingCleanup(): void
    {
        $fixture = $this->createPilotFixture(false);
        file_put_contents($fixture . '/activation-present', '1');
        file_put_contents($fixture . '/fail-resume-status', '1');
        file_put_contents(
            $fixture . '/pilot-state.json',
            json_encode([
                'schema' => 'csp_report_only_pilot.v2',
                'run_id' => str_repeat('a', 32),
                'release_binding' => str_repeat('a', 64),
                'production_target_binding' => hash('sha256', 'root@example.test'),
                'activation_at' => '1700000000',
                'completed' => 'activation,0m,15m,60m,remove',
                'checkpoint' => '',
                'results' =>
                    'activation=activation_installed_verified,0m=evidence_verified,15m=evidence_verified,60m=evidence_verified,remove=activation_remove_verified',
                'terminal' => '',
            ]),
        );
        try {
            $result = $this->runPilot($fixture, 'pilot');
            self::assertSame(1, $result['exit_code']);
            self::assertSame('', file_get_contents($fixture . '/activation-calls'));
            $state = json_decode((string) file_get_contents($fixture . '/pilot-state.json'), true);
            self::assertSame('checkpoint_outcome_unknown_cleanup_unverified', $state['terminal'] ?? null);
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    public function testResumeRejectsJournalForDifferentProductionTarget(): void
    {
        $fixture = $this->createPilotFixture(false);
        file_put_contents(
            $fixture . '/pilot-state.json',
            json_encode([
                'schema' => 'csp_report_only_pilot.v2',
                'run_id' => str_repeat('a', 32),
                'release_binding' => str_repeat('a', 64),
                'production_target_binding' => hash('sha256', 'root@other.example.test'),
                'activation_at' => '1700000000',
                'completed' => 'activation',
                'checkpoint' => '',
                'results' => 'activation=activation_installed_verified',
                'terminal' => '',
            ]),
        );
        try {
            $result = $this->runPilot($fixture, 'pilot');
            self::assertSame(1, $result['exit_code']);
            self::assertStringContainsString('resume_production_target_changed', $result['stdout']);
            self::assertSame('', file_get_contents($fixture . '/status-calls'));
            self::assertSame('', file_get_contents($fixture . '/activation-calls'));
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    public function testRemovalCheckpointWithUnavailableInactiveCheckStopsWithoutMutation(): void
    {
        $fixture = $this->createPilotFixture(false);
        file_put_contents($fixture . '/activation-present', '1');
        file_put_contents($fixture . '/fail-resume-status', '1');
        file_put_contents(
            $fixture . '/pilot-state.json',
            json_encode([
                'schema' => 'csp_report_only_pilot.v2',
                'run_id' => str_repeat('a', 32),
                'release_binding' => str_repeat('a', 64),
                'production_target_binding' => hash('sha256', 'root@example.test'),
                'activation_at' => '1700000000',
                'completed' => 'activation,0m,15m,60m',
                'checkpoint' => 'remove',
                'results' =>
                    'activation=activation_installed_verified,0m=evidence_verified,15m=evidence_verified,60m=evidence_verified',
                'terminal' => '',
            ]),
        );
        try {
            $result = $this->runPilot($fixture, 'pilot');
            self::assertSame(1, $result['exit_code']);
            self::assertSame('', file_get_contents($fixture . '/activation-calls'));
            $state = json_decode((string) file_get_contents($fixture . '/pilot-state.json'), true);
            self::assertSame('checkpoint_outcome_unknown_cleanup_unverified', $state['terminal'] ?? null);
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    public function testFinalOutputFailurePreservesCompletedJournalAndReleasesPilotLock(): void
    {
        $fixture = $this->createPilotFixture(false);
        file_put_contents(
            $fixture . '/fail-final-output.sh',
            <<<'BASH'
            printf() {
                if [[ "${1-}" == 'csp_pilot.status=passed\n' ]] &&
                    [[ -n "${CSP_PILOT_STATE_FILE:-}" ]] &&
                    grep -q '"completed":"activation,0m,15m,60m,remove,postflight"' "$CSP_PILOT_STATE_FILE" 2>/dev/null; then
                    return 1
                fi
                builtin printf "$@"
            }
            BASH
            ,
        );
        try {
            $result = $this->runPilot($fixture, 'pilot', ['BASH_ENV' => $fixture . '/fail-final-output.sh']);
            self::assertSame(1, $result['exit_code']);
            self::assertFileExists($fixture . '/pilot-state.json');
            self::assertDirectoryDoesNotExist($fixture . '/pilot-lock');
            $state = json_decode((string) file_get_contents($fixture . '/pilot-state.json'), true);
            self::assertSame('activation,0m,15m,60m,remove,postflight', $state['completed'] ?? null);
            self::assertSame('', $state['checkpoint'] ?? null);
            self::assertSame('', $state['terminal'] ?? null);
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    public function testImpossibleJournalStateStopsBeforeProductionWork(): void
    {
        $fixture = $this->createPilotFixture(false);
        file_put_contents(
            $fixture . '/pilot-state.json',
            json_encode([
                'schema' => 'csp_report_only_pilot.v2',
                'run_id' => str_repeat('a', 32),
                'release_binding' => str_repeat('a', 64),
                'production_target_binding' => hash('sha256', 'root@example.test'),
                'activation_at' => '',
                'completed' => 'activation,15m',
                'checkpoint' => '',
                'results' => 'activation=activation_installed_verified,15m=evidence_verified',
                'terminal' => '',
            ]),
        );
        try {
            $result = $this->runPilot($fixture, 'pilot');
            self::assertSame(1, $result['exit_code']);
            self::assertStringContainsString('state_semantics_invalid', $result['stdout']);
            self::assertSame('', file_get_contents($fixture . '/status-calls'));
            self::assertSame('', file_get_contents($fixture . '/activation-calls'));
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    public function testActivationInFlightWithInactiveReadOnlyStateDoesNotRepeatRemoval(): void
    {
        $fixture = $this->createPilotFixture(false);
        file_put_contents(
            $fixture . '/pilot-state.json',
            json_encode([
                'schema' => 'csp_report_only_pilot.v2',
                'run_id' => str_repeat('a', 32),
                'release_binding' => str_repeat('a', 64),
                'production_target_binding' => hash('sha256', 'root@example.test'),
                'activation_at' => '',
                'completed' => '',
                'checkpoint' => 'activation',
                'results' => '',
                'terminal' => '',
            ]),
        );
        try {
            $result = $this->runPilot($fixture, 'pilot');
            self::assertSame(1, $result['exit_code'], $result['stdout'] . $result['stderr']);
            self::assertStringContainsString('checkpoint_outcome_unknown_cleanup_verified', $result['stdout']);
            self::assertSame('', file_get_contents($fixture . '/activation-calls'));
            $state = json_decode((string) file_get_contents($fixture . '/pilot-state.json'), true);
            self::assertSame(str_repeat('a', 64), $state['release_binding'] ?? null);
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    public function testActivationInFlightWithActiveStatePerformsOneBoundedCleanup(): void
    {
        $fixture = $this->createPilotFixture(false);
        file_put_contents($fixture . '/activation-present', '1');
        file_put_contents(
            $fixture . '/pilot-state.json',
            json_encode([
                'schema' => 'csp_report_only_pilot.v2',
                'run_id' => str_repeat('a', 32),
                'release_binding' => str_repeat('a', 64),
                'production_target_binding' => hash('sha256', 'root@example.test'),
                'activation_at' => '',
                'completed' => '',
                'checkpoint' => 'activation',
                'results' => '',
                'terminal' => '',
            ]),
        );
        try {
            $result = $this->runPilot($fixture, 'pilot');
            self::assertSame(1, $result['exit_code']);
            self::assertStringContainsString('checkpoint_outcome_unknown_cleanup_verified', $result['stdout']);
            self::assertSame("remove\n", file_get_contents($fixture . '/activation-calls'));
            $state = json_decode((string) file_get_contents($fixture . '/pilot-state.json'), true);
            self::assertSame(str_repeat('a', 64), $state['release_binding'] ?? null);
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    public function testInFlightPostflightStopsWithoutRepeatingCompletedRemoval(): void
    {
        $fixture = $this->createPilotFixture(false);
        file_put_contents(
            $fixture . '/pilot-state.json',
            json_encode([
                'schema' => 'csp_report_only_pilot.v2',
                'run_id' => str_repeat('a', 32),
                'release_binding' => str_repeat('a', 64),
                'production_target_binding' => hash('sha256', 'root@example.test'),
                'activation_at' => '1700000000',
                'completed' => 'activation,0m,15m,60m,remove',
                'checkpoint' => 'postflight',
                'results' =>
                    'activation=activation_installed_verified,0m=evidence_verified,15m=evidence_verified,60m=evidence_verified,remove=activation_remove_verified',
                'terminal' => '',
            ]),
        );
        try {
            $result = $this->runPilot($fixture, 'pilot');
            self::assertSame(1, $result['exit_code']);
            self::assertStringContainsString('checkpoint_outcome_unknown_cleanup_verified', $result['stdout']);
            self::assertSame('', file_get_contents($fixture . '/activation-calls'));
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    public function testFullyCompletedJournalIsReadOnlyVerifiedAndRemoved(): void
    {
        $fixture = $this->createPilotFixture(false);
        file_put_contents(
            $fixture . '/pilot-state.json',
            json_encode([
                'schema' => 'csp_report_only_pilot.v2',
                'run_id' => str_repeat('a', 32),
                'release_binding' => str_repeat('a', 64),
                'production_target_binding' => hash('sha256', 'root@example.test'),
                'activation_at' => '1700000000',
                'completed' => 'activation,0m,15m,60m,remove,postflight',
                'checkpoint' => '',
                'results' =>
                    'activation=activation_installed_verified,0m=evidence_verified,15m=evidence_verified,60m=evidence_verified,remove=activation_remove_verified,postflight=preflight_verified',
                'terminal' => '',
            ]),
        );
        try {
            $result = $this->runPilot($fixture, 'pilot');
            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('postflight_already_verified', $result['stdout']);
            self::assertSame('', file_get_contents($fixture . '/activation-calls'));
            self::assertFileDoesNotExist($fixture . '/pilot-state.json');
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    public function testFailedNormalRemovalIsNotRetriedByExitTrap(): void
    {
        $fixture = $this->createPilotFixture(false, true);
        try {
            $result = $this->runPilot($fixture, 'pilot');
            self::assertSame(1, $result['exit_code']);
            self::assertSame("install\nremove\n", file_get_contents($fixture . '/activation-calls'));
            self::assertStringContainsString('csp_pilot.rollback.status=failed', $result['stdout']);
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    public function testPreflightNeverCallsActivation(): void
    {
        $fixture = $this->createPilotFixture(false);
        try {
            $result = $this->runPilot($fixture, 'preflight');
            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertSame("preflight\n", file_get_contents($fixture . '/status-calls'));
            self::assertSame('', file_get_contents($fixture . '/activation-calls'));
            self::assertSame('', file_get_contents($fixture . '/sleep-calls'));
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    public function testBusyPilotLockStopsBeforeProductionWork(): void
    {
        $fixture = $this->createPilotFixture(false);
        mkdir($fixture . '/pilot-lock');
        try {
            $result = $this->runPilot($fixture, 'pilot');
            self::assertSame(1, $result['exit_code']);
            self::assertStringContainsString('pilot_lock_busy', $result['stdout']);
            self::assertSame('', file_get_contents($fixture . '/status-calls'));
            self::assertSame('', file_get_contents($fixture . '/activation-calls'));
        } finally {
            rmdir($fixture . '/pilot-lock');
            $this->removeDirectory($fixture);
        }
    }

    public function testPostflightFailureAfterReadOnlyRemovalDoesNotRepeatRemoval(): void
    {
        $fixture = $this->createPilotFixture(false);
        file_put_contents($fixture . '/fail-preflight', '1');
        file_put_contents(
            $fixture . '/pilot-state.json',
            json_encode([
                'schema' => 'csp_report_only_pilot.v2',
                'run_id' => str_repeat('a', 32),
                'release_binding' => str_repeat('a', 64),
                'production_target_binding' => hash('sha256', 'root@example.test'),
                'activation_at' => '1700000000',
                'completed' => 'activation,0m,15m,60m,remove',
                'checkpoint' => '',
                'results' =>
                    'activation=activation_installed_verified,0m=evidence_verified,15m=evidence_verified,60m=evidence_verified,remove=activation_remove_read_only_verified',
                'terminal' => '',
            ]),
        );
        try {
            $result = $this->runPilot($fixture, 'pilot');
            self::assertSame(1, $result['exit_code']);
            self::assertSame('', file_get_contents($fixture . '/activation-calls'));
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    public function testActiveResumeStatusFailureStillPerformsBoundedCleanup(): void
    {
        $fixture = $this->createPilotFixture(false);
        file_put_contents($fixture . '/activation-present', '1');
        file_put_contents($fixture . '/fail-resume-status', '1');
        file_put_contents(
            $fixture . '/pilot-state.json',
            json_encode([
                'schema' => 'csp_report_only_pilot.v2',
                'run_id' => str_repeat('a', 32),
                'release_binding' => str_repeat('a', 64),
                'production_target_binding' => hash('sha256', 'root@example.test'),
                'activation_at' => '1700000000',
                'completed' => 'activation',
                'checkpoint' => '',
                'results' => 'activation=activation_installed_verified',
                'terminal' => '',
            ]),
        );
        try {
            $result = $this->runPilot($fixture, 'pilot');
            self::assertSame(1, $result['exit_code']);
            self::assertSame("remove\n", file_get_contents($fixture . '/activation-calls'));
            $state = json_decode((string) file_get_contents($fixture . '/pilot-state.json'), true);
            self::assertSame('checkpoint_outcome_unknown_cleanup_verified', $state['terminal'] ?? null);
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    public function testActiveResumeStatusFailureRetainsUnverifiedCleanupState(): void
    {
        $fixture = $this->createPilotFixture(false, true);
        file_put_contents($fixture . '/activation-present', '1');
        file_put_contents($fixture . '/fail-resume-status', '1');
        file_put_contents(
            $fixture . '/pilot-state.json',
            json_encode([
                'schema' => 'csp_report_only_pilot.v2',
                'run_id' => str_repeat('a', 32),
                'release_binding' => str_repeat('a', 64),
                'production_target_binding' => hash('sha256', 'root@example.test'),
                'activation_at' => '1700000000',
                'completed' => 'activation',
                'checkpoint' => '',
                'results' => 'activation=activation_installed_verified',
                'terminal' => '',
            ]),
        );
        try {
            $result = $this->runPilot($fixture, 'pilot');
            self::assertSame(1, $result['exit_code']);
            self::assertSame("remove\n", file_get_contents($fixture . '/activation-calls'));
            $state = json_decode((string) file_get_contents($fixture . '/pilot-state.json'), true);
            self::assertSame('checkpoint_outcome_unknown_cleanup_unverified', $state['terminal'] ?? null);
        } finally {
            $this->removeDirectory($fixture);
        }
    }

    public function testPreflightCliEmitsAReceiptBeforeProductionPrerequisitesAreAvailable(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('The production CLI preflight requires root identity.');
        }

        $result = $this->runCommand([
            PHP_BINARY,
            $this->repoRoot() . '/scripts/ops/csp_report_only_activation.php',
            '--action=preflight',
            '--expected-release-binding=' . str_repeat('a', 64),
        ]);

        self::assertSame(1, $result['exit_code'], $result['stderr']);
        self::assertSame('', $result['stderr']);
        $receipt = json_decode(trim($result['stdout']), true);
        self::assertIsArray($receipt);
        self::assertSame('csp_report_only_activation.v2', $receipt['schema'] ?? null);
        self::assertSame('preflight', $receipt['action'] ?? null);
        self::assertSame('failed', $receipt['status'] ?? null);
        self::assertNotSame('unknown', $receipt['result_class'] ?? null);
    }

    private function createPilotFixture(
        bool $failFirstActive,
        bool $failRemove = false,
        bool $changeBinding = false,
    ): string {
        $fixture = sys_get_temp_dir() . '/csp-pilot-' . bin2hex(random_bytes(6));
        mkdir($fixture . '/bin', 0700, true);
        foreach (['status-calls', 'activation-calls', 'sleep-calls'] as $file) {
            file_put_contents($fixture . '/' . $file, '');
        }
        file_put_contents($fixture . '/fail-active', $failFirstActive ? '1' : '0');
        file_put_contents($fixture . '/fail-remove', $failRemove ? '1' : '0');
        file_put_contents($fixture . '/change-binding', $changeBinding ? '1' : '0');
        file_put_contents($fixture . '/interrupt-active', '0');
        file_put_contents($fixture . '/interrupt-sleep', '0');
        file_put_contents($fixture . '/fail-preflight', '0');
        file_put_contents($fixture . '/fail-resume-status', '0');
        file_put_contents($fixture . '/activation-present', '0');
        file_put_contents($fixture . '/release-binding', str_repeat('a', 64));
        file_put_contents(
            $fixture . '/status.sh',
            <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            fixture="$(cd "$(dirname "$0")" && pwd)"
            phase=''
            expected_binding=''
            while [[ $# -gt 0 ]]; do
                case "$1" in
                    --phase) phase="$2"; shift 2 ;;
                    --prod-ssh-target) shift 2 ;;
                    --expected-release-binding) expected_binding="$2"; shift 2 ;;
                    *) exit 2 ;;
                esac
            done
            printf '%s\n' "$phase" >>"$fixture/status-calls"
            if [[ "$phase" == 'preflight' && "$(cat "$fixture/fail-preflight")" == '1' ]]; then
                exit 1
            fi
            if [[ "$phase" == 'active' && "$(cat "$fixture/fail-active")" == '1' ]]; then
                printf '0' >"$fixture/fail-active"
                exit 1
            fi
            if [[ "$phase" == 'active' && "$(cat "$fixture/interrupt-active")" == '1' ]]; then
                kill -TERM $$
            fi
            binding="$(cat "$fixture/release-binding")"
            if [[ "$phase" == 'active' && "$(cat "$fixture/change-binding")" == '1' ]]; then
                binding="$(printf 'b%.0s' {1..64})"
                printf 'csp_evidence.release_binding=%s\n' "$binding"
                printf 'csp_evidence.status=failed\n'
                exit 1
            fi
            if [[ -n "$expected_binding" && "$expected_binding" != "$binding" ]]; then
                printf 'csp_evidence.release_binding=%s\n' "$binding"
                printf 'csp_evidence.status=failed\n'
                exit 1
            fi
            printf 'csp_evidence.release_binding=%s\n' "$binding"
            printf 'csp_evidence.status=passed\n'
            BASH
            ,
        );
        chmod($fixture . '/status.sh', 0755);
        $hash = hash_file('sha256', $this->repoRoot() . '/scripts/ops/config/csp_report_only.production.v1.json');
        file_put_contents($fixture . '/candidate-hash', $hash);
        file_put_contents(
            $fixture . '/bin/ssh',
            <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            fixture="$(cd "$(dirname "$0")/.." && pwd)"
            action=''
            binding=''
            run_id=''
            expectation=''
            for argument in "$@"; do
                if [[ "$argument" == *csp_report_only_status.php* ]]; then
                    [[ "$argument" == *--expect=active* ]] && expectation='active'
                    [[ "$argument" == *--expect=inactive* ]] && expectation='inactive'
                fi
            done
            if [[ -n "$expectation" && "$(cat "$fixture/fail-resume-status")" == '1' ]]; then
                exit 255
            fi
            if [[ -n "$expectation" ]]; then
                hash="$(cat "$fixture/candidate-hash")"
                present="$(cat "$fixture/activation-present")"
                if [[ "$expectation" == 'active' && "$present" == '1' ]]; then
                    printf '{"schema":"csp_report_only_state.v2","expectation":"active","status":"passed","result_class":"state_verified","release_binding":"%s","activation":{"status":"active","sha256":"%s"},"aggregate":{"status":"missing","summary":null}}\n' "$(cat "$fixture/release-binding")" "$hash"
                elif [[ "$expectation" == 'inactive' && "$present" == '0' ]]; then
                    printf '{"schema":"csp_report_only_state.v2","expectation":"inactive","status":"passed","result_class":"state_verified","release_binding":"%s","activation":{"status":"inactive","sha256":null},"aggregate":{"status":"missing","summary":null}}\n' "$(cat "$fixture/release-binding")"
                elif [[ "$expectation" == 'active' ]]; then
                    printf '{"schema":"csp_report_only_state.v2","expectation":"active","status":"failed","result_class":"activation_missing","release_binding":"%s","activation":{"status":"inactive","sha256":null},"aggregate":{"status":"missing","summary":null}}\n' "$(cat "$fixture/release-binding")"
                    exit 1
                else
                    printf '{"schema":"csp_report_only_state.v2","expectation":"inactive","status":"failed","result_class":"activation_unexpected","release_binding":"%s","activation":{"status":"active","sha256":"%s"},"aggregate":{"status":"missing","summary":null}}\n' "$(cat "$fixture/release-binding")" "$hash"
                    exit 1
                fi
                exit 0
            fi
            for argument in "$@"; do
                case "$argument" in
                    *--action=install*) action='install' ;;
                    *--action=remove*) action='remove' ;;
                esac
                if [[ "$argument" == *--expected-release-binding=* ]]; then
                    binding="${argument#*--expected-release-binding=}"
                    binding="${binding%% *}"
                fi
                if [[ "$argument" == *--run-id=* ]]; then
                    run_id="${argument#*--run-id=}"
                    run_id="${run_id%% *}"
                fi
            done
            [[ -n "$action" && "$binding" =~ ^[a-f0-9]{64}$ && "$run_id" =~ ^[a-f0-9]{32}$ ]]
            printf '%s\n' "$action" >>"$fixture/activation-calls"
            hash="$(cat "$fixture/candidate-hash")"
            if [[ "$action" == 'install' ]]; then
                class='activation_installed'
            else
                class='activation_removed'
            fi
            if [[ "$action" == 'remove' && "$(cat "$fixture/fail-remove")" == '1' ]]; then
                printf '{"schema":"csp_report_only_activation.v2","action":"%s","status":"failed","result_class":"activation_remove_failed","candidate_sha256":"%s","release_binding":"%s","run_id":"%s"}\n' "$action" "$hash" "$binding" "$run_id"
                exit 1
            fi
            if [[ "$action" == 'install' ]]; then
                printf '1' >"$fixture/activation-present"
            else
                printf '0' >"$fixture/activation-present"
            fi
            printf '{"schema":"csp_report_only_activation.v2","action":"%s","status":"passed","result_class":"%s","candidate_sha256":"%s","release_binding":"%s","run_id":"%s"}\n' "$action" "$class" "$hash" "$binding" "$run_id"
            BASH
            ,
        );
        chmod($fixture . '/bin/ssh', 0755);
        file_put_contents(
            $fixture . '/bin/sleep',
            <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            fixture="$(cd "$(dirname "$0")/.." && pwd)"
            printf '%s\n' "$1" >>"$fixture/sleep-calls"
            if [[ "$(cat "$fixture/interrupt-sleep")" == '1' ]]; then
                kill -TERM $$
            fi
            BASH
            ,
        );
        chmod($fixture . '/bin/sleep', 0755);
        return $fixture;
    }

    /** @param array<string,string> $extraEnvironment */
    private function runPilot(string $fixture, string $phase, array $extraEnvironment = []): array
    {
        return $this->runCommand(
            [
                'bash',
                'scripts/ops/prod_csp_report_only_pilot.sh',
                '--phase',
                $phase,
                '--prod-ssh-target',
                'root@example.test',
            ],
            array_merge(
                [
                    'PATH' => $fixture . '/bin' . PATH_SEPARATOR . (getenv('PATH') ?: ''),
                    'CSP_PILOT_STATUS_SCRIPT' => $fixture . '/status.sh',
                    'CSP_PILOT_STATE_FILE' => $fixture . '/pilot-state.json',
                    'CSP_PILOT_LOCK_PATH' => $fixture . '/pilot-lock',
                ],
                $extraEnvironment,
            ),
        );
    }

    /** @param list<string> $command @param array<string,string> $env */
    private function runCommand(array $command, array $env = []): array
    {
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->repoRoot(),
            array_merge($_ENV, $env),
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [
            'exit_code' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private function removeDirectory(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
