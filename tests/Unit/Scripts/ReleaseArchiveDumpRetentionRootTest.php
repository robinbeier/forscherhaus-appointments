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
    private const LEGACY_HOLD = '/etc/fh/legacy-release-hold.v1.json';
    private string $helper;
    private bool $legacyHoldFixtureCreated = false;
    /** @var array<string, string> */
    private array $dumpLeaves = [];

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
        if (file_exists(self::LEGACY_HOLD) || is_link(self::LEGACY_HOLD) || is_dir('/etc/fh')) {
            $this->markTestSkipped('A protected /etc/fh root already exists; ROB-470 will not mutate it.');
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
            if ($this->legacyHoldFixtureCreated) {
                @unlink(self::LEGACY_HOLD);
                @rmdir('/etc/fh');
                $this->legacyHoldFixtureCreated = false;
            }
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
        self::assertSame('prod_release_archive_dump_retention.v3', $value['schema']);
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
        self::assertSame('prod_release_archive_dump_retention.v3', $value['schema']);
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
        $handoff = [
            'backup_set_id' => $leaf,
            'compressed_size_bytes' => strlen($bytes),
            'dump_sha256' => hash('sha256', $bytes),
            'schema' => 'production_backup_set_handoff.v1',
            'uncompressed_size_bytes' => strlen($bytes),
        ];
        file_put_contents(self::BACKUPS . '/last_backup_set.json', $this->canonical($handoff));
        chmod(self::BACKUPS . '/last_backup_set.json', 0600);
        $markerTime = \DateTimeImmutable::createFromFormat('!Ymd\THis\Z', $leaf, new \DateTimeZone('UTC'));
        self::assertInstanceOf(\DateTimeImmutable::class, $markerTime);
        file_put_contents(self::BACKUPS . '/last_backup_success.utc', $markerTime->format('Y-m-d\TH:i:s\Z') . "\n");
        chmod(self::BACKUPS . '/last_backup_success.utc', 0600);
        $state = [
            'handoff' => $handoff,
            'schema' => 'production_backup_continuity_state.v1',
            'status' => 'verified',
        ];
        file_put_contents(self::BACKUPS . '/backup_continuity_state.json', $this->canonical($state));
        chmod(self::BACKUPS . '/backup_continuity_state.json', 0600);

        $result = $this->runHelper('dry-run');

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertSame(0, $this->decode($result)['dump_foreign_count']);
        self::assertSame(3, $this->decode($result)['protected_verified_dump_count']);

        $state['status'] = 'pending';
        file_put_contents(self::BACKUPS . '/backup_continuity_state.json', $this->canonical($state));
        chmod(self::BACKUPS . '/backup_continuity_state.json', 0600);
        $pendingAttestationPath = self::ATTESTATIONS . '/' . hash('sha256', $bytes) . '.json';
        $pendingAttestation = (string) file_get_contents($pendingAttestationPath);
        unlink($pendingAttestationPath);
        $pending = $this->runHelper('dry-run');
        self::assertSame(0, $pending['exit'], $pending['stdout'] . $pending['stderr']);
        $pendingValue = $this->decode($pending);
        self::assertSame(0, $pendingValue['dump_foreign_count']);
        self::assertSame(1, $pendingValue['dump_pending_restore_verification_count']);
        self::assertFalse($pendingValue['execution_ready']);
        $pendingAdmission = $this->runHelper('admission-status');
        self::assertSame(75, $pendingAdmission['exit'], $pendingAdmission['stdout'] . $pendingAdmission['stderr']);
        $pendingAdmissionValue = $this->decode($pendingAdmission);
        self::assertSame('restore_verification_pending', $pendingAdmissionValue['reason']);
        self::assertSame(3, $pendingAdmissionValue['manifest_bound_dump_count']);
        self::assertSame(2, $pendingAdmissionValue['verified_dump_count']);

        $this->dumpSet('pending-execute-candidate', 50 * 86400);
        $pendingExecute = $this->runHelper('execute');
        self::assertSame(75, $pendingExecute['exit'], $pendingExecute['stdout'] . $pendingExecute['stderr']);
        $pendingExecuteValue = $this->decode($pendingExecute);
        self::assertSame('retryable', $pendingExecuteValue['status']);
        self::assertSame('restore_verification_pending', $pendingExecuteValue['reason']);
        self::assertFalse($pendingExecuteValue['deletion_performed']);
        self::assertSame('none', $pendingExecuteValue['mutation_outcome']);
        self::assertSame(0, array_sum($pendingExecuteValue['mutation_counts']));
        self::assertFileExists(self::RELEASES . '/old.tar.gz');
        self::assertDirectoryExists(self::BACKUPS . '/' . $this->dumpLeaf('pending-execute-candidate'));
        self::assertDirectoryExists('/var/www/html/easyappointments_prev_legacy');

        $markerTemp = self::STATE . '/.last-success.json.tmp-' . str_repeat('d', 32);
        file_put_contents($markerTemp, "trusted recovery temp\n");
        chmod($markerTemp, 0600);
        $pendingRecovery = $this->runHelper('execute');
        self::assertSame(75, $pendingRecovery['exit'], $pendingRecovery['stdout'] . $pendingRecovery['stderr']);
        $pendingRecoveryValue = $this->decode($pendingRecovery);
        self::assertSame('retryable', $pendingRecoveryValue['status']);
        self::assertSame('restore_verification_pending', $pendingRecoveryValue['reason']);
        self::assertTrue($pendingRecoveryValue['deletion_performed']);
        self::assertSame('known', $pendingRecoveryValue['mutation_outcome']);
        self::assertSame(1, $pendingRecoveryValue['mutation_counts']['marker_temp_files']);
        self::assertSame(0, $pendingRecoveryValue['mutation_counts']['archive_files']);
        self::assertSame(0, $pendingRecoveryValue['mutation_counts']['dump_sets']);
        self::assertSame(0, $pendingRecoveryValue['mutation_counts']['release_dirs']);
        self::assertSame(1, array_sum($pendingRecoveryValue['mutation_counts']));
        self::assertFileDoesNotExist($markerTemp);
        self::assertFileExists(self::RELEASES . '/old.tar.gz');
        self::assertDirectoryExists(self::BACKUPS . '/' . $this->dumpLeaf('pending-execute-candidate'));
        self::assertDirectoryExists('/var/www/html/easyappointments_prev_legacy');

        file_put_contents($pendingAttestationPath, $pendingAttestation);
        chmod($pendingAttestationPath, 0600);
        $state['status'] = 'verified';
        file_put_contents(self::BACKUPS . '/backup_continuity_state.json', $this->canonical($state));
        chmod(self::BACKUPS . '/backup_continuity_state.json', 0600);

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

    public function testAdmissionStatusAcceptsOnlyManifestBoundSetsAndIsAggregateOnly(): void
    {
        $result = $this->runHelper('admission-status');

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        $value = $this->decode($result);
        self::assertSame('prod_dump_producer_admission.v1', $value['schema']);
        self::assertSame('pass', $value['status']);
        self::assertSame(1, $value['authorized_producer_count']);
        self::assertSame(3, $value['manifest_bound_dump_count']);
        self::assertSame(0, $value['foreign_count']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $value['producer_registry_sha256']);
        self::assertFalse($value['deletion_performed']);
        self::assertSame('none', $value['mutation_outcome']);
        self::assertSame(0, array_sum($value['mutation_counts']));
        self::assertStringNotContainsString($this->dumpLeaf('old'), $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString('dump-old', $result['stdout'] . $result['stderr']);
    }

    public function testAdmissionStatusRequiresEveryFixedTopLevelAuthority(): void
    {
        foreach (
            [
                '.backup-set-producer.lock',
                'backup_continuity_state.json',
                'last_backup_set.json',
                'last_backup_success.utc',
                'last_verify_success.utc',
            ]
            as $leaf
        ) {
            $path = self::BACKUPS . '/' . $leaf;
            $bytes = (string) file_get_contents($path);
            unlink($path);

            $result = $this->runHelper('admission-status');

            self::assertSame(70, $result['exit'], $leaf . ': ' . $result['stdout'] . $result['stderr']);
            self::assertSame('missing_backup_authority', $this->decode($result)['reason']);
            self::assertSame(0, array_sum($this->decode($result)['mutation_counts']));
            file_put_contents($path, $bytes);
            chmod($path, 0600);
        }
    }

    public function testAdmissionStatusRejectsMalformedOrLinkedAuthorityObjects(): void
    {
        $marker = self::BACKUPS . '/last_backup_success.utc';
        $markerBytes = (string) file_get_contents($marker);
        file_put_contents($marker, "not-a-utc-marker\n");
        chmod($marker, 0600);

        $malformed = $this->runHelper('admission-status');

        self::assertSame(70, $malformed['exit'], $malformed['stdout'] . $malformed['stderr']);
        self::assertSame('invalid_backup_authority', $this->decode($malformed)['reason']);
        self::assertSame(0, array_sum($this->decode($malformed)['mutation_counts']));
        file_put_contents($marker, $markerBytes);
        chmod($marker, 0600);

        $lock = self::BACKUPS . '/.backup-set-producer.lock';
        $alias = sys_get_temp_dir() . '/rob483-authority-link-' . bin2hex(random_bytes(8));
        self::assertTrue(link($lock, $alias));
        try {
            $linked = $this->runHelper('admission-status');
            self::assertSame(70, $linked['exit'], $linked['stdout'] . $linked['stderr']);
            self::assertSame('unsafe_file', $this->decode($linked)['reason']);
            self::assertSame(0, array_sum($this->decode($linked)['mutation_counts']));
        } finally {
            unlink($alias);
        }
    }

    public function testAdmissionStatusRejectsBackupSuccessMarkerThatDoesNotMatchHandoff(): void
    {
        $marker = self::BACKUPS . '/last_backup_success.utc';
        $markerBytes = (string) file_get_contents($marker);
        file_put_contents($marker, "2000-01-01T00:00:00Z\n");
        chmod($marker, 0600);

        try {
            $admission = $this->runHelper('admission-status');
            self::assertSame(70, $admission['exit'], $admission['stdout'] . $admission['stderr']);
            self::assertSame('backup_success_marker_mismatch', $this->decode($admission)['reason']);
            self::assertSame(0, array_sum($this->decode($admission)['mutation_counts']));

            $retention = $this->runHelper('dry-run');
            self::assertSame(70, $retention['exit'], $retention['stdout'] . $retention['stderr']);
            self::assertSame('backup_success_marker_mismatch', $this->decode($retention)['reason']);
            self::assertSame(0, array_sum($this->decode($retention)['mutation_counts']));
        } finally {
            file_put_contents($marker, $markerBytes);
            chmod($marker, 0600);
        }
    }

    public function testAdmissionStatusBlocksUnknownTopLevelEntryWithoutLeakingItsIdentity(): void
    {
        $secretLeaf = 'foreign-high-entropy-' . str_repeat('x', 32);
        file_put_contents(self::BACKUPS . '/' . $secretLeaf, 'secret material');
        chmod(self::BACKUPS . '/' . $secretLeaf, 0600);

        $result = $this->runHelper('admission-status');

        self::assertSame(70, $result['exit'], $result['stdout'] . $result['stderr']);
        $value = $this->decode($result);
        self::assertSame('prod_dump_producer_admission.v1', $value['schema']);
        self::assertSame('blocked', $value['status']);
        self::assertSame('unclassified_dump_entry', $value['reason']);
        self::assertFalse($value['deletion_performed']);
        self::assertSame(0, array_sum($value['mutation_counts']));
        self::assertStringNotContainsString($secretLeaf, $result['stdout'] . $result['stderr']);
        self::assertFileExists(self::BACKUPS . '/' . $secretLeaf);
    }

    public function testMissingInvalidAndUnsafeBackupManifestsFailClosed(): void
    {
        $leaf = $this->dumpLeaf('old');
        $manifest = self::BACKUPS . '/' . $leaf . '/meta/backup.env';
        $original = (string) file_get_contents($manifest);

        unlink($manifest);
        $missing = $this->runHelper('admission-status');
        self::assertSame(70, $missing['exit'], $missing['stdout'] . $missing['stderr']);
        self::assertSame('invalid_backup_set_manifest', $this->decode($missing)['reason']);

        file_put_contents($manifest, str_replace('production_backup_set.v1', 'unknown_backup_set.v1', $original));
        chmod($manifest, 0600);
        $invalid = $this->runHelper('admission-status');
        self::assertSame(70, $invalid['exit'], $invalid['stdout'] . $invalid['stderr']);
        self::assertSame('invalid_backup_set_manifest', $this->decode($invalid)['reason']);

        file_put_contents($manifest, $original);
        chmod($manifest, 0644);
        $unsafe = $this->runHelper('admission-status');
        self::assertSame(70, $unsafe['exit'], $unsafe['stdout'] . $unsafe['stderr']);
        self::assertSame('unsafe_file', $this->decode($unsafe)['reason']);

        chmod($manifest, 0600);

        $alias = sys_get_temp_dir() . '/rob483-manifest-link-' . bin2hex(random_bytes(8));
        self::assertTrue(link($manifest, $alias));
        try {
            $linked = $this->runHelper('admission-status');
            self::assertSame(70, $linked['exit'], $linked['stdout'] . $linked['stderr']);
            self::assertSame('unsafe_file', $this->decode($linked)['reason']);
        } finally {
            unlink($alias);
        }

        chown($manifest, 'www-data');
        $wrongOwner = $this->runHelper('admission-status');
        self::assertSame(70, $wrongOwner['exit'], $wrongOwner['stdout'] . $wrongOwner['stderr']);
        self::assertSame('unsafe_file', $this->decode($wrongOwner)['reason']);
        chown($manifest, 0);

        $meta = dirname($manifest);
        chmod($meta, 0755);
        $wrongDirectoryMode = $this->runHelper('admission-status');
        self::assertSame(
            70,
            $wrongDirectoryMode['exit'],
            $wrongDirectoryMode['stdout'] . $wrongDirectoryMode['stderr'],
        );
        self::assertSame('unsafe_directory_mode', $this->decode($wrongDirectoryMode)['reason']);
        chmod($meta, 0700);

        $dump = dirname($meta) . '/db/easyappointments.sql.gz';
        chmod($dump, 0640);
        $wrongDumpMode = $this->runHelper('admission-status');
        self::assertSame(70, $wrongDumpMode['exit'], $wrongDumpMode['stdout'] . $wrongDumpMode['stderr']);
        self::assertSame('unsafe_file', $this->decode($wrongDumpMode)['reason']);
        chmod($dump, 0600);

        $renamedMeta = dirname($meta) . '/metadata';
        rename($meta, $renamedMeta);
        $wrongPath = $this->runHelper('admission-status');
        self::assertSame(70, $wrongPath['exit'], $wrongPath['stdout'] . $wrongPath['stderr']);
        self::assertSame('invalid_backup_set_manifest', $this->decode($wrongPath)['reason']);
        rename($renamedMeta, $meta);
    }

    public function testAdmissionStatusReturnsRetryableWhenTheGlobalLockIsBusy(): void
    {
        $lock = fopen(self::ORCHESTRATOR . '/locks/fh-production-change.lock', 'r+b');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $result = $this->runHelper('admission-status');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        self::assertSame(75, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertSame('active_production_work', $this->decode($result)['reason']);
        self::assertDirectoryExists(self::BACKUPS . '/' . $this->dumpLeaf('old'));
        self::assertFileDoesNotExist(self::STATE . '/last-success.json');
    }

    public function testAdmissionStatusRejectsAttestationTimeThatDoesNotMatchTheManifest(): void
    {
        $path = self::ATTESTATIONS . '/' . $this->dumpSha('old') . '.json';
        $attestation = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $created = strtotime($attestation['dump']['created_at_utc']);
        self::assertIsInt($created);
        $attestation['dump']['created_at_utc'] = gmdate('Y-m-d\TH:i:s\Z', $created - 1);
        file_put_contents($path, $this->canonical($attestation));
        chmod($path, 0600);

        $result = $this->runHelper('admission-status');

        self::assertSame(70, $result['exit'], $result['stdout'] . $result['stderr']);
        $value = $this->decode($result);
        self::assertSame('backup_manifest_attestation_mismatch', $value['reason']);
        self::assertFalse($value['deletion_performed']);
        self::assertSame(0, array_sum($value['mutation_counts']));
        self::assertDirectoryExists(self::BACKUPS . '/' . $this->dumpLeaf('old'));
    }

    public function testAdmissionStatusPreservesOnlyTheSafeRegisteredInstallerSnapshotClass(): void
    {
        $snapshot = self::BACKUPS . '/install-snapshots';
        mkdir($snapshot, 0700);
        file_put_contents($snapshot . '/evidence.bin', 'installer evidence');
        chmod($snapshot . '/evidence.bin', 0600);

        $accepted = $this->runHelper('admission-status');
        self::assertSame(0, $accepted['exit'], $accepted['stdout'] . $accepted['stderr']);
        self::assertSame('pass', $this->decode($accepted)['status']);
        self::assertFileExists($snapshot . '/evidence.bin');

        chmod($snapshot, 0777);
        $unsafe = $this->runHelper('admission-status');
        self::assertSame(70, $unsafe['exit'], $unsafe['stdout'] . $unsafe['stderr']);
        self::assertSame('candidate_directory_mode', $this->decode($unsafe)['reason']);
        chmod($snapshot, 0700);
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

    public function testArchiveOnlyCrashPrefixWithoutPermanentHoldFailsClosedAndSidecarOnlyRejects(): void
    {
        unlink(self::RELEASES . '/old.build-provenance.json');
        $dry = $this->runHelper('dry-run');
        self::assertSame(70, $dry['exit']);
        self::assertSame('unheld_archive_only', $this->decode($dry)['reason']);
        self::assertFileExists(self::RELEASES . '/old.tar.gz');
        unlink(self::RELEASES . '/old.tar.gz');

        $this->archivePair('orphan', 40 * 86400);
        unlink(self::RELEASES . '/orphan.tar.gz');
        $rejected = $this->runHelper('dry-run');
        self::assertSame(70, $rejected['exit']);
        self::assertSame('unsafe_incomplete_archive_pair', $this->decode($rejected)['reason']);
    }

    public function testRetentionOwnedArchiveOnlyCrashPrefixResumesFromPendingSidecar(): void
    {
        $result = $this->runPatchedHelper(
            "    detach_file(directory, state, record['archive_leaf'], record['archive_identity'], 'archive', mutations)\n    mutations.begin()",
            "    reject('after_sidecar_detach')",
            'execute',
        );

        self::assertSame(70, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertSame('after_sidecar_detach', $this->decode($result)['reason']);
        self::assertFileExists(self::RELEASES . '/old.tar.gz');
        self::assertFileDoesNotExist(self::RELEASES . '/old.build-provenance.json');
        self::assertCount(1, glob(self::STATE . '/.pending-archive-sidecar-*'));

        $resumed = $this->runHelper('execute');

        self::assertSame(0, $resumed['exit'], $resumed['stdout'] . $resumed['stderr']);
        $value = $this->decode($resumed);
        self::assertSame('pass', $value['status']);
        self::assertTrue($value['deletion_performed']);
        self::assertSame('known', $value['mutation_outcome']);
        self::assertSame(1, $value['deleted']['archive_pairs']);
        self::assertSame(1, $value['mutation_counts']['archive_files']);
        self::assertFileDoesNotExist(self::RELEASES . '/old.tar.gz');
        self::assertFileDoesNotExist(self::RELEASES . '/old.build-provenance.json');
        self::assertCount(0, glob(self::STATE . '/.pending-archive-sidecar-*'));
    }

    public function testPinnedRecoverySidecarIdentityRaceBlocksBeforeArchiveMutation(): void
    {
        $this->runPatchedHelper(
            "    detach_file(directory, state, record['archive_leaf'], record['archive_identity'], 'archive', mutations)\n    mutations.begin()",
            "    reject('after_sidecar_detach')",
            'execute',
        );

        $result = $this->runPatchedHelper(
            "            unlink_pair(first['releases'], first['state'], record, MUTATIONS)",
            "            if record.get('recovery_pending'):\n                pending = record['recovery_sidecar_leaf']\n                os.unlink(pending, dir_fd=first['state'])\n                replacement = os.open(pending, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC | os.O_NOFOLLOW, 0o600, dir_fd=first['state'])\n                os.close(replacement)\n                os.fsync(first['state'])\n            unlink_pair(first['releases'], first['state'], record, MUTATIONS)",
            'execute',
        );

        self::assertSame(75, $result['exit'], $result['stdout'] . $result['stderr']);
        $value = $this->decode($result);
        self::assertSame('candidate_changed', $value['reason']);
        self::assertFalse($value['deletion_performed']);
        self::assertSame('none', $value['mutation_outcome']);
        self::assertSame(0, array_sum($value['mutation_counts']));
        self::assertFileExists(self::RELEASES . '/old.tar.gz');
    }

    public function testChangedRecoverySidecarBlocksResumeBeforeArchiveMutation(): void
    {
        $this->runPatchedHelper(
            "    detach_file(directory, state, record['archive_leaf'], record['archive_identity'], 'archive', mutations)\n    mutations.begin()",
            "    reject('after_sidecar_detach')",
            'execute',
        );
        $pending = (string) glob(self::STATE . '/.pending-archive-sidecar-*')[0];
        file_put_contents($pending, "tampered\n");
        chmod($pending, 0600);

        $result = $this->runHelper('execute');

        self::assertSame(70, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertSame('invalid_json', $this->decode($result)['reason']);
        self::assertFileExists(self::RELEASES . '/old.tar.gz');
    }

    public function testCanonicalMismatchedRecoverySidecarBlocksResumeBeforeArchiveMutation(): void
    {
        $pending = self::STATE . '/.pending-archive-sidecar-' . str_repeat('f', 32);
        mkdir(self::STATE, 0700, true);
        $sidecar = json_decode(
            (string) file_get_contents(self::RELEASES . '/old.build-provenance.json'),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        $sidecar['archive']['sha256'] = str_repeat('f', 64);
        file_put_contents($pending, $this->canonical($sidecar));
        chmod($pending, 0600);
        unlink(self::RELEASES . '/old.build-provenance.json');

        $result = $this->runHelper('dry-run');

        self::assertSame(70, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertSame('invalid_release_sidecar', $this->decode($result)['reason']);
        self::assertFileExists(self::RELEASES . '/old.tar.gz');
    }

    public function testDuplicateRecoverySidecarsBlockResume(): void
    {
        $this->runPatchedHelper(
            "    detach_file(directory, state, record['archive_leaf'], record['archive_identity'], 'archive', mutations)\n    mutations.begin()",
            "    reject('after_sidecar_detach')",
            'execute',
        );
        $pending = (string) glob(self::STATE . '/.pending-archive-sidecar-*')[0];
        copy($pending, self::STATE . '/.pending-archive-sidecar-' . str_repeat('d', 32));
        chmod(self::STATE . '/.pending-archive-sidecar-' . str_repeat('d', 32), 0600);

        $result = $this->runHelper('execute');

        self::assertSame(70, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertSame('unsafe_pending_entry', $this->decode($result)['reason']);
        self::assertFileExists(self::RELEASES . '/old.tar.gz');
    }

    public function testRecoverySidecarForProtectedCurrentArchiveBlocks(): void
    {
        $pending = self::STATE . '/.pending-archive-sidecar-' . str_repeat('e', 32);
        mkdir(self::STATE, 0700, true);
        copy(self::RELEASES . '/current.build-provenance.json', $pending);
        chmod($pending, 0600);
        unlink(self::RELEASES . '/current.build-provenance.json');

        $result = $this->runHelper('dry-run');

        self::assertSame(70, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertSame('archive_recovery_protected', $this->decode($result)['reason']);
        self::assertFileExists(self::RELEASES . '/current.tar.gz');
    }

    public function testRecoverySidecarForProtectedRollbackArchiveBlocks(): void
    {
        $pending = self::STATE . '/.pending-archive-sidecar-' . str_repeat('a', 32);
        mkdir(self::STATE, 0700, true);
        copy(self::RELEASES . '/rollback.build-provenance.json', $pending);
        chmod($pending, 0600);
        unlink(self::RELEASES . '/rollback.build-provenance.json');

        $result = $this->runHelper('dry-run');

        self::assertSame(70, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertSame('archive_recovery_protected', $this->decode($result)['reason']);
        self::assertFileExists(self::RELEASES . '/rollback.tar.gz');
    }

    public function testHeldLegacyArchivesStayProtectedAfterMarkerRotation(): void
    {
        $this->archivePair('held-current', 80 * 86400);
        $this->archivePair('held-rollback', 81 * 86400);
        $this->writeLegacyTarArchive('held-current');
        $this->writeLegacyProvenance('held-current');
        $this->writeLegacyTarArchive('held-rollback');
        unlink(self::RELEASES . '/held-rollback.build-provenance.json');
        mkdir('/etc/fh', 0700, true);
        $this->legacyHoldFixtureCreated = true;
        file_put_contents(
            self::LEGACY_HOLD,
            $this->canonical([
                'schema' => 'legacy_release_hold.v1',
                'targets' => [
                    $this->legacyHoldTarget('current', 'held-current'),
                    $this->legacyHoldTarget('rollback', 'held-rollback'),
                ],
            ]),
        );
        chmod(self::LEGACY_HOLD, 0600);

        $dry = $this->runHelper('dry-run');
        self::assertSame(0, $dry['exit'], $dry['stdout'] . $dry['stderr']);
        $value = $this->decode($dry);
        self::assertSame(2, $value['legacy_hold_count']);
        self::assertSame(1, $value['would_delete']['archive_pairs']);
        self::assertFileExists(self::RELEASES . '/held-current.tar.gz');
        self::assertFileExists(self::RELEASES . '/held-rollback.tar.gz');

        $execute = $this->runHelper('execute');
        self::assertSame(0, $execute['exit'], $execute['stdout'] . $execute['stderr']);
        self::assertFileExists(self::RELEASES . '/held-current.tar.gz');
        self::assertFileExists(self::RELEASES . '/held-rollback.tar.gz');
        self::assertFileDoesNotExist(self::RELEASES . '/old.tar.gz');
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function legacyHoldCapacityBoundsProvider(): iterable
    {
        foreach (['current', 'rollback'] as $role) {
            yield $role . '_stage_unpacked_bytes' => [$role, 'stage_unpacked_bytes', -4096];
            yield $role . '_stage_file_count' => [$role, 'stage_file_count', 1];
            yield $role . '_stage_inode_count' => [$role, 'stage_inode_count', -1];
            yield $role . '_temp_scratch_bytes' => [$role, 'temp_scratch_bytes', -4096];
        }
    }

    #[DataProvider('legacyHoldCapacityBoundsProvider')]
    public function testLegacyHoldRejectsEachCapacityBoundThatDoesNotMatchArchive(
        string $role,
        string $field,
        int $delta,
    ): void {
        $this->archivePair('held-current', 80 * 86400);
        $this->archivePair('held-rollback', 81 * 86400);
        $this->writeLegacyTarArchive('held-current');
        $this->writeLegacyProvenance('held-current');
        $this->writeLegacyTarArchive('held-rollback');
        $this->writeLegacyProvenance('held-rollback');
        mkdir('/etc/fh', 0700, true);
        $this->legacyHoldFixtureCreated = true;
        $current = $this->legacyHoldTarget('current', 'held-current');
        $rollback = $this->legacyHoldTarget('rollback', 'held-rollback');
        if ($role === 'current') {
            $current['capacity_bounds'][$field] += $delta;
        } else {
            $rollback['capacity_bounds'][$field] += $delta;
        }
        file_put_contents(
            self::LEGACY_HOLD,
            $this->canonical([
                'schema' => 'legacy_release_hold.v1',
                'targets' => [$current, $rollback],
            ]),
        );
        chmod(self::LEGACY_HOLD, 0600);

        $result = $this->runHelper('dry-run');
        self::assertSame(70, $result['exit'], $result['stdout'] . $result['stderr']);
        $expectedReason = $field === 'temp_scratch_bytes' ? 'unsafe_legacy_hold' : 'legacy_hold_bounds_drift';
        self::assertSame($expectedReason, $this->decode($result)['reason']);
        self::assertFileExists(self::RELEASES . '/old.tar.gz');
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function heldArchiveProvenanceBoundsProvider(): iterable
    {
        $mutations = [
            'stage_file_count' => 2,
            'stage_inode_count' => 4,
            'stage_unpacked_bytes' => 8192,
            'temp_scratch_bytes' => 64 * 1024 * 1024 - 4096,
        ];
        foreach (['held-current', 'held-rollback'] as $release) {
            foreach ($mutations as $field => $value) {
                yield $release . '_' . $field => [$release, $field, $value];
            }
        }
    }

    #[DataProvider('heldArchiveProvenanceBoundsProvider')]
    public function testHeldArchiveRejectsProvenanceCapacityBoundsDrift(
        string $release,
        string $field,
        int $value,
    ): void {
        $this->archivePair('held-current', 80 * 86400);
        $this->archivePair('held-rollback', 81 * 86400);
        $this->writeLegacyTarArchive('held-current');
        $this->writeLegacyProvenance('held-current');
        $this->writeLegacyTarArchive('held-rollback');
        $this->writeLegacyProvenance('held-rollback');
        $provenancePath = self::RELEASES . '/' . $release . '.build-provenance.json';
        $provenance = json_decode((string) file_get_contents($provenancePath), true, 32, JSON_THROW_ON_ERROR);
        $provenance['capacity_bounds'][$field] = $value;
        file_put_contents($provenancePath, $this->canonical($provenance));
        chmod($provenancePath, 0600);
        mkdir('/etc/fh', 0700, true);
        $this->legacyHoldFixtureCreated = true;
        file_put_contents(
            self::LEGACY_HOLD,
            $this->canonical([
                'schema' => 'legacy_release_hold.v1',
                'targets' => [
                    $this->legacyHoldTarget('current', 'held-current'),
                    $this->legacyHoldTarget('rollback', 'held-rollback'),
                ],
            ]),
        );
        chmod(self::LEGACY_HOLD, 0600);

        $result = $this->runHelper('dry-run');
        self::assertSame(70, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertSame('legacy_hold_bounds_drift', $this->decode($result)['reason']);
    }

    public function testLegacyHoldStageInodeBoundaryIncludesStagingRootButRejectsBeyondIt(): void
    {
        mkdir('/etc/fh', 0700, true);
        $this->legacyHoldFixtureCreated = true;
        $current = $this->legacyHoldTarget('current', 'current');
        $rollback = $this->legacyHoldTarget('rollback', 'rollback');
        $current['capacity_bounds']['stage_inode_count'] = 1_000_001;
        $rollback['capacity_bounds']['stage_inode_count'] = 1_000_001;
        file_put_contents(
            self::LEGACY_HOLD,
            $this->canonical([
                'schema' => 'legacy_release_hold.v1',
                'targets' => [$current, $rollback],
            ]),
        );
        chmod(self::LEGACY_HOLD, 0600);
        $boundary = $this->runHelper('dry-run');
        self::assertSame(70, $boundary['exit'], $boundary['stdout'] . $boundary['stderr']);
        self::assertNotSame('unsafe_legacy_hold', $this->decode($boundary)['reason']);

        $current['capacity_bounds']['stage_inode_count'] = 1_000_002;
        file_put_contents(
            self::LEGACY_HOLD,
            $this->canonical([
                'schema' => 'legacy_release_hold.v1',
                'targets' => [$current, $rollback],
            ]),
        );
        $over = $this->runHelper('dry-run');
        self::assertSame(70, $over['exit'], $over['stdout'] . $over['stderr']);
        self::assertSame('unsafe_legacy_hold', $this->decode($over)['reason']);
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
            '        clean_pending_entries(state, web_uid, MUTATIONS, preserved_sidecars)',
            "        clean_pending_entries(state, web_uid, MUTATIONS, preserved_sidecars)\n        reject('after_pending_cleanup')",
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
            '                os.kill(os.getpid(), 9)',
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

        file_put_contents(self::BACKUPS . '/.backup-set-producer.lock', '');
        chmod(self::BACKUPS . '/.backup-set-producer.lock', 0600);
        $newBytes = 'dump-new';
        $handoff = [
            'backup_set_id' => $this->dumpLeaf('new'),
            'compressed_size_bytes' => strlen($newBytes),
            'dump_sha256' => hash('sha256', $newBytes),
            'schema' => 'production_backup_set_handoff.v1',
            'uncompressed_size_bytes' => strlen($newBytes),
        ];
        file_put_contents(self::BACKUPS . '/last_backup_set.json', $this->canonical($handoff));
        chmod(self::BACKUPS . '/last_backup_set.json', 0600);
        file_put_contents(
            self::BACKUPS . '/backup_continuity_state.json',
            $this->canonical([
                'handoff' => $handoff,
                'schema' => 'production_backup_continuity_state.v1',
                'status' => 'verified',
            ]),
        );
        chmod(self::BACKUPS . '/backup_continuity_state.json', 0600);
        $markerTime = \DateTimeImmutable::createFromFormat(
            '!Ymd\THis\Z',
            $handoff['backup_set_id'],
            new \DateTimeZone('UTC'),
        );
        self::assertInstanceOf(\DateTimeImmutable::class, $markerTime);
        $marker = $markerTime->format('Y-m-d\TH:i:s\Z') . "\n";
        file_put_contents(self::BACKUPS . '/last_backup_success.utc', $marker);
        chmod(self::BACKUPS . '/last_backup_success.utc', 0600);
        file_put_contents(self::BACKUPS . '/last_verify_success.utc', $marker);
        chmod(self::BACKUPS . '/last_verify_success.utc', 0600);

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

    /** @param array<string, int>|null $capacityBounds */
    private function writeProvenance(string $release, ?array $capacityBounds = null): void
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
                'capacity_bounds' => $capacityBounds ?? [
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
        $whenTimestamp = time() - $age;
        $leaf = gmdate('Ymd\THis\Z', $whenTimestamp);
        $this->dumpLeaves[$name] = $leaf;
        $dir = self::BACKUPS . '/' . $leaf . '/db';
        mkdir($dir, 0700, true);
        $meta = self::BACKUPS . '/' . $leaf . '/meta';
        mkdir($meta, 0700);
        $bytes = 'dump-' . $name;
        file_put_contents($dir . '/easyappointments.sql.gz', $bytes);
        chmod($dir . '/easyappointments.sql.gz', 0600);
        $sha = hash('sha256', $bytes);
        $created = gmdate('Y-m-d\TH:i:s\Z', $whenTimestamp);
        file_put_contents(
            $meta . '/backup.env',
            "schema=production_backup_set.v1\n" .
                "backup_set_id={$leaf}\n" .
                "created_at_utc={$created}\n" .
                "dump_sha256={$sha}\n" .
                'compressed_size_bytes=' .
                strlen($bytes) .
                "\n" .
                'uncompressed_size_bytes=' .
                strlen($bytes) .
                "\n",
        );
        chmod($meta . '/backup.env', 0600);
        $when = $created;
        file_put_contents(
            self::ATTESTATIONS . '/' . $sha . '.json',
            $this->canonical([
                'attested_at_utc' => $when,
                'dump' => [
                    'created_at_utc' => $created,
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

    /** @return array<string,mixed> */
    private function legacyHoldTarget(string $role, string $release): array
    {
        $archive = self::RELEASES . '/' . $release . '.tar.gz';
        return [
            'archive' => [
                'name' => $release . '.tar.gz',
                'sha256' => hash_file('sha256', $archive),
                'size_bytes' => filesize($archive),
            ],
            'capacity_bounds' => $this->legacyCapacityBounds(),
            'release_id' => $release,
            'role_at_provisioning' => $role,
        ];
    }

    private function writeLegacyTarArchive(string $release): void
    {
        $script = <<<'PY'
        import io, sys, tarfile
        with tarfile.open(sys.argv[1], mode='w:gz') as archive:
            data = b'x'
            info = tarfile.TarInfo('app/file.txt')
            info.size = len(data)
            archive.addfile(info, io.BytesIO(data))
        PY;
        $process = proc_open(
            ['/usr/bin/python3', '-c', $script, self::RELEASES . '/' . $release . '.tar.gz'],
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
        chmod(self::RELEASES . '/' . $release . '.tar.gz', 0600);
    }

    private function writeLegacyProvenance(string $release): void
    {
        $this->writeProvenance($release, $this->legacyCapacityBounds());
    }

    /** @return array<string, int> */
    private function legacyCapacityBounds(): array
    {
        return [
            'stage_file_count' => 1,
            'stage_inode_count' => 3,
            'stage_unpacked_bytes' => 12288,
            'temp_scratch_bytes' => 64 * 1024 * 1024,
        ];
    }

    private function dumpSha(string $name): string
    {
        return hash('sha256', 'dump-' . $name);
    }

    private function dumpLeaf(string $name): string
    {
        self::assertArrayHasKey($name, $this->dumpLeaves);
        return $this->dumpLeaves[$name];
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
