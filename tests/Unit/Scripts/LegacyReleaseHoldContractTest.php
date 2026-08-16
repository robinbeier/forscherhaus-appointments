<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class LegacyReleaseHoldContractTest extends TestCase
{
    public function testHelperAndWrapperKeepClosedBoundaries(): void
    {
        $root = dirname(__DIR__, 3);
        $helper = (string) file_get_contents($root . '/scripts/ops/libexec/legacy_release_hold_v1.py');
        $wrapper = (string) file_get_contents($root . '/scripts/ops/prod_legacy_release_hold.sh');
        $docs = (string) file_get_contents($root . '/docs/ops/production-legacy-release-hold.md');
        self::assertStringContainsString("HOLD_SCHEMA = 'legacy_release_hold.v1'", $helper);
        self::assertStringContainsString("HOLD_PATH = '/etc/fh/legacy-release-hold.v1.json'", $helper);
        self::assertStringContainsString('sys.argv[2] == TOKEN', $helper);
        self::assertStringContainsString("TOKEN = 'ROB-470-HOLD'", $helper);
        self::assertStringContainsString('renameat2', $helper);
        self::assertStringContainsString('RENAME_NOREPLACE', $helper);
        self::assertStringContainsString('TEMP_PATTERN = re.compile', $helper);
        self::assertStringContainsString('targets_preflighted', $helper);
        self::assertStringNotContainsString('expected_commit', $helper);
        self::assertStringContainsString('--confirm-live-write ROB-470-HOLD', $wrapper);
        self::assertStringContainsString('legacy_unverifiable_hold', $docs);
        self::assertStringContainsString('does not authorize installation', $docs);
    }

    public function testWrapperValidatesStubbedAggregateAndNeverPrintsSensitiveRemoteBytes(): void
    {
        $root = dirname(__DIR__, 3);
        $workspace = sys_get_temp_dir() . '/rob470-wrapper-' . bin2hex(random_bytes(6));
        mkdir($workspace . '/bin', 0777, true);
        $log = $workspace . '/calls.log';
        file_put_contents(
            $workspace . '/bin/ssh',
            "#!/usr/bin/env bash\nprintf '%s\\n' \"" .
                '$*' .
                "\" >> " .
                escapeshellarg($log) .
                "\nprintf '%s\\n' '{\"attached\":false,\"mode\":\"inspect\",\"mutation_counts\":{\"hold_published\":0,\"temp_files_created\":0,\"temp_files_removed\":0},\"mutation_outcome\":\"none\",\"pending\":true,\"schema\":\"legacy_release_hold_result.v1\",\"status\":\"pass\",\"targets_preflighted\":2}'\n",
        );
        chmod($workspace . '/bin/ssh', 0755);
        try {
            $result = $this->runWrapper([], $workspace . '/bin', $root);
            self::assertSame(0, $result['exit'], $result['stderr']);
            self::assertStringContainsString('"mode":"inspect"', $result['stdout']);
            self::assertStringContainsString('fh-legacy-release-hold-v1', (string) file_get_contents($log));
            file_put_contents(
                $workspace . '/bin/ssh',
                "#!/usr/bin/env bash\nprintf '%s\\n' '{\"attached\":false,\"mode\":\"inspect\",\"mutation_counts\":{\"hold_published\":0,\"temp_files_created\":0,\"temp_files_removed\":0},\"mutation_outcome\":\"none\",\"pending\":true,\"schema\":\"legacy_release_hold_result.v1\",\"status\":\"pass\",\"targets\":[\"sensitive\"],\"targets_preflighted\":2}'\n",
            );
            chmod($workspace . '/bin/ssh', 0755);
            $extra = $this->runWrapper([], $workspace . '/bin', $root);
            self::assertSame(75, $extra['exit']);
            self::assertStringNotContainsString('sensitive', $extra['stdout']);
            self::assertStringContainsString('transport_result_invalid', $extra['stdout']);
            file_put_contents(
                $workspace . '/bin/ssh',
                "#!/usr/bin/env bash\nprintf '%s\\n' \"" .
                    '$*' .
                    "\" >> " .
                    escapeshellarg($log) .
                    "\nprintf '%s\\n' '{\"mode\":\"provision\",\"mutation_counts\":{\"hold_published\":1,\"temp_files_created\":1,\"temp_files_removed\":1},\"mutation_outcome\":\"known\",\"schema\":\"legacy_release_hold_result.v1\",\"status\":\"pass\"}'\n",
            );
            chmod($workspace . '/bin/ssh', 0755);
            file_put_contents($log, '');
            $provision = $this->runWrapper(
                ['--provision', '--confirm-live-write', 'ROB-470-HOLD'],
                $workspace . '/bin',
                $root,
            );
            self::assertSame(0, $provision['exit'], $provision['stderr']);
            self::assertStringContainsString('provision ROB-470-HOLD', (string) file_get_contents($log));
            file_put_contents($log, '');
            $invalid = $this->runWrapper(['--provision'], $workspace . '/bin', $root);
            self::assertSame(1, $invalid['exit']);
            self::assertSame('', (string) file_get_contents($log));
        } finally {
            $this->removeTree($workspace);
        }
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private function runWrapper(array $arguments, string $stubBin, string $root): array
    {
        $process = proc_open(
            array_merge(
                ['bash', 'scripts/ops/prod_legacy_release_hold.sh', '--prod-ssh-target', 'prod.example'],
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
