<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProdValidateKumaMonitorsTest extends TestCase
{
    private const ROLES = [
        ['App-Homepage', 'http'],
        ['App — Health Shallow', 'keyword'],
        ['App - Health Deep', 'json-query'],
        ['Host - Services', 'push'],
        ['Host - Resources', 'push'],
        ['Ops - Restore Verify Freshness', 'push'],
        ['Ops - Backup Creation Freshness', 'push'],
        ['App - Log Errors', 'push'],
        ['App - php8.5-fpm Log Errors', 'push'],
        ['App - PDF Renderer Log Errors', 'push'],
        ['App - Dashboard PDF Export', 'push'],
        ['Security - Scanner Activity', 'push'],
    ];

    #[DataProvider('kumaScenarios')]
    public function testKumaSectionRequiresExpectedMonitorRolesAndGreenCount(
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
            if ($scenario === 'missing') {
                // Keep the database absent so the real readability guard is exercised.
            } elseif ($scenario === 'unreadable') {
                symlink($workspace . '/does-not-exist', $database);
            } else {
                $this->createDatabase($database, $scenario);
            }
            $this->writeSqliteShim($stubBin . '/sqlite3');
            $this->writeKumaSectionRunner($runner, $database);

            $result = $this->runCommand(['bash', $runner], $this->repoRoot(), [
                'PATH' => $stubBin . ':' . (getenv('PATH') ?: ''),
                'KUMA_SCENARIO' => $scenario,
            ]);

            self::assertSame($expectedExitCode, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString($expectedOutput, $result['stdout'] . $result['stderr']);
            self::assertStringNotContainsString('SELECT *', $result['stdout'] . $result['stderr']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    /** @return iterable<string,array{string,int,string}> */
    public static function kumaScenarios(): iterable
    {
        yield 'current ids' => ['current', 0, 'kuma.expected_monitor_roles=12'];
        yield 'regenerated ids' => ['regenerated', 0, 'kuma.expected_monitor_roles=12'];
        yield 'missing role' => ['missing_role', 1, 'FAIL kuma expected 12 monitor roles'];
        yield 'wrong role type' => ['wrong_type', 1, 'FAIL kuma expected 12 monitor roles'];
        yield 'duplicate role' => ['duplicate_role', 1, 'FAIL kuma expected 12 monitor roles'];
        yield 'red latest status' => ['red', 1, 'FAIL kuma expected 12 monitor roles'];
        yield 'extra active monitor' => ['extra', 1, 'FAIL kuma expected 12 monitor roles'];
        yield 'missing database' => ['missing', 1, 'FAIL kuma unavailable'];
        yield 'unreadable database' => ['unreadable', 1, 'FAIL kuma unavailable'];
        yield 'query failure' => ['queryfail', 1, 'FAIL kuma expected 12 monitor roles'];
    }

    private function createDatabase(string $path, string $scenario): void
    {
        $database = new \SQLite3($path);
        $database->exec('CREATE TABLE monitor (id INTEGER PRIMARY KEY, name TEXT, type TEXT, active INTEGER)');
        $database->exec('CREATE TABLE heartbeat (monitor_id INTEGER, status INTEGER, time INTEGER)');
        $ids = $scenario === 'regenerated' ? range(101, 112) : [1, 2, 4, 5, 6, 7, 9, 10, 11, 12, 13, 14];
        foreach (self::ROLES as $index => [$name, $type]) {
            if ($scenario === 'missing_role' && $name === 'App - Health Deep') {
                continue;
            }
            if ($scenario === 'wrong_type' && $name === 'App - Health Deep') {
                $type = 'keyword';
            }
            $statement = $database->prepare(
                'INSERT INTO monitor (id, name, type, active) VALUES (:id, :name, :type, 1)',
            );
            $statement->bindValue(':id', $ids[$index], SQLITE3_INTEGER);
            $statement->bindValue(':name', $name, SQLITE3_TEXT);
            $statement->bindValue(':type', $type, SQLITE3_TEXT);
            $statement->execute();
            $heartbeat = $database->prepare(
                'INSERT INTO heartbeat (monitor_id, status, time) VALUES (:id, :status, :time)',
            );
            $heartbeat->bindValue(':id', $ids[$index], SQLITE3_INTEGER);
            $heartbeat->bindValue(':status', $scenario === 'red' && $index === 0 ? 0 : 1, SQLITE3_INTEGER);
            $heartbeat->bindValue(':time', $index, SQLITE3_INTEGER);
            $heartbeat->execute();
        }
        if ($scenario === 'duplicate_role') {
            $database->exec("DELETE FROM monitor WHERE name = 'Security - Scanner Activity'");
            $database->exec(
                "INSERT INTO monitor (id, name, type, active) VALUES (999, 'App - Health Deep', 'json-query', 1)",
            );
            $database->exec('INSERT INTO heartbeat (monitor_id, status, time) VALUES (999, 1, 99)');
        }
        if ($scenario === 'extra') {
            $database->exec("INSERT INTO monitor (id, name, type, active) VALUES (999, 'Untracked', 'push', 1)");
            $database->exec('INSERT INTO heartbeat (monitor_id, status, time) VALUES (999, 1, 99)');
        }
        $database->close();
    }

    private function writeSqliteShim(string $path): void
    {
        file_put_contents(
            $path,
            <<<'PHP'
            #!/usr/bin/env php
            <?php
            if (getenv('KUMA_SCENARIO') === 'queryfail') {
                exit(1);
            }
            $database = new SQLite3($argv[1], SQLITE3_OPEN_READONLY);
            $value = $database->querySingle($argv[2]);
            echo $value . PHP_EOL;
            PHP
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
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }
}
