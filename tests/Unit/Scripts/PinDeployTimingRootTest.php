<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class PinDeployTimingRootTest extends TestCase
{
    private const TIMING_ID = '11111111-1111-4111-8111-111111111111';
    private const RUN_ID = '22222222-2222-4222-8222-222222222222';
    private const TIMING_ROOT = '/var/lib/fh-deploy-timing';
    private const STATE_ROOT = '/var/lib/fh-deploy-orchestrator';
    private string $runRoot;
    private string $helper;

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY !== 'Linux' || posix_geteuid() !== 0) {
            $this->markTestSkipped('Linux root is required for the protected timing-pin contract.');
        }
        if (file_exists(self::TIMING_ROOT) || file_exists(self::STATE_ROOT)) {
            $this->markTestSkipped('Protected timing roots already exist; the root test will not mutate them.');
        }
        $this->helper = dirname(__DIR__, 3) . '/scripts/ops/libexec/pin_deploy_timing_v1.py';
        mkdir(self::TIMING_ROOT, 0700);
        mkdir(self::STATE_ROOT . '/runs/' . self::RUN_ID, 0700, true);
        chmod(self::TIMING_ROOT, 0700);
        chmod(self::STATE_ROOT, 0700);
        chmod(self::STATE_ROOT . '/runs', 0700);
        chmod(self::STATE_ROOT . '/runs/' . self::RUN_ID, 0700);
        $this->runRoot = self::STATE_ROOT . '/runs/' . self::RUN_ID;
    }

    protected function tearDown(): void
    {
        if (isset($this->runRoot)) {
            $this->removeTree(self::TIMING_ROOT);
            $this->removeTree(self::STATE_ROOT);
        }
        parent::tearDown();
    }

    public function testMissingSourceIsNotObserved(): void
    {
        $result = $this->runPin();
        self::assertSame(0, $result['exit']);
        self::assertSame('not_observed', $this->decode($result)['status']);
        self::assertFileDoesNotExist($this->runRoot . '/deploy-timing.jsonl');
    }

    public function testPinsAttachesAndRejectsChangedExistingTargetWithoutOverwrite(): void
    {
        $bytes = "timing-authority\n";
        $this->writeSource($bytes);
        $first = $this->runPin();
        self::assertSame(
            [
                'bytes_base64' => base64_encode($bytes),
                'sha256' => hash('sha256', $bytes),
                'status' => 'pinned',
            ],
            $this->decode($first),
        );
        self::assertSame($bytes, file_get_contents($this->runRoot . '/deploy-timing.jsonl'));
        $metadata = lstat($this->runRoot . '/deploy-timing.jsonl');
        self::assertIsArray($metadata);
        self::assertSame(0, $metadata['uid']);
        self::assertSame(0600, $metadata['mode'] & 0777);
        self::assertSame(1, $metadata['nlink']);

        $second = $this->runPin();
        self::assertSame('attached', $this->decode($second)['status']);

        file_put_contents($this->runRoot . '/deploy-timing.jsonl', "changed\n");
        chmod($this->runRoot . '/deploy-timing.jsonl', 0600);
        $conflict = $this->runPin();
        self::assertSame(75, $conflict['exit']);
        self::assertSame("changed\n", file_get_contents($this->runRoot . '/deploy-timing.jsonl'));
    }

    public function testConcurrentIdenticalPinsSerializeWithoutDeletingLiveTemp(): void
    {
        $bytes = str_repeat('a', 524_288) . "\n";
        $this->writeSource($bytes);
        [$firstProcess, $firstOutput] = $this->startPinWithFileOutput();
        [$secondProcess, $secondOutput] = $this->startPinWithFileOutput();
        $first = $this->finishWithFileOutput($firstProcess, $firstOutput);
        $second = $this->finishWithFileOutput($secondProcess, $secondOutput);

        self::assertSame([0, 0], [$first['exit'], $second['exit']]);
        $statuses = [$this->decode($first)['status'], $this->decode($second)['status']];
        sort($statuses);
        self::assertSame(['attached', 'pinned'], $statuses);
        self::assertSame($bytes, file_get_contents($this->runRoot . '/deploy-timing.jsonl'));
        self::assertSame([], glob($this->runRoot . '/.deploy-timing.jsonl.tmp-*'));
    }

    public function testExternalRunLockDeterministicallyBlocksReconciliationAndPublication(): void
    {
        $this->writeSource("locked\n");
        $stale = $this->runRoot . '/.deploy-timing.jsonl.tmp-' . str_repeat('b', 32);
        file_put_contents($stale, "stale\n");
        chmod($stale, 0600);
        [$locker, $lockerPipes] = $this->startDirectoryLocker();
        self::assertSame("READY\n", fgets($lockerPipes[1]));
        [$pin, $pinPipes] = $this->startPin();
        usleep(150_000);

        self::assertTrue(proc_get_status($pin)['running']);
        self::assertFileExists($stale);
        self::assertFileDoesNotExist($this->runRoot . '/deploy-timing.jsonl');

        fwrite($lockerPipes[0], "release\n");
        fclose($lockerPipes[0]);
        fclose($lockerPipes[1]);
        fclose($lockerPipes[2]);
        self::assertSame(0, proc_close($locker));
        $result = $this->finish($pin, $pinPipes);
        self::assertSame('pinned', $this->decode($result)['status']);
        self::assertFileDoesNotExist($stale);
        $this->assertRunLockReleased();
    }

    public function testExactMaximumIsAcceptedAndMaximumPlusOneIsRejected(): void
    {
        $this->writeSource(str_repeat('x', 1_048_576));
        self::assertSame(0, $this->runPin()['exit']);
        unlink($this->runRoot . '/deploy-timing.jsonl');
        $this->writeSource(str_repeat('x', 1_048_577));
        self::assertSame(70, $this->runPin()['exit']);
        self::assertFileDoesNotExist($this->runRoot . '/deploy-timing.jsonl');
    }

    public function testUnsafeSourceKindsMetadataAndRunDirectoryReject(): void
    {
        $source = self::TIMING_ROOT . '/' . self::TIMING_ID . '.jsonl';
        symlink('/dev/null', $source);
        self::assertSame(70, $this->runPin()['exit']);
        unlink($source);

        mkdir($source, 0700);
        self::assertSame(70, $this->runPin()['exit']);
        rmdir($source);

        $this->writeSource('');
        self::assertSame(70, $this->runPin()['exit']);
        unlink($source);

        posix_mkfifo($source, 0600);
        self::assertSame(70, $this->runPin()['exit']);
        unlink($source);

        $socket = stream_socket_server('unix://' . $source);
        self::assertIsResource($socket);
        self::assertSame(70, $this->runPin()['exit']);
        fclose($socket);
        @unlink($source);

        $device = $this->runCommand(['mknod', $source, 'c', '1', '3']);
        self::assertSame(0, $device['exit'], $device['stderr']);
        self::assertSame(70, $this->runPin()['exit']);
        unlink($source);

        $this->writeSource("mode\n");
        chmod($source, 0644);
        self::assertSame(70, $this->runPin()['exit']);
        chmod($source, 0600);
        link($source, self::TIMING_ROOT . '/extra-link');
        self::assertSame(70, $this->runPin()['exit']);
        unlink(self::TIMING_ROOT . '/extra-link');

        chown($source, 65534);
        self::assertSame(70, $this->runPin()['exit']);
        chown($source, 0);

        chmod($this->runRoot, 0755);
        self::assertSame(70, $this->runPin()['exit']);
    }

    public function testRecognizedStaleTempIsReconciledUnderTheRunLock(): void
    {
        $this->writeSource("stable\n");
        $stale = $this->runRoot . '/.deploy-timing.jsonl.tmp-' . str_repeat('a', 32);
        file_put_contents($stale, "partial\n");
        chmod($stale, 0600);

        self::assertSame('pinned', $this->decode($this->runPin())['status']);
        self::assertFileDoesNotExist($stale);
    }

    public function testUnsafeRecognizedTempsRejectWithoutUnlinkOrPublishing(): void
    {
        $this->writeSource("stable\n");
        $leaf = '.deploy-timing.jsonl.tmp-' . str_repeat('c', 32);
        $path = $this->runRoot . '/' . $leaf;
        $cases = [
            'symlink' => static fn() => symlink('/dev/null', $path),
            'fifo' => static fn() => posix_mkfifo($path, 0600),
            'directory' => static fn() => mkdir($path, 0600),
            'wrong mode' => static function () use ($path): void {
                file_put_contents($path, 'x');
                chmod($path, 0644);
            },
            'oversize' => static function () use ($path): void {
                file_put_contents($path, str_repeat('x', 1_048_577));
                chmod($path, 0600);
            },
        ];
        foreach ($cases as $name => $create) {
            $create();
            self::assertSame(70, $this->runPin()['exit'], $name);
            self::assertFileExists($path, $name);
            self::assertFileDoesNotExist($this->runRoot . '/deploy-timing.jsonl', $name);
            $this->removeTree($path);
            $this->assertRunLockReleased();
        }
        file_put_contents($path, 'x');
        chmod($path, 0600);
        link($path, $this->runRoot . '/temp-hardlink');
        self::assertSame(70, $this->runPin()['exit'], 'hardlink');
        self::assertFileExists($path);
        self::assertFileDoesNotExist($this->runRoot . '/deploy-timing.jsonl');
        unlink($this->runRoot . '/temp-hardlink');
        unlink($path);
        $this->assertRunLockReleased();
    }

    public function testUnsafeExistingTargetKindsAndMetadataRejectWithoutReplacement(): void
    {
        $this->writeSource("source\n");
        $target = $this->runRoot . '/deploy-timing.jsonl';
        symlink('/dev/null', $target);
        self::assertSame(70, $this->runPin()['exit']);
        unlink($target);

        posix_mkfifo($target, 0600);
        self::assertSame(70, $this->runPin()['exit']);
        unlink($target);

        mkdir($target, 0700);
        self::assertSame(70, $this->runPin()['exit']);
        rmdir($target);

        $socket = stream_socket_server('unix://' . $target);
        self::assertIsResource($socket);
        self::assertSame(70, $this->runPin()['exit']);
        fclose($socket);
        @unlink($target);

        self::assertSame(0, $this->runCommand(['mknod', $target, 'c', '1', '3'])['exit']);
        self::assertSame(70, $this->runPin()['exit']);
        unlink($target);

        file_put_contents($target, '');
        chmod($target, 0600);
        self::assertSame(70, $this->runPin()['exit']);
        unlink($target);

        file_put_contents($target, str_repeat('x', 1_048_577));
        chmod($target, 0600);
        self::assertSame(70, $this->runPin()['exit']);
        unlink($target);

        file_put_contents($target, "source\n");
        chmod($target, 0644);
        self::assertSame(70, $this->runPin()['exit']);
        chmod($target, 0600);
        link($target, $this->runRoot . '/target-hardlink');
        self::assertSame(70, $this->runPin()['exit']);
        unlink($this->runRoot . '/target-hardlink');
        chown($target, 65534);
        self::assertSame(70, $this->runPin()['exit']);
    }

    public function testInvalidArgvRejectsWithoutTouchingProtectedRoots(): void
    {
        $result = $this->runCommand(['/usr/bin/python3', '-I', '-B', $this->helper, 'bad', self::RUN_ID]);
        self::assertSame(70, $result['exit']);
        self::assertFileDoesNotExist($this->runRoot . '/deploy-timing.jsonl');
    }

    public function testUnsafeProtectedRootDirectoriesRejectBeforePublication(): void
    {
        $this->writeSource("source\n");
        chmod(self::TIMING_ROOT, 0755);
        self::assertSame(70, $this->runPin()['exit']);
        chmod(self::TIMING_ROOT, 0700);
        chmod(self::STATE_ROOT . '/runs', 0755);
        self::assertSame(70, $this->runPin()['exit']);
        self::assertFileDoesNotExist($this->runRoot . '/deploy-timing.jsonl');
    }

    private function writeSource(string $bytes): void
    {
        $path = self::TIMING_ROOT . '/' . self::TIMING_ID . '.jsonl';
        file_put_contents($path, $bytes);
        chmod($path, 0600);
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private function runPin(): array
    {
        return $this->runCommand(['/usr/bin/python3', '-I', '-B', $this->helper, self::TIMING_ID, self::RUN_ID]);
    }

    /** @return array{resource,array<int,resource>} */
    private function startPin(): array
    {
        $command = array_map('escapeshellarg', [
            '/usr/bin/python3',
            '-I',
            '-B',
            $this->helper,
            self::TIMING_ID,
            self::RUN_ID,
        ]);
        $pipes = [];
        $process = proc_open(implode(' ', $command), [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        return [$process, $pipes];
    }

    /** @return array{resource,array{stdout:resource,stderr:resource}} */
    private function startPinWithFileOutput(): array
    {
        $command = array_map('escapeshellarg', [
            '/usr/bin/python3',
            '-I',
            '-B',
            $this->helper,
            self::TIMING_ID,
            self::RUN_ID,
        ]);
        $stdout = tmpfile();
        $stderr = tmpfile();
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);
        $process = proc_open(implode(' ', $command), [['file', '/dev/null', 'r'], $stdout, $stderr], $pipes);
        self::assertIsResource($process);

        return [$process, ['stdout' => $stdout, 'stderr' => $stderr]];
    }

    /**
     * @param resource $process
     * @param array{stdout:resource,stderr:resource} $output
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function finishWithFileOutput($process, array $output): array
    {
        $exit = proc_close($process);
        rewind($output['stdout']);
        rewind($output['stderr']);
        $stdout = stream_get_contents($output['stdout']);
        $stderr = stream_get_contents($output['stderr']);
        fclose($output['stdout']);
        fclose($output['stderr']);

        return [
            'exit' => $exit,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    /** @return array{resource,array<int,resource>} */
    private function startDirectoryLocker(): array
    {
        $code =
            'import fcntl,os,sys; fd=os.open(sys.argv[1],os.O_RDONLY|os.O_DIRECTORY); fcntl.flock(fd,fcntl.LOCK_EX); print("READY",flush=True); sys.stdin.readline()';
        $pipes = [];
        $process = proc_open(
            implode(' ', array_map('escapeshellarg', ['/usr/bin/python3', '-I', '-B', '-c', $code, $this->runRoot])),
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        return [$process, $pipes];
    }

    private function assertRunLockReleased(): void
    {
        $code =
            'import fcntl,os,sys; fd=os.open(sys.argv[1],os.O_RDONLY|os.O_DIRECTORY); fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)';
        $result = $this->runCommand(['/usr/bin/python3', '-I', '-B', '-c', $code, $this->runRoot]);
        self::assertSame(0, $result['exit'], $result['stderr']);
    }

    /** @param resource $process @param array<int,resource> $pipes @return array{exit:int,stdout:string,stderr:string} */
    private function finish($process, array $pipes): array
    {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @param list<string> $command @return array{exit:int,stdout:string,stderr:string} */
    private function runCommand(array $command): array
    {
        [$process, $pipes] = $this->startCommand($command);
        return $this->finish($process, $pipes);
    }

    /** @param list<string> $command @return array{resource,array<int,resource>} */
    private function startCommand(array $command): array
    {
        $pipes = [];
        $process = proc_open(
            implode(' ', array_map('escapeshellarg', $command)),
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        return [$process, $pipes];
    }

    /** @param array{stdout:string} $result @return array<string,mixed> */
    private function decode(array $result): array
    {
        return json_decode($result['stdout'], true, 16, JSON_THROW_ON_ERROR);
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $leaf) {
                if ($leaf !== '.' && $leaf !== '..') {
                    $this->removeTree($path . '/' . $leaf);
                }
            }
            rmdir($path);
            return;
        }
        unlink($path);
    }
}
