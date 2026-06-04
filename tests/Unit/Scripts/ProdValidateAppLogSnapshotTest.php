<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class ProdValidateAppLogSnapshotTest extends TestCase
{
    public function testProdValidateDoesNotTreatExistingAppLogErrorsAsCurrent(): void
    {
        $workspace = sys_get_temp_dir() . '/prod-validate-app-log-' . bin2hex(random_bytes(8));
        $stubBin = $workspace . '/bin';
        $appRoot = $workspace . '/app-root';
        $logFile = $appRoot . '/storage/logs/log-2026-06-04.php';

        mkdir($stubBin, 0777, true);
        mkdir(dirname($logFile), 0777, true);

        try {
            $this->writeStubs($stubBin);
            file_put_contents(
                $logFile,
                implode("\n", [
                    "<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>",
                    'ERROR - 2026-06-04 14:45:09 --> historical PDF renderer fallback',
                    '',
                ]),
            );

            $result = $this->runCommand(
                ['bash', 'scripts/ops/prod_validate_after_change.sh', '--prod-ssh-target', 'prod.example'],
                $this->repoRoot(),
                $this->commandEnv($stubBin, $appRoot),
            );

            self::assertStringContainsString('app_error_like_lines_current=0', $result['stdout']);
            self::assertStringContainsString('app_error_like_lines_24h=1', $result['stdout']);
            self::assertStringContainsString('app_error_like_lines_24h_historical=1', $result['stdout']);
            self::assertStringNotContainsString(
                'historical PDF renderer fallback',
                $result['stdout'] . $result['stderr'],
            );
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testProdValidateCountsOnlyNewActionableAppLogBytesAsCurrent(): void
    {
        $workspace = sys_get_temp_dir() . '/prod-validate-app-log-' . bin2hex(random_bytes(8));
        $stubBin = $workspace . '/bin';
        $appRoot = $workspace . '/app-root';
        $logFile = $appRoot . '/storage/logs/log-2026-06-04.php';
        $appendMarker = $workspace . '/append.done';

        mkdir($stubBin, 0777, true);
        mkdir(dirname($logFile), 0777, true);

        try {
            $this->writeStubs($stubBin);
            file_put_contents(
                $logFile,
                implode("\n", [
                    "<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>",
                    'ERROR - 2026-06-04 14:45:09 --> historical PDF renderer fallback',
                    '',
                ]),
            );

            $result = $this->runCommand(
                ['bash', 'scripts/ops/prod_validate_after_change.sh', '--prod-ssh-target', 'prod.example'],
                $this->repoRoot(),
                array_merge($this->commandEnv($stubBin, $appRoot), [
                    'APP_LOG_APPEND_FILE' => $logFile,
                    'APP_LOG_APPEND_MARKER' => $appendMarker,
                ]),
            );

            self::assertNotSame(0, $result['exit_code'], 'A new actionable app-log error should fail the gate.');
            self::assertStringContainsString('app_error_like_lines_current=1', $result['stdout']);
            self::assertStringContainsString('app_error_like_lines_24h=2', $result['stdout']);
            self::assertStringContainsString('FAIL app_error_like_lines_current expected=0 got=1', $result['stderr']);
            self::assertStringNotContainsString('post-snapshot failure', $result['stdout'] . $result['stderr']);
            self::assertStringNotContainsString('Azenvnet/index', $result['stdout'] . $result['stderr']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testProdValidateTreatsTruncatedAppLogContentAsCurrent(): void
    {
        $workspace = sys_get_temp_dir() . '/prod-validate-app-log-' . bin2hex(random_bytes(8));
        $stubBin = $workspace . '/bin';
        $appRoot = $workspace . '/app-root';
        $logFile = $appRoot . '/storage/logs/log-2026-06-04.php';
        $appendMarker = $workspace . '/append.done';

        mkdir($stubBin, 0777, true);
        mkdir(dirname($logFile), 0777, true);

        try {
            $this->writeStubs($stubBin);
            file_put_contents(
                $logFile,
                implode("\n", [
                    "<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>",
                    'ERROR - 2026-06-04 14:45:09 --> historical PDF renderer fallback before truncation',
                    'ERROR - 2026-06-04 14:45:10 --> another historical line before truncation',
                    '',
                ]),
            );

            $result = $this->runCommand(
                ['bash', 'scripts/ops/prod_validate_after_change.sh', '--prod-ssh-target', 'prod.example'],
                $this->repoRoot(),
                array_merge($this->commandEnv($stubBin, $appRoot), [
                    'APP_LOG_APPEND_FILE' => $logFile,
                    'APP_LOG_APPEND_MARKER' => $appendMarker,
                    'APP_LOG_APPEND_MODE' => 'replace',
                ]),
            );

            self::assertNotSame(0, $result['exit_code'], 'Truncated replacement content should be treated as current.');
            self::assertStringContainsString('app_error_like_lines_current=1', $result['stdout']);
            self::assertStringContainsString('app_error_like_lines_24h=1', $result['stdout']);
            self::assertStringContainsString('FAIL app_error_like_lines_current expected=0 got=1', $result['stderr']);
            self::assertStringNotContainsString(
                'current failure after truncation',
                $result['stdout'] . $result['stderr'],
            );
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testProdValidateDetectsRewrittenAppLogThatGrowsBackPastSnapshotSize(): void
    {
        $workspace = sys_get_temp_dir() . '/prod-validate-app-log-' . bin2hex(random_bytes(8));
        $stubBin = $workspace . '/bin';
        $appRoot = $workspace . '/app-root';
        $logFile = $appRoot . '/storage/logs/log-2026-06-04.php';
        $appendMarker = $workspace . '/append.done';

        mkdir($stubBin, 0777, true);
        mkdir(dirname($logFile), 0777, true);

        try {
            $this->writeStubs($stubBin);
            file_put_contents(
                $logFile,
                implode("\n", [
                    "<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>",
                    'ERROR - 2026-06-04 14:45:09 --> historical PDF renderer fallback before rewrite',
                    str_repeat('historical padding ', 20),
                    '',
                ]),
            );

            $result = $this->runCommand(
                ['bash', 'scripts/ops/prod_validate_after_change.sh', '--prod-ssh-target', 'prod.example'],
                $this->repoRoot(),
                array_merge($this->commandEnv($stubBin, $appRoot), [
                    'APP_LOG_APPEND_FILE' => $logFile,
                    'APP_LOG_APPEND_MARKER' => $appendMarker,
                    'APP_LOG_APPEND_MODE' => 'replace-grow',
                ]),
            );

            self::assertNotSame(0, $result['exit_code'], 'Rewritten log content should not trust the stale offset.');
            self::assertStringContainsString('app_error_like_lines_current=1', $result['stdout']);
            self::assertStringContainsString('app_error_like_lines_24h=1', $result['stdout']);
            self::assertStringContainsString('FAIL app_error_like_lines_current expected=0 got=1', $result['stderr']);
            self::assertStringNotContainsString(
                'current failure after grow-back rewrite',
                $result['stdout'] . $result['stderr'],
            );
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    private function writeStubs(string $stubBin): void
    {
        $this->writeSshStub($stubBin);
        $this->writeCurlStub($stubBin);
        $this->writeJournalctlStub($stubBin);
        $this->writeSystemctlStub($stubBin);
        $this->writeDockerStub($stubBin);
        $this->writeSqliteStub($stubBin);
        $this->writeCertbotStub($stubBin);
    }

    private function writeSshStub(string $stubBin): void
    {
        file_put_contents(
            $stubBin . '/ssh',
            <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail

            remote_cmd=''
            while [[ $# -gt 0 ]]; do
                case "$1" in
                    -o)
                        shift 2
                        ;;
                    *)
                        remote_cmd="$1"
                        shift
                        ;;
                esac
            done

            if [[ -z "$remote_cmd" ]]; then
                remote_cmd='bash -s'
            fi

            bash -c "$remote_cmd"
            BASH
            ,
        );
        chmod($stubBin . '/ssh', 0755);
    }

    private function writeCurlStub(string $stubBin): void
    {
        file_put_contents(
            $stubBin . '/curl',
            <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail

            if [[ -n "${APP_LOG_APPEND_FILE:-}" && -n "${APP_LOG_APPEND_MARKER:-}" && ! -e "${APP_LOG_APPEND_MARKER}" ]]; then
                if [[ "${APP_LOG_APPEND_MODE:-append}" == "replace" ]]; then
                    printf '%s\n' 'ERROR - 2026-06-04 14:56:00 --> current failure after truncation' > "${APP_LOG_APPEND_FILE}"
                elif [[ "${APP_LOG_APPEND_MODE:-append}" == "replace-grow" ]]; then
                    {
                        printf '%s\n' 'ERROR - 2026-06-04 14:56:00 --> current failure after grow-back rewrite'
                        printf '%s\n' 'non-error padding after rewrite'
                        printf '%s\n' 'non-error padding after rewrite'
                        printf '%s\n' 'non-error padding after rewrite'
                        printf '%s\n' 'non-error padding after rewrite'
                        printf '%s\n' 'non-error padding after rewrite'
                        printf '%s\n' 'non-error padding after rewrite'
                        printf '%s\n' 'non-error padding after rewrite'
                        printf '%s\n' 'non-error padding after rewrite'
                        printf '%s\n' 'non-error padding after rewrite'
                        printf '%s\n' 'non-error padding after rewrite'
                        printf '%s\n' 'non-error padding after rewrite'
                        printf '%s\n' 'non-error padding after rewrite'
                        printf '%s\n' 'non-error padding after rewrite'
                        printf '%s\n' 'non-error padding after rewrite'
                        printf '%s\n' 'non-error padding after rewrite'
                    } > "${APP_LOG_APPEND_FILE}"
                else
                    {
                        printf '%s\n' 'ERROR - 2026-06-04 14:55:00 --> 404 Page Not Found: Azenvnet/index'
                        printf '%s\n' 'ERROR - 2026-06-04 14:56:00 --> post-snapshot failure'
                    } >> "${APP_LOG_APPEND_FILE}"
                fi
                : > "${APP_LOG_APPEND_MARKER}"
            fi

            url=''
            while [[ $# -gt 0 ]]; do
                case "$1" in
                    -o|-w|--max-time)
                        shift 2
                        ;;
                    -H)
                        shift 2
                        ;;
                    -sS)
                        shift
                        ;;
                    *)
                        url="$1"
                        shift
                        ;;
                esac
            done

            case "$url" in
                https://monitor.dasforscherhaus-leg.de/)
                    printf '302'
                    ;;
                https://dasforscherhaus-leg.de/|https://www.dasforscherhaus-leg.de/|http://127.0.0.1:3003/healthz|http://localhost/index.php/healthz)
                    printf '200'
                    ;;
                *)
                    printf '403'
                    ;;
            esac
            BASH
            ,
        );
        chmod($stubBin . '/curl', 0755);
    }

    private function writeJournalctlStub(string $stubBin): void
    {
        file_put_contents($stubBin . '/journalctl', "#!/usr/bin/env bash\nprintf '%s\n' '-- No entries --'\n");
        chmod($stubBin . '/journalctl', 0755);
    }

    private function writeSystemctlStub(string $stubBin): void
    {
        file_put_contents(
            $stubBin . '/systemctl',
            <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail

            if [[ "${1:-}" == "is-active" ]]; then
                printf 'active\n'
                exit 0
            fi

            if [[ "${1:-}" == "list-timers" ]]; then
                printf 'NEXT LEFT LAST PASSED UNIT ACTIVATES\n'
                printf 'n/a n/a n/a n/a certbot.timer certbot.service\n'
                exit 0
            fi

            exit 0
            BASH
            ,
        );
        chmod($stubBin . '/systemctl', 0755);
    }

    private function writeDockerStub(string $stubBin): void
    {
        file_put_contents(
            $stubBin . '/docker',
            <<<'BASH'
            #!/usr/bin/env bash
            printf '%s\n' 'uptime-kuma-uptime-kuma-1'
            printf '%s\n' 'fh-pdf-renderer-pdf-renderer-1'
            BASH
            ,
        );
        chmod($stubBin . '/docker', 0755);
    }

    private function writeSqliteStub(string $stubBin): void
    {
        file_put_contents(
            $stubBin . '/sqlite3',
            <<<'BASH'
            #!/usr/bin/env bash
            query="${*: -1}"
            if [[ "$query" == *"COUNT(*) FROM monitor"* ]]; then
                printf '13\n'
            else
                printf '13\n'
            fi
            BASH
            ,
        );
        chmod($stubBin . '/sqlite3', 0755);
    }

    private function writeCertbotStub(string $stubBin): void
    {
        file_put_contents(
            $stubBin . '/certbot',
            <<<'BASH'
            #!/usr/bin/env bash
            printf 'Certificate Name: dasforscherhaus-leg.de\n'
            printf 'Domains: dasforscherhaus-leg.de monitor.dasforscherhaus-leg.de www.dasforscherhaus-leg.de\n'
            printf 'Expiry Date: 2026-08-16 17:37:43+00:00 (VALID: 73 days)\n'
            BASH
            ,
        );
        chmod($stubBin . '/certbot', 0755);
    }

    /**
     * @return array<string, string>
     */
    private function commandEnv(string $stubBin, string $appRoot): array
    {
        return [
            'APP_ROOT' => $appRoot,
            'PATH' => $stubBin . PATH_SEPARATOR . (getenv('PATH') ?: ''),
        ];
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runCommand(array $command, string $cwd, array $env = []): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $cwd, array_merge($_ENV, $env));
        self::assertIsResource($process);

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout === false ? '' : $stdout,
            'stderr' => $stderr === false ? '' : $stderr,
        ];
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
