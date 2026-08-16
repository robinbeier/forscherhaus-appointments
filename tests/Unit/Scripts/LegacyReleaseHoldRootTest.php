<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class LegacyReleaseHoldRootTest extends TestCase
{
    private const APP = '/var/www/html/easyappointments';
    private const ROLLBACK = '/var/www/html/easyappointments_prev_current';
    private const RELEASES = '/root/releases';
    private const ETC_FH = '/etc/fh';
    private const HOLD = '/etc/fh/legacy-release-hold.v1.json';
    private const ORCHESTRATOR = '/var/lib/fh-deploy-orchestrator';
    private string $helper;

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY !== 'Linux' || posix_geteuid() !== 0) {
            $this->markTestSkipped('Linux root is required for the ROB-470 hold contract.');
        }
        if (is_file('/var/www/html/composer.json') && file_exists('/var/www/html/.git')) {
            $this->markTestSkipped(
                'The general Docker suite mounts source at the production web root; use the dedicated sudo root gate.',
            );
        }
        foreach ([self::APP, self::ROLLBACK, self::RELEASES, self::ETC_FH, self::HOLD, self::ORCHESTRATOR] as $path) {
            if (file_exists($path) || is_link($path)) {
                $this->markTestSkipped(
                    'A protected ROB-470 test root already exists; the root gate will not mutate it.',
                );
            }
        }
        $this->helper = dirname(__DIR__, 3) . '/scripts/ops/libexec/legacy_release_hold_v1.py';
        $this->prepareFixture();
    }

    protected function tearDown(): void
    {
        if (isset($this->helper)) {
            foreach ([self::APP, self::ROLLBACK, self::RELEASES, self::ORCHESTRATOR] as $path) {
                $this->removeTree($path);
            }
            @unlink(self::HOLD);
            @rmdir(self::ETC_FH);
        }
        parent::tearDown();
    }

    public function testInspectProvisionAndReplayStayClosedAndCanonical(): void
    {
        $inspect = $this->runHelper();
        self::assertSame(0, $inspect['exit'], $inspect['stdout'] . $inspect['stderr']);
        $initial = $this->decode($inspect);
        self::assertFalse($initial['attached']);
        self::assertTrue($initial['pending']);
        self::assertSame(2, $initial['targets_preflighted']);
        self::assertSame('none', $initial['mutation_outcome']);

        $provision = $this->runHelper('provision', 'ROB-470-HOLD');
        self::assertSame(0, $provision['exit'], $provision['stdout'] . $provision['stderr']);
        $published = $this->decode($provision);
        self::assertSame('known', $published['mutation_outcome']);
        self::assertSame(1, $published['mutation_counts']['hold_published']);
        self::assertFileExists(self::HOLD);
        $metadata = lstat(self::HOLD);
        self::assertIsArray($metadata);
        self::assertSame(0, $metadata['uid']);
        self::assertSame(0600, $metadata['mode'] & 0777);
        self::assertSame(1, $metadata['nlink']);
        $hold = json_decode((string) file_get_contents(self::HOLD), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('legacy_release_hold.v1', $hold['schema']);
        self::assertSame(['current', 'rollback'], array_column($hold['targets'], 'role_at_provisioning'));
        self::assertArrayNotHasKey('commit', $hold);

        $after = $this->runHelper();
        self::assertSame(0, $after['exit'], $after['stdout'] . $after['stderr']);
        self::assertTrue($this->decode($after)['attached']);
        self::assertFalse($this->decode($after)['pending']);
        self::assertSame(2, $this->decode($after)['targets_preflighted']);

        $replay = $this->runHelper('provision', 'ROB-470-HOLD');
        self::assertSame(0, $replay['exit'], $replay['stdout'] . $replay['stderr']);
        $replayed = $this->decode($replay);
        self::assertSame('none', $replayed['mutation_outcome']);
        self::assertSame(0, $replayed['mutation_counts']['hold_published']);
    }

    public function testBusyLockAndConflictingExistingHoldFailClosed(): void
    {
        file_put_contents(
            self::HOLD,
            $this->canonical([
                'schema' => 'legacy_release_hold.v1',
                'targets' => [
                    [
                        'archive' => ['name' => 'other.tar.gz', 'sha256' => str_repeat('a', 64), 'size_bytes' => 1],
                        'capacity_bounds' => [
                            'stage_file_count' => 1,
                            'stage_inode_count' => 1,
                            'stage_unpacked_bytes' => 1,
                            'temp_scratch_bytes' => 67108864,
                        ],
                        'release_id' => 'other',
                        'role_at_provisioning' => 'current',
                    ],
                    [
                        'archive' => [
                            'name' => 'other-rollback.tar.gz',
                            'sha256' => str_repeat('b', 64),
                            'size_bytes' => 1,
                        ],
                        'capacity_bounds' => [
                            'stage_file_count' => 1,
                            'stage_inode_count' => 1,
                            'stage_unpacked_bytes' => 1,
                            'temp_scratch_bytes' => 67108864,
                        ],
                        'release_id' => 'other-rollback',
                        'role_at_provisioning' => 'rollback',
                    ],
                ],
            ]),
        );
        chmod(self::HOLD, 0600);
        $conflict = $this->runHelper('provision', 'ROB-470-HOLD');
        self::assertSame(70, $conflict['exit']);
        self::assertSame('hold_conflict', $this->decode($conflict)['reason']);
        unlink(self::HOLD);

        $lock = fopen(self::ORCHESTRATOR . '/locks/fh-production-change.lock', 'r+b');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $busy = $this->runHelper('provision', 'ROB-470-HOLD');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        self::assertSame(75, $busy['exit']);
        self::assertSame('lock_busy', $this->decode($busy)['reason']);
        self::assertFileDoesNotExist(self::HOLD);
    }

    private function prepareFixture(): void
    {
        mkdir(self::APP, 0755, true);
        file_put_contents(self::APP . '/_RELEASE', "current commit\n");
        chmod(self::APP . '/_RELEASE', 0644);

        mkdir(self::ROLLBACK, 0755, true);
        file_put_contents(self::ROLLBACK . '/_RELEASE', "rollback commit\n");
        chmod(self::ROLLBACK . '/_RELEASE', 0644);

        mkdir(self::RELEASES, 0700, true);
        $this->writeArchive(self::RELEASES . '/current.tar.gz', ['app/config/settings.json' => "x\n"]);
        $this->writeArchive(self::RELEASES . '/rollback.tar.gz', ['app/public/index.php' => "<?php\n"]);

        mkdir(self::ORCHESTRATOR . '/locks', 0700, true);
        touch(self::ORCHESTRATOR . '/locks/fh-production-change.lock');
        chmod(self::ORCHESTRATOR . '/locks/fh-production-change.lock', 0600);
        touch(self::RELEASES . '/.release-pair.lock');
        chmod(self::RELEASES . '/.release-pair.lock', 0600);

        mkdir(self::ETC_FH, 0700, true);
    }

    /** @param array<string,string> $entries */
    private function writeArchive(string $path, array $entries): void
    {
        $script = <<<'PY'
        import io, json, sys, tarfile
        path = sys.argv[1]
        entries = json.loads(sys.argv[2])
        with tarfile.open(path, mode='w:gz') as archive:
            for name, content in entries.items():
                data = content.encode('utf-8')
                info = tarfile.TarInfo(name)
                info.size = len(data)
                archive.addfile(info, io.BytesIO(data))
        PY;
        $process = proc_open(
            ['/usr/bin/python3', '-c', $script, $path, json_encode($entries, JSON_THROW_ON_ERROR)],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $stdout . (string) $stderr);
        chmod($path, 0600);
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private function runHelper(string ...$arguments): array
    {
        $process = proc_open(
            array_merge(['/usr/bin/python3', '-I', '-B', $this->helper], $arguments),
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($process), 'stdout' => $stdout ?: '', 'stderr' => $stderr ?: ''];
    }

    /** @param array{stdout:string} $result @return array<string,mixed> */
    private function decode(array $result): array
    {
        $value = json_decode(trim($result['stdout']), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($value);
        return $value;
    }

    /** @param array<string,mixed> $value */
    private function canonical(array $value): string
    {
        ksort($value);
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
