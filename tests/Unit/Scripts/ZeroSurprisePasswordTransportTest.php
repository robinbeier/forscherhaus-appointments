<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class ZeroSurprisePasswordTransportTest extends TestCase
{
    public function testLiveCanaryPassesPasswordThroughStdinToBothChildChecks(): void
    {
        $fixture = sys_get_temp_dir() . '/zero-surprise-transport-' . bin2hex(random_bytes(6));
        $bin = $fixture . '/bin';
        $repoRoot = dirname(__DIR__, 3);
        $password = 'P@ss:word!';
        $record = $fixture . '/stub-record.log';
        $report = $fixture . '/canary-report.json';
        $credentials = $fixture . '/canary.ini';
        $originalPath = getenv('PATH');

        mkdir($bin, 0700, true);

        foreach (
            [
                'scripts/release-gate/zero_surprise_live_canary.php',
                'scripts/release-gate/lib/GateProcessRunner.php',
                'scripts/release-gate/lib/ZeroSurpriseReport.php',
                'scripts/release-gate/lib/ZeroSurpriseCredentials.php',
                'scripts/release-gate/lib/ZeroSurpriseProfile.php',
                'scripts/release-gate/config/zero_surprise_profiles.php',
            ]
            as $relativePath
        ) {
            $target = $fixture . '/' . $relativePath;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0700, true);
            }
            self::assertTrue(copy($repoRoot . '/' . $relativePath, $target), $relativePath);
        }

        file_put_contents(
            $credentials,
            implode(PHP_EOL, [
                'base_url = "http://example.test"',
                'index_page = "index.php"',
                'username = "fixture-user"',
                'password = "' . $password . '"',
                'start_date = "2026-09-07"',
                'end_date = "2026-09-11"',
                'booking_search_days = 1',
                'retry_count = 0',
                'max_pdf_duration_ms = 100',
                'timezone = "Europe/Berlin"',
            ]) . PHP_EOL,
        );

        $stub = <<<'SH'
        #!/bin/sh
        set -eu

        record="${ZERO_SURPRISE_STUB_RECORD:?}"
        script="${1:-}"
        shift
        printf 'call=%s\n' "$script" >> "$record"
        output=''
        for arg in "$@"; do
            printf 'arg=%s\n' "$arg" >> "$record"
            case "$arg" in
                --output-json=*) output=${arg#*=} ;;
            esac
        done

        stdin_file="$record.stdin.$$"
        cat > "$stdin_file"
        if command -v sha256sum >/dev/null 2>&1; then
            hash=$(sha256sum "$stdin_file" | awk '{print $1}')
        else
            hash=$(shasum -a 256 "$stdin_file" | awk '{print $1}')
        fi
        printf 'stdin_sha256=%s\n' "$hash" >> "$record"
        rm -f "$stdin_file"

        mkdir -p "$(dirname "$output")"
        case "$script" in
            scripts/ci/booking_write_contract_smoke.php)
                cat > "$output" <<'JSON'
        {"checks":[{"name":"booking_register_unavailable_contract","status":"pass","slot_appointments_count":1}]}
        JSON
                ;;
            scripts/release-gate/dashboard_release_gate.php)
                cat > "$output" <<'JSON'
        {"checks":[{"name":"dashboard_metrics","status":"pass"},{"name":"export_principal_pdf","status":"pass","duration_ms":1},{"name":"export_teacher_pdf","status":"pass","duration_ms":1}]}
        JSON
                ;;
            *)
                printf 'unexpected child script: %s\n' "$script" >&2
                exit 1
                ;;
        esac
        SH;
        file_put_contents($bin . '/php', $stub);
        chmod($bin . '/php', 0700);

        putenv('PATH=' . $bin . ':' . (is_string($originalPath) ? $originalPath : ''));
        putenv('ZERO_SURPRISE_STUB_RECORD=' . $record);

        try {
            $result = $this->runCanary($fixture, $credentials, $report);

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertFileExists($report);
            self::assertJson((string) file_get_contents($report));

            $records = (string) file_get_contents($record);
            self::assertSame(2, substr_count($records, 'call='));
            self::assertSame(2, substr_count($records, 'arg=--password-stdin'));
            self::assertStringNotContainsString($password, $records);
            self::assertSame(2, substr_count($records, 'stdin_sha256=' . hash('sha256', $password)));
            self::assertStringNotContainsString($password, $result['stdout'] . $result['stderr']);

            $canary = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(0, $canary['summary']['exit_code'] ?? null);
            self::assertSame('pass', $canary['invariants']['overbooking']['status'] ?? null);
            self::assertSame('pass', $canary['invariants']['fill_rate_math']['status'] ?? null);
            self::assertSame('pass', $canary['invariants']['pdf_exports']['status'] ?? null);
        } finally {
            putenv('PATH=' . (is_string($originalPath) ? $originalPath : ''));
            putenv('ZERO_SURPRISE_STUB_RECORD');
            self::removeTree($fixture);
        }
    }

    /** @return array{exit_code:int,stdout:string,stderr:string} */
    private function runCanary(string $fixture, string $credentials, string $report): array
    {
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [
                PHP_BINARY,
                $fixture . '/scripts/release-gate/zero_surprise_live_canary.php',
                '--release-id=fixture-release',
                '--credentials-file=' . $credentials,
                '--profile=school-day-default',
                '--timeout-seconds=10',
                '--output-json=' . $report,
            ],
            $descriptorSpec,
            $pipes,
            $fixture,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit_code' => proc_close($process),
            'stdout' => (string) $stdout,
            'stderr' => (string) $stderr,
        ];
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;
            if (is_dir($child) && !is_link($child)) {
                self::removeTree($child);
            } else {
                unlink($child);
            }
        }

        rmdir($path);
    }
}
