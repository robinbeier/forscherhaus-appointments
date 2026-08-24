<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class DumpProducerAdmissionContractTest extends TestCase
{
    public function testRegistryIsCanonicalAndClosed(): void
    {
        $root = dirname(__DIR__, 3);
        $path = $root . '/scripts/ops/config/dump_producer_registry.v1.json';
        $bytes = (string) file_get_contents($path);
        self::assertSame("\n", substr($bytes, -1));
        $value = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($bytes, json_encode($value, JSON_UNESCAPED_SLASHES) . "\n");
        self::assertSame('dump_producer_registry.v1', $value['schema']);
        self::assertSame(['fh_backup_set_producer_v1'], array_keys($value['allowed_producers']));
        $producer = $value['allowed_producers']['fh_backup_set_producer_v1'];
        self::assertSame('/root/backups/easyappointments', $producer['publication_root']);
        self::assertSame('/usr/local/libexec/fh-backup-set-producer-v1', $producer['binary']);
        self::assertSame('/usr/local/libexec/fh-backup-set-producer-supervisor-v1', $producer['supervisor']);
        self::assertSame('20[0-9]{6}T[0-9]{6}Z', $producer['set_leaf_pattern']);
        self::assertSame('db/easyappointments.sql.gz', $producer['database_leaf']);
        self::assertSame('meta/backup.env', $producer['manifest_leaf']);
        self::assertSame('production_backup_set.v1', $producer['manifest_schema']);
        self::assertSame('canonical_easyappointments_database_backup', $producer['purpose']);
        self::assertSame(
            [
                'directory_gid' => 0,
                'directory_mode' => '0700',
                'directory_uid' => 0,
                'mount_crossings' => 'forbidden',
                'regular_file_gid' => 0,
                'regular_file_mode' => '0600',
                'regular_file_nlink' => 1,
                'regular_file_uid' => 0,
                'symlinks' => 'forbidden',
            ],
            $producer['object_contract'],
        );
        self::assertSame('renameat2_noreplace_then_parent_fsync', $producer['publication']['method']);
        self::assertSame(
            ['/var/lib/fh-deploy-orchestrator/locks/fh-production-change.lock', '.backup-set-producer.lock'],
            $producer['publication']['locks'],
        );
        self::assertSame(
            ['private_staging', 'published', 'restore_verified', 'retention_eligible'],
            $producer['lifecycle']['states'],
        );
        self::assertSame('fh_deployment_dump_attestation_v1', $producer['restore_authority']['writer_id']);
        self::assertSame(
            '/var/lib/fh-deploy-evidence/dump-attestations',
            $producer['restore_authority']['attestation_root'],
        );
        self::assertSame('deployment_dump_attestation.v1', $producer['restore_authority']['schema']);
        self::assertSame('docs/ops/production-backup-set-producer.md', $producer['runbook']);
        self::assertSame('preserve_outside_dump_retention', $value['preserved_classes']['install-snapshots']);
        self::assertSame(
            [
                '.backup-set-producer.lock' => [
                    'class' => 'backup_set_producer_lock',
                    'writer_ids' => ['fh_backup_set_producer_v1'],
                ],
                'backup_continuity_state.json' => [
                    'class' => 'backup_continuity_state',
                    'writer_ids' => ['fh_backup_set_producer_v1', 'fh_deployment_dump_attestation_v1'],
                ],
                'last_backup_set.json' => [
                    'class' => 'backup_set_handoff',
                    'writer_ids' => ['fh_backup_set_producer_v1'],
                ],
                'last_backup_success.utc' => [
                    'class' => 'backup_success_marker',
                    'writer_ids' => ['fh_backup_set_producer_v1'],
                ],
                'last_verify_success.utc' => [
                    'class' => 'restore_verify_success_marker',
                    'writer_ids' => ['fh_deployment_dump_attestation_v1'],
                ],
            ],
            $value['top_level_authorities'],
        );
        self::assertStringNotContainsString('password', strtolower($bytes));
        self::assertStringNotContainsString('secret', strtolower($bytes));
    }

    public function testWrapperHasOnlyReadOnlyAdmissionPath(): void
    {
        $root = dirname(__DIR__, 3);
        $wrapper = (string) file_get_contents($root . '/scripts/ops/prod_dump_producer_admission.sh');
        self::assertStringContainsString('--prod-ssh-target', $wrapper);
        self::assertStringContainsString(
            '/usr/bin/python3 -I -B /usr/local/libexec/fh-release-archive-dump-retention-v1 admission-status',
            $wrapper,
        );
        self::assertStringNotContainsString('--execute', $wrapper);
        self::assertStringNotContainsString('confirm-live-write', $wrapper);
        self::assertStringNotContainsString('systemctl ', $wrapper);
        self::assertStringNotContainsString('rm ', $wrapper);
    }

    public function testRetentionHelperPinsRegistryAndManifestBeforeAdmission(): void
    {
        $root = dirname(__DIR__, 3);
        $registryPath = $root . '/scripts/ops/config/dump_producer_registry.v1.json';
        $helper = (string) file_get_contents($root . '/scripts/ops/libexec/release_archive_dump_retention_v1.py');
        self::assertStringContainsString(
            "PRODUCER_REGISTRY_SHA256 = '" . hash_file('sha256', $registryPath) . "'",
            $helper,
        );
        self::assertStringContainsString("ADMISSION_SCHEMA = 'prod_dump_producer_admission.v1'", $helper);
        self::assertStringContainsString('def producer_registry():', $helper);
        self::assertStringContainsString("reject('producer_registry_drift')", $helper);
        self::assertStringContainsString('def validate_backup_manifest(', $helper);
        self::assertStringContainsString("reject('invalid_backup_set_manifest')", $helper);
        self::assertStringContainsString("reject('missing_backup_authority')", $helper);
        self::assertStringContainsString("reject('backup_success_marker_mismatch')", $helper);
        self::assertStringContainsString('MAX_PENDING_RESTORE_AGE_SECONDS = 14_400', $helper);
        self::assertStringContainsString("reject('pending_restore_outside_recovery_window')", $helper);
        self::assertStringContainsString("'producer_registry_sha256': PRODUCER_REGISTRY_SHA256", $helper);
        self::assertStringContainsString("'manifest_bound_verified_dump_count': len(gathered['dumps'])", $helper);
        self::assertStringContainsString("'manifest_bound_dump_count': manifest_bound", $helper);
        self::assertStringContainsString("'pending_restore_verification_count': pending_restore", $helper);
        self::assertStringContainsString("and gathered['dump_pending_restore'] == 0", $helper);

        $scan = substr($helper, (int) strpos($helper, 'def scan_backup_sets('));
        $manifest = strpos($scan, 'validate_backup_manifest(');
        $tree = strpos($scan, 'tree = validate_candidate_tree(');
        self::assertNotFalse($manifest);
        self::assertNotFalse($tree);
        self::assertLessThan($tree, $manifest);

        $admission = substr($helper, (int) strpos($helper, 'def admission_status():'));
        self::assertStringContainsString('global_lock = open_global_lock()', $admission);
        self::assertGreaterThanOrEqual(2, substr_count($admission, 'activity_count()'));
        self::assertStringContainsString("payload['reason'] = 'unclassified_dump_entry'", $admission);
        self::assertStringContainsString('payload.update(MUTATIONS.fields())', $admission);
        self::assertStringNotContainsString(
            'os.unlink(',
            substr($admission, 0, (int) strpos($admission, 'def dry_run():')),
        );
    }

    public function testUnitsAreDesiredStateOnlyAndHardened(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string) file_get_contents($root . '/scripts/ops/systemd/fh-dump-producer-admission.service');
        $timer = (string) file_get_contents($root . '/scripts/ops/systemd/fh-dump-producer-admission.timer');
        self::assertStringContainsString('Type=oneshot', $service);
        self::assertStringContainsString('User=root', $service);
        self::assertStringContainsString(
            'ExecStart=/usr/bin/python3 -I -B /usr/local/libexec/fh-release-archive-dump-retention-v1 admission-status',
            $service,
        );
        self::assertStringContainsString('ProtectSystem=strict', $service);
        self::assertStringContainsString('NoNewPrivileges=yes', $service);
        self::assertStringContainsString('PrivateNetwork=yes', $service);
        self::assertStringContainsString('RestrictAddressFamilies=AF_UNIX', $service);
        self::assertStringNotContainsString('ConditionPathIsDirectory=', $service);
        self::assertStringContainsString(
            'ReadOnlyPaths=/usr/local/libexec/fh-release-archive-dump-retention-v1 -/root/backups/easyappointments',
            $service,
        );
        self::assertStringContainsString(
            'ReadWritePaths=-/var/lib/fh-deploy-orchestrator/locks/fh-production-change.lock',
            $service,
        );
        self::assertStringContainsString(
            'CapabilityBoundingSet=CAP_DAC_OVERRIDE CAP_DAC_READ_SEARCH CAP_SYS_PTRACE',
            $service,
        );
        self::assertStringContainsString('AmbientCapabilities=', $service);
        self::assertStringContainsString('OnCalendar=*-*-* *:00/15:00 UTC', $timer);
        self::assertStringContainsString('Persistent=false', $timer);
        self::assertStringContainsString('Unit=fh-dump-producer-admission.service', $timer);
        self::assertStringNotContainsString('enable', $service . $timer);
        self::assertStringNotContainsString('start', $service . $timer);
    }

    public function testRunbookStatesBoundariesAndStopRules(): void
    {
        $root = dirname(__DIR__, 3);
        $docs = (string) file_get_contents($root . '/docs/ops/production-dump-producer-admission.md');
        foreach (
            [
                'fail-closed',
                'registry',
                'manifest',
                'redaction',
                'repository',
                'installation',
                'read-only',
                'monitoring',
                'Objektmutation',
                'decision_blocked',
                'Stop',
                'systemd-analyze verify',
                'Persistent=false',
            ]
            as $term
        ) {
            self::assertStringContainsString($term, $docs);
        }
        self::assertStringNotContainsString('enable --now', $docs);
        self::assertStringNotContainsString('systemctl start', $docs);
    }

    public function testCleanupInventoryConsumesOnlyAggregateAdmissionExitClass(): void
    {
        $inventory = (string) file_get_contents(dirname(__DIR__, 3) . '/scripts/ops/prod_cleanup_inventory.sh');
        self::assertStringContainsString('"$RELEASE_RETENTION_HELPER" admission-status >/dev/null 2>&1', $inventory);
        self::assertStringContainsString('dump_producer_admission.status', $inventory);
        self::assertStringContainsString('dump_producer_admission.contract', $inventory);
        self::assertStringContainsString('registry_manifest_required', $inventory);
        self::assertStringNotContainsString('dump_producer_admission.output', $inventory);
    }
}
