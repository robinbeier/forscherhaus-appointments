<?php

declare(strict_types=1);

namespace Ops;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;
use WeakMap;

final class TrafficEvidenceInvalidV1 extends RuntimeException {}

final class DeploymentEvidenceAuthorityV1
{
    /** @var WeakMap<VerifiedPredeployGateV1,string>|null */
    private static ?WeakMap $issuedPredeployGates = null;
    public const BUILD_PROVENANCE_SCHEMA = 'release_build_provenance.v1';
    public const DUMP_ATTESTATION_SCHEMA = 'deployment_dump_attestation.v1';
    public const RUN_DUMP_OBSERVATION_SCHEMA = 'deployment_run_dump_observation.v1';
    public const CAPACITY_SCHEMA = 'deployment_capacity_observation.v1';
    public const CHILD_OBSERVATION_SCHEMA = 'deployment_child_observation.v1';
    public const ORCHESTRATOR_START_SCHEMA = 'deployment_orchestrator_start.v1';
    public const PREDEPLOY_ASSEMBLY_SCHEMA = 'deployment_predeploy_evidence_assembly.v1';
    public const MAX_FILE_BYTES = 4_096;
    public const MAX_DUMP_COMPRESSED_BYTES = 17_179_869_184;
    public const MAX_DUMP_UNCOMPRESSED_BYTES = 68_719_476_736;
    private const CAPACITY_DEVICE_KEYS = [
        'artifact',
        'dump_pin',
        'release_root',
        'restore_scratch',
        'stage',
        'state_root',
        'temp',
    ];

