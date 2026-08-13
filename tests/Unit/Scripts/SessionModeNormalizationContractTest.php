<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class SessionModeNormalizationContractTest extends TestCase
{
    public function testWrapperIsReadOnlyByDefaultAndRequiresExactRob464Confirmation(): void
    {
        $workspace = sys_get_temp_dir() . '/session-mode-wrapper-' . bin2hex(random_bytes(8));
        mkdir($workspace . '/bin', 0777, true);
        $log = $workspace . '/ssh.log';
        file_put_contents(
            $workspace . '/bin/ssh',
            "#!/usr/bin/env bash\nprintf '%s\\n' \"\$*\" >> " . escapeshellarg($log) . "\n",
        );
        chmod($workspace . '/bin/ssh', 0755);

        try {
            $dryRun = $this->runWrapper([], $workspace . '/bin');
            self::assertSame(0, $dryRun['exit'], $dryRun['stderr']);
            self::assertStringContainsString('mode       : read-only', $dryRun['stdout']);
            self::assertStringContainsString(
                "/usr/bin/setpriv --bounding-set=-all,+dac_override,+fowner --inh-caps=-all --ambient-caps=-all /usr/bin/python3 -I -B /usr/local/libexec/fh-session-retention-v1 normalize-modes 'dry-run'",
                (string) file_get_contents($log),
            );

            file_put_contents($log, '');
            foreach (
                [['--execute'], ['--execute', '--confirm-live-write', 'ROB-440'], ['--confirm-live-write', 'ROB-464']]
                as $arguments
            ) {
                self::assertSame(1, $this->runWrapper($arguments, $workspace . '/bin')['exit']);
            }
            self::assertSame('', (string) file_get_contents($log));

            $execute = $this->runWrapper(['--execute', '--confirm-live-write', 'ROB-464'], $workspace . '/bin');
            self::assertSame(0, $execute['exit'], $execute['stderr']);
            self::assertStringContainsString('mode       : live-write', $execute['stdout']);
            self::assertStringContainsString(
                "/usr/bin/setpriv --bounding-set=-all,+dac_override,+fowner --inh-caps=-all --ambient-caps=-all /usr/bin/python3 -I -B /usr/local/libexec/fh-session-retention-v1 normalize-modes 'execute'",
                (string) file_get_contents($log),
            );
        } finally {
            $this->removeTree($workspace);
        }
    }

    public function testContractDoesNotBroadenRecurringRetentionUnitOrCreateTimer(): void
    {
        $root = dirname(__DIR__, 3);
        $helper = (string) file_get_contents($root . '/scripts/ops/libexec/session_retention_v1.py');
        $service = (string) file_get_contents($root . '/scripts/ops/systemd/fh-session-retention.service');
        $wrapper = (string) file_get_contents($root . '/scripts/ops/prod_session_mode_normalization.sh');

        self::assertStringContainsString("NORMALIZATION_SCHEMA = 'prod_session_mode_normalization.v1'", $helper);
        self::assertStringContainsString("sys.argv[1] == 'normalize-modes'", $helper);
        self::assertStringContainsString('os.fchmod(descriptor, 0o600)', $helper);
        self::assertStringContainsString('CapabilityBoundingSet=CAP_DAC_OVERRIDE', $service);
        self::assertStringNotContainsString('CAP_FOWNER', $service);
        self::assertStringNotContainsString('systemctl enable', $wrapper);
        self::assertStringNotContainsString('systemctl start', $wrapper);
        self::assertFileDoesNotExist($root . '/scripts/ops/systemd/fh-session-mode-normalization.timer');
    }

    /** @param list<string> $arguments @return array{exit:int,stdout:string,stderr:string} */
    private function runWrapper(array $arguments, string $stubBin): array
    {
        $root = dirname(__DIR__, 3);
        $process = proc_open(
            array_merge(
                ['bash', 'scripts/ops/prod_session_mode_normalization.sh', '--prod-ssh-target', 'prod.example'],
                $arguments,
            ),
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $root,
            array_merge($_ENV, ['PATH' => $stubBin . PATH_SEPARATOR . (getenv('PATH') ?: '')]),
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($process), 'stdout' => $stdout ?: '', 'stderr' => $stderr ?: ''];
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
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
