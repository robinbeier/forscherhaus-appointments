<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

/** Exercises the wrapper with command doubles: no root, systemd, app or database access. */
final class ZeroSurpriseCanaryLifecycleTest extends TestCase
{
    public function testIndependentCleanupIsArmedBeforeFixtureActivation(): void
    {
        $events = $this->runWrapper('activate');
        $timer = array_search('systemd-run', $events, true);
        $activate = array_search('php:activate', $events, true);
        self::assertNotFalse($timer);
        self::assertNotFalse($activate);
        self::assertLessThan($activate, $timer, 'Cleanup must already be armed when fixture creation starts.');
    }

    public function testSuccessfulCleanupStopsThePendingTimer(): void
    {
        self::assertContains('stop:fh-zero-surprise-canary-cleanup.timer', $this->runWrapper('deactivate'));
    }

    private function runWrapper(string $action): array
    {
        $dir = sys_get_temp_dir() . '/canary-wrapper-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($dir, 0700));
        $scripts = [
            'realpath' => "#!/bin/sh\nprintf '/synthetic/canary\\n'\n",
            'stat' => "#!/bin/sh\nprintf '1:2\\n'\n",
            'id' => "#!/bin/sh\nprintf '0\\n'\n",
            'php' => "#!/bin/sh\nprintf 'php:%s\\n' \"\$4\" >> \"\$CANARY_TEST_LOG\"\n",
            'systemd-run' => "#!/bin/sh\nprintf 'systemd-run\\n' >> \"\$CANARY_TEST_LOG\"\n",
            'systemctl' =>
                "#!/bin/sh\nif [ \"\$1\" = show ]; then printf 'not-found\\n'; else printf '%s:%s\\n' \"\$1\" \"\$2\" >> \"\$CANARY_TEST_LOG\"; fi\n",
        ];
        try {
            foreach ($scripts as $name => $body) {
                file_put_contents($dir . '/' . $name, $body);
                chmod($dir . '/' . $name, 0700);
            }
            $env = array_merge(getenv(), [
                'PATH' => $dir . ':' . getenv('PATH'),
                'CANARY_TEST_LOG' => $dir . '/events',
                'APP_ROOT' => '/nonexistent/synthetic-canary',
            ]);
            $process = proc_open(
                ['bash', dirname(__DIR__, 3) . '/scripts/ops/zero_surprise_canary_fixture.sh', $action],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                $env,
            );
            self::assertIsResource($process);
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $out . $err);
            return file($dir . '/events', FILE_IGNORE_NEW_LINES) ?: [];
        } finally {
            foreach (array_merge(array_keys($scripts), ['events']) as $name) {
                if (is_file($dir . '/' . $name)) {
                    unlink($dir . '/' . $name);
                }
            }
            rmdir($dir);
        }
    }
}