    /** @param array<string,mixed> $value */
    public static function encodeFile(array $value): string
    {
        self::assertObject($value, 'authority record');
        try {
            $encoded = json_encode(self::canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new RuntimeException('authority record cannot be encoded');
        }
        if (strlen($encoded) + 1 > self::MAX_FILE_BYTES || str_contains($encoded, "\0")) {
            throw new RuntimeException('authority record exceeds its exact byte contract');
        }
        return $encoded . "\n";
    }

    /** @return array<string,mixed> */
    public static function decodeAuthorizedBuildProvenance(
        string $bytes,
        string $authorizedSha256,
        string $releaseId,
        string $expectedCommit,
        string $observedArchiveSha256,
        int $observedArchiveSizeBytes,
        string $observedHostDeployScriptSha256,
        string $observedArtifactDeployScriptSha256,
        int $observedStageFileCount,
        int $observedStageUnpackedBytes,
        int $observedTempScratchBytes,
    ): array {
        $record = self::decodeAuthorizedBuildProvenanceBounds(
            $bytes,
            $authorizedSha256,
            $releaseId,
            $expectedCommit,
            $observedStageFileCount,
            $observedStageUnpackedBytes,
            $observedTempScratchBytes,
        );
        self::assertSha256($observedArchiveSha256, 'observed archive sha256');
        if (!hash_equals($record['archive']['sha256'], $observedArchiveSha256)) {
            throw new RuntimeException('host archive bytes contradict build provenance');
        }
        if ($record['archive']['size_bytes'] !== $observedArchiveSizeBytes) {
            throw new RuntimeException('host archive size contradicts build provenance');
        }
        self::assertSha256($observedHostDeployScriptSha256, 'observed host deploy script sha256');
        self::assertSha256($observedArtifactDeployScriptSha256, 'observed artifact deploy script sha256');
        if (
            !hash_equals($record['source']['deploy_ea_sha256'], $observedHostDeployScriptSha256) ||
            !hash_equals($record['source']['deploy_ea_sha256'], $observedArtifactDeployScriptSha256)
        ) {
            throw new RuntimeException('host or artifact deploy script contradicts build provenance');
        }
        return $record;
    }

    /**
     * Authenticate the detached sidecar and its independently inspected
     * capacity bounds without deciding the later artifact hash/script gate.
     *
     * @return array<string,mixed>
     */
    private static function decodeAuthorizedBuildProvenanceBounds(
        string $bytes,
        string $authorizedSha256,
        string $releaseId,
        string $expectedCommit,
        int $observedStageFileCount,
        int $observedStageUnpackedBytes,
        int $observedTempScratchBytes,
    ): array {
        self::assertSha256($authorizedSha256, 'authorized provenance sha256');
        if (!hash_equals($authorizedSha256, hash('sha256', $bytes))) {
            throw new RuntimeException('build provenance bytes are not root-authorized');
        }
        $record = self::decodeCanonical($bytes, self::MAX_FILE_BYTES);
        self::assertExactKeys(
            $record,
            ['schema', 'release_id', 'expected_commit', 'observed_commit', 'archive', 'capacity_bounds', 'source'],
            'build provenance',
        );
        self::assertSame($record['schema'], self::BUILD_PROVENANCE_SCHEMA, 'build provenance schema');
        self::assertReleaseId($record['release_id']);
        self::assertSame($record['release_id'], $releaseId, 'build provenance release_id');
        self::assertCommit($record['expected_commit'], 'build provenance expected_commit');
        self::assertCommit($record['observed_commit'], 'build provenance observed_commit');
        self::assertCommit($expectedCommit, 'expected commit authority');
        self::assertSame($record['expected_commit'], $expectedCommit, 'build provenance expected commit authority');
        self::assertSame($record['observed_commit'], $expectedCommit, 'build provenance observed commit');
        self::assertObject($record['archive'], 'build provenance archive');
        self::assertExactKeys($record['archive'], ['name', 'size_bytes', 'sha256'], 'build provenance archive');
        self::assertSame($record['archive']['name'], $releaseId . '.tar.gz', 'build provenance archive name');
        self::assertPositiveInt($record['archive']['size_bytes'], 'build provenance archive size');
        self::assertSha256($record['archive']['sha256'], 'build provenance archive sha256');
        self::assertObject($record['capacity_bounds'], 'build provenance capacity bounds');
        self::assertExactKeys(
            $record['capacity_bounds'],
            ['stage_file_count', 'stage_unpacked_bytes', 'temp_scratch_bytes'],
            'build provenance capacity bounds',
        );
        self::assertPositiveInt($record['capacity_bounds']['stage_file_count'], 'stage file count');
        self::assertPositiveInt($record['capacity_bounds']['stage_unpacked_bytes'], 'stage unpacked bytes');
        self::assertPositiveInt($record['capacity_bounds']['temp_scratch_bytes'], 'temp scratch bytes');
        if (
            $record['capacity_bounds']['stage_file_count'] !== $observedStageFileCount ||
            $record['capacity_bounds']['stage_unpacked_bytes'] !== $observedStageUnpackedBytes ||
            $record['capacity_bounds']['temp_scratch_bytes'] !== $observedTempScratchBytes
        ) {
            throw new RuntimeException('host archive inspection contradicts build provenance capacity bounds');
        }
        self::assertObject($record['source'], 'build provenance source');
        self::assertExactKeys(
            $record['source'],
            ['build_script_sha256', 'composer_lock_sha256', 'package_lock_sha256', 'deploy_ea_sha256'],
            'build provenance source',
        );
        foreach ($record['source'] as $field => $digest) {
            self::assertSha256($digest, 'build provenance source ' . $field);
        }
        return $record;
    }

    /** @return array<string,mixed> */
    private static function decodeDumpAttestation(
        string $bytes,
        string $attestationSha256,
        string $dumpSha256,
        int $dumpSizeBytes,
        string $observedAtUtc,
    ): array {
        self::assertSha256($attestationSha256, 'pinned dump attestation sha256');
        if (!hash_equals($attestationSha256, hash('sha256', $bytes))) {
            throw new RuntimeException('dump attestation bytes do not match pinned authority');
        }
        $record = self::decodeCanonical($bytes, self::MAX_FILE_BYTES);
        self::assertExactKeys($record, ['schema', 'dump', 'verification', 'attested_at_utc'], 'dump attestation');
        self::assertSame($record['schema'], self::DUMP_ATTESTATION_SCHEMA, 'dump attestation schema');
        self::assertObject($record['dump'], 'dump attestation dump');
        self::assertExactKeys(
            $record['dump'],
            ['sha256', 'size_bytes', 'uncompressed_size_bytes', 'created_at_utc'],
            'dump attestation dump',
        );
        self::assertSha256($record['dump']['sha256'], 'dump attestation sha256');
        self::assertSame($record['dump']['sha256'], $dumpSha256, 'dump attestation stable bytes');
        self::assertPositiveInt($record['dump']['size_bytes'], 'dump attestation size');
        self::assertDumpBounds($record['dump']['size_bytes'], $record['dump']['uncompressed_size_bytes']);
        self::assertSame($record['dump']['size_bytes'], $dumpSizeBytes, 'dump attestation stable size');
        self::assertPositiveInt($record['dump']['uncompressed_size_bytes'], 'dump attestation uncompressed size');
        self::assertUtc($record['dump']['created_at_utc'], 'dump attestation created_at');
        self::assertObject($record['verification'], 'dump attestation verification');
        self::assertExactKeys(
            $record['verification'],
            ['method', 'sha256_verified', 'gzip_verified', 'restore_verified', 'restored_at_utc'],
            'dump attestation verification',
        );
        self::assertSame($record['verification']['method'], 'mariadb_10_11_isolated_restore_v1', 'restore method');
        foreach (['sha256_verified', 'gzip_verified', 'restore_verified'] as $field) {
            if ($record['verification'][$field] !== true) {
                throw new RuntimeException('dump attestation verification is incomplete');
            }
        }
        self::assertUtc($record['verification']['restored_at_utc'], 'dump attestation restored_at');
        self::assertUtc($record['attested_at_utc'], 'dump attestation attested_at');
        self::assertUtc($observedAtUtc, 'dump observed_at');
        $created = self::utcEpoch($record['dump']['created_at_utc']);
        $restored = self::utcEpoch($record['verification']['restored_at_utc']);
        $attested = self::utcEpoch($record['attested_at_utc']);
        $observed = self::utcEpoch($observedAtUtc);
        if ($created > $restored || $restored > $attested || $attested > $observed) {
            throw new RuntimeException('dump attestation timestamps are not ordered');
        }
        $age = $observed - $created;
        if ($age < 0 || $age >= 14_400) {
            throw new RuntimeException('dump attestation is future-dated or stale');
        }
        return $record;
    }

    /** @param array<string,mixed> $dump @param array<string,mixed> $restore @return array<string,mixed> */
    public static function createDumpAttestation(array $dump, array $restore, string $attestedAtUtc): array
    {
        self::assertExactKeys(
            $dump,
            ['sha256', 'size_bytes', 'uncompressed_size_bytes', 'created_at_utc'],
            'stable dump',
        );
        self::assertExactKeys(
            $restore,
            [
                'method',
                'dump_sha256',
                'dump_size_bytes',
                'uncompressed_size_bytes',
                'gzip_exit_code',
                'restore_exit_code',
                'restored_at_utc',
            ],
            'restore observation',
        );
        self::assertSha256($dump['sha256'], 'stable dump sha256');
        self::assertPositiveInt($dump['size_bytes'], 'stable dump size');
        self::assertPositiveInt($dump['uncompressed_size_bytes'], 'stable dump uncompressed size');
        self::assertDumpBounds($dump['size_bytes'], $dump['uncompressed_size_bytes']);
        self::assertUtc($dump['created_at_utc'], 'stable dump created_at');
        self::assertSame($restore['method'], 'mariadb_10_11_isolated_restore_v1', 'restore observation method');
        self::assertSha256($restore['dump_sha256'], 'restore observation dump sha256');
        self::assertSame($restore['dump_sha256'], $dump['sha256'], 'restore observation stable sha256');
        self::assertSame($restore['dump_size_bytes'], $dump['size_bytes'], 'restore observation stable size');
        if (($restore['uncompressed_size_bytes'] ?? null) !== $dump['uncompressed_size_bytes']) {
            throw new RuntimeException('restore observation uncompressed size is inconsistent');
        }
        self::assertSame($restore['gzip_exit_code'], 0, 'restore observation gzip exit');
        self::assertSame($restore['restore_exit_code'], 0, 'restore observation restore exit');
        self::assertUtc($restore['restored_at_utc'], 'restore observation restored_at');
        self::assertUtc($attestedAtUtc, 'dump attested_at');
        $record = [
            'schema' => self::DUMP_ATTESTATION_SCHEMA,
            'dump' => $dump,
            'verification' => [
                'method' => $restore['method'],
                'sha256_verified' => true,
                'gzip_verified' => true,
                'restore_verified' => true,
                'restored_at_utc' => $restore['restored_at_utc'],
            ],
            'attested_at_utc' => $attestedAtUtc,
        ];
        $bytes = self::encodeFile($record);
        self::decodeDumpAttestation(
            $bytes,
            hash('sha256', $bytes),
            $dump['sha256'],
            $dump['size_bytes'],
            $attestedAtUtc,
        );
        return $record;
    }

    /** @return array<string,mixed> */
    public static function bindPinnedDumpAttestationToRun(
        string $attestationBytes,
        string $attestationSha256,
        string $runId,
        string $intentSha256,
        string $dumpSha256,
        int $dumpSizeBytes,
        string $observedAtUtc,
    ): array {
        self::assertUuidV4($runId, 'dump observation run_id');
        self::assertSha256($intentSha256, 'dump observation intent_sha256');
        $attestation = self::decodeDumpAttestation(
            $attestationBytes,
            $attestationSha256,
            $dumpSha256,
            $dumpSizeBytes,
            $observedAtUtc,
        );
        return [
            'schema' => self::RUN_DUMP_OBSERVATION_SCHEMA,
            'run_id' => $runId,
            'intent_sha256' => $intentSha256,
            'attestation_sha256' => $attestationSha256,
            'dump_sha256' => $attestation['dump']['sha256'],
            'dump_size_bytes' => $attestation['dump']['size_bytes'],
            'uncompressed_size_bytes' => $attestation['dump']['uncompressed_size_bytes'],
            'observed_at_utc' => $observedAtUtc,
        ];
    }

    /**
     * @return array<string,int|bool>
     */
    public static function capacityFromStatvfs(
        int $filesystemDevice,
        int $blockSize,
        int $blocks,
        int $blocksAvailable,
        ?int $artifactBytes,
        ?int $dumpBytes,
        ?int $stageBytes,
        ?int $tempBytes,
        ?int $rollbackBytes,
        array $componentDevices = [],
    ): array {
        if (
            $filesystemDevice < 0 ||
            $blockSize !== 4096 ||
            $blocks <= 0 ||
            $blocksAvailable < 0 ||
            $blocksAvailable > $blocks
        ) {
            throw new RuntimeException('capacity statvfs snapshot is invalid');
        }
        foreach ([$artifactBytes, $dumpBytes, $stageBytes, $tempBytes, $rollbackBytes] as $bound) {
            if (!is_int($bound) || $bound < 0) {
                throw new RuntimeException('capacity component bound is unavailable');
            }
        }
        $deviceKeys = array_keys($componentDevices);
        sort($deviceKeys);
        if ($deviceKeys !== self::CAPACITY_DEVICE_KEYS) {
            throw new RuntimeException('capacity component device observations are incomplete');
        }
        foreach ($componentDevices as $device) {
            if (!is_int($device) || $device !== $filesystemDevice) {
                throw new RuntimeException('capacity components do not share the target filesystem');
            }
        }
        $total = self::checkedMultiply($blockSize, $blocks);
        $available = self::checkedMultiply($blockSize, $blocksAvailable);
        if ($rollbackBytes !== 0) {
            throw new RuntimeException('capacity v1 rollback bound must be zero');
        }
        $base = self::checkedAdd(
            self::checkedAdd($artifactBytes, $dumpBytes),
            self::checkedAdd(self::checkedAdd($stageBytes, $tempBytes), $rollbackBytes),
        );
        $tenPercent = self::ceilDivide($base, 10);
        $headroom = max(536_870_912, $tenPercent);
        $required = self::checkedAdd($base, $headroom);
        $used = $total - $available;
        $projectedUsed = self::checkedAdd($used, $required);
        $observedPercent = self::ceilDivide(self::checkedMultiply($used, 100), $total);
        $projectedPercent = min(100, self::ceilDivide(self::checkedMultiply($projectedUsed, 100), $total));
        $passed = $available >= $required && $observedPercent < 85 && $projectedPercent < 85;
        return [
            'filesystem_device' => $filesystemDevice,
            'available_bytes' => $available,
            'base_required_bytes' => $base,
            'headroom_bytes' => $headroom,
            'projected_required_bytes' => $required,
            'observed_percent' => $observedPercent,
            'projected_percent' => $projectedPercent,
            'max_used_percent' => 85,
            'passed' => $passed,
        ];
    }

    /** @return array<string,int|bool> */
    public static function capacityFromVerifiedAuthorities(
        int $filesystemDevice,
        int $blockSize,
        int $blocks,
        int $blocksAvailable,
        string $provenanceBytes,
        string $authorizedProvenanceSha256,
        string $releaseId,
        string $expectedCommit,
        string $archiveSha256,
        int $archiveSizeBytes,
        string $hostDeployScriptSha256,
        string $artifactDeployScriptSha256,
        int $stageFileCount,
        int $stageUnpackedBytes,
        int $tempScratchBytes,
        string $attestationBytes,
        string $attestationSha256,
        string $runId,
        string $intentSha256,
        string $dumpSha256,
        int $dumpSizeBytes,
        string $observedAtUtc,
        array $componentDevices,
    ): array {
        $verifiedProvenance = self::decodeAuthorizedBuildProvenanceBounds(
            $provenanceBytes,
            $authorizedProvenanceSha256,
            $releaseId,
            $expectedCommit,
            $stageFileCount,
            $stageUnpackedBytes,
            $tempScratchBytes,
        );
        self::assertSha256($archiveSha256, 'capacity observed archive sha256');
        self::assertPositiveInt($archiveSizeBytes, 'capacity observed archive size');
        self::assertSha256($hostDeployScriptSha256, 'capacity observed host deploy script sha256');
        self::assertSha256($artifactDeployScriptSha256, 'capacity observed artifact deploy script sha256');
        $verifiedDumpObservation = self::bindPinnedDumpAttestationToRun(
            $attestationBytes,
            $attestationSha256,
            $runId,
            $intentSha256,
            $dumpSha256,
            $dumpSizeBytes,
            $observedAtUtc,
        );
        return self::capacityFromStatvfs(
            $filesystemDevice,
            $blockSize,
            $blocks,
            $blocksAvailable,
            $archiveSizeBytes,
            $verifiedDumpObservation['dump_size_bytes'],
            $verifiedProvenance['capacity_bounds']['stage_unpacked_bytes'],
            self::checkedAdd(
                $verifiedProvenance['capacity_bounds']['temp_scratch_bytes'],
                $verifiedDumpObservation['uncompressed_size_bytes'],
            ),
            0,
            $componentDevices,
        );
    }

    /** @return array<string,mixed> */
    public static function deriveBuildEvidence(
        string $provenanceBytes,
        string $authorizedProvenanceSha256,
        string $releaseId,
        string $expectedCommit,
        string $archiveSha256,
        int $archiveSizeBytes,
        string $hostDeployScriptSha256,
        string $artifactDeployScriptSha256,
        int $stageFileCount,
        int $stageUnpackedBytes,
        int $tempScratchBytes,
    ): array {
        $record = self::decodeAuthorizedBuildProvenance(
            $provenanceBytes,
            $authorizedProvenanceSha256,
            $releaseId,
            $expectedCommit,
            $archiveSha256,
            $archiveSizeBytes,
            $hostDeployScriptSha256,
            $artifactDeployScriptSha256,
            $stageFileCount,
            $stageUnpackedBytes,
            $tempScratchBytes,
        );
        return [
            'expected_commit' => [
                'expected' => $record['expected_commit'],
                'observed' => $record['observed_commit'],
                'verified' => true,
            ],
            'artifact' => [
                'status' => 'passed',
                'expectation' => 'build_from_expected_commit',
                'local_sha256' => $record['archive']['sha256'],
                'remote_sha256' => $archiveSha256,
                'manifest_sha256' => hash('sha256', $provenanceBytes),
                'host_script_sha256' => $hostDeployScriptSha256,
                'artifact_script_sha256' => $record['source']['deploy_ea_sha256'],
                'verified' => true,
            ],
        ];
    }

    private static function verifyArtifactGate(
        string $runId,
        string $intentSha256,
        string $provenanceBytes,
        string $authorizedProvenanceSha256,
        string $releaseId,
        string $expectedCommit,
        string $archiveSha256,
        int $archiveSizeBytes,
        string $hostDeployScriptSha256,
        string $artifactDeployScriptSha256,
        int $stageFileCount,
        int $stageUnpackedBytes,
        int $tempScratchBytes,
    ): VerifiedPredeployGateV1 {
        $record = self::decodeAuthorizedBuildProvenanceBounds(
            $provenanceBytes,
            $authorizedProvenanceSha256,
            $releaseId,
            $expectedCommit,
            $stageFileCount,
            $stageUnpackedBytes,
            $tempScratchBytes,
        );
        self::assertSha256($archiveSha256, 'artifact observed archive sha256');
        self::assertPositiveInt($archiveSizeBytes, 'artifact observed archive size');
        self::assertSha256($hostDeployScriptSha256, 'artifact observed host deploy script sha256');
        self::assertSha256($artifactDeployScriptSha256, 'artifact observed deploy script sha256');
        if ($record['archive']['size_bytes'] !== $archiveSizeBytes) {
            throw new RuntimeException('artifact archive size cannot be represented by deployment evidence v1');
        }
        if (
            !hash_equals($record['archive']['sha256'], $archiveSha256) ||
            !hash_equals($record['source']['deploy_ea_sha256'], $hostDeployScriptSha256) ||
            !hash_equals($record['source']['deploy_ea_sha256'], $artifactDeployScriptSha256)
        ) {
            return self::observeArtifactFailureFromCollector(
                $runId,
                $intentSha256,
                $record['archive']['sha256'],
                $archiveSha256,
                hash('sha256', $provenanceBytes),
                $hostDeployScriptSha256,
                $artifactDeployScriptSha256,
            );
        }
        return self::issueGate('artifact', $runId, $intentSha256, [
            'status' => 'passed',
            'expectation' => 'build_from_expected_commit',
            'local_sha256' => $record['archive']['sha256'],
            'remote_sha256' => $archiveSha256,
            'manifest_sha256' => hash('sha256', $provenanceBytes),
            'host_script_sha256' => $hostDeployScriptSha256,
            'artifact_script_sha256' => $artifactDeployScriptSha256,
            'verified' => true,
        ]);
    }

    private static function verifyBuildGate(
        string $runId,
        string $intentSha256,
        string $provenanceBytes,
        string $authorizedProvenanceSha256,
        string $releaseId,
        string $expectedCommit,
        string $archiveSha256,
        int $archiveSizeBytes,
        string $hostDeployScriptSha256,
        string $artifactDeployScriptSha256,
        int $stageFileCount,
        int $stageUnpackedBytes,
        int $tempScratchBytes,
    ): VerifiedPredeployGateV1 {
        self::assertUuidV4($runId, 'build gate run_id');
        self::assertSha256($intentSha256, 'build gate intent_sha256');
        return self::issueGate(
            'build',
            $runId,
            $intentSha256,
            self::deriveBuildEvidence(
                $provenanceBytes,
                $authorizedProvenanceSha256,
                $releaseId,
                $expectedCommit,
                $archiveSha256,
                $archiveSizeBytes,
                $hostDeployScriptSha256,
                $artifactDeployScriptSha256,
                $stageFileCount,
                $stageUnpackedBytes,
                $tempScratchBytes,
            ),
        );
    }

    /** @return array<string,mixed> */
    public static function verifyAndDeriveDumpEvidence(
        string $attestationBytes,
        string $pinnedAttestationSha256,
        string $expectedRunId,
        string $expectedIntentSha256,
        string $stableDumpSha256,
        int $stableDumpSizeBytes,
        string $observedAtUtc,
    ): array {
        $boundDumpObservation = self::bindPinnedDumpAttestationToRun(
            $attestationBytes,
            $pinnedAttestationSha256,
            $expectedRunId,
            $expectedIntentSha256,
            $stableDumpSha256,
            $stableDumpSizeBytes,
            $observedAtUtc,
        );
        $attestation = self::decodeDumpAttestation(
            $attestationBytes,
            $boundDumpObservation['attestation_sha256'],
            $boundDumpObservation['dump_sha256'],
            $boundDumpObservation['dump_size_bytes'],
            $boundDumpObservation['observed_at_utc'],
        );
        $age =
            self::utcEpoch($boundDumpObservation['observed_at_utc']) -
            self::utcEpoch($attestation['dump']['created_at_utc']);
        $section = [
            'status' => 'passed',
            'policy' => 'fresh_verified_under_240m',
            'age_seconds' => $age,
            'max_age_seconds' => 14_400,
            'sha256' => $boundDumpObservation['dump_sha256'],
            'sha256_verified' => true,
            'gzip_verified' => true,
            'restore_verified' => true,
        ];
        return $section;
    }

    private static function verifyDumpGate(
        string $attestationBytes,
        string $pinnedAttestationSha256,
        string $expectedRunId,
        string $expectedIntentSha256,
        string $stableDumpSha256,
        int $stableDumpSizeBytes,
        string $observedAtUtc,
    ): VerifiedPredeployGateV1 {
        return self::issueGate(
            'dump',
            $expectedRunId,
            $expectedIntentSha256,
            self::verifyAndDeriveDumpEvidence(
                $attestationBytes,
                $pinnedAttestationSha256,
                $expectedRunId,
                $expectedIntentSha256,
                $stableDumpSha256,
                $stableDumpSizeBytes,
                $observedAtUtc,
            ),
        );
    }

    /** @return array<string,mixed> */
    public static function verifyAndDeriveCapacityEvidence(
        int $filesystemDevice,
        int $blockSize,
        int $blocks,
        int $blocksAvailable,
        string $provenanceBytes,
        string $authorizedProvenanceSha256,
        string $releaseId,
        string $expectedCommit,
        string $archiveSha256,
        int $archiveSizeBytes,
        string $hostDeployScriptSha256,
        string $artifactDeployScriptSha256,
        int $stageFileCount,
        int $stageUnpackedBytes,
        int $tempScratchBytes,
        string $attestationBytes,
        string $attestationSha256,
        string $runId,
        string $intentSha256,
        string $dumpSha256,
        int $dumpSizeBytes,
        string $observedAtUtc,
        array $componentDevices,
    ): array {
        $observation = self::capacityFromVerifiedAuthorities(
            $filesystemDevice,
            $blockSize,
            $blocks,
            $blocksAvailable,
            $provenanceBytes,
            $authorizedProvenanceSha256,
            $releaseId,
            $expectedCommit,
            $archiveSha256,
            $archiveSizeBytes,
            $hostDeployScriptSha256,
            $artifactDeployScriptSha256,
            $stageFileCount,
            $stageUnpackedBytes,
            $tempScratchBytes,
            $attestationBytes,
            $attestationSha256,
            $runId,
            $intentSha256,
            $dumpSha256,
            $dumpSizeBytes,
            $observedAtUtc,
            $componentDevices,
        );
        return [
            'status' => $observation['passed'] ? 'passed' : 'failed',
            'available_bytes' => $observation['available_bytes'],
            'projected_required_bytes' => $observation['projected_required_bytes'],
            'observed_percent' => $observation['observed_percent'],
            'projected_percent' => $observation['projected_percent'],
            'max_used_percent' => $observation['max_used_percent'],
            'passed' => $observation['passed'],
        ];
    }

    private static function verifyCapacityGate(
        int $filesystemDevice,
        int $blockSize,
        int $blocks,
        int $blocksAvailable,
        string $provenanceBytes,
        string $authorizedProvenanceSha256,
        string $releaseId,
        string $expectedCommit,
        string $archiveSha256,
        int $archiveSizeBytes,
        string $hostDeployScriptSha256,
        string $artifactDeployScriptSha256,
        int $stageFileCount,
        int $stageUnpackedBytes,
        int $tempScratchBytes,
        string $attestationBytes,
        string $attestationSha256,
        string $runId,
        string $intentSha256,
        string $dumpSha256,
        int $dumpSizeBytes,
        string $observedAtUtc,
        array $componentDevices,
    ): VerifiedPredeployGateV1 {
        return self::issueGate(
            'capacity',
            $runId,
            $intentSha256,
            self::verifyAndDeriveCapacityEvidence(
                $filesystemDevice,
                $blockSize,
                $blocks,
                $blocksAvailable,
                $provenanceBytes,
                $authorizedProvenanceSha256,
                $releaseId,
                $expectedCommit,
                $archiveSha256,
                $archiveSizeBytes,
                $hostDeployScriptSha256,
                $artifactDeployScriptSha256,
                $stageFileCount,
                $stageUnpackedBytes,
                $tempScratchBytes,
                $attestationBytes,
                $attestationSha256,
                $runId,
                $intentSha256,
                $dumpSha256,
                $dumpSizeBytes,
                $observedAtUtc,
                $componentDevices,
            ),
        );
    }

    /** @return array<string,mixed> */
    public static function verifyAndDeriveTrafficEvidence(
        string $reportBytes,
        string $pinnedReportSha256,
        string $expectedRunId,
        string $expectedIntentSha256,
        string $expectedMode,
        string $expectedProducerSha256,
        string $expectedCatalogVersion,
        int $expectedWindowStartEpoch,
        int $expectedWindowEndEpoch,
    ): VerifiedPredeployGateV1 {
        require_once __DIR__ . '/VerifiedPredeployGateV1.php';
        self::assertUuidV4($expectedRunId, 'traffic authority run_id');
        self::assertSha256($expectedIntentSha256, 'traffic authority intent_sha256');
        self::assertSha256($expectedProducerSha256, 'traffic authority producer_sha256');
        if (
            preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}\.[1-9][0-9]*$/D', $expectedCatalogVersion) !== 1 ||
            $expectedWindowStartEpoch <= 0 ||
            $expectedWindowEndEpoch <= $expectedWindowStartEpoch
        ) {
            throw new RuntimeException('traffic authority requested window is invalid');
        }
        self::assertSha256($pinnedReportSha256, 'pinned traffic report sha256');
        if (!hash_equals($pinnedReportSha256, hash('sha256', $reportBytes))) {
            throw new RuntimeException('traffic report bytes do not match pinned authority');
        }
        if (
            $reportBytes === '' ||
            strlen($reportBytes) > 262_144 ||
            !str_ends_with($reportBytes, "\n") ||
            str_contains($reportBytes, "\0") ||
            str_contains($reportBytes, "\r")
        ) {
            throw new TrafficEvidenceInvalidV1('traffic report bytes are invalid');
        }
        try {
            $report = json_decode(substr($reportBytes, 0, -1), true, 64, JSON_THROW_ON_ERROR);
            $canonical = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        } catch (JsonException) {
            throw new TrafficEvidenceInvalidV1('traffic report JSON is invalid');
        }
        try {
            self::assertObject($report, 'traffic report');
            self::assertExactKeys(
                $report,
                [
                    'schema',
                    'producer_sha256',
                    'policy_version',
                    'catalog_version',
                    'purpose',
                    'mode',
                    'window_start_epoch',
                    'window_end_epoch',
                    'window_seconds',
                    'log_set_sha256',
                    'rotation_complete',
                    'parse_complete',
                    'evidence_complete',
                    'decision',
                    'exit_code',
                    'counts',
                ],
                'traffic report',
            );
        } catch (RuntimeException $error) {
            throw new TrafficEvidenceInvalidV1('traffic report schema is invalid', previous: $error);
        }
        if (!hash_equals($reportBytes, $canonical)) {
            throw new TrafficEvidenceInvalidV1('traffic report bytes are not canonical producer output');
        }
        if (!in_array($expectedMode, ['normal', 'no-business-traffic'], true)) {
            throw new RuntimeException('traffic report expected mode is invalid');
        }
        $section = ['status' => ($report['exit_code'] ?? null) === 0 ? 'passed' : 'failed'];
        $section['report_sha256'] = $pinnedReportSha256;
        foreach (
            [
                'schema',
                'producer_sha256',
                'policy_version',
                'catalog_version',
                'purpose',
                'mode',
                'window_start_epoch',
                'window_end_epoch',
                'window_seconds',
                'log_set_sha256',
                'rotation_complete',
                'parse_complete',
                'evidence_complete',
                'decision',
                'exit_code',
                'counts',
            ]
            as $field
        ) {
            $section[$field] = $report[$field] ?? null;
        }
        if (
            ($section['purpose'] ?? null) !== 'deploy' ||
            ($section['mode'] ?? null) !== $expectedMode ||
            ($section['producer_sha256'] ?? null) !== $expectedProducerSha256 ||
            ($section['catalog_version'] ?? null) !== $expectedCatalogVersion ||
            ($section['window_start_epoch'] ?? null) !== $expectedWindowStartEpoch ||
            ($section['window_end_epoch'] ?? null) !== $expectedWindowEndEpoch
        ) {
            throw new RuntimeException('traffic report does not bind the deployment intent');
        }
        require_once __DIR__ . '/DeploymentContractV1.php';
        try {
            DeploymentContractV1::validatePredeploySections([
                'expected_commit' => self::notObservedExpectedCommit(),
                'traffic_gate' => $section,
                'dump' => self::notObservedDump(),
                'capacity' => self::notObservedCapacity(),
                'artifact' => self::notObservedArtifact(),
            ]);
        } catch (RuntimeException $error) {
            throw new TrafficEvidenceInvalidV1('traffic report core is invalid', previous: $error);
        }
        return self::issueGate('traffic_gate', $expectedRunId, $expectedIntentSha256, $section);
    }

