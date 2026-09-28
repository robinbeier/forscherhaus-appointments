<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class CspReportOnlySegmentScriptTest extends TestCase
{
    public function testStartRejectsDurationOutsideBound(): void
    {
        $result = $this->runCommand([
            'bash',
            'scripts/ops/prod_csp_report_only_segment.sh',
            '--phase',
            'start',
            '--duration-seconds=899',
        ]);
        self::assertSame(2, $result['exit_code']);
        self::assertStringContainsString('duration 900..14400', $result['stderr']);
    }

    public function testObserveAndFinishNeverStartWithoutAJournal(): void
    {
        $directory = sys_get_temp_dir() . '/csp-segment-operator-' . bin2hex(random_bytes(5));
        mkdir($directory, 0700, true);
        try {
            foreach (['observe', 'finish'] as $phase) {
                $result = $this->runCommand(
                    ['bash', 'scripts/ops/prod_csp_report_only_segment.sh', '--phase', $phase],
                    ['CSP_SEGMENT_STATE_FILE' => $directory . '/state.json'],
                );
                self::assertSame(1, $result['exit_code']);
                self::assertStringContainsString('journal_invalid', $result['stdout']);
            }
            self::assertFileDoesNotExist($directory . '/state.json');
        } finally {
            rmdir($directory);
        }
    }

    public function testUnknownInstallOutcomeLeavesRecoveryJournalWithoutRetry(): void
    {
        $directory = sys_get_temp_dir() . '/csp-segment-unknown-' . bin2hex(random_bytes(5));
        mkdir($directory . '/bin', 0700, true);
        $state = $directory . '/state.json';
        $status = $directory . '/status.sh';
        file_put_contents(
            $status,
            "#!/usr/bin/env bash\nprintf 'csp_evidence.release_binding=%064d\\n' 0\nprintf 'csp_evidence.status=passed\\n'\nprintf 'csp_evidence.result_class=evidence_verified\\n'\n",
        );
        chmod($status, 0755);
        file_put_contents($directory . '/bin/ssh', "#!/usr/bin/env bash\nexit 255\n");
        chmod($directory . '/bin/ssh', 0755);
        try {
            $result = $this->runCommand(
                ['bash', 'scripts/ops/prod_csp_report_only_segment.sh', '--phase', 'start', '--duration-seconds=900'],
                [
                    'PATH' => $directory . '/bin:' . (getenv('PATH') ?: ''),
                    'CSP_SEGMENT_STATUS_SCRIPT' => $status,
                    'CSP_SEGMENT_STATE_FILE' => $state,
                ],
            );
            self::assertSame(1, $result['exit_code']);
            self::assertStringContainsString('activation_outcome_unknown', $result['stdout']);
            self::assertFileExists($state);
            $journal = json_decode((string) file_get_contents($state), true, 8, JSON_THROW_ON_ERROR);
            self::assertSame('activation_outcome_unknown', $journal['terminal']);
            self::assertSame('activation', $journal['checkpoint']);
            self::assertNull($journal['candidate_sha256']);
        } finally {
            @unlink($state);
            @unlink($status);
            @unlink($directory . '/bin/ssh');
            rmdir($directory . '/bin');
            rmdir($directory);
        }
    }

    public function testValidatedServerFailureIsDistinctFromUnknownTransportOutcome(): void
    {
        $directory = sys_get_temp_dir() . '/csp-segment-failed-' . bin2hex(random_bytes(5));
        mkdir($directory . '/bin', 0700, true);
        $state = $directory . '/state.json';
        $status = $directory . '/status.sh';
        file_put_contents($status, "#!/usr/bin/env bash\nprintf 'csp_evidence.release_binding=%064d\\n' 0\n");
        file_put_contents(
            $directory . '/bin/ssh',
            <<<'SH'
            #!/usr/bin/env bash
            [[ "$*" =~ --run-id=([a-f0-9]{32}) ]] || exit 2
            printf '{"schema":"csp_report_only_activation.v2","action":"install-segment","status":"failed","result_class":"activation_already_present","candidate_sha256":"%064d","release_binding":"%064d","run_id":"%s"}\n' 0 0 "${BASH_REMATCH[1]}"
            exit 2
            SH
            ,
        );
        chmod($status, 0755);
        chmod($directory . '/bin/ssh', 0755);
        try {
            $result = $this->runCommand(
                ['bash', 'scripts/ops/prod_csp_report_only_segment.sh', '--phase', 'start', '--duration-seconds=900'],
                [
                    'PATH' => $directory . '/bin:' . (getenv('PATH') ?: ''),
                    'CSP_SEGMENT_STATUS_SCRIPT' => $status,
                    'CSP_SEGMENT_STATE_FILE' => $state,
                ],
            );
            self::assertSame(1, $result['exit_code'], $result['stdout'] . $result['stderr']);
            self::assertStringContainsString('activation_already_present', $result['stdout']);
            self::assertStringContainsString('csp_segment.result_class=activation_failed', $result['stdout']);
            $journal = json_decode((string) file_get_contents($state), true, 8, JSON_THROW_ON_ERROR);
            self::assertSame('activation_failed', $journal['terminal']);
        } finally {
            @unlink($state);
            @unlink($status);
            @unlink($directory . '/bin/ssh');
            @rmdir($directory . '/bin');
            @rmdir($directory);
        }
    }

    public function testFailedInitialProbeRemovesBoundActivationOnceAndKeepsCleanupEvidence(): void
    {
        $directory = sys_get_temp_dir() . '/csp-segment-cleanup-' . bin2hex(random_bytes(5));
        mkdir($directory . '/bin', 0700, true);
        $state = $directory . '/state.json';
        $status = $directory . '/status.sh';
        $sshLog = $directory . '/ssh.log';
        $binding = str_repeat('b', 64);
        $starts = time() - 10;
        $expires = $starts + 900;
        $candidate = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/scripts/ops/config/csp_report_only.production.v1.json'),
            true,
            8,
            JSON_THROW_ON_ERROR,
        );
        $candidate['schema'] = 'csp_report_only_config.v2';
        $candidate['starts_at_unix'] = $starts;
        $candidate['expires_at_unix'] = $expires;
        $hash = hash('sha256', json_encode($candidate, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");

        file_put_contents(
            $status,
            <<<'SH'
            #!/usr/bin/env bash
            if [[ "$*" == *"--phase preflight"* ]]; then
                printf 'csp_evidence.release_binding=%s\n' "$FAKE_BINDING"
                exit 0
            fi
            if [[ "$*" == *"--expect segment-active"* ]]; then
                exit 1
            fi
            if [[ "$*" == *"--expect inactive"* ]]; then
                [[ "$*" == *"--expected-release-binding $FAKE_BINDING"* ]] || exit 1
                printf 'csp_evidence.status=passed\n'
                exit 0
            fi
            exit 2
            SH
            ,
        );
        chmod($status, 0755);
        file_put_contents(
            $directory . '/bin/ssh',
            <<<'SH'
            #!/usr/bin/env bash
            printf '%s\n' "$*" >> "$FAKE_SSH_LOG"
            [[ "$*" =~ --run-id=([a-f0-9]{32}) ]] || { printf 'regex_failed\n' >> "$FAKE_SSH_LOG"; exit 2; }
            run_id="${BASH_REMATCH[1]}"
            if [[ "$*" == *"--action=install-segment"* ]]; then
                printf '{"schema":"csp_report_only_activation.v2","action":"install-segment","status":"passed","result_class":"activation_installed","candidate_sha256":"%s","release_binding":"%s","run_id":"%s","starts_at_unix":%s,"expires_at_unix":%s}\n' "$FAKE_HASH" "$FAKE_BINDING" "$run_id" "$FAKE_STARTS" "$FAKE_EXPIRES"
            elif [[ "$*" == *"--action=remove"* ]]; then
                printf '{"schema":"csp_report_only_activation.v2","action":"remove","status":"passed","result_class":"activation_removed","candidate_sha256":"%s","release_binding":"%s","run_id":"%s"}\n' "$FAKE_HASH" "$FAKE_BINDING" "$run_id"
            else
                printf 'action_failed\n' >> "$FAKE_SSH_LOG"
                exit 2
            fi
            SH
            ,
        );
        chmod($directory . '/bin/ssh', 0755);

        $env = [
            'PATH' => $directory . '/bin:' . (getenv('PATH') ?: ''),
            'CSP_SEGMENT_STATUS_SCRIPT' => $status,
            'CSP_SEGMENT_STATE_FILE' => $state,
            'FAKE_SSH_LOG' => $sshLog,
            'FAKE_BINDING' => $binding,
            'FAKE_HASH' => $hash,
            'FAKE_STARTS' => (string) $starts,
            'FAKE_EXPIRES' => (string) $expires,
        ];
        try {
            $start = $this->runCommand(
                ['bash', 'scripts/ops/prod_csp_report_only_segment.sh', '--phase', 'start', '--duration-seconds=900'],
                $env,
            );
            self::assertSame(
                1,
                $start['exit_code'],
                $start['stdout'] .
                    $start['stderr'] .
                    ' SSH=' .
                    (is_file($sshLog) ? (string) file_get_contents($sshLog) : '<none>') .
                    ' STATE=' .
                    (is_file($state) ? (string) file_get_contents($state) : '<none>'),
            );
            self::assertStringContainsString(
                'active_probe_failed_cleanup_verified',
                $start['stdout'],
                $start['stderr'] . ' SSH=' . (is_file($sshLog) ? (string) file_get_contents($sshLog) : '<none>'),
            );
            $journal = json_decode((string) file_get_contents($state), true, 8, JSON_THROW_ON_ERROR);
            self::assertSame('active_probe_failed_cleanup_verified', $journal['terminal']);
            self::assertTrue($journal['removal_attempted']);
            self::assertSame($hash, $journal['candidate_sha256']);
            $calls = (string) file_get_contents($sshLog);
            self::assertSame(1, substr_count($calls, '--action=install-segment'));
            self::assertSame(1, substr_count($calls, '--action=remove'));

            $finish = $this->runCommand(
                ['bash', 'scripts/ops/prod_csp_report_only_segment.sh', '--phase', 'finish'],
                $env,
            );
            self::assertSame(1, $finish['exit_code']);
            self::assertSame($calls, (string) file_get_contents($sshLog));
        } finally {
            @unlink($state);
            @unlink($status);
            @unlink($sshLog);
            @unlink($directory . '/bin/ssh');
            @rmdir($directory . '/bin');
            @rmdir($directory);
        }
    }

    public function testLostPostInstallJournalCanBeRecoveredReadOnlyThenFinishedOnce(): void
    {
        $directory = sys_get_temp_dir() . '/csp-segment-recovery-' . bin2hex(random_bytes(5));
        mkdir($directory . '/bin', 0700, true);
        $state = $directory . '/state.json';
        $status = $directory . '/status.sh';
        $sshLog = $directory . '/ssh.log';
        $binding = str_repeat('b', 64);
        $starts = time() - 10;
        $expires = $starts + 900;
        $candidate = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/scripts/ops/config/csp_report_only.production.v1.json'),
            true,
            8,
            JSON_THROW_ON_ERROR,
        );
        $candidate['schema'] = 'csp_report_only_config.v2';
        $candidate['starts_at_unix'] = $starts;
        $candidate['expires_at_unix'] = $expires;
        $hash = hash('sha256', json_encode($candidate, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
        file_put_contents(
            $status,
            <<<'SH'
            #!/usr/bin/env bash
            if [[ "$*" == *"--phase preflight"* ]]; then
                printf 'csp_evidence.release_binding=%s\n' "$FAKE_BINDING"
            elif [[ "$*" == *"--expect segment-observe"* ]]; then
                [[ "${FAKE_STATUS_FAIL:-0}" == 0 ]] || exit 1
                printf '{"schema":"csp_report_only_state.v2","status":"passed","release_binding":"%s","activation":{"status":"active","sha256":"%s","starts_at_unix":%s,"expires_at_unix":%s},"aggregate":{"status":"missing","summary":null,"scope":"cumulative_retention_window"}}\n' "$FAKE_BINDING" "$FAKE_HASH" "$FAKE_STARTS" "$FAKE_EXPIRES"
            elif [[ "$*" == *"--expect inactive"* ]]; then
                [[ "$*" == *"--expected-release-binding $FAKE_BINDING"* ]] || exit 1
                printf 'csp_evidence.status=passed\n'
            else
                exit 2
            fi
            SH
            ,
        );
        file_put_contents(
            $directory . '/bin/ssh',
            <<<'SH'
            #!/usr/bin/env bash
            printf '%s\n' "$*" >> "$FAKE_SSH_LOG"
            [[ "$*" =~ --run-id=([a-f0-9]{32}) ]] || exit 2
            run_id="${BASH_REMATCH[1]}"
            if [[ "$*" == *"--action=install-segment"* ]]; then
                action=install-segment; result=activation_installed
            elif [[ "$*" == *"--action=inspect-segment"* ]]; then
                [[ "$*" == *"--duration-seconds=900"* ]] || exit 2
                action=inspect-segment; result=segment_inspected
            elif [[ "$*" == *"--action=remove"* ]]; then
                printf '{"schema":"csp_report_only_activation.v2","action":"remove","status":"passed","result_class":"activation_removed","candidate_sha256":"%s","release_binding":"%s","run_id":"%s"}\n' "$FAKE_HASH" "$FAKE_BINDING" "$run_id"
                exit 0
            else
                exit 2
            fi
            printf '{"schema":"csp_report_only_activation.v2","action":"%s","status":"passed","result_class":"%s","candidate_sha256":"%s","release_binding":"%s","run_id":"%s","starts_at_unix":%s,"expires_at_unix":%s}\n' "$action" "$result" "$FAKE_HASH" "$FAKE_BINDING" "$run_id" "$FAKE_STARTS" "$FAKE_EXPIRES"
            SH
            ,
        );
        file_put_contents(
            $directory . '/bin/php',
            <<<'SH'
            #!/usr/bin/env bash
            if [[ "${FAIL_SECOND_WRITE:-0}" == 1 && "$1" == -r && "$2" == *'tempnam($dir,".segment-")'* ]]; then
                n=0; [[ -f "$FAKE_WRITE_COUNT" ]] && n="$(cat "$FAKE_WRITE_COUNT")"
                n=$((n+1)); printf '%s\n' "$n" > "$FAKE_WRITE_COUNT"
                [[ "$n" == 2 ]] && exit 77
            fi
            exec "$FAKE_REAL_PHP" "$@"
            SH
            ,
        );
        file_put_contents($directory . '/bin/uname', "#!/usr/bin/env bash\nprintf 'Linux\\n'\n");
        foreach ([$status, $directory . '/bin/ssh', $directory . '/bin/php', $directory . '/bin/uname'] as $script) {
            chmod($script, 0755);
        }
        $env = [
            'PATH' => $directory . '/bin:' . (getenv('PATH') ?: ''),
            'CSP_SEGMENT_STATUS_SCRIPT' => $status,
            'CSP_SEGMENT_STATE_FILE' => $state,
            'FAKE_SSH_LOG' => $sshLog,
            'FAKE_BINDING' => $binding,
            'FAKE_HASH' => $hash,
            'FAKE_STARTS' => (string) $starts,
            'FAKE_EXPIRES' => (string) $expires,
            'FAKE_REAL_PHP' => PHP_BINARY,
            'FAKE_WRITE_COUNT' => $directory . '/write-count',
        ];
        try {
            $start = $this->runCommand(
                ['bash', 'scripts/ops/prod_csp_report_only_segment.sh', '--phase', 'start', '--duration-seconds=900'],
                [...$env, 'FAIL_SECOND_WRITE' => '1'],
            );
            self::assertSame(1, $start['exit_code'], $start['stdout'] . $start['stderr']);
            self::assertStringContainsString('postinstall_journal_unavailable', $start['stdout']);
            self::assertSame(
                'activation',
                json_decode((string) file_get_contents($state), true, 8, JSON_THROW_ON_ERROR)['checkpoint'],
            );
            $interrupted = $this->runCommand(
                ['bash', 'scripts/ops/prod_csp_report_only_segment.sh', '--phase', 'recover'],
                [...$env, 'FAKE_STATUS_FAIL' => '1'],
            );
            self::assertSame(1, $interrupted['exit_code']);
            self::assertStringContainsString('recovery_state_unknown', $interrupted['stdout']);
            self::assertSame(
                'activation',
                json_decode((string) file_get_contents($state), true, 8, JSON_THROW_ON_ERROR)['checkpoint'],
            );
            $recovered = $this->runCommand(
                ['bash', 'scripts/ops/prod_csp_report_only_segment.sh', '--phase', 'recover'],
                $env,
            );
            self::assertSame(0, $recovered['exit_code'], $recovered['stdout'] . $recovered['stderr']);
            self::assertStringContainsString('segment_recovered', $recovered['stdout']);
            self::assertSame(
                $hash,
                json_decode((string) file_get_contents($state), true, 8, JSON_THROW_ON_ERROR)['candidate_sha256'],
            );
            $finish = $this->runCommand(
                ['bash', 'scripts/ops/prod_csp_report_only_segment.sh', '--phase', 'finish'],
                $env,
            );
            self::assertSame(0, $finish['exit_code'], $finish['stdout'] . $finish['stderr']);
            $calls = (string) file_get_contents($sshLog);
            self::assertSame(1, substr_count($calls, '--action=install-segment'));
            self::assertSame(2, substr_count($calls, '--action=inspect-segment'));
            self::assertSame(1, substr_count($calls, '--action=remove'));
        } finally {
            foreach (
                [
                    $state,
                    $status,
                    $sshLog,
                    $directory . '/write-count',
                    $directory . '/bin/ssh',
                    $directory . '/bin/php',
                    $directory . '/bin/uname',
                ]
                as $file
            ) {
                @unlink($file);
            }
            @rmdir($directory . '/bin');
            @rmdir($directory);
        }
    }

    /** @param list<string> $command @param array<string,string> $env */
    private function runCommand(array $command, array $env = []): array
    {
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
            array_merge($_ENV, $env),
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit_code' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
    }
}
