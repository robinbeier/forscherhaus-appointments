<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProdValidateKumaMonitorsTest extends TestCase
{
    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    #[DataProvider('kumaScenarios')]
    public function testKumaSectionRequiresExpectedActiveIdentityAndGreenCount(
        string $scenario,
        int $expectedExitCode,
        string $expectedOutput,
    ): void {
        $workspace = sys_get_temp_dir() . '/prod-validate-kuma-' . bin2hex(random_bytes(8));
        $stubBin = $workspace . '/bin';
        $database = $workspace . '/kuma.db';
        $runner = $workspace . '/kuma-section.sh';

        mkdir($stubBin, 0777, true);

        try {
            if ($scenario === 'missing' || $scenario === 'unreadable') {
                if ($scenario === 'unreadable') {
                    symlink($workspace . '/does-not-exist', $database);
                }
            } else {
                file_put_contents($database, 'fixture');
            }
            $this->writeSqliteStub($stubBin . '/sqlite3');
            $this->writeKumaSectionRunner($runner, $database);

            $result = $this->runCommand(['bash', $runner], $this->repoRoot(), [
                'PATH' => $stubBin . ':' . getenv('PATH'),
                'KUMA_SCENARIO' => $scenario,
            ]);

            self::assertSame($expectedExitCode, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString($expectedOutput, $result['stdout'] . $result['stderr']);
            self::assertStringNotContainsString('1,2,4,5,6,7,9,10,11,12,13,14', $result['stdout']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    /** @return iterable<string,array{string,int,string}> */
    public static function kumaScenarios(): iterable
    {
        yield 'expected set and green' => ['expected', 0, 'kuma.expected_active_ids=12'];
        yield 'wrong identity set' => ['wrong_identity', 1, 'FAIL kuma expected 12 active IDs'];
        yield 'missing database' => ['missing', 1, 'FAIL kuma unavailable'];
        yield 'unreadable database' => ['unreadable', 1, 'FAIL kuma unavailable'];
        yield 'red latest status' => ['red', 1, 'FAIL kuma expected 12 active IDs'];
        yield 'extra active monitor' => ['extra', 1, 'FAIL kuma expected 12 active IDs'];
        yield 'query failure' => ['queryfail', 1, 'FAIL kuma expected 12 active IDs'];
    }

    private function writeSqliteStub(string $path): void
    {
        file_put_contents(
            $path,
            <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            query="${*: -1}"
            if [[ "${KUMA_SCENARIO}" == 'queryfail' ]]; then
                exit 1
            elif [[ "$query" == *'AND id IN (1,2,4,5,6,7,9,10,11,12,13,14)'* ]]; then
                [[ "${KUMA_SCENARIO}" == 'wrong_identity' ]] && printf '11\n' || printf '12\n'
            elif [[ "$query" == *'COUNT(*) FROM monitor'* ]]; then
                [[ "${KUMA_SCENARIO}" == 'extra' ]] && printf '13\n' || printf '12\n'
            else
                [[ "${KUMA_SCENARIO}" == 'red' ]] && printf '11\n' || printf '12\n'
            fi
            BASH
            ,
        );
        chmod($path, 0755);
    }

    private function writeKumaSectionRunner(string $path, string $database): void
    {
        $script = file_get_contents($this->repoRoot() . '/scripts/ops/prod_validate_after_change.sh');
        self::assertIsString($script);
        $lines = explode("\n", $script);
        $start = array_search('section kuma', $lines, true);
        $end = array_search('section resources', $lines, true);
        self::assertIsInt($start);
        self::assertIsInt($end);
        $fragment = implode("\n", array_slice($lines, $start, $end - $start));
        $fragment = str_replace('/var/lib/uptime-kuma-data/kuma.db', $database, $fragment);
        file_put_contents(
            $path,
            "#!/usr/bin/env bash\nset -euo pipefail\nfailures=0\nsection() { :; }\n" .
                $fragment .
                "printf 'failures=%s\\n' \"\$failures\"\nexit \"\$failures\"\n",
        );
        chmod($path, 0755);
    }

    /** @param list<string> $command @param array<string,string> $env */
    private function runCommand(array $command, string $cwd, array $env): array
    {
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            array_merge($_ENV, $env),
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
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
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }
}
