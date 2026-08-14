<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReleaseArchiveDumpRetentionRootTest extends TestCase
{
    private const APP = '/var/www/html/easyappointments';
    private const RELEASES = '/root/releases';
    private const BACKUPS = '/root/backups/easyappointments';
    private const ATTESTATIONS = '/var/lib/fh-deploy-evidence/dump-attestations';
    private const STATE = '/var/lib/fh-release-retention';
    private const ORCHESTRATOR = '/var/lib/fh-deploy-orchestrator';
    private string $helper;

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY !== 'Linux' || posix_geteuid() !== 0) {
            $this->markTestSkipped('Linux root is required for the ROB-453 protected-path contract.');
        }
        if (is_file('/var/www/html/composer.json') && file_exists('/var/www/html/.git')) {
            $this->markTestSkipped(
                'The general Docker suite mounts source at the production web root; use the dedicated sudo root gate.',
            );
        }
        foreach (
            [self::APP, self::RELEASES, self::BACKUPS, self::ATTESTATIONS, self::STATE, self::ORCHESTRATOR]
            as $path
        ) {
            if (file_exists($path) || is_link($path)) {
                $this->markTestSkipped('A protected test root already exists; ROB-453 will not mutate it.');
            }
        }
        if (!is_array(posix_getpwnam('www-data'))) {
            $this->markTestSkipped('The production www-data account is required.');
        }
        foreach (['/', '/var', '/var/www', '/var/www/html', '/var/lib', '/root'] as $ancestor) {
            $metadata = lstat($ancestor);
            if (
                !is_array($metadata) ||
                ($metadata['mode'] & 0170000) !== 0040000 ||
                $metadata['uid'] !== 0 ||
                ($metadata['mode'] & 0022) !== 0
            ) {
                $this->markTestSkipped('Existing protected ancestors must satisfy the production trust boundary.');
            }
        }
        $this->helper = dirname(__DIR__, 3) . '/scripts/ops/libexec/release_archive_dump_retention_v1.py';
        $this->prepareFixture();
    }

    protected function tearDown(): void
    {
        if (isset($this->helper)) {
            foreach (
                [
                    self::APP,
                    '/var/www/html/easyappointments_prev_current',
                    '/var/www/html/easyappointments_prev_legacy',
                    '/var/www/html/easyappointments_old_stage',
                    '/var/www/html/easyappointments_failed_bad',
                    self::RELEASES,
                    self::BACKUPS,
                    self::ATTESTATIONS,
                    self::STATE,
                    self::ORCHESTRATOR,
                ]
                as $path
            ) {
                $this->removeTree($path);
            }
            for ($index = 1; $index <= 4; $index++) {
                foreach (
                    [
                        "/var/www/html/easyappointments_prev_extra{$index}",
                        "/var/www/html/easyappointments_extra{$index}_stage",
                        "/var/www/html/easyappointments_failed_extra{$index}",
                    ]
                    as $path
                ) {
                    $this->removeTree($path);
                }
            }
            @rmdir('/root/backups');
            @rmdir('/var/lib/fh-deploy-evidence');
        }
        parent::tearDown();
    }

    public function testDryRunIsAggregateOnlyAndBindsLogicalLiveStorageCapacity(): void
    {
        $sparse = self::APP . '/storage/live-sparse.bin';
        $handle = fopen($sparse, 'c+b');
        self::assertIsResource($handle);
        self::assertTrue(ftruncate($handle, 128 * 1024 * 1024));
        fclose($handle);
        chown($sparse, 'www-data');
        chgrp($sparse, 'www-data');
        chmod($sparse, 0600);

        $result = $this->runHelper('dry-run');

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        $value = $this->decode($result);
        self::assertSame('prod_release_archive_dump_retention.v2', $value['schema']);
        self::assertSame('dry-run', $value['mode']);
        self::assertFalse($value['deletion_performed']);
        self::assertSame('none', $value['mutation_outcome']);
        self::assertSame(0, array_sum($value['mutation_counts']));
        self::assertTrue($value['execution_ready']);
        self::assertGreaterThanOrEqual(128 * 1024 * 1024, $value['capacity']['projected_required_bytes']);
        self::assertSame(1, $value['would_delete']['archive_pairs']);
        self::assertSame(1, $value['would_delete']['dump_sets']);
        self::assertSame(1, $value['would_delete']['previous_dirs']);
        self::assertSame(1, $value['would_delete']['stage_dirs']);
        self::assertSame(1, $value['would_delete']['failed_dirs']);
        self::assertFileExists(self::RELEASES . '/old.tar.gz');
        self::assertDirectoryExists(self::BACKUPS . '/' . $this->dumpLeaf('old'));
        self::assertFileDoesNotExist(self::STATE . '/last-success.json');
        self::assertStringNotContainsString('dump-old', $result['stdout'] . $result['stderr']);
    }

    public function testExecuteDeletesOnlyEligibleClassesRetainsAttestationsAndPublishesMarker(): void
    {
        $result = $this->runHelper('execute');

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        $value = $this->decode($result);
        self::assertSame('prod_release_archive_dump_retention.v2', $value['schema']);
        self::assertSame('pass', $value['status']);
        self::assertTrue($value['deletion_performed']);
        self::assertSame('known', $value['mutation_outcome']);
        self::assertSame(2, $value['mutation_counts']['archive_files']);
        self::assertSame(1, $value['mutation_counts']['dump_sets']);
        self::assertSame(3, $value['mutation_counts']['release_dirs']);
        self::assertSame(1, $value['deleted']['archive_pairs']);
        self::assertSame(1, $value['deleted']['dump_sets']);
        self::assertFileDoesNotExist(self::RELEASES . '/old.tar.gz');
        self::assertFileDoesNotExist(self::RELEASES . '/old.build-provenance.json');
        self::assertDirectoryDoesNotExist(self::BACKUPS . '/' . $this->dumpLeaf('old'));
        self::assertFileExists(self::ATTESTATIONS . '/' . $this->dumpSha('old') . '.json');
        self::assertDirectoryExists(self::APP);
        self::assertDirectoryExists('/var/www/html/easyappointments_prev_current');
        self::assertFileExists(self::RELEASES . '/current.tar.gz');
        self::assertFileExists(self::RELEASES . '/rollback.tar.gz');

        $marker = self::STATE . '/last-success.json';
        self::assertFileExists($marker);
        $metadata = lstat($marker);
        self::assertIsArray($metadata);
        self::assertSame(0, $metadata['uid']);
        self::assertSame(0600, $metadata['mode'] & 0777);
        self::assertSame(1, $metadata['nlink']);
        self::assertSame('pass', $this->decode($this->runHelper('marker-status', '1209600'))['status']);
    }

    public function testProducerLockAndExactHandoffAreClassifiedAndBoundToAttestedSet(): void
    {
        file_put_contents(self::BACKUPS . '/.backup-set-producer.lock', '');
        chmod(self::BACKUPS . '/.backup-set-producer.lock', 0600);
        // Bind the producer handoff to the otherwise eligible oldest set. The
        // handoff must extend the two-newest retention set before eligibility
        // is calculated, not merely restate an already protected digest.
        $leaf = $this->dumpLeaf('old');
        $bytes = 'dump-old';
        file_put_contents(
            self::BACKUPS . '/last_backup_set.json',
            $this->canonical([
                'backup_set_id' => $leaf,
                'compressed_size_bytes' => strlen($bytes),
                'dump_sha256' => hash('sha256', $bytes),
                'schema' => 'production_backup_set_handoff.v1',
                'uncompressed_size_bytes' => strlen($bytes),
            ]),
        );
        chmod(self::BACKUPS . '/last_backup_set.json', 0600);

        $result = $this->runHelper('dry-run');

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertSame(0, $this->decode($result)['dump_foreign_count']);
        self::assertSame(3, $this->decode($result)['protected_verified_dump_count']);

        $handoff = json_decode(
            (string) file_get_contents(self::BACKUPS . '/last_backup_set.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $handoff['dump_sha256'] = str_repeat('f', 64);
        file_put_contents(self::BACKUPS . '/last_backup_set.json', $this->canonical($handoff));
        chmod(self::BACKUPS . '/last_backup_set.json', 0600);
        self::assertSame(70, $this->runHelper('dry-run')['exit']);
    }

    public function testUnknownEntryBusyLockAndOpenCandidateFailClosedWithoutDeletion(): void
    {
        file_put_contents(self::RELEASES . '/unknown-secret', 'do not print');
        chmod(self::RELEASES . '/unknown-secret', 0600);
        $unknown = $this->runHelper('execute');
        self::assertSame(70, $unknown['exit']);
        self::assertSame('unclassified_retention_entry', $this->decode($unknown)['reason']);
        self::assertFileExists(self::RELEASES . '/old.tar.gz');
        self::assertStringNotContainsString('unknown-secret', $unknown['stdout'] . $unknown['stderr']);
        unlink(self::RELEASES . '/unknown-secret');

        $lock = fopen(self::ORCHESTRATOR . '/locks/fh-production-change.lock', 'r+b');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        self::assertSame(75, $this->runHelper('execute')['exit']);
        self::assertFileExists(self::RELEASES . '/old.tar.gz');
        flock($lock, LOCK_UN);
        fclose($lock);

        $open = fopen(self::RELEASES . '/old.tar.gz', 'rb');
        self::assertIsResource($open);
        $opened = $this->runHelper('execute');
        self::assertSame(75, $opened['exit']);
        self::assertSame('candidate_open', $this->decode($opened)['reason']);
        self::assertFileExists(self::RELEASES . '/old.tar.gz');
        fclose($open);
    }

    public function testMarkerTempCleanupBeforeBusyLockReportsKnownMutation(): void
    {
        mkdir(self::STATE, 0700, true);
        $temp = self::STATE . '/.last-success.json.tmp-' . str_repeat('c', 32);
        file_put_contents($temp, "temporary marker\n");
        chmod($temp, 0600);
        $lock = fopen(self::ORCHESTRATOR . '/locks/fh-production-change.lock', 'r+b');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $result = $this->runHelper('execute');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        self::assertSame(75, $result['exit'], $result['stdout'] . $result['stderr']);
        $value = $this->decode($result);
        self::assertSame('active_production_work', $value['reason']);
        self::assertTrue($value['deletion_performed']);
        self::assertSame('known', $value['mutation_outcome']);
        self::assertSame(1, $value['mutation_counts']['marker_temp_files']);
        self::assertSame(1, array_sum($value['mutation_counts']));
        self::assertFileDoesNotExist($temp);
        self::assertFileExists(self::RELEASES . '/old.tar.gz');
    }

    public function testArchiveOnlyCrashPrefixIsUndeployableAndResumableButSidecarOnlyRejects(): void
    {
        unlink(self::RELEASES . '/old.build-provenance.json');
        $dry = $this->decode($this->runHelper('dry-run'));
        self::assertSame(1, $dry['would_delete']['archive_pairs']);
        self::assertSame(0, $this->runHelper('execute')['exit']);
        self::assertFileDoesNotExist(self::RELEASES . '/old.tar.gz');

        $this->archivePair('orphan', 40 * 86400);
        unlink(self::RELEASES . '/orphan.tar.gz');
        $rejected = $this->runHelper('dry-run');
        self::assertSame(70, $rejected['exit']);
        self::assertSame('unsafe_incomplete_archive_pair', $this->decode($rejected)['reason']);
    }

    public function testUnsafeSymlinkHardlinkAndNonterminalRunnerRejectWithoutMutation(): void
    {
        $archive = self::RELEASES . '/old.tar.gz';
        $sidecar = self::RELEASES . '/old.build-provenance.json';
        unlink($sidecar);
        symlink('/dev/null', $sidecar);
        self::assertSame(70, $this->runHelper('dry-run')['exit']);
        unlink($sidecar);
        $this->writeProvenance('old');
        link($archive, self::RELEASES . '/old-hardlink');
        self::assertSame(70, $this->runHelper('dry-run')['exit']);
        unlink(self::RELEASES . '/old-hardlink');

        mkdir(self::ORCHESTRATOR . '/runs', 0700);
        $run = self::ORCHESTRATOR . '/runs/11111111-1111-4111-8111-111111111111';
        mkdir($run, 0700);
        file_put_contents($run . '/state.json', $this->canonical(['terminal' => null]));
        chmod($run . '/state.json', 0600);
        file_put_contents($run . '/events.jsonl', "{}\n");
        chmod($run . '/events.jsonl', 0600);
        $blocked = $this->runHelper('execute');
        self::assertSame(75, $blocked['exit']);
        self::assertSame('active_host_runner', $this->decode($blocked)['reason']);
        self::assertFileExists($archive);
    }

    public function testEveryClassDeletionCapIsEnforcedAndPartialRunDoesNotPublishSuccess(): void
    {
        for ($index = 1; $index <= 4; $index++) {
            $this->releaseDirectory(
                "/var/www/html/easyappointments_prev_extra{$index}",
                "previous-{$index}",
                (9 + $index) * 86400,
            );
            $this->releaseDirectory(
                "/var/www/html/easyappointments_extra{$index}_stage",
                "stage-{$index}",
                (9 + $index) * 86400,
            );
            $this->releaseDirectory(
                "/var/www/html/easyappointments_failed_extra{$index}",
                "failed-{$index}",
                (9 + $index) * 86400,
            );
        }
        for ($index = 1; $index <= 9; $index++) {
            $this->archivePair("old-extra-{$index}", (40 + $index) * 86400);
        }
        for ($index = 1; $index <= 5; $index++) {
            $this->dumpSet("old-extra-{$index}", (40 + $index) * 86400);
        }

        $result = $this->runHelper('execute');

        self::assertSame(75, $result['exit'], $result['stdout'] . $result['stderr']);
        $value = $this->decode($result);
        self::assertSame('partial', $value['status']);
        self::assertSame(4, $value['deleted']['previous_dirs']);
        self::assertSame(4, $value['deleted']['stage_dirs']);
        self::assertSame(4, $value['deleted']['failed_dirs']);
        self::assertSame(8, $value['deleted']['archive_pairs']);
        self::assertSame(4, $value['deleted']['dump_sets']);
        self::assertSame(16, $value['mutation_counts']['archive_files']);
        self::assertSame(4, $value['mutation_counts']['dump_sets']);
        self::assertSame(12, $value['mutation_counts']['release_dirs']);
        self::assertSame(0, $value['mutation_counts']['pending_archive_files']);
        self::assertSame(0, $value['mutation_counts']['pending_dump_sets']);
        self::assertSame(0, $value['mutation_counts']['pending_release_dirs']);
        self::assertSame(0, $value['mutation_counts']['marker_temp_files']);
        self::assertSame(32, array_sum($value['mutation_counts']));
        self::assertGreaterThan(0, $value['remaining']['previous_dirs']);
        self::assertGreaterThan(0, $value['remaining']['archive_pairs']);
        self::assertGreaterThan(0, $value['remaining']['dump_sets']);
        self::assertFileDoesNotExist(self::STATE . '/last-success.json');
    }

    #[DataProvider('postDeletionFailureProvider')]
    public function testPostDeletionFailuresReportKnownMutation(
        string $search,
        string $replacement,
        string $reason,
        int $exit,
    ): void {
        $result = $this->runPatchedHelper($search, $replacement, 'execute');

        self::assertSame($exit, $result['exit'], $result['stdout'] . $result['stderr']);
        $value = $this->decode($result);
        self::assertSame($reason, $value['reason']);
        self::assertTrue($value['deletion_performed']);
        self::assertSame('known', $value['mutation_outcome']);
        self::assertGreaterThan(0, array_sum($value['mutation_counts']));
        self::assertDirectoryDoesNotExist('/var/www/html/easyappointments_prev_legacy');
        self::assertFileDoesNotExist(self::STATE . '/last-success.json');
    }

    /** @return iterable<string,array{string,string,string,int}> */
    public static function postDeletionFailureProvider(): iterable
    {
        yield 'post-delete activity check' => [
            "        if activity_count() != 0:\n            reject('active_production_work', 75)\n        assert_no_nonterminal_runs()\n        second = gather()",
            "        reject('active_production_work', 75)\n        assert_no_nonterminal_runs()\n        second = gather()",
            'active_production_work',
            75,
        ];
        yield 'second gather' => [
            '        second = gather()',
            "        reject('second_gather_failed')",
            'second_gather_failed',
            70,
        ];
    }

    public function testMarkerFailureAfterDeletionReportsKnownMutation(): void
    {
        mkdir(self::STATE, 0700, true);
        mkdir(self::STATE . '/last-success.json', 0700);

        $result = $this->runHelper('execute');

        self::assertSame(70, $result['exit'], $result['stdout'] . $result['stderr']);
        $value = $this->decode($result);
        self::assertSame('unsafe_file', $value['reason']);
        self::assertTrue($value['deletion_performed']);
        self::assertSame('known', $value['mutation_outcome']);
        self::assertGreaterThan(0, array_sum($value['mutation_counts']));
        self::assertDirectoryExists(self::STATE . '/last-success.json');
        self::assertDirectoryDoesNotExist('/var/www/html/easyappointments_prev_legacy');
    }

    public function testPendingCleanupThenLaterFailureReportsKnownMutation(): void
    {
        mkdir(self::STATE, 0700, true);
        $pending = self::STATE . '/.pending-archive-archive-' . str_repeat('a', 32);
        file_put_contents($pending, 'detached archive');
        chmod($pending, 0600);

        $result = $this->runPatchedHelper(
            '        clean_pending_entries(state, web_uid, MUTATIONS)',
            "        clean_pending_entries(state, web_uid, MUTATIONS)\n        reject('after_pending_cleanup')",
            'execute',
        );

        self::assertSame(70, $result['exit'], $result['stdout'] . $result['stderr']);
        $value = $this->decode($result);
        self::assertSame('after_pending_cleanup', $value['reason']);
        self::assertTrue($value['deletion_performed']);
        self::assertSame('known', $value['mutation_outcome']);
        self::assertSame(1, $value['mutation_counts']['pending_archive_files']);
        self::assertFileDoesNotExist($pending);
        self::assertFileExists(self::RELEASES . '/old.tar.gz');
    }

    public function testInterruptedPendingCleanupReportsUnknownMutation(): void
    {
        mkdir(self::STATE, 0700, true);
        $pending = self::STATE . '/.pending-archive-archive-' . str_repeat('b', 32);
        file_put_contents($pending, 'detached archive');
        chmod($pending, 0600);

        $result = $this->runPatchedHelper(
            "            os.unlink(name, dir_fd=state)\n            mutations.confirm('pending_archive_files')",
            "            os.unlink(name, dir_fd=state)\n            reject('pending_cleanup_interrupted')",
            'execute',
        );

        self::assertSame(70, $result['exit'], $result['stdout'] . $result['stderr']);
        $value = $this->decode($result);
        self::assertSame('pending_cleanup_interrupted', $value['reason']);
        self::assertNull($value['deletion_performed']);
        self::assertSame('unknown', $value['mutation_outcome']);
        self::assertSame(0, array_sum($value['mutation_counts']));
        self::assertFileDoesNotExist($pending);
    }

    public function testCandidateRenameInterruptionReportsUnknownMutation(): void
    {
        $result = $this->runPatchedHelper(
            "    mutations.begin()\n    os.rename(record['leaf'], pending, src_dir_fd=source, dst_dir_fd=state)\n    mutations.confirm('release_dirs' if kind == 'release' else 'dump_sets')",
            "    mutations.begin()\n    os.rename(record['leaf'], pending, src_dir_fd=source, dst_dir_fd=state)\n    reject('candidate_rename_interrupted')",
            'execute',
        );

        self::assertSame(70, $result['exit'], $result['stdout'] . $result['stderr']);
        $value = $this->decode($result);
        self::assertSame('candidate_rename_interrupted', $value['reason']);
        self::assertNull($value['deletion_performed']);
        self::assertSame('unknown', $value['mutation_outcome']);
        self::assertSame(0, array_sum($value['mutation_counts']));
        self::assertDirectoryDoesNotExist('/var/www/html/easyappointments_prev_legacy');
        self::assertCount(1, glob(self::STATE . '/.pending-release-*') ?: []);
    }

    public function testCandidatePhysicalCleanupInterruptionRetainsKnownRenameCountAndReportsUnknown(): void
    {
        $result = $this->runPatchedHelper(
            "    mutations.begin()\n    remove_tree(state, pending, record['identity'], allowed_uids, os.fstat(state).st_dev)\n    mutations.finish()",
            "    mutations.begin()\n    reject('candidate_cleanup_interrupted')",
            'execute',
        );

        self::assertSame(70, $result['exit'], $result['stdout'] . $result['stderr']);
        $value = $this->decode($result);
        self::assertSame('candidate_cleanup_interrupted', $value['reason']);
        self::assertNull($value['deletion_performed']);
        self::assertSame('unknown', $value['mutation_outcome']);
        self::assertSame(1, $value['mutation_counts']['release_dirs']);
        self::assertSame(1, array_sum($value['mutation_counts']));
        self::assertDirectoryDoesNotExist('/var/www/html/easyappointments_prev_legacy');
        self::assertCount(1, glob(self::STATE . '/.pending-release-*') ?: []);
    }

    public function testSignalAfterDeletionProducesNoFalseOutcomeAndRequiresOperatorRescan(): void
    {
        $result = $this->runPatchedHelper(
            "                deleted[kind + '_dirs'] += 1",
            "                os.kill(os.getpid(), 9)",
            'execute',
        );

        self::assertNotSame(0, $result['exit']);
        self::assertSame('', $result['stdout']);
        self::assertSame('', $result['stderr']);
        self::assertDirectoryDoesNotExist('/var/www/html/easyappointments_prev_legacy');
        self::assertFileDoesNotExist(self::STATE . '/last-success.json');
    }

    private function prepareFixture(): void
    {
        mkdir(self::APP . '/storage', 0755, true);
        chown(self::APP . '/storage', 'www-data');
        chgrp(self::APP . '/storage', 'www-data');
        file_put_contents(self::APP . '/_RELEASE', "current commit\n");
        chmod(self::APP . '/_RELEASE', 0644);
        $this->releaseDirectory('/var/www/html/easyappointments_prev_current', 'rollback', 0);
        $this->releaseDirectory('/var/www/html/easyappointments_prev_legacy', 'legacy', 8 * 86400);
        $this->releaseDirectory('/var/www/html/easyappointments_old_stage', 'stage', 8 * 86400);
        $this->releaseDirectory('/var/www/html/easyappointments_failed_bad', 'failed', 8 * 86400);

        mkdir(self::RELEASES, 0700, true);
        foreach (
            [['current', 0], ['rollback', 0], ['recent-a', 86400], ['recent-b', 2 * 86400], ['old', 40 * 86400]]
            as [$release, $age]
        ) {
            $this->archivePair($release, $age);
        }

        mkdir(self::BACKUPS, 0700, true);
        mkdir(self::ATTESTATIONS, 0700, true);
        foreach ([['new', 5 * 86400], ['second', 10 * 86400], ['old', 40 * 86400]] as [$name, $age]) {
            $this->dumpSet($name, $age);
        }

        mkdir(self::ORCHESTRATOR . '/locks', 0700, true);
        touch(self::ORCHESTRATOR . '/locks/fh-production-change.lock');
        chmod(self::ORCHESTRATOR . '/locks/fh-production-change.lock', 0600);
    }

    private function releaseDirectory(string $path, string $release, int $age): void
    {
        mkdir($path, 0755, true);
        file_put_contents($path . '/_RELEASE', $release . " commit\n");
        chmod($path . '/_RELEASE', 0644);
        file_put_contents($path . '/file.php', '<?php return true;');
        chmod($path . '/file.php', 0644);
        touch($path, time() - $age);
    }

    private function archivePair(string $release, int $age): void
    {
        file_put_contents(self::RELEASES . '/' . $release . '.tar.gz', 'archive-' . $release);
        chmod(self::RELEASES . '/' . $release . '.tar.gz', 0600);
        $this->writeProvenance($release);
        touch(self::RELEASES . '/' . $release . '.tar.gz', time() - $age);
        touch(self::RELEASES . '/' . $release . '.build-provenance.json', time() - $age);
    }

    private function writeProvenance(string $release): void
    {
        $archive = self::RELEASES . '/' . $release . '.tar.gz';
        file_put_contents(
            self::RELEASES . '/' . $release . '.build-provenance.json',
            $this->canonical([
                'archive' => [
                    'name' => $release . '.tar.gz',
                    'sha256' => hash_file('sha256', $archive),
                    'size_bytes' => filesize($archive),
                ],
                'capacity_bounds' => [
                    'stage_file_count' => 1,
                    'stage_inode_count' => 2,
                    'stage_unpacked_bytes' => 4096,
                    'temp_scratch_bytes' => 64 * 1024 * 1024,
                ],
                'expected_commit' => str_repeat('a', 40),
                'observed_commit' => str_repeat('a', 40),
                'release_id' => $release,
                'schema' => 'release_build_provenance.v1',
                'source' => [
                    'build_script_sha256' => str_repeat('b', 64),
                    'composer_lock_sha256' => str_repeat('c', 64),
                    'deploy_ea_sha256' => str_repeat('d', 64),
                    'package_lock_sha256' => str_repeat('e', 64),
                ],
            ]),
        );
        chmod(self::RELEASES . '/' . $release . '.build-provenance.json', 0600);
    }

    private function dumpSet(string $name, int $age): void
    {
        $dir = self::BACKUPS . '/' . $this->dumpLeaf($name) . '/db';
        mkdir($dir, 0700, true);
        $bytes = 'dump-' . $name;
        file_put_contents($dir . '/easyappointments.sql.gz', $bytes);
        chmod($dir . '/easyappointments.sql.gz', 0600);
        $sha = hash('sha256', $bytes);
        $when = gmdate('Y-m-d\TH:i:s\Z', time() - $age);
        file_put_contents(
            self::ATTESTATIONS . '/' . $sha . '.json',
            $this->canonical([
                'attested_at_utc' => $when,
                'dump' => [
                    'created_at_utc' => $when,
                    'sha256' => $sha,
                    'size_bytes' => strlen($bytes),
                    'uncompressed_size_bytes' => strlen($bytes),
                ],
                'schema' => 'deployment_dump_attestation.v1',
                'verification' => [
                    'gzip_verified' => true,
                    'image' => 'mariadb@sha256:2f2b6bbcdbaf88afe53b76cb8d73927b623559180c5ab15db2049736f32ec590',
                    'method' => 'mariadb_10_11_isolated_restore_v1',
                    'restore_verified' => true,
                    'restored_at_utc' => $when,
                    'restored_datadir_allocated_bytes' => 4096,
                    'restored_datadir_inode_count' => 1,
                    'sha256_verified' => true,
                ],
            ]),
        );
        chmod(self::ATTESTATIONS . '/' . $sha . '.json', 0600);
    }

    private function dumpSha(string $name): string
    {
        return hash('sha256', 'dump-' . $name);
    }

    private function dumpLeaf(string $name): string
    {
        $offset = abs((int) crc32($name)) % (300 * 86400);
        return gmdate('Ymd\THis\Z', strtotime('2026-01-01T00:00:00Z') + $offset);
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

    /** @return array{exit:int,stdout:string,stderr:string} */
    private function runPatchedHelper(string $search, string $replacement, string ...$arguments): array
    {
        $source = (string) file_get_contents($this->helper);
        self::assertSame(1, substr_count($source, $search), 'Fault boundary must remain unique.');
        $path = sys_get_temp_dir() . '/rob461-retention-' . bin2hex(random_bytes(8)) . '.py';
        file_put_contents($path, str_replace($search, $replacement, $source));
        chmod($path, 0700);
        $original = $this->helper;
        $this->helper = $path;
        try {
            return $this->runHelper(...$arguments);
        } finally {
            $this->helper = $original;
            unlink($path);
        }
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
