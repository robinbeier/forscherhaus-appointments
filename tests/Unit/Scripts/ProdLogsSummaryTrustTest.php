<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class ProdLogsSummaryTrustTest extends TestCase
{
    public function testRemoteSummaryUsesLocalRulesAndRetainsRedaction(): void
    {
        $fixture = sys_get_temp_dir() . '/prod-logs-trust-' . bin2hex(random_bytes(8));
        $app = $fixture . '/app';
        mkdir($fixture . '/bin', 0700, true);
        mkdir($app . '/storage/logs', 0700, true);
        mkdir($app . '/scripts/ops/lib', 0700, true);

        try {
            file_put_contents(
                $app . '/scripts/ops/lib/app_log_classification.sh',
                'touch "$TRUST_MARKER"' . "\n" . 'app_log_error_like_regex() { printf NEVER_MATCH; }' . "\n",
            );
            file_put_contents(
                $app . '/storage/logs/log-test.php',
                "ERROR - 2026-09-09 10:00:00 --> 404 Page Not Found: Wwwgooglecom/index\n" .
                    "ERROR - 2026-09-09 10:01:00 --> real failure https://example.test/?token=private-sentinel\n",
            );
            $stubs = [
                'uname' => "#!/bin/sh\nprintf 'Linux\\n'\n",
                'journalctl' => "#!/bin/sh\nprintf '%s\\n' '-- No entries --'\n",
                'ssh' => "#!/bin/sh\ncat > \"\$REMOTE_SCRIPT\"\nSINCE='60 min ago' bash \"\$REMOTE_SCRIPT\"\n",
            ];
            foreach ($stubs as $name => $contents) {
                file_put_contents($fixture . '/bin/' . $name, $contents);
                chmod($fixture . '/bin/' . $name, 0700);
            }

            $process = proc_open(
                ['bash', 'scripts/ops/prod_logs_summary.sh', '--prod-ssh-target', 'fixture.invalid'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                dirname(__DIR__, 3),
                array_merge(getenv(), [
                    'PATH' => $fixture . '/bin:' . getenv('PATH'),
                    'APP_ROOT' => $app,
                    'REMOTE_SCRIPT' => $fixture . '/remote.sh',
                    'TRUST_MARKER' => $fixture . '/remote-code-executed',
                ]),
            );
            self::assertIsResource($process);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $stdout . $stderr);
            self::assertFileDoesNotExist($fixture . '/remote-code-executed');
            self::assertStringContainsString('app_error_like_lines_24h=1', $stdout);
            self::assertStringContainsString('app_error_like_lines_24h_total=2', $stdout);
            self::assertStringContainsString('app_error_like_lines_24h_ignored_known_noise=1', $stdout);
            self::assertStringContainsString('real failure https://[REDACTED_URL]', $stdout);
            self::assertStringNotContainsString('private-sentinel', $stdout . $stderr);
            self::assertStringNotContainsString('Wwwgooglecom/index', $stdout);
            self::assertStringNotContainsString('source ', file_get_contents($fixture . '/remote.sh'));
        } finally {
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($fixture, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($entries as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($fixture);
        }
    }
}
