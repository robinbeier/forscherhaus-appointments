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

    public function testSetupScriptRejectsSymlinkRootAndImmediateChildrenAndPreparesNormalStorage(): void
    {
        $root = $this->makeSetupFixture();
        $outside = $root . '/outside';
        mkdir($outside, 0750);
        file_put_contents($outside . '/sentinel', 'must remain unchanged');
        $before = hash_file('sha256', $outside . '/sentinel');

        try {
            symlink($outside, $root . '/storage');
            $result = $this->runSetup($root);
            self::assertNotSame(0, $result['exit_code']);
            self::assertSame(0750, fileperms($outside) & 0777);
            self::assertSame($before, hash_file('sha256', $outside . '/sentinel'));
            unlink($root . '/storage');

            mkdir($root . '/storage');
            symlink($outside, $root . '/storage/backups');
            $result = $this->runSetup($root);
            self::assertNotSame(0, $result['exit_code']);
            self::assertSame(0750, fileperms($outside) & 0777);
            self::assertSame($before, hash_file('sha256', $outside . '/sentinel'));
            unlink($root . '/storage/backups');

            chmod($root . '/storage', 0777);
            mkdir($root . '/storage/cache');
            mkdir($root . '/storage/cache/existing', 0700);
            file_put_contents($root . '/storage/cache/existing/data', 'keep private');
            chmod($root . '/storage/cache/existing/data', 0640);
            $nestedBefore = hash_file('sha256', $root . '/storage/cache/existing/data');
            $result = $this->runSetup($root);
            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertSame(0755, fileperms($root . '/storage') & 0777);
            self::assertSame(0777, fileperms($root . '/storage/cache') & 0777);
            self::assertSame(0700, fileperms($root . '/storage/cache/existing') & 0777);
            self::assertSame(0640, fileperms($root . '/storage/cache/existing/data') & 0777);
            self::assertSame($nestedBefore, hash_file('sha256', $root . '/storage/cache/existing/data'));
            self::assertDirectoryExists($root . '/storage/sessions');
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testContainerStartupRejectsStorageSymlinksBeforeAnyPermissionChange(): void
    {
        $root = $this->makeSetupFixture();
        mkdir($root . '/docker/php-fpm', 0755, true);
        copy(dirname(__DIR__, 3) . '/docker/php-fpm/start-container', $root . '/docker/php-fpm/start-container');
        $outside = $root . '/outside';
        mkdir($outside, 0750);
        file_put_contents($outside . '/sentinel', 'must remain unchanged');
        $before = hash_file('sha256', $outside . '/sentinel');

        try {
            symlink($outside, $root . '/storage');
            self::assertNotSame(0, $this->runSetup($root, 'docker/php-fpm/start-container')['exit_code']);
            self::assertSame(0750, fileperms($outside) & 0777);
            self::assertSame($before, hash_file('sha256', $outside . '/sentinel'));
            unlink($root . '/storage');

            mkdir($root . '/storage');
            symlink($outside, $root . '/storage/cache');
            self::assertNotSame(0, $this->runSetup($root, 'docker/php-fpm/start-container')['exit_code']);
            self::assertSame(0750, fileperms($outside) & 0777);
            self::assertSame($before, hash_file('sha256', $outside . '/sentinel'));
        } finally {
            $this->removeDirectory($root);
        }
    }

    /** @return array{exit_code:int,stdout:string,stderr:string} */
    private function runSetup(string $root, string $script = 'scripts/setup-worktree.sh'): array
    {
        $environment = $_ENV;
        $environment['PATH'] = $root . '/bin' . PATH_SEPARATOR . (getenv('PATH') ?: '');
        $process = proc_open(
            ['bash', $root . '/' . $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            $environment,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit_code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function makeSetupFixture(): string
    {
        $root = sys_get_temp_dir() . '/fh-setup-' . bin2hex(random_bytes(6));
        mkdir($root . '/scripts/ci', 0755, true);
        mkdir($root . '/bin');
        copy(dirname(__DIR__, 3) . '/scripts/setup-worktree.sh', $root . '/scripts/setup-worktree.sh');
        copy(
            dirname(__DIR__, 3) . '/scripts/prepare-session-storage.sh',
            $root . '/scripts/prepare-session-storage.sh',
        );
        file_put_contents($root . '/config-sample.php', "<?php\n");
        foreach (['git', 'php', 'composer', 'node', 'npm', 'npx'] as $command) {
            file_put_contents($root . '/bin/' . $command, "#!/bin/sh\nexit 0\n");
            chmod($root . '/bin/' . $command, 0755);
        }
        foreach (['require_node_minimum.sh', 'ensure_local_deps.sh'] as $script) {
            file_put_contents($root . '/scripts/ci/' . $script, "#!/bin/sh\nexit 0\n");
            chmod($root . '/scripts/ci/' . $script, 0755);
        }
        file_put_contents($root . '/scripts/install-git-hooks.sh', "#!/bin/sh\nexit 0\n");
        chmod($root . '/scripts/install-git-hooks.sh', 0755);
        return $root;
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
