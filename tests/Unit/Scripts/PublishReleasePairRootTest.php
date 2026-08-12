<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class PublishReleasePairRootTest extends TestCase
{
    private string $helper;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || posix_geteuid() !== 0) {
            $this->markTestSkipped('Linux root is required for the production release-root contract.');
        }
        if (file_exists('/root/releases') || is_link('/root/releases')) {
            $this->markTestSkipped('/root/releases already exists; the root test will not mutate it.');
        }
        $this->helper = dirname(__DIR__, 3) . '/scripts/ops/libexec/publish_release_pair_v1.py';
    }

    protected function tearDown(): void
    {
        if (isset($this->helper) && is_link('/root/releases')) {
            unlink('/root/releases');
        }
        if (isset($this->helper) && is_dir('/root/releases')) {
            foreach (scandir('/root/releases') ?: [] as $leaf) {
                if ($leaf !== '.' && $leaf !== '..') {
                    unlink('/root/releases/' . $leaf);
                }
            }
            rmdir('/root/releases');
        }
    }

    public function testPrepareCreatesExactRootDirectoryWithoutNormalizingUnsafeExistingMode(): void
    {
        $created = $this->prepare();
        self::assertSame(0, $created['exit']);
        self::assertSame("ready\n", $created['stdout']);
        $metadata = lstat('/root/releases');
        self::assertIsArray($metadata);
        self::assertSame(0, $metadata['uid']);
        self::assertSame(0700, $metadata['mode'] & 0777);

        chmod('/root/releases', 0777);
        $rejected = $this->prepare();
        self::assertSame(70, $rejected['exit']);
        self::assertSame(0777, (lstat('/root/releases')['mode'] ?? 0) & 0777);
    }

    public function testPrepareRejectsSymlinkWithoutFollowingOrReplacingIt(): void
    {
        symlink('/tmp', '/root/releases');
        $result = $this->prepare();
        self::assertSame(70, $result['exit']);
        self::assertTrue(is_link('/root/releases'));
        self::assertSame('/tmp', readlink('/root/releases'));
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private function prepare(): array
    {
        $arguments = ['/usr/bin/python3', '-I', '-B', $this->helper, '--prepare', '/root/releases'];
        $pipes = [];
        $process = proc_open(
            implode(' ', array_map('escapeshellarg', $arguments)),
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
