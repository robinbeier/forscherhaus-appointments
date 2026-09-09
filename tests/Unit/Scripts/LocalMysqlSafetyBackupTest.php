<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class LocalMysqlSafetyBackupTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/fh-local-mysql-backup-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->workspace, 0700));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
    }

    public function testBackupUsesPrivateUniqueArchiveDirectoriesAndPreservesMysqlContent(): void
    {
        $repoRoot = $this->workspace . '/repo';
        $tmpDir = $this->workspace . '/tmp';
        self::assertTrue(mkdir($repoRoot . '/docker/mysql', 0777, true));
        self::assertTrue(mkdir($tmpDir, 0777));
        self::assertNotFalse(file_put_contents($repoRoot . '/docker/mysql/fixture.sql', 'synthetic mysql data\n'));
        chmod($repoRoot . '/docker/mysql/fixture.sql', 0666);

        [$first, $second] = $this->runBackupFunction($repoRoot, $tmpDir);

        self::assertNotSame($first, $second);
        foreach ([$first, $second] as $archive) {
            self::assertSame('mysql.tgz', basename($archive));
            self::assertSame(0600, fileperms($archive) & 0777, $archive);
            self::assertSame(0700, fileperms(dirname($archive)) & 0777, dirname($archive));
            self::assertSame("mysql/\nmysql/fixture.sql\n", $this->tarListing($archive));
            self::assertStringContainsString('synthetic mysql data', $this->tarContents($archive, 'mysql/fixture.sql'));
        }
    }

    /** @return array{string, string} */
    private function runBackupFunction(string $repoRoot, string $tmpDir): array
    {
        $source = file_get_contents(__DIR__ . '/../../../scripts/import_prod_backup.sh');
        self::assertNotFalse($source);
        preg_match('/backup_local_mysql_dir\(\) \{.*?\n\}\n/s', $source, $matches);
        self::assertCount(1, $matches, 'Could not extract backup_local_mysql_dir().');

        $script = $this->workspace . '/run-backup.sh';
        $body =
            "#!/usr/bin/env bash\nset -euo pipefail\numask 000\nREPO_ROOT=" .
            escapeshellarg($repoRoot) .
            "\nTMPDIR=" .
            escapeshellarg($tmpDir) .
            "\nlog() { :; }\n" .
            $matches[0] .
            "backup_local_mysql_dir\nprintf '%s\\n' \"\${LOCAL_MYSQL_BACKUP_TGZ}\"\n" .
            "backup_local_mysql_dir\nprintf '%s\\n' \"\${LOCAL_MYSQL_BACKUP_TGZ}\"\n";
        self::assertNotFalse(file_put_contents($script, $body));
        self::assertTrue(chmod($script, 0700));

        $command = 'bash ' . escapeshellarg($script);
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $stderr);

        $paths = array_values(array_filter(explode("\n", trim((string) $stdout))));
        self::assertCount(2, $paths, (string) $stderr);
        return [$paths[0], $paths[1]];
    }

    private function tarListing(string $archive): string
    {
        return $this->runCommand('tar -tzf ' . escapeshellarg($archive));
    }

    private function tarContents(string $archive, string $path): string
    {
        return $this->runCommand('tar -xOzf ' . escapeshellarg($archive) . ' ' . escapeshellarg($path));
    }

    private function runCommand(string $command): string
    {
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        self::assertSame(0, $exitCode, $command);
        return implode("\n", $output) . "\n";
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        self::assertIsArray($items);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . '/' . $item;
            is_dir($child) && !is_link($child) ? $this->removeTree($child) : unlink($child);
        }
        rmdir($path);
    }
}
