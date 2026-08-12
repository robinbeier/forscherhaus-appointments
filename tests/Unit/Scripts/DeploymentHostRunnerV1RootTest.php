<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use Ops\DeploymentHostRunnerContractV1;
use Ops\DeploymentHostRunnerV1;
use Ops\HelperBackedHostRunnerSystemAdapter;
use RuntimeException;

require_once __DIR__ . '/../../../scripts/ops/lib/DeploymentContractV1.php';
require_once __DIR__ . '/../../../scripts/ops/lib/DeployResultV1.php';
require_once __DIR__ . '/../../../scripts/ops/lib/DeploymentHostRunnerContractV1.php';
require_once __DIR__ . '/../../../scripts/ops/lib/DeploymentHostRunnerV1.php';

final class DeploymentHostRunnerV1RootTest extends TestCase
{
    private const RUN_ID = '018f6f52-4c87-4d4e-8b19-6a66e6e1af25';
    private const OTHER_RUN_ID = '028f6f52-4c87-4d4e-8b19-6a66e6e1af25';

    private string $root;
    private string $helper;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Host-runner storage requires Linux root.');
        }
        if (!is_executable('/usr/bin/python3')) {
            self::fail('The documented target Python runtime is unavailable in the root gate.');
        }
        if (!is_executable('/usr/bin/php')) {
            self::fail('The documented target PHP CLI runtime is unavailable in the root gate.');
        }

        $this->root = '/root/fh-host-runner-core-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0700));
        self::assertTrue(chmod($this->root, 0700));
        $this->helper = dirname(__DIR__, 3) . '/scripts/ops/libexec/deployment_host_runner_fs_v1.py';
    }

    protected function tearDown(): void
    {
        if (!isset($this->root) || !is_dir($this->root)) {
            return;
        }
        $this->removeTree($this->root);
    }

    public function testBoundedReadUsesOneProtectedRegularFileAndReturnsExactBytes(): void
    {
        $path = $this->root . '/request.json';
        self::assertSame(8, file_put_contents($path, "{\"a\":1}\n"));
        self::assertTrue(chmod($path, 0600));

        $result = $this->runHelper(['read', $this->root, 'request.json', '8']);

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame("{\"a\":1}\n", $result['stdout']);
        self::assertSame('', $result['stderr']);

        self::assertSame(64, file_put_contents($path, str_repeat('x', 64)));
        self::assertSame(0, $this->runHelper(['read', $this->root, 'request.json', '64'])['exit_code']);
        self::assertSame(65, file_put_contents($path, str_repeat('x', 65)));
        $oversized = $this->runHelper(['read', $this->root, 'request.json', '64']);
        self::assertSame(70, $oversized['exit_code']);
        self::assertSame('', $oversized['stdout']);
    }

    public function testBoundedReadRejectsEveryUnsafeLeafWithoutFollowingOrBlocking(): void
    {
        $regular = $this->root . '/regular';
        self::assertSame(6, file_put_contents($regular, 'secret'));
        self::assertTrue(chmod($regular, 0600));
        self::assertTrue(link($regular, $this->root . '/hardlink'));
        self::assertTrue(symlink($regular, $this->root . '/symlink'));
        self::assertTrue(posix_mkfifo($this->root . '/fifo', 0600));

        foreach (['regular', 'hardlink', 'symlink', 'fifo'] as $leaf) {
            $started = microtime(true);
            $result = $this->runHelper(['read', $this->root, $leaf, '64']);
            self::assertSame(70, $result['exit_code'], $leaf . ': ' . $result['stderr']);
            self::assertSame('', $result['stdout']);
            self::assertLessThan(2.0, microtime(true) - $started, $leaf . ' read blocked');
            self::assertMatchesRegularExpression('/^host-runner storage rejected\n$/D', $result['stderr']);
        }
    }

    public function testNoReplacePinAndCowPublishExactModeAndNeverClobberPinnedBytes(): void
    {
        $first = $this->runHelper(['pin', $this->root, 'intent.json', '64'], "first\n");
        self::assertSame(0, $first['exit_code'], $first['stderr']);
        $retry = $this->runHelper(['pin', $this->root, 'intent.json', '64'], "changed\n");
        self::assertSame(75, $retry['exit_code']);
        $exactRetry = $this->runHelper(['pin', $this->root, 'intent.json', '64'], "first\n");
        self::assertSame(0, $exactRetry['exit_code'], $exactRetry['stderr']);
        self::assertSame("first\n", file_get_contents($this->root . '/intent.json'));

        $cow = $this->runHelper(['cow', $this->root, 'state.json', '64'], "state-1\n");
        self::assertSame(0, $cow['exit_code'], $cow['stderr']);
        $cow = $this->runHelper(['cow', $this->root, 'state.json', '64'], "state-2\n");
        self::assertSame(0, $cow['exit_code'], $cow['stderr']);
        self::assertSame("state-2\n", file_get_contents($this->root . '/state.json'));

        foreach (['intent.json', 'state.json'] as $leaf) {
            $stat = lstat($this->root . '/' . $leaf);
            self::assertIsArray($stat);
            self::assertSame(0, $stat['uid']);
            self::assertSame(0600, $stat['mode'] & 07777);
            self::assertSame(1, $stat['nlink']);
        }
        self::assertSame([], glob($this->root . '/.*.tmp-*') ?: []);

        foreach (['pin', 'cow'] as $operation) {
            $oversized = $this->runHelper([$operation, $this->root, $operation . '-large', '64'], str_repeat('x', 65));
            self::assertSame(70, $oversized['exit_code']);
            self::assertFileDoesNotExist($this->root . '/' . $operation . '-large');
        }
    }

    public function testPinnedReferencesStreamExactBytesAndNeverExposeSourceAuthority(): void
    {
        self::assertTrue(mkdir($this->root . '/runs', 0700));
        self::assertSame(0, $this->runHelper(['prepare-run', $this->root, self::RUN_ID])['exit_code']);
        $source = $this->root . '/source-secret';
        $bytes = "fixed secret\n";
        self::assertSame(strlen($bytes), file_put_contents($source, $bytes));
        self::assertTrue(chmod($source, 0600));
        $payload = json_encode(['source_path' => $source, 'sha256' => hash('sha256', $bytes)], JSON_THROW_ON_ERROR);
        $result = $this->runHelper(['pin-reference', $this->root, self::RUN_ID, 'healthz-token'], $payload);
        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame('', $result['stdout']);
        self::assertSame('', $result['stderr']);
        $target = $this->root . '/runs/' . self::RUN_ID . '/deploy-ref-healthz-token';
        self::assertSame($bytes, file_get_contents($target));
        self::assertSame(0600, fileperms($target) & 07777);
        self::assertSame(0, $this->runHelper(['pin-reference', $this->root, self::RUN_ID, 'healthz-token'], $payload)['exit_code']);

        self::assertSame(strlen($bytes), file_put_contents($source, "changed data\n"));
        self::assertTrue(chmod($source, 0600));
        $changed = json_encode(['source_path' => $source, 'sha256' => hash('sha256', "changed data\n")], JSON_THROW_ON_ERROR);
        self::assertSame(75, $this->runHelper(['pin-reference', $this->root, self::RUN_ID, 'healthz-token'], $changed)['exit_code']);
        self::assertSame($bytes, file_get_contents($target));

        $wrongHash = json_encode(['source_path' => $source, 'sha256' => str_repeat('0', 64)], JSON_THROW_ON_ERROR);
        self::assertSame(70, $this->runHelper(['pin-reference', $this->root, self::RUN_ID, 'canary-credentials'], $wrongHash)['exit_code']);
        self::assertFileDoesNotExist($this->root . '/runs/' . self::RUN_ID . '/deploy-ref-canary-credentials');

        $oversized = $this->root . '/oversized';
        $handle = fopen($oversized, 'w');
        self::assertIsResource($handle);
        self::assertTrue(ftruncate($handle, 1_048_577));
        fclose($handle);
        self::assertTrue(chmod($oversized, 0600));
        $oversizedPayload = json_encode(['source_path' => $oversized, 'sha256' => str_repeat('0', 64)], JSON_THROW_ON_ERROR);
        self::assertSame(70, $this->runHelper(['pin-reference', $this->root, self::RUN_ID, 'incident-webhook'], $oversizedPayload)['exit_code']);

        self::assertTrue(symlink($source, $this->root . '/source-link'));
        $symlinkPayload = json_encode(['source_path' => $this->root . '/source-link', 'sha256' => hash('sha256', "changed data\n")], JSON_THROW_ON_ERROR);
        self::assertSame(70, $this->runHelper(['pin-reference', $this->root, self::RUN_ID, 'predeploy-credentials'], $symlinkPayload)['exit_code']);

        $literal = $this->root . '/${FH_HEALTHZ_TOKEN}.token';
        self::assertTrue(mkdir($this->root . '/runs/' . self::OTHER_RUN_ID, 0700));
        self::assertSame(strlen($bytes), file_put_contents($literal, $bytes));
        self::assertTrue(chmod($literal, 0600));
        $literalPayload = json_encode(['source_path' => $literal, 'sha256' => hash('sha256', $bytes)], JSON_THROW_ON_ERROR);
        self::assertSame(0, $this->runHelper(['pin-reference', $this->root, self::OTHER_RUN_ID, 'healthz-token'], $literalPayload)['exit_code']);
    }

    public function testPinAndCowRejectUnsafeExistingLeavesWithoutTouchingTheirReferents(): void
    {
        $referent = $this->root . '/referent';
        self::assertSame(6, file_put_contents($referent, 'secret'));
        self::assertTrue(chmod($referent, 0600));
        self::assertTrue(symlink($referent, $this->root . '/symlink'));
        self::assertTrue(posix_mkfifo($this->root . '/fifo', 0600));
        self::assertTrue(mkdir($this->root . '/directory', 0700));
        $wrongMode = $this->root . '/wrong-mode';
        self::assertSame(3, file_put_contents($wrongMode, 'old'));
        self::assertTrue(chmod($wrongMode, 0640));

        foreach (['symlink', 'fifo', 'directory', 'wrong-mode'] as $leaf) {
            foreach (['pin', 'cow'] as $operation) {
                $result = $this->runHelper([$operation, $this->root, $leaf, '64'], "changed\n");
                self::assertSame(70, $result['exit_code'], $operation . ':' . $leaf);
                self::assertSame('secret', file_get_contents($referent));
            }
        }
        self::assertSame('old', file_get_contents($wrongMode));
    }

    public function testConcurrentNoReplacePinHasOneWinnerAndNoTemporaryLeak(): void
    {
        $command = ['/usr/bin/env', '-i', 'LANG=C', 'LC_ALL=C', 'PATH=/usr/sbin:/usr/bin:/sbin:/bin', '/usr/bin/python3', '-I', '-B', $this->helper, 'pin', $this->root, 'winner', '64'];
        $processes = [];
        foreach (["one\n", "two\n"] as $value) {
            $pipes = [];
            $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, []);
            self::assertIsResource($process);
            fwrite($pipes[0], $value);
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }
        $exits = [];
        foreach ($processes as [$process, $pipes]) {
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exits[] = proc_close($process);
        }
        sort($exits);
        self::assertSame([0, 75], $exits);
        self::assertContains(file_get_contents($this->root . '/winner'), ["one\n", "two\n"]);
        self::assertSame([], glob($this->root . '/.*.tmp-*') ?: []);
    }

    public function testKernelCapabilitiesRequiredByTheHelperExistOnTargetLinux(): void
    {
        $script = <<<'PYTHON'
import os
import ctypes
required = ['O_CLOEXEC', 'O_DIRECTORY', 'O_NOFOLLOW', 'O_NONBLOCK']
assert all(hasattr(os, name) for name in required)
assert os.open in os.supports_dir_fd
assert os.stat in os.supports_dir_fd
assert os.unlink in os.supports_dir_fd
assert os.stat in os.supports_follow_symlinks
assert getattr(ctypes.CDLL(None), 'renameat2', None) is not None
PYTHON;
        $result = $this->runCommand(['/usr/bin/python3', '-I', '-B', '-c', $script]);
        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame('', $result['stdout']);
        self::assertSame('', $result['stderr']);
    }

    public function testBootReaderReturnsExactCanonicalCurrentLinuxBootId(): void
    {
        $expected = file_get_contents('/proc/sys/kernel/random/boot_id');
        self::assertIsString($expected);
        $result = $this->runHelper(['read-boot-id']);
        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame($expected, $result['stdout']);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}\n$/D', $result['stdout']);
    }

    public function testRunScanPaginatesBeyondHistoricalDirectoryCapWithStableCursors(): void
    {
        self::assertTrue(mkdir($this->root . '/runs', 0700));
        $expected = [];
        for ($index = 0; $index < 257; $index++) {
            $runId = sprintf('018f6f52-4c87-4d4e-8b19-%012x', $index);
            self::assertTrue(mkdir($this->root . '/runs/' . $runId, 0700));
            $expected[] = $runId;
        }
        $seen = [];
        $cursor = '-';
        do {
            $result = $this->runHelper(['scan-run-ids', $this->root, $cursor]);
            self::assertSame(0, $result['exit_code'], $result['stderr']);
            $page = json_decode($result['stdout'], true, 8, JSON_THROW_ON_ERROR);
            self::assertLessThanOrEqual(128, count($page['run_ids']));
            self::assertSame($page['run_ids'], array_values(array_unique($page['run_ids'])));
            self::assertSame($page['run_ids'], (static function (array $ids): array { sort($ids); return $ids; })($page['run_ids']));
            array_push($seen, ...$page['run_ids']);
            if ($page['next_cursor'] === null) { break; }
            self::assertSame($page['run_ids'][array_key_last($page['run_ids'])], $page['next_cursor']);
            self::assertNotSame($cursor, $page['next_cursor']);
            $cursor = $page['next_cursor'];
        } while (true);
        self::assertSame($expected, $seen);
        self::assertSame(257, count($seen));
    }

    public function testRunBundleScanReadsNearMaximumJournalThroughHeldRunDirectory(): void
    {
        self::assertTrue(mkdir($this->root . '/runs', 0700));
        self::assertSame(0, $this->runHelper(['prepare-run', $this->root, self::RUN_ID])['exit_code']);
        $events = str_repeat('x', 1_048_575) . "\n";
        $path = $this->root . '/runs/' . self::RUN_ID . '/events.jsonl';
        self::assertSame(strlen($events), file_put_contents($path, $events));
        self::assertTrue(chmod($path, 0600));
        $result = $this->runHelper(['scan-run-bundle', $this->root, self::RUN_ID]);
        self::assertSame(0, $result['exit_code'], $result['stderr']);
        $bundle = json_decode($result['stdout'], true, 8, JSON_THROW_ON_ERROR);
        self::assertSame(self::RUN_ID, $bundle['run_id']);
        self::assertSame($events, base64_decode($bundle['events_bytes'], true));
        self::assertNull($bundle['state_bytes']);

        self::assertTrue(unlink($path));
        self::assertTrue(symlink('/etc/passwd', $path));
        $unsafe = $this->runHelper(['scan-run-bundle', $this->root, self::RUN_ID]);
        self::assertSame(70, $unsafe['exit_code']);
        self::assertSame('', $unsafe['stdout']);
    }

    public function testProductionStorageScopeAndOperationMatrixIsClosedWithoutTouchingIt(): void
    {
        $root = DeploymentHostRunnerContractV1::STATE_ROOT;
        $run = 'runs/' . self::RUN_ID . '/';
        foreach ([
            ['read', $run . 'request.json'],
            ['pin', $run . 'request.json'],
            ['pin', $run . 'traffic-gate-report.json'],
            ['cow', $run . 'events.jsonl'],
            ['claim-refresh', 'active-run.json'],
        ] as [$operation, $relative]) {
            self::assertSame(0, $this->runHelper(['validate-storage-scope', $root, $relative, $operation])['exit_code']);
        }
        foreach ([
            ['cow', $run . 'request.json'],
            ['cow', $run . 'traffic-gate-report.json'],
            ['cow', 'active-run.json'],
            ['pin', $run . 'state.json'],
            ['cow', $run . 'run.lock'],
            ['pin', 'locks/fh-production-change.lock'],
            ['cow', '../escape'],
        ] as [$operation, $relative]) {
            $result = $this->runHelper(['validate-storage-scope', $root, $relative, $operation]);
            self::assertSame(70, $result['exit_code']);
            self::assertSame("host-runner storage rejected\n", $result['stderr']);
        }
    }

    public function testTrafficCollectorAttachesExactImmutableRunReportWithoutProducerExecution(): void
    {
        self::assertSame(0, $this->runHelper(['prepare-run', $this->root, self::RUN_ID])['exit_code']);
        $report = "{\"schema\":\"traffic_gate.v1\"}\n";
        $path = $this->root . '/runs/' . self::RUN_ID . '/traffic-gate-report.json';
        self::assertSame(strlen($report), file_put_contents($path, $report));
        self::assertTrue(chmod($path, 0600));

        $result = $this->runHelper(['collect-traffic', $this->root, self::RUN_ID, 'normal']);

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        $decoded = json_decode($result['stdout'], true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('attached', $decoded['status']);
        self::assertSame(base64_encode($report), $decoded['bytes_base64']);
        self::assertSame(hash('sha256', $report), $decoded['sha256']);
        self::assertSame('', $result['stderr']);

        self::assertSame(0, $this->runHelper(['prepare-run', $this->root, self::OTHER_RUN_ID])['exit_code']);
        $missing = $this->runHelper(['collect-traffic', $this->root, self::OTHER_RUN_ID, 'normal']);
        self::assertSame(70, $missing['exit_code']);
        self::assertFileDoesNotExist($this->root . '/runs/' . self::OTHER_RUN_ID . '/traffic-gate-report.json');
    }

    public function testDumpObserverHashesExactPinnedRunCopyBeforeReportingMissingAttestation(): void
    {
        self::assertSame(0, $this->runHelper(['prepare-run', $this->root, self::RUN_ID])['exit_code']);
        $dump = "CREATE TABLE exact_dump (id INT);\n";
        $sha = hash('sha256', $dump);
        $path = $this->root . '/runs/' . self::RUN_ID . '/deploy-ref-zero-surprise-dump.sql';
        self::assertSame(strlen($dump), file_put_contents($path, $dump));
        self::assertTrue(chmod($path, 0600));

        $result = $this->runHelper([
            'observe-dump', $this->root, self::RUN_ID, 'deploy-ref-zero-surprise-dump.sql', $sha,
        ]);

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        $decoded = json_decode($result['stdout'], true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('not_observed', $decoded['status']);
        self::assertSame($sha, $decoded['dump_sha256']);
        self::assertSame(strlen($dump), $decoded['dump_size_bytes']);
        self::assertNull($decoded['attestation_bytes_base64']);
        self::assertNull($decoded['attestation_sha256']);

        $changed = $this->runHelper([
            'observe-dump', $this->root, self::RUN_ID, 'deploy-ref-zero-surprise-dump.sql', str_repeat('a', 64),
        ]);
        self::assertSame(75, $changed['exit_code']);
        self::assertSame($dump, file_get_contents($path));
    }

    public function testBuildObserverBindsAuthorizedSidecarArchiveInventoryAndBothDeployScripts(): void
    {
        $release = 'ea_20260812';
        $stage = $this->root . '/stage';
        self::assertTrue(mkdir($stage, 0700));
        $deployScript = "#!/bin/bash\nexit 0\n";
        self::assertSame(strlen($deployScript), file_put_contents($stage . '/deploy_ea.sh', $deployScript));
        self::assertTrue(chmod($stage . '/deploy_ea.sh', 0755));
        self::assertSame(strlen($deployScript), file_put_contents($this->root . '/deploy_ea.sh', $deployScript));
        self::assertTrue(chmod($this->root . '/deploy_ea.sh', 0755));
        $archivePath = $this->root . '/' . $release . '.tar.gz';
        $tar = $this->runCommand(['/bin/tar', '-czf', $archivePath, '-C', $stage, '.']);
        self::assertSame(0, $tar['exit_code'], $tar['stderr']);
        self::assertTrue(chmod($archivePath, 0600));
        $inspection = $this->runCommand([
            '/usr/bin/python3', '-I', '-B', dirname(__DIR__, 3) . '/scripts/ops/libexec/inspect_release_archive_v1.py',
            $archivePath,
        ]);
        self::assertSame(0, $inspection['exit_code'], $inspection['stderr']);
        $archive = json_decode($inspection['stdout'], true, 16, JSON_THROW_ON_ERROR);
        $digest = hash('sha256', $deployScript);
        $provenance = json_encode([
            'archive' => [
                'name' => $release . '.tar.gz',
                'sha256' => $archive['archive_sha256'],
                'size_bytes' => $archive['archive_size_bytes'],
            ],
            'capacity_bounds' => [
                'stage_file_count' => $archive['entry_count'],
                'stage_inode_count' => $archive['stage_inode_count'],
                'stage_unpacked_bytes' => $archive['stage_unpacked_bytes'],
                'temp_scratch_bytes' => 67_108_864,
            ],
            'expected_commit' => str_repeat('a', 40),
            'observed_commit' => str_repeat('a', 40),
            'release_id' => $release,
            'schema' => 'release_build_provenance.v1',
            'source' => [
                'build_script_sha256' => str_repeat('b', 64),
                'composer_lock_sha256' => str_repeat('c', 64),
                'deploy_ea_sha256' => $digest,
                'package_lock_sha256' => str_repeat('d', 64),
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $sidecarPath = $this->root . '/' . $release . '.build-provenance.json';
        self::assertSame(strlen($provenance), file_put_contents($sidecarPath, $provenance));
        self::assertTrue(chmod($sidecarPath, 0600));
        $authorized = hash('sha256', $provenance);

        $result = $this->runHelper(['observe-build', $this->root, $release, $authorized]);

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        $decoded = json_decode($result['stdout'], true, 16, JSON_THROW_ON_ERROR);
        self::assertSame($authorized, $decoded['provenance_sha256']);
        self::assertSame(base64_encode($provenance), $decoded['provenance_bytes_base64']);
        self::assertSame($archive['archive_sha256'], $decoded['archive_sha256']);
        self::assertSame($archive['archive_size_bytes'], $decoded['archive_size_bytes']);
        self::assertSame($archive['entry_count'], $decoded['stage_file_count']);
        self::assertSame($archive['stage_inode_count'], $decoded['stage_inode_count']);
        self::assertSame($archive['stage_unpacked_bytes'], $decoded['stage_unpacked_bytes']);
        self::assertSame($digest, $decoded['host_deploy_script_sha256']);
        self::assertSame($digest, $decoded['artifact_deploy_script_sha256']);

        $wrongAuthority = $this->runHelper(['observe-build', $this->root, $release, str_repeat('e', 64)]);
        self::assertSame(75, $wrongAuthority['exit_code']);
        self::assertSame('', $wrongAuthority['stdout']);
    }

    public function testCapacityObserverMeasuresLogicalAndAllocatedLiveStorageFromOneFilesystem(): void
    {
        self::assertSame(0, $this->runHelper(['prepare-run', $this->root, self::RUN_ID])['exit_code']);
        foreach (['live-storage', 'live-storage/nested', 'renderer-state', 'restore-scratch', 'target'] as $relative) {
            self::assertTrue(mkdir($this->root . '/' . $relative, 0700));
            self::assertTrue(chmod($this->root . '/' . $relative, 0700));
        }
        $sparse = fopen($this->root . '/live-storage/nested/sparse.bin', 'x+b');
        self::assertIsResource($sparse);
        self::assertSame(0, fseek($sparse, 1_048_575));
        self::assertSame(1, fwrite($sparse, "x"));
        self::assertTrue(fclose($sparse));
        self::assertTrue(chmod($this->root . '/live-storage/nested/sparse.bin', 0600));
        $policy = json_encode([
            'external' => ['bytes' => 0, 'inodes' => 0],
            'host' => ['bytes' => 1_000_000, 'inodes' => 1_000],
            'schema' => 'deployment_renderer_capacity_policy.v1',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        self::assertSame(strlen($policy), file_put_contents($this->root . '/deployment-renderer-capacity-v1.json', $policy));
        self::assertTrue(chmod($this->root . '/deployment-renderer-capacity-v1.json', 0600));

        $result = $this->runHelper(['observe-capacity', $this->root, self::RUN_ID, 'ea_20260812', 'external']);

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        $decoded = json_decode($result['stdout'], true, 16, JSON_THROW_ON_ERROR);
        self::assertSame(1_048_576, $decoded['live_storage_logical_bytes']);
        self::assertGreaterThan(0, $decoded['live_storage_allocated_bytes']);
        self::assertSame(3, $decoded['live_storage_inode_count']);
        self::assertSame(base64_encode($policy), $decoded['policy_bytes_base64']);
        self::assertSame(
            array_fill_keys([
                'artifact', 'dump_pin', 'live_storage', 'release_root', 'renderer_state',
                'restore_scratch', 'stage', 'state_root', 'temp',
            ], $decoded['filesystem_device']),
            $decoded['component_devices'],
        );
    }

    public function testDeployScriptReaderReturnsOnlyStableRootExecutableBytes(): void
    {
        $script = "#!/bin/bash\nexit 0\n";
        $path = $this->root . '/deploy_ea.sh';
        self::assertSame(strlen($script), file_put_contents($path, $script));
        self::assertTrue(chmod($path, 0755));

        $result = $this->runHelper(['read-host-deploy-script', $this->root]);
        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame($script, $result['stdout']);

        self::assertTrue(chmod($path, 0777));
        $unsafe = $this->runHelper(['read-host-deploy-script', $this->root]);
        self::assertSame(70, $unsafe['exit_code']);
        self::assertSame('', $unsafe['stdout']);
    }

    public function testPrepareRunIsIdempotentAndRejectsUnsafeExistingRunLeaf(): void
    {
        self::assertTrue(mkdir($this->root . '/runs', 0700));
        self::assertSame(0, $this->runHelper(['prepare-run', $this->root, self::RUN_ID])['exit_code']);
        self::assertSame(0, $this->runHelper(['prepare-run', $this->root, self::RUN_ID])['exit_code']);
        $run = $this->root . '/runs/' . self::RUN_ID;
        self::assertDirectoryExists($run);
        self::assertSame(0700, fileperms($run) & 07777);
        self::assertFileExists($run . '/run.lock');
        self::assertSame(0600, fileperms($run . '/run.lock') & 07777);
        self::assertSame(1, lstat($run . '/run.lock')['nlink']);

        self::assertTrue(symlink($run, $this->root . '/runs/' . self::OTHER_RUN_ID));
        $unsafe = $this->runHelper(['prepare-run', $this->root, self::OTHER_RUN_ID]);
        self::assertSame(70, $unsafe['exit_code']);
        self::assertSame("host-runner storage rejected\n", $unsafe['stderr']);
    }

    public function testPrepareHostCreatesOnlyTheFixedPrivateTreeAndRejectsUnsafeExistingLeaf(): void
    {
        self::assertSame(0, $this->runHelper(['prepare-host', $this->root])['exit_code']);
        self::assertSame(0, $this->runHelper(['prepare-host', $this->root])['exit_code']);
        foreach (['locks', 'runs'] as $leaf) {
            self::assertDirectoryExists($this->root . '/' . $leaf);
            self::assertSame(0700, fileperms($this->root . '/' . $leaf) & 07777);
        }
        $lock = $this->root . '/locks/fh-production-change.lock';
        self::assertSame('', file_get_contents($lock));
        self::assertSame(0600, fileperms($lock) & 07777);
        self::assertSame(1, lstat($lock)['nlink']);

        $this->removeTree($this->root . '/runs');
        self::assertTrue(symlink($this->root . '/locks', $this->root . '/runs'));
        $unsafe = $this->runHelper(['prepare-host', $this->root]);
        self::assertSame(70, $unsafe['exit_code']);
        self::assertTrue(is_link($this->root . '/runs'));
    }

    public function testExactActiveClaimClearRejectsChangedBytesAndUnlinksOnlyExactSafeLeaf(): void
    {
        $claim = "{\"claim\":1}\n";
        self::assertSame(0, $this->runHelper(['pin', $this->root, 'active-run.json', '64'], $claim)['exit_code']);
        self::assertSame(75, $this->runHelper(['clear-exact', $this->root, 'active-run.json', '64'], "{\"claim\":2}\n")['exit_code']);
        self::assertSame($claim, file_get_contents($this->root . '/active-run.json'));
        self::assertSame(0, $this->runHelper(['clear-exact', $this->root, 'active-run.json', '64'], $claim)['exit_code']);
        self::assertFileDoesNotExist($this->root . '/active-run.json');
    }

    public function testSupervisorHoldsGlobalThenRunLocksForTheWholeFixedChildLifetime(): void
    {
        self::assertTrue(mkdir($this->root . '/locks', 0700));
        self::assertTrue(mkdir($this->root . '/runs', 0700));
        foreach ([self::RUN_ID, self::OTHER_RUN_ID] as $runId) {
            self::assertTrue(mkdir($this->root . '/runs/' . $runId, 0700));
            self::assertSame(0, file_put_contents($this->root . '/runs/' . $runId . '/run.lock', ''));
            self::assertTrue(chmod($this->root . '/runs/' . $runId . '/run.lock', 0600));
        }
        self::assertSame(0, file_put_contents($this->root . '/locks/fh-production-change.lock', ''));
        self::assertTrue(chmod($this->root . '/locks/fh-production-change.lock', 0600));

        [$first, $firstPipes] = $this->startHelper(['probe-locks', $this->root, self::RUN_ID, '2000']);
        usleep(100_000);

        foreach ([self::RUN_ID, self::OTHER_RUN_ID] as $runId) {
            $contender = $this->runHelper(['probe-locks', $this->root, $runId, '100']);
            self::assertSame(75, $contender['exit_code']);
            self::assertSame('', $contender['stdout']);
            self::assertSame("host-runner storage rejected\n", $contender['stderr']);
        }

        fclose($firstPipes[0]);
        $firstStdout = (string) stream_get_contents($firstPipes[1]);
        $firstStderr = (string) stream_get_contents($firstPipes[2]);
        fclose($firstPipes[1]);
        fclose($firstPipes[2]);
        self::assertSame(0, proc_close($first), $firstStderr . $firstStdout);

        $after = $this->runHelper(['probe-locks', $this->root, self::OTHER_RUN_ID, '50']);
        self::assertSame(0, $after['exit_code'], $after['stderr']);
        self::assertStringContainsString('run=' . $this->root . '/runs/' . self::OTHER_RUN_ID . "/run.lock\n", $after['stdout']);
    }

    public function testCliSupervisorValidatesExactProtectedBytesBeforePreparingAndPassesOnlyTheirHashes(): void
    {
        self::assertTrue(mkdir($this->root . '/locks', 0700));
        self::assertTrue(mkdir($this->root . '/runs', 0700));
        self::assertSame(0, file_put_contents($this->root . '/locks/fh-production-change.lock', ''));
        self::assertTrue(chmod($this->root . '/locks/fh-production-change.lock', 0600));
        $request = (string) file_get_contents(__DIR__ . '/../../Fixtures/deployment-host-runner-v1/deploy-request.json');
        $input = (string) file_get_contents(__DIR__ . '/../../Fixtures/deployment-host-runner-v1/execution-input.json');
        foreach (['request.json' => $request, 'input.json' => $input] as $leaf => $bytes) {
            self::assertSame(strlen($bytes), file_put_contents($this->root . '/' . $leaf, $bytes));
            self::assertTrue(chmod($this->root . '/' . $leaf, 0600));
        }

        $result = $this->runHelper([
            'supervise-cli-probe', $this->root, 'deploy',
            $this->root . '/request.json', $this->root . '/input.json',
        ]);

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame('', $result['stderr']);
        $summary = json_decode($result['stdout'], true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('deploy', $summary['action']);
        self::assertSame(self::RUN_ID, $summary['run_id']);
        self::assertSame(hash('sha256', $request), $summary['request_sha256']);
        self::assertSame(hash('sha256', $input), $summary['execution_input_sha256']);
        self::assertArrayNotHasKey('request_bytes', $summary);
        self::assertFileExists($this->root . '/runs/' . self::RUN_ID . '/run.lock');
    }

    public function testCliSupervisorRejectsFullContractFailureBeforeCreatingRunDirectory(): void
    {
        self::assertTrue(mkdir($this->root . '/locks', 0700));
        self::assertTrue(mkdir($this->root . '/runs', 0700));
        self::assertSame(0, file_put_contents($this->root . '/locks/fh-production-change.lock', ''));
        self::assertTrue(chmod($this->root . '/locks/fh-production-change.lock', 0600));
        $request = (string) file_get_contents(__DIR__ . '/../../Fixtures/deployment-host-runner-v1/deploy-request.json');
        $decoded = json_decode(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/deployment-host-runner-v1/execution-input.json'),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        $decoded['action'] = 'rollback';
        $decoded['parameters'] = ['release_id' => $decoded['parameters']['release_id']];
        $invalidInput = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        foreach (['request.json' => $request, 'input.json' => $invalidInput] as $leaf => $bytes) {
            self::assertSame(strlen($bytes), file_put_contents($this->root . '/' . $leaf, $bytes));
            self::assertTrue(chmod($this->root . '/' . $leaf, 0600));
        }

        $result = $this->runHelper([
            'supervise-cli-probe', $this->root, 'deploy',
            $this->root . '/request.json', $this->root . '/input.json',
        ]);

        self::assertSame(70, $result['exit_code']);
        self::assertSame('', $result['stdout']);
        self::assertSame("host-runner storage rejected\n", $result['stderr']);
        self::assertFileDoesNotExist($this->root . '/runs/' . self::RUN_ID);
    }

    public function testCliSupervisorRejectsUnsafeInputLeafWithoutPreparingRun(): void
    {
        self::assertTrue(mkdir($this->root . '/locks', 0700));
        self::assertTrue(mkdir($this->root . '/runs', 0700));
        self::assertSame(0, file_put_contents($this->root . '/locks/fh-production-change.lock', ''));
        self::assertTrue(chmod($this->root . '/locks/fh-production-change.lock', 0600));
        $request = (string) file_get_contents(__DIR__ . '/../../Fixtures/deployment-host-runner-v1/deploy-request.json');
        $input = (string) file_get_contents(__DIR__ . '/../../Fixtures/deployment-host-runner-v1/execution-input.json');
        self::assertSame(strlen($request), file_put_contents($this->root . '/request.json', $request));
        self::assertTrue(chmod($this->root . '/request.json', 0600));
        self::assertSame(strlen($input), file_put_contents($this->root . '/input-source.json', $input));
        self::assertTrue(chmod($this->root . '/input-source.json', 0600));
        self::assertTrue(symlink($this->root . '/input-source.json', $this->root . '/input.json'));

        $result = $this->runHelper([
            'supervise-cli-probe', $this->root, 'deploy',
            $this->root . '/request.json', $this->root . '/input.json',
        ]);

        self::assertSame(70, $result['exit_code']);
        self::assertSame('', $result['stdout']);
        self::assertFileDoesNotExist($this->root . '/runs/' . self::RUN_ID);
    }

    public function testPhpCoHoldsExactlyTheTwoReservedLocksAfterSupervisorDies(): void
    {
        $this->createLockTree();
        [$supervisor, $pipes] = $this->startHelper(['probe-locks', $this->root, self::RUN_ID, '5000']);
        fclose($pipes[0]);
        $status = proc_get_status($supervisor);
        $childrenPath = '/proc/' . $status['pid'] . '/task/' . $status['pid'] . '/children';
        $children = '';
        $deadline = microtime(true) + 1.0;
        while (trim($children) === '' && microtime(true) < $deadline) {
            $children = (string) @file_get_contents($childrenPath);
            usleep(10_000);
        }
        self::assertMatchesRegularExpression('/^[1-9][0-9]* $/D', $children);
        $phpPid = (int) trim($children);
        self::assertDirectoryExists('/proc/' . $phpPid);
        self::assertSame(
            $this->root . '/locks/fh-production-change.lock',
            readlink('/proc/' . $phpPid . '/fd/198'),
        );
        self::assertSame(
            $this->root . '/runs/' . self::RUN_ID . '/run.lock',
            readlink('/proc/' . $phpPid . '/fd/199'),
        );
        $lockInodes = [
            (int) lstat($this->root . '/locks/fh-production-change.lock')['ino'],
            (int) lstat($this->root . '/runs/' . self::RUN_ID . '/run.lock')['ino'],
        ];
        $fdInfo = (string) file_get_contents('/proc/locks');
        foreach ($lockInodes as $inode) {
            self::assertMatchesRegularExpression('/FLOCK\s+ADVISORY\s+WRITE\s+\d+\s+[^:]+:[^:]+:' . $inode . '\s+0\s+EOF/', $fdInfo);
        }

        self::assertTrue(posix_kill($status['pid'], 9));
        usleep(50_000);
        self::assertDirectoryExists('/proc/' . $phpPid);
        $locksAfterKill = (string) file_get_contents('/proc/locks');
        foreach ($lockInodes as $inode) {
            self::assertMatchesRegularExpression('/FLOCK\s+ADVISORY\s+WRITE\s+\d+\s+[^:]+:[^:]+:' . $inode . '\s+0\s+EOF/', $locksAfterKill);
        }
        $contender = $this->runHelper(['probe-locks', $this->root, self::OTHER_RUN_ID, '50']);
        self::assertSame(75, $contender['exit_code']);

        usleep(5_200_000);
        $after = $this->runHelper(['probe-locks', $this->root, self::OTHER_RUN_ID, '50']);
        self::assertSame(0, $after['exit_code'], $after['stderr']);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($supervisor);
    }

    public function testControllerValidatorAcceptsOnlyTheExactContractShowArgv(): void
    {
        $argv = DeploymentHostRunnerContractV1::systemctlShowArgv(
            DeploymentHostRunnerContractV1::unitName('deploy', self::RUN_ID, str_repeat('a', 64)),
        );
        $payload = json_encode([
            'argv' => $argv,
            'environment' => ['LANG' => 'C', 'LC_ALL' => 'C', 'PATH' => '/usr/sbin:/usr/bin:/sbin:/bin'],
            'timeout_seconds' => 30,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        self::assertSame(0, $this->runHelper(['validate-controller-payload', $payload])['exit_code']);

        foreach ([
            array_replace($argv, [5 => '/usr/bin/systemctl']),
            [...$argv, '--extra'],
            array_replace($argv, [9 => 'fh-deploy-bad.service']),
        ] as $mutated) {
            $invalid = json_encode([
                'argv' => $mutated,
                'environment' => ['LANG' => 'C', 'LC_ALL' => 'C', 'PATH' => '/usr/sbin:/usr/bin:/sbin:/bin'],
                'timeout_seconds' => 30,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $result = $this->runHelper(['validate-controller-payload', $invalid]);
            self::assertSame(70, $result['exit_code']);
            self::assertSame('', $result['stdout']);
        }
    }

    public function testControllerValidatorAcceptsOnlyExactContractRunArgvForBothActions(): void
    {
        $deploy = $this->systemdFixture('deploy', '/etc/fh/${FH_HEALTHZ_TOKEN}.token');
        $externalDeploy = $this->systemdFixture('deploy', null, 'external');
        $rollback = $this->systemdFixture('rollback');

        foreach ([$deploy['argv'], $externalDeploy['argv'], $rollback['argv']] as $argv) {
            self::assertSame(0, $this->validateControllerArgv($argv), implode(' ', $argv));
            self::assertContains('--expand-environment=no', $argv);
            self::assertLessThan(
                array_search('--', $argv, true),
                array_search('--expand-environment=no', $argv, true),
            );
        }
        self::assertNotContains('/etc/fh/${FH_HEALTHZ_TOKEN}.token', $deploy['argv']);
        self::assertContains(
            DeploymentHostRunnerContractV1::STATE_ROOT . '/runs/' . self::RUN_ID . '/deploy-ref-healthz-token',
            $deploy['argv'],
        );

        $deployArgv = $deploy['argv'];
        $separator = array_search('--', $deployArgv, true);
        self::assertIsInt($separator);
        $unit = array_search('--unit=' . $deploy['launch']['unit_name'], $deployArgv, true);
        $type = array_search('--property=Type=exec', $deployArgv, true);
        $expand = array_search('--expand-environment=no', $deployArgv, true);
        self::assertIsInt($unit);
        self::assertIsInt($type);
        self::assertIsInt($expand);

        $mutations = [];
        $mutations['property value'] = array_replace($deployArgv, [$type => '--property=Type=oneshot']);
        $reordered = $deployArgv;
        [$reordered[$type], $reordered[$type + 1]] = [$reordered[$type + 1], $reordered[$type]];
        $mutations['property order'] = $reordered;
        $omitted = $deployArgv;
        array_splice($omitted, $type, 1);
        $mutations['property omission'] = $omitted;
        $expandAfter = $deployArgv;
        array_splice($expandAfter, $expand, 1);
        $newSeparator = array_search('--', $expandAfter, true);
        self::assertIsInt($newSeparator);
        array_splice($expandAfter, $newSeparator + 1, 0, ['--expand-environment=no']);
        $mutations['expand after separator'] = $expandAfter;
        $mutations['missing separator'] = array_values(array_filter(
            $deployArgv,
            static fn(string $value): bool => $value !== '--',
        ));
        $mutations['wrong unit action'] = array_replace(
            $deployArgv,
            [$unit => str_replace('fh-deploy-', 'fh-rollback-', $deployArgv[$unit])],
        );
        $mutations['manager option'] = [...array_slice($deployArgv, 0, $separator), '--wait', ...array_slice($deployArgv, $separator)];
        $mutations['child executable'] = array_replace($deployArgv, [$separator + 7 => '/bin/sh']);
        $mutations['noncanonical path'] = array_replace(
            $deployArgv,
            [array_search('/etc/fh/${FH_HEALTHZ_TOKEN}.token', $deployArgv, true) => '/etc//fh/token'],
        );
        $mutations['different result run'] = array_replace(
            $deployArgv,
            [count($deployArgv) - 1 => '/var/lib/fh-deploy-orchestrator/runs/' . self::OTHER_RUN_ID . '/deploy-result.json'],
        );
        $renderer = array_search('--renderer-deploy-mode', $deployArgv, true);
        self::assertIsInt($renderer);
        $mutations['undocumented docker renderer'] = array_replace($deployArgv, [$renderer + 1 => 'docker']);

        foreach ($mutations as $name => $mutated) {
            self::assertSame(70, $this->validateControllerArgv($mutated), $name);
        }

        $rollbackArgv = $rollback['argv'];
        $previous = array_search('--previous', $rollbackArgv, true);
        $failed = array_search('--failed', $rollbackArgv, true);
        self::assertIsInt($previous);
        self::assertIsInt($failed);
        foreach ([
            'noncanonical previous path' => array_replace(
                $rollbackArgv,
                [$previous + 1 => '/var/www/html//easyappointments_prev_ea_20260811'],
            ),
            'different failed run' => array_replace(
                $rollbackArgv,
                [$failed + 1 => '/var/www/html/.fh-failed-' . self::OTHER_RUN_ID],
            ),
        ] as $name => $mutated) {
            self::assertSame(70, $this->validateControllerArgv($mutated), 'rollback ' . $name);
        }
    }

    public function testProductWrapperTimeoutKillsTheClosedControllerProcessGroupWithoutOutput(): void
    {
        $token = bin2hex(random_bytes(16));
        $adapter = HelperBackedHostRunnerSystemAdapter::forTimeoutProbe($token, 0.1);
        $argv = DeploymentHostRunnerContractV1::systemctlShowArgv(
            DeploymentHostRunnerContractV1::unitName('deploy', self::RUN_ID, str_repeat('a', 64)),
        );
        $started = microtime(true);
        try {
            $adapter->run($argv, DeploymentHostRunnerV1::CONTROLLER_ENVIRONMENT, 30);
            self::fail('Expected the fixed wrapper timeout.');
        } catch (RuntimeException $error) {
            self::assertSame('host-runner controller failed', $error->getMessage());
        }
        self::assertLessThan(3.0, microtime(true) - $started);

        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $cmdline) {
            self::assertStringNotContainsString($token, (string) @file_get_contents($cmdline), $cmdline);
        }
    }

    public function testControllerClosesEveryInheritedNonStdioDescriptorBeforeItsChild(): void
    {
        $extra = fopen($this->root . '/inherited-extra', 'w+');
        self::assertIsResource($extra);
        $command = [
            '/usr/bin/env', '-i', 'LANG=C', 'LC_ALL=C', 'PATH=/usr/sbin:/usr/bin:/sbin:/bin',
            '/usr/bin/python3', '-I', '-B', $this->helper, 'controller-fd-probe',
        ];
        $pipes = [];
        $process = proc_open(
            $command,
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w'], 198 => $extra, 199 => $extra, 211 => $extra],
            $pipes,
            null,
            [],
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        fclose($extra);
        self::assertSame(0, proc_close($process), $stderr);
        self::assertSame("0,1,2,3\n", $stdout);
        self::assertSame('', $stderr);
    }

    public function testUnsafeAncestorAndNonCanonicalRelativePathFailClosed(): void
    {
        self::assertTrue(mkdir($this->root . '/unsafe', 0770));
        self::assertTrue(chmod($this->root . '/unsafe', 0770));
        self::assertSame(2, file_put_contents($this->root . '/unsafe/x', "x\n"));
        self::assertTrue(chmod($this->root . '/unsafe/x', 0600));

        foreach ([['read', $this->root, 'unsafe/x', '8'], ['read', $this->root, '../etc/passwd', '4096']] as $argv) {
            $result = $this->runHelper($argv);
            self::assertSame(70, $result['exit_code']);
            self::assertSame('', $result['stdout']);
        }
    }

    /** @param list<string> $arguments @return array{exit_code:int,stdout:string,stderr:string} */
    private function runHelper(array $arguments, string $stdin = ''): array
    {
        $command = ['/usr/bin/env', '-i', 'LANG=C', 'LC_ALL=C', 'PATH=/usr/sbin:/usr/bin:/sbin:/bin', '/usr/bin/python3', '-I', '-B', $this->helper, ...$arguments];
        return $this->runCommand($command, $stdin);
    }

    /** @param list<string> $arguments @return array{resource,array<int,resource>} */
    private function startHelper(array $arguments): array
    {
        $command = ['/usr/bin/env', '-i', 'LANG=C', 'LC_ALL=C', 'PATH=/usr/sbin:/usr/bin:/sbin:/bin', '/usr/bin/python3', '-I', '-B', $this->helper, ...$arguments];
        $pipes = [];
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, []);
        self::assertIsResource($process);

        return [$process, $pipes];
    }

    private function createLockTree(): void
    {
        if (!is_dir($this->root . '/locks')) {
            self::assertTrue(mkdir($this->root . '/locks', 0700));
        }
        if (!is_dir($this->root . '/runs')) {
            self::assertTrue(mkdir($this->root . '/runs', 0700));
        }
        foreach ([self::RUN_ID, self::OTHER_RUN_ID] as $runId) {
            if (!is_dir($this->root . '/runs/' . $runId)) {
                self::assertTrue(mkdir($this->root . '/runs/' . $runId, 0700));
            }
            $lock = $this->root . '/runs/' . $runId . '/run.lock';
            if (!file_exists($lock)) {
                self::assertSame(0, file_put_contents($lock, ''));
                self::assertTrue(chmod($lock, 0600));
            }
        }
        $global = $this->root . '/locks/fh-production-change.lock';
        if (!file_exists($global)) {
            self::assertSame(0, file_put_contents($global, ''));
            self::assertTrue(chmod($global, 0600));
        }
    }

    /** @param list<string> $argv */
    private function validateControllerArgv(array $argv): int
    {
        $payload = json_encode([
            'argv' => $argv,
            'environment' => ['LANG' => 'C', 'LC_ALL' => 'C', 'PATH' => '/usr/sbin:/usr/bin:/sbin:/bin'],
            'timeout_seconds' => 60,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $this->runHelper(['validate-controller-payload', $payload])['exit_code'];
    }

    /** @return array{launch:array<string,mixed>,argv:list<string>} */
    private function systemdFixture(string $action, ?string $healthzPath = null, string $rendererMode = 'host'): array
    {
        $request = DeploymentHostRunnerContractV1::decodeDeployRequest(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/deployment-host-runner-v1/deploy-request.json'),
        );
        $input = DeploymentHostRunnerContractV1::decodeExecutionInput(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/deployment-host-runner-v1/execution-input.json'),
        );
        $originalDeployRequest = null;
        if ($healthzPath !== null) {
            $input['parameters']['healthz_token']['path'] = $healthzPath;
        }
        $input['parameters']['renderer_deploy_mode'] = $rendererMode;
        if ($action === 'rollback') {
            $originalDeployRequest = $request;
            $request = [
                'schema' => DeploymentHostRunnerContractV1::RECOVERY_REQUEST_SCHEMA,
                'run_id' => self::RUN_ID,
                'intent_sha256' => $originalDeployRequest['intent_sha256'],
            ];
            $input = [
                'schema' => DeploymentHostRunnerContractV1::EXECUTION_INPUT_SCHEMA,
                'run_id' => self::RUN_ID,
                'intent_sha256' => $originalDeployRequest['intent_sha256'],
                'action' => 'rollback',
                'parameters' => ['release_id' => $originalDeployRequest['release_id']],
            ];
        }
        $script = "#!/bin/bash\nexit 0\n";
        $launch = DeploymentHostRunnerContractV1::createSystemdLaunch(
            $input,
            $request,
            $originalDeployRequest,
            $script,
            static fn(): string => str_repeat("\x22", 32),
        );
        $binding = [
            'schema' => DeploymentHostRunnerContractV1::UNIT_BINDING_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => $input['intent_sha256'],
            'action' => $action,
            'unit_name' => $launch['unit_name'],
            'unit_launch_sha256' => DeploymentHostRunnerContractV1::fileSha256(
                DeploymentHostRunnerContractV1::encodeFile($launch),
            ),
            'unit_manager_boot_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'unit_invocation_id' => null,
            'binding_state' => 'reserved',
        ];

        return [
            'launch' => $launch,
            'argv' => DeploymentHostRunnerContractV1::systemdRunArgv(
                $launch,
                $binding,
                "cccccccc-cccc-4ccc-8ccc-cccccccccccc\n",
                $input,
                $request,
                $originalDeployRequest,
                $script,
            ),
        ];
    }

    /** @param list<string> $command @return array{exit_code:int,stdout:string,stderr:string} */
    private function runCommand(array $command, string $stdin = ''): array
    {
        $pipes = [];
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, []);
        self::assertIsResource($process);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + 2.0;
        do {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        if ($status['running']) {
            proc_terminate($process, 9);
            self::fail('Storage helper exceeded the fixed two-second deadline.');
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode === -1) {
            $exitCode = $status['exitcode'];
        }

        return ['exit_code' => $exitCode, 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
    }

    private function removeTree(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            if (is_dir($child) && !is_link($child)) {
                $this->removeTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