    /**
     * Freeze the ordered pre-deploy result. Every supplied section has already
     * been produced by one of this class's source-bound collectors. This method
     * validates the complete section semantics again, selects the first
     * non-passing gate, and discards every later observation.
     *
     * @param array<string,mixed> $expectedCommit
     * @param array<string,mixed> $traffic
     * @param array<string,mixed> $dump
     * @param array<string,mixed> $capacity
     * @param array<string,mixed> $artifact
     * @return array<string,mixed>
     */
    private static function assemblePredeployEvidence(
        array $expectedCommit,
        array $traffic,
        array $dump,
        array $capacity,
        array $artifact,
    ): array {
        require_once __DIR__ . '/DeploymentContractV1.php';
        $sections = compact('expectedCommit', 'traffic', 'dump', 'capacity', 'artifact');
        $sections = [
            'expected_commit' => $sections['expectedCommit'],
            'traffic_gate' => $sections['traffic'],
            'dump' => $sections['dump'],
            'capacity' => $sections['capacity'],
            'artifact' => $sections['artifact'],
        ];
        DeploymentContractV1::validatePredeploySections($sections);

        $reason = null;
        $exitCode = 0;
        if ($sections['expected_commit']['verified'] !== true) {
            [$reason, $exitCode] = ['expected_commit_mismatch', 25];
            $sections['traffic_gate'] = self::notObservedTraffic();
            $sections['dump'] = self::notObservedDump();
            $sections['capacity'] = self::notObservedCapacity();
            $sections['artifact'] = self::notObservedArtifact();
        } elseif ($sections['traffic_gate']['status'] !== 'passed') {
            [$reason, $exitCode] =
                $sections['traffic_gate']['status'] === 'invalid'
                    ? ['traffic_evidence_invalid', 21]
                    : (($sections['traffic_gate']['exit_code'] ?? null) === 20
                        ? ['traffic_hard_stop', 20]
                        : ['traffic_evidence_invalid', 21]);
            $sections['dump'] = self::notObservedDump();
            $sections['capacity'] = self::notObservedCapacity();
            $sections['artifact'] = self::notObservedArtifact();
        } elseif ($sections['dump']['status'] !== 'passed') {
            [$reason, $exitCode] = ['dump_verification_failed', 22];
            $sections['capacity'] = self::notObservedCapacity();
            $sections['artifact'] = self::notObservedArtifact();
        } elseif ($sections['capacity']['status'] !== 'passed') {
            [$reason, $exitCode] = ['capacity_gate_failed', 23];
            $sections['artifact'] = self::notObservedArtifact();
        } elseif ($sections['artifact']['status'] !== 'passed') {
            [$reason, $exitCode] = ['artifact_verification_failed', 24];
        }
        DeploymentContractV1::validatePredeploySections($sections);
        return [
            'schema' => self::PREDEPLOY_ASSEMBLY_SCHEMA,
            'status' => $reason === null ? 'passed' : 'failed',
            'exit_code' => $exitCode,
            'reason' => $reason ?? 'ok',
            'sections' => $sections,
        ];
    }

