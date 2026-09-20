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
            self::assertSame("900\n2700\n", file_get_contents($fixture . '/sleep-calls'));
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
            self::assertSame(1, $result['exit_code']);
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
            if [[ "$phase" == 'active' && "$(cat "$fixture/fail-active")" == '1' ]]; then
                printf '0' >"$fixture/fail-active"
                exit 1
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
            BASH
            ,
        );
        chmod($fixture . '/bin/sleep', 0755);
        return $fixture;
    }

    private function runPilot(string $fixture, string $phase): array
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
            [
                'PATH' => $fixture . '/bin' . PATH_SEPARATOR . (getenv('PATH') ?: ''),
                'CSP_PILOT_STATUS_SCRIPT' => $fixture . '/status.sh',
            ],
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
