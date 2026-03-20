<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class KumaPushScriptEnvLoadingTest extends TestCase
{
    public function testPdfRendererLogMonitorLoadsEnvBeforeResolvingDefaults(): void
    {
        $workspace = $this->createWorkspace();

        try {
            $envFile = $workspace . '/renderer.env';
            $journalArgsFile = $workspace . '/journalctl-args.txt';

            file_put_contents(
                $envFile,
                implode(PHP_EOL, [
                    'KUMA_PUSH_URL_PDF_RENDERER_LOGS=https://kuma.example/render',
                    'KUMA_PDF_RENDERER_SERVICE_NAME=custom-renderer.service',
                    'KUMA_PDF_RENDERER_LOG_WINDOW_MINUTES=7',
                    'KUMA_PDF_RENDERER_ERROR_THRESHOLD=0',
                    '',
                ]),
            );

            $this->writeStub(
                $workspace . '/bin/journalctl',
                <<<'BASH'
                #!/usr/bin/env bash
                set -euo pipefail
                printf '%s\n' "$*" > "$JOURNALCTL_ARGS_FILE"
                exit 0
                BASH
                ,
            );

            $this->writeStub(
                $workspace . '/bin/curl',
                <<<'BASH'
                #!/usr/bin/env bash
                set -euo pipefail
                exit 0
                BASH
                ,
            );

            $result = $this->runCommand(['bash', 'scripts/ops/kuma_push_pdf_renderer_logs.sh'], $this->repoRoot(), [
                'PATH' => $workspace . '/bin:' . (getenv('PATH') ?: ''),
                'KUMA_PUSH_ENV_FILE' => $envFile,
                'JOURNALCTL_ARGS_FILE' => $journalArgsFile,
            ]);

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('OK pdf_renderer_errors=0 window=7m', $result['stdout']);
            self::assertSame(
                '-u custom-renderer.service --since -7 min -p err..alert --no-pager -o cat' . PHP_EOL,
                file_get_contents($journalArgsFile),
            );
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testPhpFpmLogMonitorLoadsEnvBeforeResolvingDefaults(): void
    {
        $workspace = $this->createWorkspace();

        try {
            $envFile = $workspace . '/php-fpm.env';
            $journalArgsFile = $workspace . '/journalctl-args.txt';

            file_put_contents(
                $envFile,
                implode(PHP_EOL, [
                    'KUMA_PUSH_URL_PHP_FPM_LOGS=https://kuma.example/php-fpm',
                    'KUMA_PHP_FPM_SERVICE_NAME=php9.9-fpm',
                    'KUMA_PHP_FPM_LOG_WINDOW_MINUTES=11',
                    'KUMA_PHP_FPM_ERROR_THRESHOLD=0',
                    '',
                ]),
            );

            $this->writeStub(
                $workspace . '/bin/journalctl',
                <<<'BASH'
                #!/usr/bin/env bash
                set -euo pipefail
                printf '%s\n' "$*" > "$JOURNALCTL_ARGS_FILE"
                exit 0
                BASH
                ,
            );

            $this->writeStub(
                $workspace . '/bin/curl',
                <<<'BASH'
                #!/usr/bin/env bash
                set -euo pipefail
                exit 0
                BASH
                ,
            );

            $result = $this->runCommand(['bash', 'scripts/ops/kuma_push_php_fpm_logs.sh'], $this->repoRoot(), [
                'PATH' => $workspace . '/bin:' . (getenv('PATH') ?: ''),
                'KUMA_PUSH_ENV_FILE' => $envFile,
                'JOURNALCTL_ARGS_FILE' => $journalArgsFile,
            ]);

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('OK php_fpm_errors=0 window=11m', $result['stdout']);
            self::assertSame(
                '-u php9.9-fpm --since -11 min -p err..alert --no-pager -o cat' . PHP_EOL,
                file_get_contents($journalArgsFile),
            );
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testPdfExportMonitorLoadsEnvBeforeResolvingDefaults(): void
    {
        $workspace = $this->createWorkspace();

        try {
            $envFile = $workspace . '/pdf-export.env';
            $credentialsFile = $workspace . '/pdf-export-credentials.env';
            $repoOverride = $workspace . '/repo-override';
            $outputDir = $workspace . '/pdf-export-output';
            $phpGateArgsFile = $workspace . '/php-gate-args.txt';
            $phpSummaryArgsFile = $workspace . '/php-summary-args.txt';
            $reportPath = $outputDir . '/kuma-pdf-export-latest.json';

            mkdir($repoOverride . '/scripts/release-gate', 0777, true);

            file_put_contents(
                $envFile,
                implode(PHP_EOL, [
                    'KUMA_PUSH_URL_PDF_EXPORT=https://kuma.example/pdf-export',
                    'KUMA_PDF_EXPORT_REPO_ROOT=' . $repoOverride,
                    'KUMA_PDF_EXPORT_OUTPUT_DIR=' . $outputDir,
                    'KUMA_PDF_EXPORT_BASE_URL=https://appointments.example.test',
                    'KUMA_PDF_EXPORT_INDEX_PAGE=app.php',
                    'KUMA_PDF_EXPORT_PDF_HEALTH_URL=https://renderer.example.test/healthz',
                    'KUMA_PDF_EXPORT_HTTP_TIMEOUT=19',
                    'KUMA_PDF_EXPORT_EXPORT_TIMEOUT=71',
                    'KUMA_PDF_EXPORT_MAX_PDF_DURATION_MS=12345',
                    'KUMA_PDF_EXPORT_REQUIRE_NONEMPTY_METRICS=1',
                    'KUMA_PDF_EXPORT_CREDENTIALS_FILE=' . $credentialsFile,
                    '',
                ]),
            );

            file_put_contents(
                $credentialsFile,
                implode(PHP_EOL, ['USERNAME=monitor-user', 'PASSWORD=monitor-pass', '']),
            );

            $this->writeStub(
                $workspace . '/bin/php',
                <<<'BASH'
                #!/usr/bin/env bash
                set -euo pipefail

                if [[ "${1:-}" == "-r" ]]; then
                  printf '%s\n' "$*" > "$PHP_SUMMARY_ARGS_FILE"
                  report_path="${3:-}"
                  if [[ -z "$report_path" || ! -f "$report_path" ]]; then
                    echo "missing or unreadable summary report path" >&2
                    exit 1
                  fi
                  printf 'all_checks_passed'
                  exit 0
                fi

                printf '%s\n' "$*" > "$PHP_GATE_ARGS_FILE"

                output_json=""
                for arg in "$@"; do
                  case "$arg" in
                    --output-json=*)
                      output_json="${arg#--output-json=}"
                      ;;
                  esac
                done

                if [[ -z "$output_json" ]]; then
                  echo "missing output json" >&2
                  exit 1
                fi

                mkdir -p "$(dirname "$output_json")"
                cat > "$output_json" <<'JSON'
                {"checks":[{"name":"dashboard_export_pdf","status":"pass"}]}
                JSON
                exit 0
                BASH
                ,
            );

            $this->writeStub(
                $workspace . '/bin/curl',
                <<<'BASH'
                #!/usr/bin/env bash
                set -euo pipefail
                exit 0
                BASH
                ,
            );

            $result = $this->runCommand(['bash', 'scripts/ops/kuma_push_pdf_export.sh'], $this->repoRoot(), [
                'PATH' => $workspace . '/bin:' . (getenv('PATH') ?: ''),
                'KUMA_PUSH_ENV_FILE' => $envFile,
                'PHP_GATE_ARGS_FILE' => $phpGateArgsFile,
                'PHP_SUMMARY_ARGS_FILE' => $phpSummaryArgsFile,
            ]);

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertFileExists($reportPath);
            self::assertStringContainsString('OK dashboard_pdf_gate=all_checks_passed', $result['stdout']);

            $phpArgs = file_get_contents($phpGateArgsFile);
            self::assertIsString($phpArgs);
            self::assertStringContainsString(
                $repoOverride . '/scripts/release-gate/dashboard_release_gate.php',
                $phpArgs,
            );
            self::assertStringContainsString('--base-url=https://appointments.example.test', $phpArgs);
            self::assertStringContainsString('--index-page=app.php', $phpArgs);
            self::assertStringContainsString('--pdf-health-url=https://renderer.example.test/healthz', $phpArgs);
            self::assertStringContainsString('--http-timeout=19', $phpArgs);
            self::assertStringContainsString('--export-timeout=71', $phpArgs);
            self::assertStringContainsString('--max-pdf-duration-ms=12345', $phpArgs);
            self::assertStringContainsString('--require-nonempty-metrics=1', $phpArgs);
            self::assertStringContainsString('--username=monitor-user', $phpArgs);
            self::assertStringContainsString('--password=monitor-pass', $phpArgs);
            self::assertStringContainsString('--output-json=' . $reportPath, $phpArgs);

            $summaryArgs = file_get_contents($phpSummaryArgsFile);
            self::assertIsString($summaryArgs);
            self::assertStringContainsString('-r', $summaryArgs);
            self::assertStringContainsString($reportPath, $summaryArgs);
        } finally {
            $this->removeDirectory($workspace);
        }
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
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private function createWorkspace(): string
    {
        $workspace = sys_get_temp_dir() . '/kuma-push-script-env-' . bin2hex(random_bytes(8));
        mkdir($workspace . '/bin', 0777, true);

        return $workspace;
    }

    private function writeStub(string $path, string $contents): void
    {
        file_put_contents($path, $contents);
        chmod($path, 0755);
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
                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($path);
    }
}
