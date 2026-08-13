<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class ZeroSurpriseProductionImageCleanupRootTest extends TestCase
{
    private string $root;
    private string $lock;

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY !== 'Linux' || (function_exists('posix_geteuid') && posix_geteuid() !== 0)) {
            self::markTestSkipped('Linux root is required.');
        }
        $this->root = '/var/lib/fh-rob458-test-' . bin2hex(random_bytes(8));
        $locks = $this->root . '/locks';
        self::assertTrue(mkdir($locks, 0700, true));
        chmod($this->root, 0700);
        chmod($locks, 0700);
        $this->lock = $locks . '/fh-production-change.lock';
        self::assertSame(0, file_put_contents($this->lock, ''));
        chmod($this->lock, 0600);
    }

    protected function tearDown(): void
    {
        if (isset($this->lock)) {
            if (is_link($this->lock) || is_file($this->lock)) {
                unlink($this->lock);
            }
            $target = $this->root . '/target';
            if (is_file($target)) {
                unlink($target);
            }
            if (is_dir($this->root . '/locks')) {
                rmdir($this->root . '/locks');
            }
            if (is_dir($this->root)) {
                rmdir($this->root);
            }
        }
        parent::tearDown();
    }

    public function testRootLockAuthorityAcceptsExactFileAndRejectsBusyOrUnsafeLeaf(): void
    {
        $acquired = $this->probe('lock', $this->lock);
        self::assertSame(0, $acquired['exit'], $acquired['stderr']);
        self::assertSame("acquired\n", $acquired['stdout']);

        $handle = fopen($this->lock, 'r+b');
        self::assertIsResource($handle);
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        $busy = $this->probe('lock', $this->lock);
        self::assertSame(75, $busy['exit']);
        self::assertSame("global_lock_busy\n", $busy['stdout']);
        flock($handle, LOCK_UN);
        fclose($handle);

        chmod($this->lock, 0644);
        $wrongMode = $this->probe('lock', $this->lock);
        self::assertSame(70, $wrongMode['exit']);
        self::assertSame("global_lock_unsafe\n", $wrongMode['stdout']);

        unlink($this->lock);
        file_put_contents($this->root . '/target', '');
        chmod($this->root . '/target', 0600);
        symlink($this->root . '/target', $this->lock);
        $symlink = $this->probe('lock', $this->lock);
        self::assertSame(70, $symlink['exit']);
        self::assertSame("global_lock_unsafe\n", $symlink['stdout']);
    }

    public function testProductionDockerExecutablePassesExactTrustCheck(): void
    {
        self::assertFileExists('/usr/bin/docker');
        $result = $this->probe('docker', '/usr/bin/docker');
        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame("trusted\n", $result['stdout']);
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private function probe(string $operation, string $path): array
    {
        $root = dirname(__DIR__, 3);
        $code = <<<'PYTHON'
        import importlib.util
        import os
        import sys

        spec = importlib.util.spec_from_file_location("rob458_cleanup", sys.argv[1])
        module = importlib.util.module_from_spec(spec)
        sys.modules[spec.name] = module
        spec.loader.exec_module(module)
        try:
            if sys.argv[2] == "lock":
                fd = module.acquire_global_lock(sys.argv[3])
                os.close(fd)
                print("acquired")
            else:
                module.assert_trusted_docker(sys.argv[3])
                print("trusted")
        except module.CleanupError as error:
            print(error.reason)
            raise SystemExit(error.exit_code)
        PYTHON;
        $process = proc_open(
            [
                '/usr/bin/python3',
                '-I',
                '-B',
                '-c',
                $code,
                $root . '/scripts/ops/libexec/zero_surprise_image_cleanup_v1.py',
                $operation,
                $path,
            ],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $root,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($process), 'stdout' => $stdout ?: '', 'stderr' => $stderr ?: ''];
    }
}