    private static function observeExpectedCommitGate(
        string $runId,
        string $intentSha256,
        string $expectedCommit,
        ?string $observedCommit,
    ): VerifiedPredeployGateV1 {
        self::assertUuidV4($runId, 'expected commit gate run_id');
        self::assertSha256($intentSha256, 'expected commit gate intent_sha256');
        self::assertCommit($expectedCommit, 'expected commit gate expected');
        if ($observedCommit !== null) {
            self::assertCommit($observedCommit, 'expected commit gate observed');
        }
        return self::issueGate('expected_commit', $runId, $intentSha256, [
            'expected' => $expectedCommit,
            'observed' => $observedCommit,
            'verified' => $observedCommit !== null && hash_equals($expectedCommit, $observedCommit),
        ]);
    }

    private static function observeExpectedCommitFromPinnedProvenance(
        string $runId,
        string $intentSha256,
        string $provenanceBytes,
        string $pinnedProvenanceSha256,
        string $expectedCommit,
    ): VerifiedPredeployGateV1 {
        self::assertSha256($pinnedProvenanceSha256, 'expected commit pinned provenance sha256');
        if (!hash_equals($pinnedProvenanceSha256, hash('sha256', $provenanceBytes))) {
            throw new RuntimeException('expected commit provenance bytes conflict with their protected pin');
        }
        $record = self::decodeCanonical($provenanceBytes, self::MAX_FILE_BYTES);
        self::assertExactKeys(
            $record,
            ['schema', 'release_id', 'expected_commit', 'observed_commit', 'archive', 'capacity_bounds', 'source'],
            'expected commit provenance',
        );
        self::assertSame($record['schema'], self::BUILD_PROVENANCE_SCHEMA, 'expected commit provenance schema');
        self::assertCommit($record['expected_commit'], 'expected commit provenance expected');
        self::assertCommit($record['observed_commit'], 'expected commit provenance observed');
        self::assertSame($record['expected_commit'], $expectedCommit, 'expected commit protected intent');
        return self::observeExpectedCommitGate($runId, $intentSha256, $expectedCommit, $record['observed_commit']);
    }

