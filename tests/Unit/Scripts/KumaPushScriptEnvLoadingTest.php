<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class KumaPushScriptEnvLoadingTest extends TestCase
{
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
            $releaseGateRepoRootFile = $workspace . '/release-gate-repo-root.txt';
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
                printf '%s\n' "${RELEASE_GATE_REPO_ROOT:-}" > "$RELEASE_GATE_REPO_ROOT_FILE"

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
                'RELEASE_GATE_REPO_ROOT_FILE' => $releaseGateRepoRootFile,
            ]);

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertFileExists($reportPath);
            $reportPath = realpath($reportPath);
            self::assertIsString($reportPath);
            self::assertStringContainsString('OK dashboard_pdf_gate=all_checks_passed', $result['stdout']);

            $phpArgs = file_get_contents($phpGateArgsFile);
            self::assertIsString($phpArgs);
            self::assertStringContainsString(
                $this->repoRoot() . '/scripts/release-gate/dashboard_release_gate.php',
                $phpArgs,
            );
            self::assertSame($repoOverride . PHP_EOL, file_get_contents($releaseGateRepoRootFile));
            self::assertStringContainsString('--base-url=https://appointments.example.test', $phpArgs);
            self::assertStringContainsString('--index-page=app.php', $phpArgs);
            self::assertSame(1, preg_match('/--start-date=(\d{4}-\d{2}-\d{2})/', $phpArgs, $startMatch));
            self::assertSame(1, preg_match('/--end-date=(\d{4}-\d{2}-\d{2})/', $phpArgs, $endMatch));
            $start = new \DateTimeImmutable($startMatch[1]);
            $end = new \DateTimeImmutable($endMatch[1]);
            self::assertSame('1', $start->format('N'));
            self::assertSame('5', $end->format('N'));
            self::assertSame(4, $start->diff($end)->days);

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

    public function testPdfExportRejectsLinkedReportWithoutTouchingItsTarget(): void
    {
        foreach (['symlink', 'hardlink'] as $kind) {
            $workspace = $this->createWorkspace();
            try {
                $outputDir = $workspace . '/private-output';
                mkdir($outputDir, 0700);
                $target = $workspace . '/protected-target';
                file_put_contents($target, 'keep this target');
                chmod($target, 0600);
                $report = $outputDir . '/kuma-pdf-export-latest.json';
                if ($kind === 'symlink') {
                    symlink($target, $report);
                } else {
                    link($target, $report);
                }
                $marker = $workspace . '/child-started';
                file_put_contents($workspace . '/fixture.env', '');
                $this->writeStub($workspace . '/bin/php', "#!/bin/sh\ntouch \"\$CHILD_MARKER\"\n");
                $this->writeStub($workspace . '/bin/curl', "#!/bin/sh\ntouch \"\$CHILD_MARKER\"\n");
                $result = $this->runCommand(['bash', 'scripts/ops/kuma_push_pdf_export.sh'], $this->repoRoot(), [
                    'PATH' => $workspace . '/bin:' . getenv('PATH'),
                    'KUMA_PUSH_ENV_FILE' => $workspace . '/fixture.env',
                    'KUMA_PDF_EXPORT_CREDENTIALS_FILE' => $workspace . '/absent-credentials.env',
                    'KUMA_PUSH_URL_PDF_EXPORT' => 'https://fixture.invalid/push',
                    'KUMA_PDF_EXPORT_USERNAME' => 'fixture',
                    'KUMA_PDF_EXPORT_PASSWORD' => 'synthetic-password',
                    'KUMA_PDF_EXPORT_OUTPUT_DIR' => $outputDir,
                    'CHILD_MARKER' => $marker,
                ]);
                self::assertNotSame(0, $result['exit_code'], $kind);
                self::assertStringContainsString('Unsafe private file', $result['stderr']);
                self::assertFileDoesNotExist($marker);
                self::assertSame('keep this target', file_get_contents($target));
                self::assertSame(0600, fileperms($target) & 0777);
                self::assertFileExists($report);
            } finally {
                $this->removeDirectory($workspace);
            }
        }
    }

    public function testOpsJobsMonitorReportsRestoreVerifyFreshness(): void
    {
        $workspace = $this->createWorkspace();

        try {
            $envFile = $workspace . '/ops-jobs.env';
            $markerFile = $workspace . '/last_verify_success.utc';
            $curlArgsFile = $workspace . '/curl-args.txt';

            file_put_contents($markerFile, gmdate('c'));
            file_put_contents(
                $envFile,
                implode(PHP_EOL, [
                    'KUMA_PUSH_URL_OPS_JOBS=https://kuma.example/ops-jobs',
                    'KUMA_OPS_JOBS_VERIFY_FILE=' . $markerFile,
                    'KUMA_OPS_JOBS_MAX_VERIFY_AGE_MINUTES=60',
                    '',
                ]),
            );

            $this->writeStub(
                $workspace . '/bin/curl',
                <<<'BASH'
                #!/usr/bin/env bash
                set -euo pipefail
                printf '%s\n' "$*" > "$CURL_ARGS_FILE"
                exit 0
                BASH
                ,
            );

            $result = $this->runCommand(['bash', 'scripts/ops/kuma_push_ops_jobs.sh'], $this->repoRoot(), [
                'PATH' => $workspace . '/bin:' . (getenv('PATH') ?: ''),
                'KUMA_PUSH_ENV_FILE' => $envFile,
                'CURL_ARGS_FILE' => $curlArgsFile,
            ]);

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertMatchesRegularExpression('/OK restore_verify_age_minutes=\d+ max=60/', $result['stdout']);

            $curlArgs = file_get_contents($curlArgsFile);
            self::assertIsString($curlArgs);
            self::assertStringContainsString('https://kuma.example/ops-jobs', $curlArgs);
            self::assertStringContainsString('msg=OK restore_verify_age_minutes=', $curlArgs);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testBackupCreationMonitorReportsMarkerFreshness(): void
    {
        $workspace = $this->createWorkspace();

        try {
            $envFile = $workspace . '/backup-creation.env';
            $markerFile = $workspace . '/last_backup_success.utc';
            $curlArgsFile = $workspace . '/curl-args.txt';

            file_put_contents($markerFile, gmdate('c'));
            file_put_contents(
                $envFile,
                implode(PHP_EOL, [
                    'KUMA_PUSH_URL_BACKUP_CREATION=https://kuma.example/backup-creation',
                    'KUMA_BACKUP_CREATION_MARKER_FILE=' . $markerFile,
                    'KUMA_BACKUP_CREATION_MAX_AGE_MINUTES=60',
                    '',
                ]),
            );

            $this->writeStub(
                $workspace . '/bin/curl',
                <<<'BASH'
                #!/usr/bin/env bash
                set -euo pipefail
                printf '%s\n' "$*" > "$CURL_ARGS_FILE"
                exit 0
                BASH
                ,
            );

            $result = $this->runCommand(['bash', 'scripts/ops/kuma_push_backup_creation.sh'], $this->repoRoot(), [
                'PATH' => $workspace . '/bin:' . (getenv('PATH') ?: ''),
                'KUMA_PUSH_ENV_FILE' => $envFile,
                'CURL_ARGS_FILE' => $curlArgsFile,
            ]);

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertMatchesRegularExpression('/OK backup_age_minutes=\d+ max=60/', $result['stdout']);

            $curlArgs = file_get_contents($curlArgsFile);
            self::assertIsString($curlArgs);
            self::assertStringContainsString('https://kuma.example/backup-creation', $curlArgs);
            self::assertStringContainsString('msg=OK backup_age_minutes=', $curlArgs);
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
