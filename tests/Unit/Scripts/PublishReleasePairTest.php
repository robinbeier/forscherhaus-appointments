<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class PublishReleasePairTest extends TestCase
{
    private string $root;
    private string $helper;

    protected function setUp(): void
    {
        $this->root = '/tmp/release-pair-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0700));
        $this->helper = dirname(__DIR__, 3) . '/scripts/ops/libexec/publish_release_pair_v1.py';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*', GLOB_NOSORT) ?: [] as $path) {
            unlink($path);
        }
        foreach (glob($this->root . '/.*', GLOB_NOSORT) ?: [] as $path) {
            if (!in_array(basename($path), ['.', '..'], true)) {
                is_dir($path) ? rmdir($path) : unlink($path);
            }
        }
        rmdir($this->root);
    }

    public function testPublishesArchiveFirstAndSidecarLastThenAttachesExactRetry(): void
    {
        [$arguments, $archive, $sidecar] = $this->pair('archive', "sidecar\n");
        $first = $this->runHelper($arguments);
        self::assertSame(0, $first['exit']);
        self::assertSame("published:published\n", $first['stdout']);
        self::assertSame('archive', file_get_contents($this->root . '/ea_test.tar.gz'));
        self::assertSame("sidecar\n", file_get_contents($this->root . '/ea_test.build-provenance.json'));
        foreach (['ea_test.tar.gz', 'ea_test.build-provenance.json'] as $leaf) {
            $metadata = lstat($this->root . '/' . $leaf);
            self::assertIsArray($metadata);
            self::assertSame(posix_geteuid(), $metadata['uid']);
            self::assertSame(0600, $metadata['mode'] & 0777);
            self::assertSame(1, $metadata['nlink']);
        }

        file_put_contents($archive, 'archive');
        file_put_contents($sidecar, "sidecar\n");
        chmod($archive, 0600);
        chmod($sidecar, 0600);
        $second = $this->runHelper($arguments);
        self::assertSame(0, $second['exit']);
        self::assertSame("attached:attached\n", $second['stdout']);
        self::assertSame([], glob($this->root . '/.*.upload-*'));
    }

    public function testDifferentExistingFinalRejectsWithoutOverwriteOrSidecarPublication(): void
    {
        [$arguments] = $this->pair('candidate', "sidecar\n");
        file_put_contents($this->root . '/ea_test.tar.gz', 'existing');
        chmod($this->root . '/ea_test.tar.gz', 0600);

        $result = $this->runHelper($arguments);
        self::assertSame(70, $result['exit']);
        self::assertSame('existing', file_get_contents($this->root . '/ea_test.tar.gz'));
        self::assertFileDoesNotExist($this->root . '/ea_test.build-provenance.json');
        self::assertSame([], glob($this->root . '/.*.upload-*'));
    }

    public function testConflictingSidecarRejectsBeforeArchivePublication(): void
    {
        [$arguments] = $this->pair('candidate', "new-sidecar\n");
        file_put_contents($this->root . '/ea_test.build-provenance.json', "old-sidecar\n");
        chmod($this->root . '/ea_test.build-provenance.json', 0600);

        $result = $this->runHelper($arguments);
        self::assertSame(70, $result['exit']);
        self::assertFileDoesNotExist($this->root . '/ea_test.tar.gz');
        self::assertSame("old-sidecar\n", file_get_contents($this->root . '/ea_test.build-provenance.json'));
    }

    public function testArchiveCanAttachBeforeSidecarIsPublishedOnRetry(): void
    {
        [$arguments, $archive] = $this->pair('archive', "sidecar\n");
        self::assertTrue(copy($archive, $this->root . '/ea_test.tar.gz'));
        chmod($this->root . '/ea_test.tar.gz', 0600);
        $result = $this->runHelper($arguments);
        self::assertSame(0, $result['exit']);
        self::assertSame("attached:published\n", $result['stdout']);
    }

    public function testConcurrentExactPairsSerializeAsPublishedThenAttached(): void
    {
        [$first] = $this->pair('archive', "sidecar\n", str_repeat('a', 32));
        [$second] = $this->pair('archive', "sidecar\n", str_repeat('b', 32));
        [$locker, $lockerPipes] = $this->startDirectoryLocker();
        self::assertSame("READY\n", fgets($lockerPipes[1]));
        [$firstProcess, $firstPipes] = $this->start($first);
        [$secondProcess, $secondPipes] = $this->start($second);
        usleep(100_000);
        $firstStatus = proc_get_status($firstProcess);
        $secondStatus = proc_get_status($secondProcess);
        self::assertTrue($firstStatus['running'], json_encode($firstStatus, JSON_THROW_ON_ERROR));
        self::assertTrue($secondStatus['running'], json_encode($secondStatus, JSON_THROW_ON_ERROR));
        fwrite($lockerPipes[0], "release\n");
        foreach ($lockerPipes as $pipe) {
            fclose($pipe);
        }
        self::assertSame(0, proc_close($locker));

        $results = [$this->finish($firstProcess, $firstPipes), $this->finish($secondProcess, $secondPipes)];
        self::assertSame([0, 0], [$results[0]['exit'], $results[1]['exit']]);
        $statuses = [$results[0]['stdout'], $results[1]['stdout']];
        sort($statuses);
        self::assertSame(["attached:attached\n", "published:published\n"], $statuses);
        self::assertSame([], glob($this->root . '/.*.upload-*'));
    }

    public function testConcurrentDifferentPairsProduceOneCoherentWinner(): void
    {
        [$first] = $this->pair('archive-a', "sidecar-a\n", str_repeat('c', 32));
        [$second] = $this->pair('archive-b', "sidecar-b\n", str_repeat('d', 32));
        [$locker, $lockerPipes] = $this->startDirectoryLocker();
        self::assertSame("READY\n", fgets($lockerPipes[1]));
        [$firstProcess, $firstPipes] = $this->start($first);
        [$secondProcess, $secondPipes] = $this->start($second);
        usleep(100_000);
        $firstStatus = proc_get_status($firstProcess);
        $secondStatus = proc_get_status($secondProcess);
        self::assertTrue($firstStatus['running'], json_encode($firstStatus, JSON_THROW_ON_ERROR));
        self::assertTrue($secondStatus['running'], json_encode($secondStatus, JSON_THROW_ON_ERROR));
        fwrite($lockerPipes[0], "release\n");
        foreach ($lockerPipes as $pipe) {
            fclose($pipe);
        }
        self::assertSame(0, proc_close($locker));
        $results = [$this->finish($firstProcess, $firstPipes), $this->finish($secondProcess, $secondPipes)];
        $exits = [$results[0]['exit'], $results[1]['exit']];
        sort($exits);
        self::assertSame([0, 70], $exits);
        $archive = file_get_contents($this->root . '/ea_test.tar.gz');
        $sidecar = file_get_contents($this->root . '/ea_test.build-provenance.json');
        self::assertContains([$archive, $sidecar], [['archive-a', "sidecar-a\n"], ['archive-b', "sidecar-b\n"]]);
        self::assertSame([], glob($this->root . '/.*.upload-*'));
    }

    public function testUnsafeTemporaryLeafOrDigestMismatchRejects(): void
    {
        [$arguments, $archive] = $this->pair('archive', "sidecar\n");
        chmod($archive, 0644);
        self::assertSame(70, $this->runHelper($arguments)['exit']);

        [$changed, $archive] = $this->pair('archive', "sidecar\n");
        $changed[7] = str_repeat('0', 64);
        self::assertSame(70, $this->runHelper($changed)['exit']);
    }

    public function testUnsafeTemporaryAndExistingLeafKindsReject(): void
    {
        foreach (['symlink', 'fifo', 'directory', 'hardlink'] as $kind) {
            [$arguments, $archive] = $this->pair('archive', "sidecar\n");
            unlink($archive);
            if ($kind === 'symlink') {
                symlink('/dev/null', $archive);
            }
            if ($kind === 'fifo') {
                posix_mkfifo($archive, 0600);
            }
            if ($kind === 'directory') {
                mkdir($archive, 0600);
            }
            if ($kind === 'hardlink') {
                file_put_contents($archive, 'archive');
                chmod($archive, 0600);
                link($archive, $archive . '.link');
            }
            self::assertSame(70, $this->runHelper($arguments)['exit'], $kind);
            $this->removePath($archive);
            $this->removePath($archive . '.link');
            self::assertFileDoesNotExist(
                $this->root . '/.ea_test.build-provenance.json.upload-' . str_repeat('a', 32),
                $kind,
            );
        }

        [$arguments] = $this->pair('archive', "sidecar\n");
        $final = $this->root . '/ea_test.tar.gz';
        symlink('/dev/null', $final);
        self::assertSame(70, $this->runHelper($arguments)['exit']);
        self::assertTrue(is_link($final));
    }

    public function testUnsafeRootModeAndZeroLengthCandidateReject(): void
    {
        [$arguments] = $this->pair('archive', "sidecar\n");
        chmod($this->root, 0755);
        self::assertSame(70, $this->runHelper($arguments)['exit']);
        chmod($this->root, 0700);

        [$arguments] = $this->pair('archive', "sidecar\n");
        $arguments[8] = '0';
        self::assertSame(70, $this->runHelper($arguments)['exit']);
    }

    /** @return array{list<string>,string,string} */
    private function pair(string $archiveBytes, string $sidecarBytes, ?string $nonce = null): array
    {
        $nonce ??= str_repeat('a', 32);
        $archive = $this->root . '/.ea_test.tar.gz.upload-' . $nonce;
        $sidecar = $this->root . '/.ea_test.build-provenance.json.upload-' . $nonce;
        file_put_contents($archive, $archiveBytes);
        file_put_contents($sidecar, $sidecarBytes);
        chmod($archive, 0600);
        chmod($sidecar, 0600);
        return [
            [
                '/usr/bin/python3',
                '-I',
                '-B',
                $this->helper,
                $this->root,
                'ea_test',
                $nonce,
                hash('sha256', $archiveBytes),
                (string) strlen($archiveBytes),
                hash('sha256', $sidecarBytes),
                (string) strlen($sidecarBytes),
            ],
            $archive,
            $sidecar,
        ];
    }

    /** @param list<string> $arguments @return array{exit:int,stdout:string,stderr:string} */
    private function runHelper(array $arguments): array
    {
        [$process, $pipes] = $this->start($arguments);
        return $this->finish($process, $pipes);
    }

    /** @param list<string> $arguments @return array{resource,array<int,resource>} */
    private function start(array $arguments): array
    {
        $pipes = [];
        $process = proc_open(
            implode(' ', array_map('escapeshellarg', $arguments)),
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        return [$process, $pipes];
    }

    /** @return array{resource,array<int,resource>} */
    private function startDirectoryLocker(): array
    {
        $program = <<<'PY'
        import fcntl, os, sys
        fd = os.open(sys.argv[1] + "/.release-pair.lock", os.O_RDWR | os.O_CREAT, 0o600)
        fcntl.flock(fd, fcntl.LOCK_EX)
        sys.stdout.write("READY\n"); sys.stdout.flush()
        sys.stdin.readline()
        os.close(fd)
        PY;
        $arguments = ['/usr/bin/python3', '-I', '-B', '-c', $program, $this->root];
        $pipes = [];
        $process = proc_open(
            implode(' ', array_map('escapeshellarg', $arguments)),
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        return [$process, $pipes];
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

    private function removePath(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        is_dir($path) && !is_link($path) ? rmdir($path) : unlink($path);
    }
}