    /** @param array<string,mixed> $section */
    private static function observeInternalGateFailure(
        string $gate,
        string $runId,
        string $intentSha256,
        array $section,
    ): VerifiedPredeployGateV1 {
        if (!in_array($gate, ['traffic_gate', 'dump', 'capacity', 'artifact'], true)) {
            throw new RuntimeException('internal failed gate name is invalid');
        }
        require_once __DIR__ . '/DeploymentContractV1.php';
        $sections = [
            'expected_commit' => self::notObservedExpectedCommit(),
            'traffic_gate' => self::notObservedTraffic(),
            'dump' => self::notObservedDump(),
            'capacity' => self::notObservedCapacity(),
            'artifact' => self::notObservedArtifact(),
        ];
        $sections[$gate] = $section;
        DeploymentContractV1::validatePredeploySections($sections);
        if (!in_array($section['status'] ?? null, ['failed', 'invalid'], true)) {
            throw new RuntimeException('internal failed gate must retain failed or invalid status');
        }
        return self::issueGate($gate, $runId, $intentSha256, $section);
    }

    private static function observeMissingTrafficGate(
        string $runId,
        string $intentSha256,
        ?string $pinnedReportSha256,
    ): VerifiedPredeployGateV1 {
        if ($pinnedReportSha256 !== null) {
            self::assertSha256($pinnedReportSha256, 'missing traffic pinned sha256');
        }
        $section = self::notObservedTraffic();
        $section['status'] = 'invalid';
        $section['report_sha256'] = $pinnedReportSha256;
        $section['exit_code'] = 21;
        return self::observeInternalGateFailure('traffic_gate', $runId, $intentSha256, $section);
    }

    private static function observeInvalidTrafficFromPinnedBytes(
        string $runId,
        string $intentSha256,
        ?string $pinnedReportBytes,
    ): VerifiedPredeployGateV1 {
        return self::observeMissingTrafficGate(
            $runId,
            $intentSha256,
            $pinnedReportBytes === null ? null : hash('sha256', $pinnedReportBytes),
        );
    }

    /** @param array<string,mixed> $stableDumpObservation */
    private static function observeDumpGateFailure(
        string $runId,
        string $intentSha256,
        array $stableDumpObservation,
        bool $measurementComplete,
    ): VerifiedPredeployGateV1 {
        self::assertExactKeys(
            $stableDumpObservation,
            ['age_seconds', 'sha256', 'sha256_verified', 'gzip_verified', 'restore_verified'],
            'failed dump collector observation',
        );
        $section = [
            'status' => $measurementComplete ? 'failed' : 'invalid',
            'policy' => 'fresh_verified_under_240m',
            'age_seconds' => $stableDumpObservation['age_seconds'],
            'max_age_seconds' => 14_400,
            'sha256' => $stableDumpObservation['sha256'],
            'sha256_verified' => $stableDumpObservation['sha256_verified'],
            'gzip_verified' => $stableDumpObservation['gzip_verified'],
            'restore_verified' => $stableDumpObservation['restore_verified'],
        ];
        return self::observeInternalGateFailure('dump', $runId, $intentSha256, $section);
    }

    private static function observeDumpFailureFromCollector(
        string $runId,
        string $intentSha256,
        ?int $ageSeconds,
        ?string $dumpSha256,
        ?bool $sha256Verified,
        ?bool $gzipVerified,
        ?bool $restoreVerified,
    ): VerifiedPredeployGateV1 {
        $complete =
            $ageSeconds !== null &&
            $dumpSha256 !== null &&
            $sha256Verified !== null &&
            $gzipVerified !== null &&
            $restoreVerified !== null;
        return self::observeDumpGateFailure(
            $runId,
            $intentSha256,
            [
                'age_seconds' => $ageSeconds,
                'sha256' => $dumpSha256,
                'sha256_verified' => $sha256Verified,
                'gzip_verified' => $gzipVerified,
                'restore_verified' => $restoreVerified,
            ],
            $complete,
        );
    }

    /** @param array<string,mixed> $statvfsObservation */
    private static function observeCapacityGateFailure(
        string $runId,
        string $intentSha256,
        array $statvfsObservation,
        bool $measurementComplete,
    ): VerifiedPredeployGateV1 {
        self::assertExactKeys(
            $statvfsObservation,
            ['available_bytes', 'projected_required_bytes', 'observed_percent', 'projected_percent'],
            'failed capacity collector observation',
        );
        $section = [
            'status' => $measurementComplete ? 'failed' : 'invalid',
            ...$statvfsObservation,
            'max_used_percent' => 85,
            'passed' => $measurementComplete ? false : null,
        ];
        return self::observeInternalGateFailure('capacity', $runId, $intentSha256, $section);
    }

    private static function observeCapacityFailureFromCollector(
        string $runId,
        string $intentSha256,
        ?int $availableBytes,
        ?int $projectedRequiredBytes,
        ?int $observedPercent,
        ?int $projectedPercent,
    ): VerifiedPredeployGateV1 {
        $complete =
            $availableBytes !== null &&
            $projectedRequiredBytes !== null &&
            $observedPercent !== null &&
            $projectedPercent !== null;
        return self::observeCapacityGateFailure(
            $runId,
            $intentSha256,
            [
                'available_bytes' => $availableBytes,
                'projected_required_bytes' => $projectedRequiredBytes,
                'observed_percent' => $observedPercent,
                'projected_percent' => $projectedPercent,
            ],
            $complete,
        );
    }

