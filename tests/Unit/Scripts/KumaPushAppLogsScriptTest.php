<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

class KumaPushAppLogsScriptTest extends TestCase
{
    public function testCopiedLogPrefixResumesSafelyAndReplacementsAreReprocessed(): void
    {
        $result = $this->runCommand(
            [
                'bash',
                '-c',
                <<<'BASH'
                set -Eeuo pipefail
                fixture="$(mktemp -d)"
                trap 'rm -rf "$fixture"' EXIT
                mkdir -p "$fixture/bin" "$fixture/state"
                chmod 700 "$fixture/state"
                printf '# synthetic environment\n' > "$fixture/env"
                cat > "$fixture/bin/curl" <<'CURL'
                #!/usr/bin/env bash
                exit 0
                CURL
                chmod 755 "$fixture/bin/curl"
                export PATH="$fixture/bin:$PATH"
                export KUMA_PUSH_ENV_FILE="$fixture/env"
                export KUMA_PUSH_URL_APP_LOGS='https://kuma.example/app'
                export KUMA_APP_LOG_FILE="$fixture/log.php"
                export KUMA_PUSH_STATE_DIR="$fixture/state"

                printf 'ERROR - old copied failure\n' > "$KUMA_APP_LOG_FILE"
                bash scripts/ops/kuma_push_app_logs.sh
                cat > "$fixture/bin/head" <<'HEAD'
                #!/usr/bin/env bash
                exit 99
                HEAD
                chmod 755 "$fixture/bin/head"
                output="$(bash scripts/ops/kuma_push_app_logs.sh)"
                [[ "$output" == *'OK new_app_errors=0'* ]]
                rm "$fixture/bin/head"
                mv "$KUMA_APP_LOG_FILE" "$fixture/old.php"
                cp "$fixture/old.php" "$fixture/replacement.php"
                printf 'ERROR - copied-file failure\n' >> "$fixture/replacement.php"
                mv "$fixture/replacement.php" "$KUMA_APP_LOG_FILE"
                output="$(bash scripts/ops/kuma_push_app_logs.sh)"
                [[ "$output" == *'CRIT new_app_errors=1'* ]]

                rm -rf "$fixture/state"
                mkdir "$fixture/state"
                chmod 700 "$fixture/state"
                printf 'ERROR - old identical failure\n' > "$KUMA_APP_LOG_FILE"
                bash scripts/ops/kuma_push_app_logs.sh
                mv "$KUMA_APP_LOG_FILE" "$fixture/identical-old.php"
                cp "$fixture/identical-old.php" "$KUMA_APP_LOG_FILE"
                output="$(bash scripts/ops/kuma_push_app_logs.sh)"
                [[ "$output" == *'OK new_app_errors=0'* ]]

                rm -rf "$fixture/state"
                mkdir "$fixture/state"
                chmod 700 "$fixture/state"
                printf 'ERROR - old!\n' > "$KUMA_APP_LOG_FILE"
                output="$(bash scripts/ops/kuma_push_app_logs.sh)"
                [[ "$output" == *'OK primed app log monitor'* ]]
                IFS='|' read -r _ legacy_inode legacy_size < "$KUMA_PUSH_STATE_DIR/app-logs.state"
                printf '%s|%s|%s\n' "$KUMA_APP_LOG_FILE" "$legacy_inode" "$legacy_size" > "$KUMA_PUSH_STATE_DIR/app-logs.state"
                output="$(bash scripts/ops/kuma_push_app_logs.sh)"
                [[ "$output" == *'OK new_app_errors=0'* ]]
                printf '%s|%s|%s\n' "$KUMA_APP_LOG_FILE" "$legacy_inode" "$legacy_size" > "$KUMA_PUSH_STATE_DIR/app-logs.state"
                mv "$KUMA_APP_LOG_FILE" "$fixture/old-same-size.php"
                printf 'ERROR - new!\n' > "$KUMA_APP_LOG_FILE"
                output="$(bash scripts/ops/kuma_push_app_logs.sh)"
                [[ "$output" == *'CRIT new_app_errors=1'* ]]

                rm -rf "$fixture/state"
                mkdir "$fixture/state"
                chmod 700 "$fixture/state"
                printf 'long prefix before truncation\n' > "$KUMA_APP_LOG_FILE"
                bash scripts/ops/kuma_push_app_logs.sh
                printf 'ERROR - after truncation\n' > "$KUMA_APP_LOG_FILE"
                output="$(bash scripts/ops/kuma_push_app_logs.sh)"
                [[ "$output" == *'CRIT new_app_errors=1'* ]]
                : > "$KUMA_PUSH_STATE_DIR/app-logs.state"
                output="$(bash scripts/ops/kuma_push_app_logs.sh)"
                [[ "$output" == *'CRIT new_app_errors=1'* ]]
                BASH
                ,
                'bash',
            ],
            $this->repoRoot(),
        );

        self::assertSame(0, $result['exit_code'], $result['stdout'] . $result['stderr']);
    }

