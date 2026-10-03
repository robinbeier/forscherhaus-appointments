<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class PrepareSessionStorageTest extends TestCase
{
    public function testFreshDirectoryIsCreatedPrivate(): void
    {
        $root = $this->makeStorageRoot();

        try {
            self::assertSame(0, $this->runHelper($root)['exit_code']);
            self::assertSame(0700, fileperms($root . '/sessions') & 0777);
        } finally {
            $this->removeDirectory(dirname($root));
        }
    }

    public function testTrackedPlaceholdersAreSecuredWithoutChangingContents(): void
    {
        $root = $this->makeStorageRoot();
        mkdir($root . '/sessions', 0755);
        file_put_contents($root . '/sessions/.htaccess', "Deny from all\n");
        file_put_contents($root . '/sessions/index.html', "\n");

        try {
            self::assertSame(0, $this->runHelper($root)['exit_code']);
            self::assertSame(0700, fileperms($root . '/sessions') & 0777);
            self::assertSame("Deny from all\n", file_get_contents($root . '/sessions/.htaccess'));
            self::assertSame("\n", file_get_contents($root . '/sessions/index.html'));
        } finally {
            $this->removeDirectory(dirname($root));
        }
    }

    public function testPopulatedDirectoryModeAndSessionContentsArePreserved(): void
    {
        $root = $this->makeStorageRoot();
        $sessions = $root . '/sessions';
        mkdir($sessions, 0750);
        $sessionFile = $sessions . '/synthetic-session-fixture';
        file_put_contents($sessionFile, 'synthetic session fixture');
        $before = hash_file('sha256', $sessionFile);

        try {
            self::assertSame(0, $this->runHelper($root)['exit_code']);
            self::assertSame(0750, fileperms($sessions) & 0777);
            self::assertSame($before, hash_file('sha256', $sessionFile));
        } finally {
            $this->removeDirectory(dirname($root));
        }
    }

    public function testSymlinkedDirectoryIsRejectedSafely(): void
    {
        $root = $this->makeStorageRoot();
        $target = $root . '/outside';
        mkdir($target);
        symlink($target, $root . '/sessions');

        try {
            $result = $this->runHelper($root);
            self::assertNotSame(0, $result['exit_code']);
            self::assertStringContainsString('Refusing symlinked session directory', $result['stderr']);
            self::assertSame(0755, fileperms($target) & 0777);
        } finally {
            $this->removeDirectory(dirname($root));
        }
    }

    /** @return array{exit_code:int,stdout:string,stderr:string} */
    private function runHelper(string $root): array
    {
        $process = proc_open(
            ['bash', dirname(__DIR__, 3) . '/scripts/prepare-session-storage.sh', $root],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit_code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function makeStorageRoot(): string
    {
        $root = sys_get_temp_dir() . '/fh-session-' . bin2hex(random_bytes(6));
        mkdir($root, 0755, true);
        mkdir($root . '/storage');
        return $root . '/storage';
    }

    private function removeDirectory(string $directory): void
    {
        foreach (
            new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            )
            as $path
        ) {
            $path->isDir() && !$path->isLink() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        }
        rmdir($directory);
    }
}