    /** @param array<string,mixed> $stableArtifactObservation */
    private static function observeArtifactGateFailure(
        string $runId,
        string $intentSha256,
        array $stableArtifactObservation,
        bool $measurementComplete,
    ): VerifiedPredeployGateV1 {
        self::assertExactKeys(
            $stableArtifactObservation,
            ['local_sha256', 'remote_sha256', 'manifest_sha256', 'host_script_sha256', 'artifact_script_sha256'],
            'failed artifact collector observation',
        );
        $section = [
            'status' => $measurementComplete ? 'failed' : 'invalid',
            'expectation' => 'build_from_expected_commit',
            ...$stableArtifactObservation,
            'verified' => $measurementComplete,
        ];
        return self::observeInternalGateFailure('artifact', $runId, $intentSha256, $section);
    }

    private static function observeArtifactFailureFromCollector(
        string $runId,
        string $intentSha256,
        ?string $localSha256,
        ?string $remoteSha256,
        ?string $manifestSha256,
        ?string $hostScriptSha256,
        ?string $artifactScriptSha256,
    ): VerifiedPredeployGateV1 {
        $complete =
            $localSha256 !== null &&
            $remoteSha256 !== null &&
            $manifestSha256 !== null &&
            $hostScriptSha256 !== null &&
            $artifactScriptSha256 !== null;
        return self::observeArtifactGateFailure(
            $runId,
            $intentSha256,
            [
                'local_sha256' => $localSha256,
                'remote_sha256' => $remoteSha256,
                'manifest_sha256' => $manifestSha256,
                'host_script_sha256' => $hostScriptSha256,
                'artifact_script_sha256' => $artifactScriptSha256,
            ],
            $complete,
        );
    }

    /**
     * Invoke the privileged source collectors in exact gate order. Each
     * collector must return a carrier registered by one of the source-bound
     * verifier methods above. Later collectors are never invoked after the
     * first failed or invalid result.
     */
    public static function collectPredeployEvidence(
        ProtectedPredeployObservationProvider $provider,
        string $runId,
        string $intentSha256,
        string $expectedCommit,
        string $expectedTrafficMode,
    ): array {
        require_once __DIR__ . '/ProtectedPredeployObservationProvider.php';
        self::assertUuidV4($runId, 'predeploy provider run_id');
        self::assertSha256($intentSha256, 'predeploy provider intent_sha256');
        self::assertCommit($expectedCommit, 'predeploy provider expected commit');
        $collectors = [
            'expected_commit' => static function () use (
                $provider,
                $runId,
                $intentSha256,
                $expectedCommit,
            ): VerifiedPredeployGateV1 {
                $observation = $provider->expectedCommit();
                return self::observeExpectedCommitFromPinnedProvenance(
                    $runId,
                    $intentSha256,
                    $observation->provenanceBytes,
                    $observation->pinnedProvenanceSha256,
                    $expectedCommit,
                );
            },
            'traffic_gate' => static function () use (
                $provider,
                $runId,
                $intentSha256,
                $expectedTrafficMode,
            ): VerifiedPredeployGateV1 {
                $observation = $provider->traffic();
                if ($observation->pinnedReportBytes === null) {
                    if ($observation->pinnedReportSha256 !== null) {
                        throw new RuntimeException('traffic observation cannot claim a hash without pinned bytes');
                    }
                    return self::observeMissingTrafficGate($runId, $intentSha256, null);
                }
                if (
                    $observation->pinnedReportSha256 === null ||
                    !hash_equals($observation->pinnedReportSha256, hash('sha256', $observation->pinnedReportBytes))
                ) {
                    throw new RuntimeException('traffic protected pin contradicts observed bytes');
                }
                try {
                    return self::verifyAndDeriveTrafficEvidence(
                        $observation->pinnedReportBytes,
                        $observation->pinnedReportSha256,
                        $runId,
                        $intentSha256,
                        $expectedTrafficMode,
                        $observation->expectedProducerSha256,
                        $observation->expectedCatalogVersion,
                        $observation->windowStartEpoch,
                        $observation->windowEndEpoch,
                    );
                } catch (TrafficEvidenceInvalidV1) {
                    return self::observeMissingTrafficGate($runId, $intentSha256, $observation->pinnedReportSha256);
                }
            },
            'dump' => static function () use ($provider, $runId, $intentSha256): VerifiedPredeployGateV1 {
                $observation = $provider->dump();
                $hasAttestation =
                    $observation->attestationBytes !== null ||
                    $observation->pinnedAttestationSha256 !== null ||
                    $observation->stableDumpSizeBytes !== null ||
                    $observation->observedAtUtc !== null;
                $hasFallback =
                    $observation->ageSeconds !== null ||
                    $observation->sha256Verified !== null ||
                    $observation->gzipVerified !== null ||
                    $observation->restoreVerified !== null;
                if ($hasAttestation && $hasFallback) {
                    throw new RuntimeException('dump observation contains conflicting source modes');
                }
                if (
                    $observation->attestationBytes !== null &&
                    $observation->pinnedAttestationSha256 !== null &&
                    $observation->dumpSha256 !== null &&
                    $observation->stableDumpSizeBytes !== null &&
                    $observation->observedAtUtc !== null
                ) {
                    return self::verifyDumpGate(
                        $observation->attestationBytes,
                        $observation->pinnedAttestationSha256,
                        $runId,
                        $intentSha256,
                        $observation->dumpSha256,
                        $observation->stableDumpSizeBytes,
                        $observation->observedAtUtc,
                    );
                }
                if ($hasAttestation) {
                    throw new RuntimeException('dump protected source tuple is incomplete');
                }
                return self::observeDumpFailureFromCollector(
                    $runId,
                    $intentSha256,
                    $observation->ageSeconds,
                    $observation->dumpSha256,
                    $observation->sha256Verified,
                    $observation->gzipVerified,
                    $observation->restoreVerified,
                );
            },
            'capacity' => static function () use (
                $provider,
                $runId,
                $intentSha256,
                $expectedCommit,
            ): VerifiedPredeployGateV1 {
                $observation = $provider->capacity();
                $hasFallback =
                    $observation->availableBytes !== null ||
                    $observation->projectedRequiredBytes !== null ||
                    $observation->observedPercent !== null ||
                    $observation->projectedPercent !== null;
                if ($observation->verifiedSources !== null && $hasFallback) {
                    throw new RuntimeException('capacity observation contains conflicting source modes');
                }
                if ($observation->verifiedSources !== null) {
                    $sources = $observation->verifiedSources;
                    $build = $sources->build;
                    return self::verifyCapacityGate(
                        $sources->filesystemDevice,
                        $sources->blockSize,
                        $sources->blocks,
                        $sources->blocksAvailable,
                        $build->provenanceBytes,
                        $build->authorizedProvenanceSha256,
                        $build->releaseId,
                        $expectedCommit,
                        $build->archiveSha256,
                        $build->archiveSizeBytes,
                        $build->hostDeployScriptSha256,
                        $build->artifactDeployScriptSha256,
                        $build->stageFileCount,
                        $build->stageUnpackedBytes,
                        $build->tempScratchBytes,
                        $sources->attestationBytes,
                        $sources->attestationSha256,
                        $runId,
                        $intentSha256,
                        $sources->dumpSha256,
                        $sources->dumpSizeBytes,
                        $sources->observedAtUtc,
                        $sources->componentDevices,
                    );
                }
                return self::observeCapacityFailureFromCollector(
                    $runId,
                    $intentSha256,
                    $observation->availableBytes,
                    $observation->projectedRequiredBytes,
                    $observation->observedPercent,
                    $observation->projectedPercent,
                );
            },
            'artifact' => static function () use (
                $provider,
                $runId,
                $intentSha256,
                $expectedCommit,
            ): VerifiedPredeployGateV1 {
                $observation = $provider->artifact();
                $hasFallback =
                    $observation->localSha256 !== null ||
                    $observation->remoteSha256 !== null ||
                    $observation->manifestSha256 !== null ||
                    $observation->hostScriptSha256 !== null ||
                    $observation->artifactScriptSha256 !== null;
                if ($observation->verifiedSources !== null && $hasFallback) {
                    throw new RuntimeException('artifact observation contains conflicting source modes');
                }
                if ($observation->verifiedSources !== null) {
                    $sources = $observation->verifiedSources;
                    return self::verifyArtifactGate(
                        $runId,
                        $intentSha256,
                        $sources->provenanceBytes,
                        $sources->authorizedProvenanceSha256,
                        $sources->releaseId,
                        $expectedCommit,
                        $sources->archiveSha256,
                        $sources->archiveSizeBytes,
                        $sources->hostDeployScriptSha256,
                        $sources->artifactDeployScriptSha256,
                        $sources->stageFileCount,
                        $sources->stageUnpackedBytes,
                        $sources->tempScratchBytes,
                    );
                }
                return self::observeArtifactFailureFromCollector(
                    $runId,
                    $intentSha256,
                    $observation->localSha256,
                    $observation->remoteSha256,
                    $observation->manifestSha256,
                    $observation->hostScriptSha256,
                    $observation->artifactScriptSha256,
                );
            },
        ];
        $collected = [];
        $runId = null;
        $intentSha256 = null;
        foreach ($collectors as $gate => $collector) {
            $value = $collector();
            if (!$value instanceof VerifiedPredeployGateV1) {
                throw new RuntimeException('predeploy collector did not return an authority result');
            }
            self::assertIssuedGate($value);
            if ($value->gate() !== $gate) {
                throw new RuntimeException('predeploy collector returned the wrong gate');
            }
            $runId ??= $value->runId();
            $intentSha256 ??= $value->intentSha256();
            if ($value->runId() !== $runId || !hash_equals($value->intentSha256(), $intentSha256)) {
                throw new RuntimeException('predeploy collectors do not bind one run and intent');
            }
            $collected[$gate] = $value->section();
            $passed =
                $gate === 'expected_commit'
                    ? $collected[$gate]['verified'] === true
                    : $collected[$gate]['status'] === 'passed';
            if (!$passed) {
                break;
            }
        }
        $sections = [
            'expected_commit' => $collected['expected_commit'],
            'traffic_gate' => $collected['traffic_gate'] ?? self::notObservedTraffic(),
            'dump' => $collected['dump'] ?? self::notObservedDump(),
            'capacity' => $collected['capacity'] ?? self::notObservedCapacity(),
            'artifact' => $collected['artifact'] ?? self::notObservedArtifact(),
        ];
        return self::assemblePredeployEvidence(
            $sections['expected_commit'],
            $sections['traffic_gate'],
            $sections['dump'],
            $sections['capacity'],
            $sections['artifact'],
        );
    }