    public function testFailedPushDoesNotAdvanceCursorAndRetriesTheSameError(): void
    {
        $result = $this->runCommand(
            [
                'bash',
                '-c',
                <<<'BASH'
                set -Eeuo pipefail
                fixture="$(mktemp -d)"
                trap 'rm -rf "$fixture"' EXIT
                mkdir -p "$fixture/bin" "$fixture/state"
                chmod 700 "$fixture/state"
                printf '# synthetic environment\n' > "$fixture/env"
                cat > "$fixture/bin/curl" <<'CURL'
                #!/usr/bin/env bash
                for entry in "$KUMA_PUSH_STATE_DIR"/delta.*/*; do
                  [[ -f "$entry" ]] || continue
                  (( $(wc -c < "$entry") <= 512 )) || exit 98
                done
                [[ "${FAIL_PUSH:-0}" == 1 ]] && exit 1
                exit 0
                CURL
                chmod 755 "$fixture/bin/curl"
                export PATH="$fixture/bin:$PATH"
                export KUMA_PUSH_ENV_FILE="$fixture/env"
                export KUMA_PUSH_URL_APP_LOGS='https://kuma.example/app'
                export KUMA_APP_LOG_FILE="$fixture/log.php"
                export KUMA_PUSH_STATE_DIR="$fixture/state"
                printf '%4096s\n' '' > "$KUMA_APP_LOG_FILE"
                bash scripts/ops/kuma_push_app_logs.sh
                cp "$KUMA_PUSH_STATE_DIR/app-logs.state" "$fixture/state-before"
                printf 'ERROR - retry this failure\n' >> "$KUMA_APP_LOG_FILE"
                export FAIL_PUSH=1
                ! bash scripts/ops/kuma_push_app_logs.sh
                cmp -s "$fixture/state-before" "$KUMA_PUSH_STATE_DIR/app-logs.state"
                unset FAIL_PUSH
                cat > "$fixture/bin/sha256sum" <<'HASH'
                #!/usr/bin/env bash
                cat >/dev/null
                exit 71
                HASH
                chmod 755 "$fixture/bin/sha256sum"
                if output="$(bash scripts/ops/kuma_push_app_logs.sh 2>&1)"; then
                  exit 1
                fi
                [[ "$output" == *'App log read was incomplete'* ]]
                cmp -s "$fixture/state-before" "$KUMA_PUSH_STATE_DIR/app-logs.state"
                rm "$fixture/bin/sha256sum"
                output="$(bash scripts/ops/kuma_push_app_logs.sh)"
                [[ "$output" == *'CRIT new_app_errors=1'* ]]
                output="$(bash scripts/ops/kuma_push_app_logs.sh)"
                [[ "$output" == *'OK new_app_errors=0'* ]]
                BASH
                ,
                'bash',
            ],
            $this->repoRoot(),
        );

        self::assertSame(0, $result['exit_code'], $result['stdout'] . $result['stderr']);
    }

    public function testPrivateDirectoryPreparationAcceptsOnlyARecreatedSafeRaceWinner(): void
    {
        $result = $this->runCommand(
            [
                'bash',
                '-c',
                <<<'BASH'
                set -Eeuo pipefail
                source scripts/ops/lib/kuma_push_common.sh
                fixture="$(mktemp -d)"
                trap 'rm -rf "$fixture"' EXIT
                chmod 755 "$fixture"
                expected_fixture="$(cd -P "$fixture" && pwd -P)"
                mode_of() { if stat -c '%a' "$1" >/dev/null 2>&1; then stat -c '%a' "$1"; else stat -f '%Lp' "$1"; fi; }

                mkdir() { command mkdir "$@"; return 1; }
                safe="$(kuma_push_prepare_private_directory "$fixture/safe")"
                [[ "$safe" == "$expected_fixture/safe" ]]
                [[ "$(mode_of "$safe")" == 700 ]]
                rm -rf "$safe"

                mkdir() { command mkdir "$@"; target="${!#}"; chmod 0777 "$target"; return 1; }
                if kuma_push_prepare_private_directory "$fixture/unsafe"; then
                  exit 1
                fi
                [[ "$(mode_of "$fixture/unsafe")" == 777 ]]
                BASH
                ,
                'bash',
            ],
            $this->repoRoot(),
        );

        self::assertSame(0, $result['exit_code'], $result['stdout'] . $result['stderr']);
    }

    public function testAppLogMonitorRejectsStateDirectorySymlinkWithoutTouchingTarget(): void
    {
        $workspace = sys_get_temp_dir() . '/kuma-push-private-' . bin2hex(random_bytes(8));
        $stubBin = $workspace . '/bin';
        $appRoot = $workspace . '/app-root';
        $stateDir = $workspace . '/state';
        $stateTarget = $workspace . '/state-target';
        $envFile = $workspace . '/uptime-kuma-push.env';
        mkdir($stubBin, 0700, true);
        mkdir(dirname($appRoot . '/storage/logs'), 0700, true);
        mkdir($stateTarget, 0700, true);
        symlink($stateTarget, $stateDir);
        $this->writeEnvFile($envFile, $appRoot);

        try {
            $result = $this->runCommand(
                ['bash', 'scripts/ops/kuma_push_app_logs.sh'],
                $this->repoRoot(),
                $this->commandEnv($envFile, $stateDir, $stubBin),
            );

            self::assertNotSame(0, $result['exit_code']);
            self::assertStringContainsString('Unsafe private directory', $result['stderr']);
            self::assertFileDoesNotExist($stateTarget . '/app-logs.state');
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testAppLogMonitorRejectsStateHardlinkWithoutTouchingOutsideTarget(): void
    {
        $workspace = sys_get_temp_dir() . '/kuma-push-private-' . bin2hex(random_bytes(8));
        $stubBin = $workspace . '/bin';
        $appRoot = $workspace . '/app-root';
        $stateDir = $workspace . '/state';
        $outside = $workspace . '/outside-state';
        $envFile = $workspace . '/uptime-kuma-push.env';
        mkdir($stubBin, 0700, true);
        mkdir(dirname($appRoot . '/storage/logs'), 0700, true);
        mkdir($stateDir, 0700, true);
        file_put_contents($outside, "outside-state\n");
        chmod($outside, 0600);
        link($outside, $stateDir . '/app-logs.state');
        $this->writeEnvFile($envFile, $appRoot);

        try {
            $result = $this->runCommand(
                ['bash', 'scripts/ops/kuma_push_app_logs.sh'],
                $this->repoRoot(),
                $this->commandEnv($envFile, $stateDir, $stubBin),
            );

            self::assertNotSame(0, $result['exit_code']);
            self::assertStringContainsString('Unsafe private file', $result['stderr']);
            self::assertSame("outside-state\n", file_get_contents($outside));
            self::assertSame(0600, fileperms($outside) & 0777);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testAppLogMonitorLoadsEnvOverridesBeforeResolvingDefaults(): void
    {
        $workspace = sys_get_temp_dir() . '/kuma-push-app-logs-' . bin2hex(random_bytes(8));
        $stubBin = $workspace . '/bin';
        $appRoot = $workspace . '/app-root';
        $stateDir = $workspace . '/state';
        $capturePath = $workspace . '/curl-args.log';
        $today = gmdate('Y-m-d');
        $logFile = $appRoot . '/storage/logs/log-' . $today . '.php';
        $envFile = $workspace . '/uptime-kuma-push.env';

        mkdir($stubBin, 0777, true);
        mkdir(dirname($logFile), 0777, true);
        mkdir($stateDir, 0700, true);

        try {
            file_put_contents(
                $stubBin . '/curl',
                "#!/usr/bin/env bash\nset -euo pipefail\nprintf '%s\n' \"\$@\" >> " .
                    escapeshellarg($capturePath) .
                    "\n",
            );
            chmod($stubBin . '/curl', 0755);

            file_put_contents(
                $envFile,
                'KUMA_PUSH_URL_APP_LOGS=' .
                    escapeshellarg('https://kuma.example/app-logs') .
                    "\n" .
                    'KUMA_APP_ROOT=' .
                    escapeshellarg($appRoot) .
                    "\n" .
                    'KUMA_APP_LOG_IGNORE_REGEX=' .
                    escapeshellarg('ignored-host-noise') .
                    "\n",
            );

            file_put_contents($logFile, '');

            $primeResult = $this->runCommand(
                ['bash', 'scripts/ops/kuma_push_app_logs.sh'],
                $this->repoRoot(),
                $this->commandEnv($envFile, $stateDir, $stubBin),
            );

            self::assertSame(0, $primeResult['exit_code'], $primeResult['stderr']);
            self::assertStringContainsString('OK primed app log monitor', $primeResult['stdout']);

            file_put_contents($logFile, "[error] ERROR - ignored-host-noise\n", FILE_APPEND);

            $result = $this->runCommand(
                ['bash', 'scripts/ops/kuma_push_app_logs.sh'],
                $this->repoRoot(),
                $this->commandEnv($envFile, $stateDir, $stubBin),
            );

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('OK new_app_errors=0', $result['stdout']);
            $curlCalls = $this->readFile($capturePath);
            self::assertStringContainsString('status=up', $curlCalls);
            self::assertStringContainsString('msg=OK primed app log monitor', $curlCalls);
            self::assertStringContainsString('msg=OK new_app_errors=0', $curlCalls);
            self::assertStringContainsString('ping=1', $curlCalls);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testCustomIgnoreRegexPreservesBackreferencesAndRejectsMalformedRegex(): void
    {
        $workspace = sys_get_temp_dir() . '/kuma-push-app-logs-regex-' . bin2hex(random_bytes(8));
        $stubBin = $workspace . '/bin';
        $appRoot = $workspace . '/app-root';
        $stateDir = $workspace . '/state';
        $capturePath = $workspace . '/curl-args.log';
        $today = gmdate('Y-m-d');
        $logFile = $appRoot . '/storage/logs/log-' . $today . '.php';
        $envFile = $workspace . '/uptime-kuma-push.env';

        mkdir($stubBin, 0777, true);
        mkdir(dirname($logFile), 0777, true);
        mkdir($stateDir, 0700, true);

        try {
            $this->writeCurlStub($stubBin, $capturePath);
            $this->writeEnvFile($envFile, $appRoot);
            file_put_contents(
                $envFile,
                'KUMA_APP_LOG_IGNORE_REGEX=' . escapeshellarg('ERROR - .*host=([[:alnum:]]+) \1') . "\n",
                FILE_APPEND,
            );
            file_put_contents($logFile, '');

            $primeResult = $this->runCommand(
                ['bash', 'scripts/ops/kuma_push_app_logs.sh'],
                $this->repoRoot(),
                $this->commandEnv($envFile, $stateDir, $stubBin),
            );
            self::assertSame(0, $primeResult['exit_code'], $primeResult['stderr']);

            file_put_contents(
                $logFile,
                implode("\n", [
                    'ERROR - 2026-10-06 08:00:00 --> host=abc abc',
                    'ERROR - 2026-10-06 08:00:01 --> host=abc real alarm',
                    '',
                ]),
                FILE_APPEND,
            );

            $result = $this->runCommand(
                ['bash', 'scripts/ops/kuma_push_app_logs.sh'],
                $this->repoRoot(),
                $this->commandEnv($envFile, $stateDir, $stubBin),
            );

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('CRIT new_app_errors=1', $result['stdout']);
            self::assertStringContainsString(
                'latest=ERROR - 2026-10-06 08:00:01 --> host=abc real alarm',
                $this->readFile($capturePath),
            );

            file_put_contents($envFile, 'KUMA_APP_LOG_IGNORE_REGEX=' . escapeshellarg('[') . "\n", FILE_APPEND);
            file_put_contents($logFile, "ERROR - 2026-10-06 08:00:02 --> host=abc malformed regex\n", FILE_APPEND);
            $malformedResult = $this->runCommand(
                ['bash', 'scripts/ops/kuma_push_app_logs.sh'],
                $this->repoRoot(),
                $this->commandEnv($envFile, $stateDir, $stubBin),
            );

            self::assertNotSame(0, $malformedResult['exit_code']);
            self::assertStringContainsString('Invalid app log ignore regex', $malformedResult['stderr']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testAppLogMonitorIgnoresBuiltInScannerAndProxyNoise(): void
    {
        $workspace = sys_get_temp_dir() . '/kuma-push-app-logs-' . bin2hex(random_bytes(8));
        $stubBin = $workspace . '/bin';
        $appRoot = $workspace . '/app-root';
        $stateDir = $workspace . '/state';
        $capturePath = $workspace . '/curl-args.log';
        $today = gmdate('Y-m-d');
        $logFile = $appRoot . '/storage/logs/log-' . $today . '.php';
        $envFile = $workspace . '/uptime-kuma-push.env';

        mkdir($stubBin, 0777, true);
        mkdir(dirname($logFile), 0777, true);
        mkdir($stateDir, 0700, true);

        try {
            $this->writeCurlStub($stubBin, $capturePath);
            $this->writeEnvFile($envFile, $appRoot);
            file_put_contents($logFile, '');

            $primeResult = $this->runCommand(
                ['bash', 'scripts/ops/kuma_push_app_logs.sh'],
                $this->repoRoot(),
                $this->commandEnv($envFile, $stateDir, $stubBin),
            );

            self::assertSame(0, $primeResult['exit_code'], $primeResult['stderr']);

            file_put_contents(
                $logFile,
                implode("\n", [
                    'ERROR - 2026-05-19 11:31:14 --> 404 Page Not Found: Azenvnet/index',
                    'ERROR - 2026-06-03 06:42:22 --> 404 Page Not Found: Wwwgooglecom/index Trace: array (',
                    'ERROR - 2026-06-03 09:27:14 --> 404 Page Not Found: 127001:80/index Trace: array (',
                    'ERROR - 2026-06-15 08:57:35 --> 404 Page Not Found: 1465618042:3333/index Trace: array (',
                    'ERROR - 2026-06-05 12:53:43 --> 404 Page Not Found: Index%2ephp/index Trace: array (',
                    'ERROR - 2026-05-20 06:45:10 --> Severity: Warning --> unlink(/var/www/html/easyappointments/storage/cache/rate_limit_key_203.0.113.10): No such file or directory /var/www/html/easyappointments/system/libraries/Cache/drivers/Cache_file.php 279',
                    '',
                ]),
                FILE_APPEND,
            );

            $result = $this->runCommand(
                ['bash', 'scripts/ops/kuma_push_app_logs.sh'],
                $this->repoRoot(),
                $this->commandEnv($envFile, $stateDir, $stubBin),
            );

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('OK new_app_errors=0', $result['stdout']);
            self::assertStringContainsString('msg=OK new_app_errors=0', $this->readFile($capturePath));
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testAppLogMonitorStillAlertsForRealNewAppErrors(): void
    {
        $workspace = sys_get_temp_dir() . '/kuma-push-app-logs-' . bin2hex(random_bytes(8));
        $stubBin = $workspace . '/bin';
        $appRoot = $workspace . '/app-root';
        $stateDir = $workspace . '/state';
        $capturePath = $workspace . '/curl-args.log';
        $today = gmdate('Y-m-d');
        $logFile = $appRoot . '/storage/logs/log-' . $today . '.php';
        $envFile = $workspace . '/uptime-kuma-push.env';

        mkdir($stubBin, 0777, true);
        mkdir(dirname($logFile), 0777, true);
        mkdir($stateDir, 0700, true);

        try {
            $this->writeCurlStub($stubBin, $capturePath);
            $this->writeEnvFile($envFile, $appRoot);
            file_put_contents($logFile, '');

            $primeResult = $this->runCommand(
                ['bash', 'scripts/ops/kuma_push_app_logs.sh'],
                $this->repoRoot(),
                $this->commandEnv($envFile, $stateDir, $stubBin),
            );

            self::assertSame(0, $primeResult['exit_code'], $primeResult['stderr']);

            file_put_contents(
                $logFile,
                "ERROR - 2026-05-20 08:00:00 --> Severity: Warning --> unexpected app failure\n",
                FILE_APPEND,
            );

            $result = $this->runCommand(
                ['bash', 'scripts/ops/kuma_push_app_logs.sh'],
                $this->repoRoot(),
                $this->commandEnv($envFile, $stateDir, $stubBin),
            );

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('CRIT new_app_errors=1', $result['stdout']);
            self::assertStringContainsString('status=down', $this->readFile($capturePath));
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testAppLogMonitorStillAlertsForUnknownNumeric404Routes(): void
    {
        $workspace = sys_get_temp_dir() . '/kuma-push-app-logs-' . bin2hex(random_bytes(8));
        $stubBin = $workspace . '/bin';
        $appRoot = $workspace . '/app-root';
        $stateDir = $workspace . '/state';
        $capturePath = $workspace . '/curl-args.log';
        $today = gmdate('Y-m-d');
        $logFile = $appRoot . '/storage/logs/log-' . $today . '.php';
        $envFile = $workspace . '/uptime-kuma-push.env';

        mkdir($stubBin, 0777, true);
        mkdir(dirname($logFile), 0777, true);
        mkdir($stateDir, 0700, true);

        try {
            $this->writeCurlStub($stubBin, $capturePath);
            $this->writeEnvFile($envFile, $appRoot);
            file_put_contents($logFile, '');

            $primeResult = $this->runCommand(
                ['bash', 'scripts/ops/kuma_push_app_logs.sh'],
                $this->repoRoot(),
                $this->commandEnv($envFile, $stateDir, $stubBin),
            );

            self::assertSame(0, $primeResult['exit_code'], $primeResult['stderr']);

            file_put_contents(
                $logFile,
                "ERROR - 2026-06-15 09:00:00 --> 404 Page Not Found: 1465618042:3333/book Trace: array (\n",
                FILE_APPEND,
            );

            $result = $this->runCommand(
                ['bash', 'scripts/ops/kuma_push_app_logs.sh'],
                $this->repoRoot(),
                $this->commandEnv($envFile, $stateDir, $stubBin),
            );

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('CRIT new_app_errors=1', $result['stdout']);
            self::assertStringContainsString('status=down', $this->readFile($capturePath));
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testAppLogMonitorIgnoresOnlyExactObservedInstallationAndMnavercom404Routes(): void
    {
        $workspace = sys_get_temp_dir() . '/kuma-push-app-logs-' . bin2hex(random_bytes(8));
        $stubBin = $workspace . '/bin';
        $appRoot = $workspace . '/app-root';
        $stateDir = $workspace . '/state';
        $capturePath = $workspace . '/curl-args.log';
        $today = gmdate('Y-m-d');
        $logFile = $appRoot . '/storage/logs/log-' . $today . '.php';
        $envFile = $workspace . '/uptime-kuma-push.env';

        mkdir($stubBin, 0777, true);
        mkdir(dirname($logFile), 0777, true);
        mkdir($stateDir, 0700, true);

        try {
            $this->writeCurlStub($stubBin, $capturePath);
            $this->writeEnvFile($envFile, $appRoot);
            file_put_contents($logFile, '');

            $primeResult = $this->runCommand(
                ['bash', 'scripts/ops/kuma_push_app_logs.sh'],
                $this->repoRoot(),
                $this->commandEnv($envFile, $stateDir, $stubBin),
            );

            self::assertSame(0, $primeResult['exit_code'], $primeResult['stderr']);

            file_put_contents(
                $logFile,
                implode("\n", [
                    'ERROR - 2026-10-05 08:00:00 --> 404 Page Not Found: Installation/index',
                    'ERROR - 2026-10-05 08:00:01 --> 404 Page Not Found: Mnavercom/index Trace: array (',
                    'ERROR - 2026-10-05 08:00:02 --> 404 Page Not Found: Installation/index/extra',
                    'ERROR - 2026-10-05 08:00:03 --> 404 Page Not Found: Mnavercom/index/extra',
                    'ERROR - 2026-10-05 08:00:04 --> 404 Page Not Found: Google/get_google_calendars',
                    'ERROR - 2026-10-05 08:00:05 --> Severity: Warning --> real app warning',
                    '',
                ]),
                FILE_APPEND,
            );

            $result = $this->runCommand(
                ['bash', 'scripts/ops/kuma_push_app_logs.sh'],
                $this->repoRoot(),
                $this->commandEnv($envFile, $stateDir, $stubBin),
            );

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('CRIT new_app_errors=4', $result['stdout']);
            $curlCalls = $this->readFile($capturePath);
            self::assertStringContainsString('status=down', $curlCalls);
            self::assertStringContainsString(
                'latest=ERROR - 2026-10-05 08:00:05 --> Severity: Warning --> real app warning',
                $curlCalls,
            );
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testEntrypointKeepsExact404CompatibilityWithHistoricalClassifier(): void
    {
        $workspace = sys_get_temp_dir() . '/kuma-push-app-logs-old-classifier-' . bin2hex(random_bytes(8));
        $stubBin = $workspace . '/bin';
        $runtime = $workspace . '/runtime';
        $appRoot = $workspace . '/app-root';
        $stateDir = $workspace . '/state';
        $capturePath = $workspace . '/curl-args.log';
        $today = gmdate('Y-m-d');
        $logFile = $appRoot . '/storage/logs/log-' . $today . '.php';
        $envFile = $workspace . '/uptime-kuma-push.env';

        mkdir($stubBin, 0777, true);
        mkdir($runtime . '/lib', 0777, true);
        mkdir(dirname($logFile), 0777, true);
        mkdir($stateDir, 0700, true);

        try {
            $this->writeCurlStub($stubBin, $capturePath);
            $this->writeEnvFile($envFile, $appRoot);
            copy($this->repoRoot() . '/scripts/ops/kuma_push_app_logs.sh', $runtime . '/kuma_push_app_logs.sh');
            copy($this->repoRoot() . '/scripts/ops/lib/kuma_push_common.sh', $runtime . '/lib/kuma_push_common.sh');
            $historicalClassifierPath =
                $this->repoRoot() . '/tests/Fixtures/kuma_push_app_log_classification_b8dd554f.sh';
            self::assertSame(
                'b8dd554faa7a0468f98eefccfb3432a498ea284a1ac1de1a4e84227f4c782623',
                hash_file('sha256', $historicalClassifierPath),
            );
            copy($historicalClassifierPath, $runtime . '/lib/app_log_classification.sh');
            chmod($runtime . '/kuma_push_app_logs.sh', 0755);

            file_put_contents($logFile, '');
            $env = array_merge($this->commandEnv($envFile, $stateDir, $stubBin), ['KUMA_APP_LOG_FILE' => $logFile]);
            $primeResult = $this->runCommand(['bash', $runtime . '/kuma_push_app_logs.sh'], $this->repoRoot(), $env);
            self::assertSame(0, $primeResult['exit_code'], $primeResult['stderr']);

            file_put_contents(
                $logFile,
                implode("\n", [
                    'ERROR - 2026-10-05 08:00:00 --> 404 Page Not Found: Installation/index',
                    'ERROR - 2026-10-05 08:00:01 --> 404 Page Not Found: Mnavercom/index Trace: array (',
                    'ERROR - 2026-10-05 08:00:02 --> 404 Page Not Found: Installation/index/extra',
                    'ERROR - 2026-10-05 08:00:03 --> 404 Page Not Found: Mnavercom/index/extra',
                    'ERROR - 2026-10-05 08:00:04 --> 404 Page Not Found: Google/get_google_calendars',
                    'ERROR - 2026-10-05 08:00:05 --> Severity: Warning --> real app warning',
                    '',
                ]),
                FILE_APPEND,
            );

            $result = $this->runCommand(['bash', $runtime . '/kuma_push_app_logs.sh'], $this->repoRoot(), $env);
            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('CRIT new_app_errors=4', $result['stdout']);
            self::assertStringContainsString('status=down', $this->readFile($capturePath));
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testHistoricalCompatibilityManifestChangesOnlyEntrypointHash(): void
    {
        $compatPath = $this->repoRoot() . '/scripts/ops/config/kuma_push_runtime_bundle_v1_rob757_compat.json';
        $compatManifest = json_decode((string) file_get_contents($compatPath), true, 8, JSON_THROW_ON_ERROR);
        self::assertCount(11, $compatManifest['files']);
        self::assertSame(
            'ddb47db8c80c24ee929129b5c765be05e16383d0e4a9986638acafdd43f76b54',
            $compatManifest['cron_sha256'],
        );
        self::assertSame(
            [
                [
                    'source' => 'scripts/ops/kuma_push_backup_creation.sh',
                    'install' => 'scripts/ops/kuma_push_backup_creation.sh',
                    'role' => 'entrypoint',
                    'sha256' => '7a8921e24cd7a6f1147f4cc9e12e951bb667c39f8b71c5ce19593b98c0aebb92',
                ],
                [
                    'source' => 'scripts/ops/kuma_push_host_resources.sh',
                    'install' => 'scripts/ops/kuma_push_host_resources.sh',
                    'role' => 'entrypoint',
                    'sha256' => '896b11e648a85b7541332c4dd5f9eec9c4e2f6152f24148dabd212acc29e6663',
                ],
                [
                    'source' => 'scripts/ops/kuma_push_ops_jobs.sh',
                    'install' => 'scripts/ops/kuma_push_ops_jobs.sh',
                    'role' => 'entrypoint',
                    'sha256' => '0f6d7fc4391f553a85c44cfea37571f3e8dc0e9c653ce1581caed0efeed80f3d',
                ],
                [
                    'source' => 'scripts/ops/kuma_push_pdf_export.sh',
                    'install' => 'scripts/ops/kuma_push_pdf_export.sh',
                    'role' => 'entrypoint',
                    'sha256' => '350eb79182c169a2a6b4d8784f86718c7498e95f01416f4678f0fca54df3da63',
                ],
                [
                    'source' => 'scripts/ops/lib/kuma_push_common.sh',
                    'install' => 'scripts/ops/lib/kuma_push_common.sh',
                    'role' => 'shell_library',
                    'sha256' => 'f10665edccf5a28540f3cbf5cd90bb9000b7c052901574ecc68cb0e05bc7ee56',
                ],
                [
                    'source' => 'scripts/ops/lib/app_log_classification.sh',
                    'install' => 'scripts/ops/lib/app_log_classification.sh',
                    'role' => 'shell_library',
                    'sha256' => 'b8dd554faa7a0468f98eefccfb3432a498ea284a1ac1de1a4e84227f4c782623',
                ],
                [
                    'source' => 'scripts/release-gate/dashboard_release_gate.php',
                    'install' => 'scripts/release-gate/dashboard_release_gate.php',
                    'role' => 'pdf_gate',
                    'sha256' => 'df86dd59a2000e3f6c3a1c2f0f4d215773b83c69a94354d5133295a8d600df7a',
                ],
                [
                    'source' => 'scripts/release-gate/lib/GateAssertions.php',
                    'install' => 'scripts/release-gate/lib/GateAssertions.php',
                    'role' => 'pdf_gate_library',
                    'sha256' => 'd162247e0a7f894b78bd53b96097f15d63758a27f892e6545b52fa818dccc633',
                ],
                [
                    'source' => 'scripts/release-gate/lib/GateCliSupport.php',
                    'install' => 'scripts/release-gate/lib/GateCliSupport.php',
                    'role' => 'pdf_gate_library',
                    'sha256' => 'a0169a77fabebafc5743b9665625ef37e6ae78e2fe81726293d9bbe32aed3dd1',
                ],
                [
                    'source' => 'scripts/release-gate/lib/GateHttpClient.php',
                    'install' => 'scripts/release-gate/lib/GateHttpClient.php',
                    'role' => 'pdf_gate_library',
                    'sha256' => 'dafbcd13db438c760378a560d65672bb6fecb284fed46944049b43899da28067',
                ],
            ],
            array_slice($compatManifest['files'], 1),
        );
        self::assertSame('scripts/ops/kuma_push_app_logs.sh', $compatManifest['files'][0]['source']);
        self::assertSame('scripts/ops/kuma_push_app_logs.sh', $compatManifest['files'][0]['install']);
        self::assertSame('entrypoint', $compatManifest['files'][0]['role']);
        self::assertSame(
            hash_file('sha256', $this->repoRoot() . '/scripts/ops/kuma_push_app_logs.sh'),
            $compatManifest['files'][0]['sha256'],
        );
    }

    public function testAppLogClassifierCountsOnlyRealErrorEntryHeads(): void
    {
        $workspace = sys_get_temp_dir() . '/app-log-classifier-' . bin2hex(random_bytes(8));
        $logFile = $workspace . '/log.php';

        mkdir($workspace, 0777, true);

        try {
            file_put_contents(
                $logFile,
                implode("\n", [
                    "<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>",
                    'ERROR - 2026-05-20 08:00:00 --> Severity: Warning --> unexpected app failure',
                    "0 => 'error',",
                    '#1 /var/www/html/easyappointments/system/core/Common.php(607): _error_handler()',
                    'CRITICAL - 2026-05-20 08:01:00 --> renderer unavailable',
                    'Fatal error: Allowed memory size exhausted',
                    'Uncaught RuntimeException: backend calendar failed',
                    'PHP Fatal error: Uncaught RuntimeException: queue failed',
                    '',
                ]),
            );

            $result = $this->runCommand(
                [
                    'bash',
                    '-c',
                    'source scripts/ops/lib/app_log_classification.sh; tmp="$(mktemp)"; app_log_extract_error_like_file "$1" "$tmp"; printf "count=%s\n" "$(app_log_count_error_like_file "$1")"; cat "$tmp"; rm -f "$tmp"',
                    'bash',
                    $logFile,
                ],
                $this->repoRoot(),
            );

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('count=5', $result['stdout']);
            self::assertStringContainsString('unexpected app failure', $result['stdout']);
            self::assertStringContainsString('renderer unavailable', $result['stdout']);
            self::assertStringContainsString('Allowed memory size exhausted', $result['stdout']);
            self::assertStringContainsString('backend calendar failed', $result['stdout']);
            self::assertStringContainsString('queue failed', $result['stdout']);
            self::assertStringNotContainsString("0 => 'error'", $result['stdout']);
            self::assertStringNotContainsString('_error_handler', $result['stdout']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testAppLogClassifierFiltersActionableErrorsSinceTimestamp(): void
    {
        $workspace = sys_get_temp_dir() . '/app-log-classifier-' . bin2hex(random_bytes(8));
        $logFile = $workspace . '/log.php';

        mkdir($workspace, 0777, true);

        try {
            file_put_contents(
                $logFile,
                implode("\n", [
                    "<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>",
                    'ERROR - 2026-06-04 14:45:09 --> Severity: Warning --> deploy-window PDF renderer restart',
                    'ERROR - 2026-06-04 14:51:00 --> 404 Page Not Found: Azenvnet/index',
                    'ERROR - 2026-06-04 14:52:00 --> Severity: Warning --> real post-change app failure',
                    'CRITICAL - 2026-06-04 14:53:00 --> backend calendar unavailable',
                    '',
                ]),
            );

            $result = $this->runCommand(
                [
                    'bash',
                    '-c',
                    'source scripts/ops/lib/app_log_classification.sh; matches="$(mktemp)"; actionable="$(mktemp)"; current="$(mktemp)"; app_log_extract_error_like_file "$1" "$matches"; app_log_filter_actionable_file "$matches" "$actionable"; app_log_filter_since_timestamp_file "$actionable" "$current" "2026-06-04 14:50:00"; printf "count=%s\n" "$(app_log_count_error_like_file "$current")"; cat "$current"; rm -f "$matches" "$actionable" "$current"',
                    'bash',
                    $logFile,
                ],
                $this->repoRoot(),
            );

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('count=2', $result['stdout']);
            self::assertStringContainsString('real post-change app failure', $result['stdout']);
            self::assertStringContainsString('backend calendar unavailable', $result['stdout']);
            self::assertStringNotContainsString('deploy-window PDF renderer restart', $result['stdout']);
            self::assertStringNotContainsString('Azenvnet/index', $result['stdout']);
            self::assertStringNotContainsString('Index%2ephp/index', $result['stdout']);
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

    /**
     * @return array<string, string>
     */
    private function commandEnv(string $envFile, string $stateDir, string $stubBin): array
    {
        return [
            'KUMA_PUSH_ENV_FILE' => $envFile,
            'KUMA_PUSH_STATE_DIR' => $stateDir,
            'PATH' => $stubBin . PATH_SEPARATOR . (getenv('PATH') ?: ''),
            'TZ' => 'UTC',
        ];
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private function readFile(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }

    private function writeCurlStub(string $stubBin, string $capturePath): void
    {
        file_put_contents(
            $stubBin . '/curl',
            "#!/usr/bin/env bash\nset -euo pipefail\nprintf '%s\n' \"\$@\" >> " . escapeshellarg($capturePath) . "\n",
        );
        chmod($stubBin . '/curl', 0755);
    }

    private function writeEnvFile(string $envFile, string $appRoot): void
    {
        file_put_contents(
            $envFile,
            'KUMA_PUSH_URL_APP_LOGS=' .
                escapeshellarg('https://kuma.example/app-logs') .
                "\n" .
                'KUMA_APP_ROOT=' .
                escapeshellarg($appRoot) .
                "\n",
        );
    }

    private function removeDirectory(string $path): void
    {
        if (is_link($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isLink()) {
                unlink($item->getPathname());
                continue;
            }
            if ($item->isDir()) {
                rmdir($item->getPathname());
                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($path);
    }
}
