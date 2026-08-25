<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class KumaMonitoringEnvProductionWrapperTest extends TestCase
{
    private string $wrapper;

    protected function setUp(): void
    {
        $this->wrapper = dirname(__DIR__, 3) . '/scripts/ops/prod_kuma_monitoring_env_v1.sh';
    }

    public function testDefaultPlanDoesNotContactSsh(): void
    {
        $workspace = sys_get_temp_dir() . '/rob490-wrapper-' . bin2hex(random_bytes(8));
        $bin = $workspace . '/bin';
        $marker = $workspace . '/ssh-called';
        mkdir($bin, 0700, true);
        file_put_contents($bin . '/ssh', "#!/usr/bin/env bash\ntouch " . escapeshellarg($marker) . "\nexit 99\n");
        chmod($bin . '/ssh', 0700);

        try {
            $result = $this->runWrapper([], ['PATH' => $bin . PATH_SEPARATOR . (getenv('PATH') ?: '/usr/bin:/bin')]);
            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('mode          : plan-only', $result['stdout']);
            self::assertStringContainsString('mutation      : none', $result['stdout']);
            self::assertFileDoesNotExist($marker);
        } finally {
            unlink($bin . '/ssh');
            rmdir($bin);
            rmdir($workspace);
        }
    }

    public function testWrapperBindsRemoteModesToExactMergedCommitAndClosedArtifacts(): void
    {
        $source = file_get_contents($this->wrapper);
        self::assertIsString($source);

        self::assertStringContainsString("PROD_SSH_TARGET='root@100.90.124.111'", $source);
        self::assertStringContainsString("HELPER_RELATIVE='scripts/ops/libexec/kuma_monitoring_env_v1.py'", $source);
        self::assertStringContainsString(
            "INSTALLER_RELATIVE='scripts/ops/libexec/kuma_monitoring_env_install_v1.py'",
            $source,
        );
        self::assertStringContainsString("INSTALLED_HELPER='/usr/local/libexec/fh-kuma-monitoring-env-v1'", $source);
        self::assertStringContainsString('StrictHostKeyChecking=yes', $source);
        self::assertStringNotContainsString('StrictHostKeyChecking=accept-new', $source);
        self::assertStringContainsString('local HEAD does not match --expected-commit', $source);
        self::assertStringContainsString('local origin/main does not match --expected-commit', $source);
        self::assertStringContainsString('live origin/main does not match --expected-commit', $source);
        self::assertStringContainsString('git -C "$REPO_ROOT" archive --format=tar "$EXPECTED_COMMIT"', $source);
        self::assertStringContainsString('export GIT_NO_REPLACE_OBJECTS=1', $source);
        self::assertStringContainsString('--no-same-owner --no-same-permissions', $source);
        self::assertStringNotContainsString('scp ', $source);
    }

    public function testInstallAndEnvMutationRemainSeparateModesWithNoRetry(): void
    {
        $source = file_get_contents($this->wrapper);
        self::assertIsString($source);

        self::assertStringContainsString('--install --confirm-live-write ROB-490', $source);
        self::assertStringContainsString('--execute --confirm-live-write ROB-490', $source);
        self::assertStringContainsString(
            'execute requires the exact helper to be installed by the separate install gate',
            $source,
        );
        self::assertStringContainsString('staging is retained and no retry is attempted', $source);
        self::assertStringContainsString('--invoke-installed execute --confirm-live-write ROB-490', $source);
        self::assertStringContainsString('--invoke-installed inspect', $source);
        self::assertStringContainsString('--invoke-source inspect', $source);
        self::assertStringNotContainsString('-I -B \'${REMOTE_STAGE}/${HELPER_RELATIVE}\'', $source);
        self::assertStringNotContainsString('systemctl', $source);
        self::assertStringNotContainsString('kuma_push_host_resources.sh', $source);
        self::assertStringNotContainsString('fh-release-archive-dump-retention', $source);
    }

    public function testInvalidRemoteContractsFailBeforeSsh(): void
    {
        $workspace = sys_get_temp_dir() . '/rob490-wrapper-invalid-' . bin2hex(random_bytes(8));
        $bin = $workspace . '/bin';
        $marker = $workspace . '/ssh-called';
        mkdir($bin, 0700, true);
        file_put_contents($bin . '/ssh', "#!/usr/bin/env bash\ntouch " . escapeshellarg($marker) . "\nexit 99\n");
        chmod($bin . '/ssh', 0700);
        $environment = ['PATH' => $bin . PATH_SEPARATOR . (getenv('PATH') ?: '/usr/bin:/bin')];

        try {
            $missingCommit = $this->runWrapper(['--inspect'], $environment);
            self::assertNotSame(0, $missingCommit['exit_code']);
            self::assertFileDoesNotExist($marker);

            $wrongConfirmation = $this->runWrapper(
                ['--install', '--confirm-live-write', 'ROB-488', '--expected-commit', str_repeat('a', 40)],
                $environment,
            );
            self::assertNotSame(0, $wrongConfirmation['exit_code']);
            self::assertFileDoesNotExist($marker);

            $inspectConfirmation = $this->runWrapper(
                ['--inspect', '--confirm-live-write', 'ROB-490', '--expected-commit', str_repeat('a', 40)],
                $environment,
            );
            self::assertNotSame(0, $inspectConfirmation['exit_code']);
            self::assertFileDoesNotExist($marker);
        } finally {
            unlink($bin . '/ssh');
            rmdir($bin);
            rmdir($workspace);
        }
    }

    public function testEachExactCommitMismatchStopsBeforeSsh(): void
    {
        $workspace = sys_get_temp_dir() . '/rob490-wrapper-commit-' . bin2hex(random_bytes(8));
        $bin = $workspace . '/bin';
        $marker = $workspace . '/ssh-called';
        mkdir($bin, 0700, true);
        file_put_contents($bin . '/ssh', "#!/usr/bin/env bash\ntouch " . escapeshellarg($marker) . "\nexit 99\n");
        chmod($bin . '/ssh', 0700);
        file_put_contents(
            $bin . '/git',
            <<<'BASH'
            #!/usr/bin/env bash
            case "$*" in
              *"rev-parse HEAD") printf '%s\n' "$FAKE_HEAD" ;;
              *"rev-parse --verify refs/remotes/origin/main^{commit}") printf '%s\n' "$FAKE_ORIGIN" ;;
              *"ls-remote --exit-code origin refs/heads/main") printf '%s\trefs/heads/main\n' "$FAKE_REMOTE" ;;
              *"diff --quiet"|*"diff --cached --quiet") exit 0 ;;
              *) exit 98 ;;
            esac
            BASH
            ,
        );
        chmod($bin . '/git', 0700);
        $expected = str_repeat('a', 40);
        $different = str_repeat('b', 40);
        $scenarios = [
            [$different, $expected, $expected],
            [$expected, $different, $expected],
            [$expected, $expected, $different],
        ];

        try {
            foreach ($scenarios as [$head, $origin, $remote]) {
                $result = $this->runWrapper(
                    ['--inspect', '--expected-commit', $expected],
                    [
                        'FAKE_HEAD' => $head,
                        'FAKE_ORIGIN' => $origin,
                        'FAKE_REMOTE' => $remote,
                        'PATH' => $bin . PATH_SEPARATOR . (getenv('PATH') ?: '/usr/bin:/bin'),
                    ],
                );
                self::assertNotSame(0, $result['exit_code']);
                self::assertFileDoesNotExist($marker);
            }
        } finally {
            unlink($bin . '/git');
            unlink($bin . '/ssh');
            rmdir($bin);
            rmdir($workspace);
        }
    }

    public function testSuccessfulInstallAndExecuteUseTheBoundRemoteHandoff(): void
    {
        $workspace = sys_get_temp_dir() . '/rob490-wrapper-success-' . bin2hex(random_bytes(8));
        $bin = $workspace . '/bin';
        $sshLog = $workspace . '/ssh.log';
        mkdir($bin, 0700, true);
        file_put_contents(
            $bin . '/git',
            <<<'BASH'
            #!/usr/bin/env bash
            case "$*" in
              *"rev-parse HEAD"|*"rev-parse --verify refs/remotes/origin/main^{commit}") printf '%s\n' "$FAKE_EXPECTED_COMMIT" ;;
              *"ls-remote --exit-code origin refs/heads/main") printf '%s\trefs/heads/main\n' "$FAKE_EXPECTED_COMMIT" ;;
              *"diff --quiet"|*"diff --cached --quiet") exit 0 ;;
              *"show "*) printf 'fixed-helper-bytes\n' ;;
              *"archive --format=tar"*) printf 'fake-tar-stream\n' ;;
              *) exit 98 ;;
            esac
            BASH
            ,
        );
        chmod($bin . '/git', 0700);
        file_put_contents(
            $bin . '/ssh',
            <<<'BASH'
            #!/usr/bin/env bash
            printf '%s\n' "$*" >> "$FAKE_SSH_LOG"
            payload="${!#}"
            case "$payload" in
              *"mktemp -d"*) printf '/root/.fh-kuma-monitoring-env-v1.ABCDEFGH\n' ;;
              *"/usr/bin/tar "*) dd of=/dev/null 2>/dev/null ;;
              *"--invoke-installed execute"*) printf '%s\n' '{"execution_ready":true,"monitoring_state":"enabled","mutation_performed":true,"recovery_state":"intact","rollback_outcome":"not_required","status":"pass"}' ;;
              *"--invoke-installed inspect"*) printf '%s\n' '{"execution_ready":true,"monitoring_state":"would_enable","mutation_performed":false,"recovery_state":"intact","rollback_outcome":"not_required","status":"pass"}' ;;
              *"--invoke-source inspect"*) printf '%s\n' '{"execution_ready":true,"monitoring_state":"would_enable","mutation_performed":false,"recovery_state":"intact","rollback_outcome":"not_required","status":"pass"}' ;;
              *"--execute --confirm-live-write ROB-490"*) printf '%s\n' '{"execution_ready":true,"install_state":"installed","mutation_performed":false,"status":"pass"}' ;;
              *"--expected-sha256 "*) printf '%s\n' '{"execution_ready":true,"install_state":"installed","mutation_performed":false,"status":"pass"}' ;;
              *"rm -rf --"*) exit 0 ;;
              *) exit 97 ;;
            esac
            BASH
            ,
        );
        chmod($bin . '/ssh', 0700);
        $expected = str_repeat('a', 40);
        $environment = [
            'FAKE_EXPECTED_COMMIT' => $expected,
            'FAKE_SSH_LOG' => $sshLog,
            'PATH' => $bin . PATH_SEPARATOR . (getenv('PATH') ?: '/usr/bin:/bin'),
        ];

        try {
            $inspect = $this->runWrapper(['--inspect', '--expected-commit', $expected], $environment);
            self::assertSame(0, $inspect['exit_code'], $inspect['stderr']);
            self::assertStringContainsString('[prod-kuma-monitoring-env-v1] inspect passed', $inspect['stdout']);

            $install = $this->runWrapper(
                ['--install', '--confirm-live-write', 'ROB-490', '--expected-commit', $expected],
                $environment,
            );
            self::assertSame(0, $install['exit_code'], $install['stderr']);
            self::assertStringContainsString('[prod-kuma-monitoring-env-v1] install passed', $install['stdout']);

            $execute = $this->runWrapper(
                ['--execute', '--confirm-live-write', 'ROB-490', '--expected-commit', $expected],
                $environment,
            );
            self::assertSame(0, $execute['exit_code'], $execute['stderr']);
            self::assertStringContainsString('[prod-kuma-monitoring-env-v1] execute passed', $execute['stdout']);

            $log = file_get_contents($sshLog);
            self::assertIsString($log);
            self::assertStringContainsString('StrictHostKeyChecking=yes', $log);
            self::assertStringContainsString('--invoke-installed inspect', $log);
            self::assertStringContainsString('--invoke-installed execute --confirm-live-write ROB-490', $log);
            self::assertStringContainsString('--invoke-source inspect', $log);
            self::assertMatchesRegularExpression("/--expected-sha256 '[0-9a-f]{64}'/", $log);
        } finally {
            unlink($bin . '/git');
            unlink($bin . '/ssh');
            if (is_file($sshLog)) {
                unlink($sshLog);
            }
            rmdir($bin);
            rmdir($workspace);
        }
    }

    /** @return array{exit_code:int,stdout:string,stderr:string} */
    private function runWrapper(array $arguments, array $environment): array
    {
        $process = proc_open(
            array_merge(['bash', $this->wrapper], $arguments),
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
            array_merge($_ENV, $environment),
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit_code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