    private static function assembleVerifiedPredeployEvidence(
        VerifiedPredeployGateV1 $build,
        VerifiedPredeployGateV1 $traffic,
        VerifiedPredeployGateV1 $dump,
        VerifiedPredeployGateV1 $capacity,
    ): array {
        require_once __DIR__ . '/VerifiedPredeployGateV1.php';
        $values = [$build, $traffic, $dump, $capacity];
        $expectedGates = ['build', 'traffic_gate', 'dump', 'capacity'];
        foreach ($values as $index => $value) {
            self::assertIssuedGate($value);
            if ($value->gate() !== $expectedGates[$index]) {
                throw new RuntimeException('predeploy verified gate order is invalid');
            }
            if ($value->runId() !== $build->runId() || !hash_equals($value->intentSha256(), $build->intentSha256())) {
                throw new RuntimeException('predeploy verified gates do not bind one run and intent');
            }
        }
        $buildSections = $build->section();
        self::assertExactKeys($buildSections, ['expected_commit', 'artifact'], 'verified build sections');
        return self::assemblePredeployEvidence(
            $buildSections['expected_commit'],
            $traffic->section(),
            $dump->section(),
            $capacity->section(),
            $buildSections['artifact'],
        );
    }

    /** @param array<string,mixed> $section */
    private static function issueGate(
        string $gate,
        string $runId,
        string $intentSha256,
        array $section,
    ): VerifiedPredeployGateV1 {
        require_once __DIR__ . '/VerifiedPredeployGateV1.php';
        $value = VerifiedPredeployGateV1::issueForAuthority(
            DeploymentEvidenceAuthorityV1Issuer::forAuthority(self::class),
            $gate,
            $runId,
            $intentSha256,
            $section,
        );
        self::$issuedPredeployGates ??= new WeakMap();
        self::$issuedPredeployGates[$value] = hash('sha256', serialize([$gate, $runId, $intentSha256, $section]));
        return $value;
    }

    private static function assertIssuedGate(VerifiedPredeployGateV1 $value): void
    {
        $expected = self::$issuedPredeployGates[$value] ?? null;
        $actual = hash(
            'sha256',
            serialize([$value->gate(), $value->runId(), $value->intentSha256(), $value->section()]),
        );
        if (!is_string($expected) || !hash_equals($expected, $actual)) {
            throw new RuntimeException('predeploy gate result was not issued by the authority verifier');
        }
    }

    /** @return array<string,mixed> */
    private static function notObservedExpectedCommit(): array
    {
        return ['expected' => str_repeat('0', 40), 'observed' => null, 'verified' => false];
    }

    /** @return array<string,mixed> */
    private static function notObservedTraffic(): array
    {
        return self::nullSection([
            'status',
            'report_sha256',
            'schema',
            'producer_sha256',
            'policy_version',
            'catalog_version',
            'purpose',
            'mode',
            'window_start_epoch',
            'window_end_epoch',
            'window_seconds',
            'log_set_sha256',
            'rotation_complete',
            'parse_complete',
            'evidence_complete',
            'decision',
            'exit_code',
            'counts',
        ]);
    }

    /** @return array<string,mixed> */
    private static function notObservedDump(): array
    {
        return self::nullSection([
            'status',
            'policy',
            'age_seconds',
            'max_age_seconds',
            'sha256',
            'sha256_verified',
            'gzip_verified',
            'restore_verified',
        ]);
    }

    /** @return array<string,mixed> */
    private static function notObservedCapacity(): array
    {
        return self::nullSection([
            'status',
            'available_bytes',
            'projected_required_bytes',
            'observed_percent',
            'projected_percent',
            'max_used_percent',
            'passed',
        ]);
    }

    /** @return array<string,mixed> */
    private static function notObservedArtifact(): array
    {
        return self::nullSection([
            'status',
            'expectation',
            'local_sha256',
            'remote_sha256',
            'manifest_sha256',
            'host_script_sha256',
            'artifact_script_sha256',
            'verified',
        ]);
    }

    /** @param list<string> $keys @return array<string,mixed> */
    private static function nullSection(array $keys): array
    {
        $section = array_fill_keys($keys, null);
        $section['status'] = 'not_observed';
        return $section;
    }

    /** @return array<string,mixed> */
    public static function decodeChildObservation(
        string $bytes,
        string $runId,
        string $intentSha256,
        string $timingRunId,
        string $receiptBytes,
        string $timingBytes,
        string $artifactSha256,
        string $unitLaunchSha256,
        string $managerBootId,
        string $unitInvocationId,
        int $independentlyObservedExitCode,
        string $observedAtUtc,
    ): array {
        $record = self::decodeCanonical($bytes, self::MAX_FILE_BYTES);
        self::assertExactKeys(
            $record,
            [
                'schema',
                'run_id',
                'intent_sha256',
                'timing',
                'receipt_sha256',
                'artifact_sha256',
                'unit_launch_sha256',
                'manager_boot_id',
                'unit_invocation_id',
                'exit_code',
                'observed_at_utc',
            ],
            'child observation',
        );
        self::assertSame($record['schema'], self::CHILD_OBSERVATION_SCHEMA, 'child observation schema');
        self::assertUuidV4($record['run_id'], 'child observation run_id');
        self::assertSame($record['run_id'], $runId, 'child observation run_id authority');
        self::assertSha256($record['intent_sha256'], 'child observation intent_sha256');
        self::assertSame($record['intent_sha256'], $intentSha256, 'child observation intent authority');
        foreach (['receipt_sha256', 'artifact_sha256', 'unit_launch_sha256'] as $field) {
            self::assertSha256($record[$field], 'child observation ' . $field);
        }
        require_once __DIR__ . '/DeployResultV1.php';
        require_once __DIR__ . '/DeployTimingSampleValidator.php';
        $receipt = DeployResultV1::decode($receiptBytes);
        self::assertObject($record['timing'], 'child observation timing');
        self::assertExactKeys(
            $record['timing'],
            ['status', 'authoritative_sha256', 'run_id', 'total_ms'],
            'child observation timing',
        );
        $timing = null;
        if ($timingBytes === '') {
            if (
                $record['timing']['status'] !== 'not_observed' ||
                $record['timing']['authoritative_sha256'] !== null ||
                $record['timing']['run_id'] !== null ||
                $record['timing']['total_ms'] !== null
            ) {
                throw new RuntimeException('missing timing must remain not_observed');
            }
        } else {
            self::assertSha256($record['timing']['authoritative_sha256'], 'child timing authoritative sha256');
            if ($record['timing']['total_ms'] !== null) {
                self::assertNonNegativeInt($record['timing']['total_ms'], 'child timing total');
            }
            if (!hash_equals($record['timing']['authoritative_sha256'], hash('sha256', $timingBytes))) {
                throw new RuntimeException('child timing bytes contradict observation');
            }
            try {
                $timing = DeployTimingSampleValidator::validateBytes($timingBytes);
            } catch (RuntimeException) {
                if (
                    $record['timing']['status'] !== 'invalid' ||
                    $record['timing']['run_id'] !== null ||
                    $record['timing']['total_ms'] !== null
                ) {
                    throw new RuntimeException('invalid timing observation is malformed');
                }
            }
            if ($timing !== null) {
                if (
                    $record['timing']['status'] !== 'valid' ||
                    $record['timing']['run_id'] !== $timingRunId ||
                    $record['timing']['run_id'] !== $timing['run_id'] ||
                    $record['timing']['total_ms'] !== $timing['total_ms']
                ) {
                    throw new RuntimeException('valid child timing observation is inconsistent');
                }
            }
        }
        if (
            !hash_equals($record['receipt_sha256'], hash('sha256', $receiptBytes)) ||
            !hash_equals($record['artifact_sha256'], $artifactSha256) ||
            !hash_equals($record['unit_launch_sha256'], $unitLaunchSha256)
        ) {
            throw new RuntimeException('child observation authority digest mismatch');
        }
        self::assertUuid($record['manager_boot_id'], 'child observation manager_boot_id');
        self::assertSame($record['manager_boot_id'], $managerBootId, 'child observation manager boot authority');
        if (
            !is_string($record['unit_invocation_id']) ||
            preg_match('/^[0-9a-f]{32}$/D', $record['unit_invocation_id']) !== 1 ||
            $record['unit_invocation_id'] === str_repeat('0', 32)
        ) {
            throw new RuntimeException('child observation unit_invocation_id is invalid');
        }
        self::assertSame($record['unit_invocation_id'], $unitInvocationId, 'child observation invocation authority');
        self::assertNonNegativeInt($record['exit_code'], 'child exit code');
        if (
            $record['exit_code'] !== $independentlyObservedExitCode ||
            $receipt['exit_code'] !== $independentlyObservedExitCode ||
            ($timing !== null && $timing['exit_code'] !== $independentlyObservedExitCode)
        ) {
            throw new RuntimeException('child observation contradicts independently observed normal exit');
        }
        self::assertUtc($record['observed_at_utc'], 'child observed_at');
        self::assertUtc($observedAtUtc, 'runner observed_at authority');
        self::assertSame($record['observed_at_utc'], $observedAtUtc, 'child observed_at authority');
        return $record;
    }

    /** @param array<string,mixed> $start @return array<string,mixed> */
    public static function finishOrchestratorTiming(
        array $start,
        string $finishedAtUtc,
        string $finishedBootId,
        int $finishedMonotonicNs,
        bool $terminalSuccess,
    ): array {
        self::assertExactKeys(
            $start,
            ['schema', 'run_id', 'started_at_utc', 'boot_id', 'monotonic_ns'],
            'orchestrator start',
        );
        self::assertSame($start['schema'], self::ORCHESTRATOR_START_SCHEMA, 'orchestrator start schema');
        self::assertUuidV4($start['run_id'], 'orchestrator start run_id');
        self::assertUtc($start['started_at_utc'], 'orchestrator started_at');
        self::assertUuid($start['boot_id'], 'orchestrator start boot_id');
        self::assertNonNegativeInt($start['monotonic_ns'], 'orchestrator start monotonic');
        self::assertUtc($finishedAtUtc, 'orchestrator finished_at');
        self::assertUuid($finishedBootId, 'orchestrator finish boot_id');
        self::assertNonNegativeInt($finishedMonotonicNs, 'orchestrator finish monotonic');
        $utcDeltaMs = (self::utcEpoch($finishedAtUtc) - self::utcEpoch($start['started_at_utc'])) * 1000;
        if ($utcDeltaMs < 0) {
            throw new RuntimeException('orchestrator UTC clock moved backwards');
        }
        if (!hash_equals($start['boot_id'], $finishedBootId)) {
            if ($terminalSuccess) {
                throw new RuntimeException('terminal success evidence cannot cross a boot boundary');
            }
            return [
                'started_at_utc' => $start['started_at_utc'],
                'finished_at_utc' => $finishedAtUtc,
                'wall_clock_ms' => $utcDeltaMs,
            ];
        }
        if ($finishedMonotonicNs < $start['monotonic_ns']) {
            throw new RuntimeException('orchestrator monotonic clock moved backwards');
        }
        $wallClockMs = intdiv($finishedMonotonicNs - $start['monotonic_ns'], 1_000_000);
        if (abs($utcDeltaMs - $wallClockMs) > 999) {
            throw new RuntimeException('orchestrator monotonic duration contradicts UTC timestamps');
        }
        return [
            'started_at_utc' => $start['started_at_utc'],
            'finished_at_utc' => $finishedAtUtc,
            'wall_clock_ms' => $wallClockMs,
        ];
    }

    /** @return array<string,mixed> */
    private static function decodeCanonical(string $bytes, int $maxBytes): array
    {
        if ($bytes === '' || strlen($bytes) > $maxBytes || !str_ends_with($bytes, "\n") || str_contains($bytes, "\0")) {
            throw new RuntimeException('authority record bytes are invalid');
        }
        try {
            $decoded = json_decode(substr($bytes, 0, -1), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('authority record JSON is invalid');
        }
        self::assertObject($decoded, 'authority record');
        if (!hash_equals($bytes, self::encodeFile($decoded))) {
            throw new RuntimeException('authority record is not canonical');
        }
        return $decoded;
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private static function canonicalize(array $value): array
    {
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item) && !array_is_list($item)) {
                $value[$key] = self::canonicalize($item);
            }
        }
        return $value;
    }

    private static function checkedMultiply(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || ($left !== 0 && $right > intdiv(PHP_INT_MAX, $left))) {
            throw new RuntimeException('capacity arithmetic overflow');
        }
        return $left * $right;
    }

    private static function checkedAdd(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || $right > PHP_INT_MAX - $left) {
            throw new RuntimeException('capacity arithmetic overflow');
        }
        return $left + $right;
    }

    private static function ceilDivide(int $value, int $divisor): int
    {
        return intdiv($value, $divisor) + ($value % $divisor === 0 ? 0 : 1);
    }

    private static function assertObject(mixed $value, string $context): void
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException($context . ' must be an object');
        }
    }

    /** @param array<string,mixed> $value @param list<string> $keys */
    private static function assertExactKeys(array $value, array $keys, string $context): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new RuntimeException($context . ' keys are invalid');
        }
    }

    private static function assertSame(mixed $actual, mixed $expected, string $context): void
    {
        if ($actual !== $expected) {
            throw new RuntimeException($context . ' is invalid');
        }
    }

    private static function assertSha256(mixed $value, string $context): void
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw new RuntimeException($context . ' is invalid');
        }
    }

    private static function assertCommit(mixed $value, string $context): void
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{40}$/D', $value) !== 1) {
            throw new RuntimeException($context . ' is invalid');
        }
    }

    private static function assertReleaseId(mixed $value): void
    {
        if (!is_string($value) || preg_match('/^ea_[A-Za-z0-9._-]{1,61}$/D', $value) !== 1) {
            throw new RuntimeException('release_id is invalid');
        }
    }

    private static function assertUuidV4(mixed $value, string $context): void
    {
        if (
            !is_string($value) ||
            preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) !== 1
        ) {
            throw new RuntimeException($context . ' is invalid');
        }
    }

    private static function assertUuid(mixed $value, string $context): void
    {
        if (
            !is_string($value) ||
            preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) !== 1 ||
            $value === '00000000-0000-0000-0000-000000000000'
        ) {
            throw new RuntimeException($context . ' is invalid');
        }
    }

    private static function assertPositiveInt(mixed $value, string $context): void
    {
        if (!is_int($value) || $value <= 0) {
            throw new RuntimeException($context . ' is invalid');
        }
    }

    private static function assertDumpBounds(mixed $compressedBytes, mixed $uncompressedBytes): void
    {
        self::assertPositiveInt($compressedBytes, 'dump compressed size');
        self::assertPositiveInt($uncompressedBytes, 'dump uncompressed size');
        if (
            $compressedBytes > self::MAX_DUMP_COMPRESSED_BYTES ||
            $uncompressedBytes > self::MAX_DUMP_UNCOMPRESSED_BYTES ||
            $uncompressedBytes > self::checkedMultiply($compressedBytes, 100)
        ) {
            throw new RuntimeException('dump size or expansion ratio exceeds the verifier contract');
        }
    }

    private static function assertNonNegativeInt(mixed $value, string $context): void
    {
        if (!is_int($value) || $value < 0) {
            throw new RuntimeException($context . ' is invalid');
        }
    }

    private static function assertUtc(mixed $value, string $context): void
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) !== 1) {
            throw new RuntimeException($context . ' is invalid');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new RuntimeException($context . ' is invalid');
        }
    }

    private static function utcEpoch(string $value): int
    {
        self::assertUtc($value, 'UTC timestamp');
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->getTimestamp();
    }
}
