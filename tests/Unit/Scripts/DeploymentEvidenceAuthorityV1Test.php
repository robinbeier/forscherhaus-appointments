<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Ops\DeploymentEvidenceAuthorityV1;
use Ops\DeploymentContractV1;
use Ops\DeploymentEvidenceAuthorityV1Issuer;
use Ops\VerifiedPredeployGateV1;
use Ops\ArtifactObservationV1;
use Ops\BuildVerifiedSourcesV1;
use Ops\CapacityObservationV1;
use Ops\CapacityVerifiedSourcesV1;
use Ops\DumpObservationV1;
use Ops\ExpectedCommitObservationV1;
use Ops\ProtectedPredeployObservationProvider;
use Ops\DeployResultV1;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../../scripts/ops/lib/DeploymentEvidenceAuthorityV1.php';
require_once __DIR__ . '/../../../scripts/ops/lib/DeploymentContractV1.php';
require_once __DIR__ . '/../../../scripts/ops/lib/ProtectedPredeployObservationProvider.php';
require_once __DIR__ . '/../../../scripts/ops/lib/DeployResultV1.php';

final class DeploymentEvidenceAuthorityV1Test extends TestCase
{
    private const RUN_ID = '018f6f52-4c87-4d4e-8b19-6a66e6e1af25';
    private const RELEASE_ID = 'ea_20260812_1200';
    private const COMMIT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SHA = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testFixedAuthorityPathsAreDerivedFromDigestAndRunId(): void
    {
        self::assertSame(
            '/var/lib/fh-deploy-evidence/dump-attestations/' . self::SHA . '.json',
            DeploymentEvidenceAuthorityV1::dumpAttestationPath(self::SHA),
        );
    }

    public function testInvalidAuthorityPathInputsAreRejected(): void
    {
        $rejected = 0;
        foreach (
            [fn(): string => DeploymentEvidenceAuthorityV1::dumpAttestationPath(strtoupper(self::SHA))]
            as $derive
        ) {
            try {
                $derive();
                self::fail('invalid authority input was accepted');
            } catch (RuntimeException) {
                ++$rejected;
            }
        }
        self::assertSame(1, $rejected);
    }

    public function testAuthorizedProvenanceBindsExactCanonicalSidecarAndArtifact(): void
    {
        $provenance = $this->provenance();
        $bytes = DeploymentEvidenceAuthorityV1::encodeFile($provenance);

        $decoded = DeploymentEvidenceAuthorityV1::decodeAuthorizedBuildProvenance(
            $bytes,
            hash('sha256', $bytes),
            'ea_20260812_1200',
            self::COMMIT,
            self::SHA,
            123456,
            self::SHA,
            self::SHA,
            1234,
            2000,
            400_000_000,
            800_000_000,
        );

        self::assertSame($bytes, DeploymentEvidenceAuthorityV1::encodeFile($decoded));
    }

    #[DataProvider('provenanceForgeryProvider')]
    public function testSelfConsistentButUnauthorizedOrDriftedProvenanceIsRejected(string $mutation): void
    {
        $provenance = $this->provenance();
        $authorized = hash('sha256', DeploymentEvidenceAuthorityV1::encodeFile($provenance));
        if ($mutation === 'authorized digest') {
            $authorized = str_repeat('c', 64);
        } elseif ($mutation === 'expected commit') {
            $provenance['expected_commit'] = str_repeat('c', 40);
            $provenance['observed_commit'] = str_repeat('c', 40);
        } elseif ($mutation === 'archive sha') {
            $provenance['archive']['sha256'] = str_repeat('c', 64);
        } elseif ($mutation === 'deploy script') {
            $provenance['source']['deploy_ea_sha256'] = str_repeat('c', 64);
        }
        $bytes = DeploymentEvidenceAuthorityV1::encodeFile($provenance);
        if ($mutation !== 'authorized digest') {
            $authorized = hash('sha256', $bytes);
        }

        $this->expectException(RuntimeException::class);
        DeploymentEvidenceAuthorityV1::decodeAuthorizedBuildProvenance(
            $bytes,
            $authorized,
            'ea_20260812_1200',
            self::COMMIT,
            self::SHA,
            123456,
            self::SHA,
            self::SHA,
            1234,
            2000,
            400_000_000,
            800_000_000,
        );
    }

    /** @return iterable<string,array{string}> */
    public static function provenanceForgeryProvider(): iterable
    {
        yield 'authorized digest' => ['authorized digest'];
        yield 'expected commit' => ['expected commit'];
        yield 'archive digest' => ['archive sha'];
        yield 'deploy script digest' => ['deploy script'];
    }

    public function testDumpAttestationBindsOneStableRestoredDumpAndStrictAge(): void
    {
        $attestation = $this->dumpAttestation();
        $bytes = DeploymentEvidenceAuthorityV1::encodeFile($attestation);
        $decoded = DeploymentEvidenceAuthorityV1::bindPinnedDumpAttestationToRun(
            $bytes,
            hash('sha256', $bytes),
            self::RUN_ID,
            self::SHA,
            self::SHA,
            1_000_000,
            '2026-08-12T12:30:00Z',
        );

        self::assertSame(self::SHA, $decoded['dump_sha256']);
        self::assertSame(8_000_000, $decoded['restored_datadir_allocated_bytes']);
        self::assertSame(256, $decoded['restored_datadir_inode_count']);
    }

    public function testProducerValidationAcceptsOnlyExactCanonicalAttestationBytes(): void
    {
        $bytes = DeploymentEvidenceAuthorityV1::encodeFile($this->dumpAttestation());
        $attestation = DeploymentEvidenceAuthorityV1::validateProducedDumpAttestation(
            $bytes,
            self::SHA,
            1_000_000,
            '2026-08-12T12:00:00Z',
            '2026-08-12T12:30:00Z',
        );
        self::assertSame($bytes, DeploymentEvidenceAuthorityV1::encodeFile($attestation));

        $this->expectException(RuntimeException::class);
        DeploymentEvidenceAuthorityV1::validateProducedDumpAttestation(
            $bytes,
            str_repeat('c', 64),
            1_000_000,
            '2026-08-12T12:00:00Z',
            '2026-08-12T12:30:00Z',
        );
    }

    #[DataProvider('invalidDumpAttestationProvider')]
    public function testDumpAttestationRejectsDifferentBytesFutureStaleOrUnverified(string $mutation): void
    {
        $attestation = $this->dumpAttestation();
        if ($mutation === 'sha') {
            $attestation['dump']['sha256'] = str_repeat('c', 64);
        }
        if ($mutation === 'size') {
            $attestation['dump']['size_bytes']++;
        }
        if ($mutation === 'future') {
            $attestation['dump']['created_at_utc'] = '2026-08-12T12:31:00Z';
        }
        if ($mutation === 'stale') {
            $attestation['dump']['created_at_utc'] = '2026-08-12T08:30:00Z';
        }
        if ($mutation === 'gzip') {
            $attestation['verification']['gzip_verified'] = false;
        }
        if ($mutation === 'restore') {
            $attestation['verification']['restore_verified'] = false;
        }
        if ($mutation === 'image tag') {
            $attestation['verification']['image'] = 'mariadb:10.11';
        }
        if ($mutation === 'image digest') {
            $attestation['verification']['image'] = 'mariadb@sha256:' . str_repeat('c', 64);
        }

        $this->expectException(RuntimeException::class);
        $bytes = DeploymentEvidenceAuthorityV1::encodeFile($attestation);
        DeploymentEvidenceAuthorityV1::bindPinnedDumpAttestationToRun(
            $bytes,
            hash('sha256', $bytes),
            self::RUN_ID,
            self::SHA,
            self::SHA,
            1_000_000,
            '2026-08-12T12:30:00Z',
        );
    }

    public function testProtectedStaleOrChangedDumpReturnsExit22ButContradictorySizeFailsClosed(): void
    {
        $attestationBytes = DeploymentEvidenceAuthorityV1::encodeFile($this->dumpAttestation());
        $cases = [
            'stale' => [self::SHA, 1_000_000, '2026-08-12T16:00:00Z', 14_400, true],
            'changed bytes' => [str_repeat('c', 64), 999_999, '2026-08-12T12:30:00Z', 1_800, false],
        ];
        foreach ($cases as $name => [$dumpSha, $dumpSize, $observedAt, $age, $shaVerified]) {
            $provider = $this->passedProvider(
                new DumpObservationV1(
                    $attestationBytes,
                    hash('sha256', $attestationBytes),
                    $dumpSize,
                    $observedAt,
                    null,
                    $dumpSha,
                    null,
                    null,
                    null,
                ),
            );
            $assembly = DeploymentEvidenceAuthorityV1::collectPredeployEvidence(
                $provider,
                self::RUN_ID,
                self::SHA,
                self::RELEASE_ID,
                self::COMMIT,
            );
            self::assertSame(22, $assembly['exit_code'], $name);
            self::assertSame('failed', $assembly['sections']['dump']['status'], $name);
            self::assertSame($age, $assembly['sections']['dump']['age_seconds'], $name);
            self::assertSame($dumpSha, $assembly['sections']['dump']['sha256'], $name);
            self::assertSame($shaVerified, $assembly['sections']['dump']['sha256_verified'], $name);
            self::assertSame(['expected_commit', 'dump'], $provider->ledger, $name);
        }

        $this->expectException(RuntimeException::class);
        DeploymentEvidenceAuthorityV1::collectPredeployEvidence(
            $this->passedProvider(
                new DumpObservationV1(
                    $attestationBytes,
                    hash('sha256', $attestationBytes),
                    999_999,
                    '2026-08-12T12:30:00Z',
                    null,
                    self::SHA,
                    null,
                    null,
                    null,
                ),
            ),
            self::RUN_ID,
            self::SHA,
            self::RELEASE_ID,
            self::COMMIT,
        );
    }

    /** @return iterable<string,array{string}> */
    public static function invalidDumpAttestationProvider(): iterable
    {
        foreach (['sha', 'size', 'future', 'stale', 'gzip', 'restore', 'image tag', 'image digest'] as $case) {
            yield $case => [$case];
        }
    }

    public function testCapacityUsesCheckedSingleSnapshotFormula(): void
    {
        $result = DeploymentEvidenceAuthorityV1::capacityFromStatvfs(
            filesystemDevice: 2049,
            blockSize: 4096,
            blocks: 1_000_000,
            blocksAvailable: 400_000,
            inodes: 10_000_000,
            inodesAvailable: 9_000_000,
            stageInodeCount: 2000,
            restoreInodeCount: 256,
            artifactBytes: 100_000_000,
            dumpBytes: 200_000_000,
            stageBytes: 40_000_000,
            tempBytes: 10_000_000,
            rollbackBytes: 0,
            componentDevices: $this->capacityDevices(2049),
        );

        self::assertSame(1_638_400_000, $result['available_bytes']);
        self::assertSame(350_000_000, $result['base_required_bytes']);
        self::assertSame(536_870_912, $result['headroom_bytes']);
        self::assertSame(886_870_912, $result['projected_required_bytes']);
        self::assertSame(9_000_000, $result['available_inodes']);
        self::assertSame(2320, $result['projected_required_inodes']);
        self::assertSame(60, $result['observed_percent']);
        self::assertSame(82, $result['projected_percent']);
        self::assertTrue($result['passed']);
    }

    public function testCapacityRejectsMissingBoundsOverflowAndThresholdEquality(): void
    {
        foreach (
            [
                [4096, 1_000_000, 400_000, 10_000, 9_000, 100, 1, null, 1, 1, 1, 0, $this->capacityDevices(1)],
                [PHP_INT_MAX, 2, 1, 10_000, 9_000, 100, 1, 1, 1, 1, 1, 0, $this->capacityDevices(1)],
            ]
            as $arguments
        ) {
            try {
                DeploymentEvidenceAuthorityV1::capacityFromStatvfs(1, ...$arguments);
                self::fail('Expected invalid capacity authority.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
        $threshold = DeploymentEvidenceAuthorityV1::capacityFromStatvfs(
            1,
            4096,
            244_141,
            195_313,
            10_000,
            9_000,
            100,
            1,
            113_129_088,
            0,
            0,
            0,
            0,
            $this->capacityDevices(1),
        );
        self::assertSame(85, $threshold['projected_percent']);
        self::assertFalse($threshold['passed']);
        $this->expectException(RuntimeException::class);
        $devices = $this->capacityDevices(1);
        $devices['restore_scratch'] = 2;
        DeploymentEvidenceAuthorityV1::capacityFromStatvfs(
            1,
            4096,
            1_000_000,
            400_000,
            10_000,
            9_000,
            100,
            1,
            1,
            1,
            1,
            1,
            0,
            $devices,
        );
    }

    public function testCapacityFailsClosedWhenAvailableInodesCannotMaterializeTheStage(): void
    {
        $result = DeploymentEvidenceAuthorityV1::capacityFromStatvfs(
            filesystemDevice: 1,
            blockSize: 4096,
            blocks: 1_000_000,
            blocksAvailable: 900_000,
            inodes: 10_000,
            inodesAvailable: 63,
            stageInodeCount: 1,
            restoreInodeCount: 1,
            artifactBytes: 1,
            dumpBytes: 1,
            stageBytes: 1,
            tempBytes: 1,
            rollbackBytes: 0,
            componentDevices: $this->capacityDevices(1),
        );

        self::assertSame(66, $result['projected_required_inodes']);
        self::assertFalse($result['passed']);

        $this->expectException(RuntimeException::class);
        DeploymentEvidenceAuthorityV1::capacityFromStatvfs(
            1,
            4096,
            1_000_000,
            900_000,
            10_000,
            10_001,
            1,
            1,
            1,
            1,
            1,
            1,
            0,
            $this->capacityDevices(1),
        );
    }

    public function testCapacityDerivesArchiveStageLiveStorageScratchAndInodesOnlyFromVerifiedAuthorities(): void
    {
        $provenance = $this->provenance();
        $provenanceBytes = DeploymentEvidenceAuthorityV1::encodeFile($provenance);
        $bytes = DeploymentEvidenceAuthorityV1::encodeFile($this->dumpAttestation());
        $result = DeploymentEvidenceAuthorityV1::capacityFromVerifiedAuthorities(
            1,
            4096,
            1_000_000,
            900_000,
            10_000_000,
            9_000_000,
            $provenanceBytes,
            hash('sha256', $provenanceBytes),
            'ea_20260812_1200',
            self::COMMIT,
            1234,
            2000,
            400_000_000,
            800_000_000,
            $bytes,
            hash('sha256', $bytes),
            self::RUN_ID,
            self::SHA,
            self::SHA,
            1_000_000,
            '2026-08-12T12:30:00Z',
            50_000_000,
            60_000_000,
            500,
            70_000_000,
            700,
            $this->capacityDevices(1),
        );

        self::assertSame(1_343_123_456, $result['base_required_bytes']);
        self::assertSame(3200, $result['stage_inode_count']);
        self::assertSame(3520, $result['projected_required_inodes']);
        self::assertTrue($result['passed']);

        $largeStorage = DeploymentEvidenceAuthorityV1::capacityFromVerifiedAuthorities(
            1,
            4096,
            1_000_000,
            900_000,
            10_000_000,
            9_000_000,
            $provenanceBytes,
            hash('sha256', $provenanceBytes),
            'ea_20260812_1200',
            self::COMMIT,
            1234,
            2000,
            400_000_000,
            800_000_000,
            $bytes,
            hash('sha256', $bytes),
            self::RUN_ID,
            self::SHA,
            self::SHA,
            1_000_000,
            '2026-08-12T12:30:00Z',
            3_000_000_000,
            3_100_000_000,
            500,
            70_000_000,
            700,
            $this->capacityDevices(1),
        );
        self::assertSame(4_383_123_456, $largeStorage['base_required_bytes']);
        self::assertFalse($largeStorage['passed']);

        $largeRenderer = DeploymentEvidenceAuthorityV1::capacityFromVerifiedAuthorities(
            1,
            4096,
            1_000_000,
            900_000,
            10_000_000,
            9_000_000,
            $provenanceBytes,
            hash('sha256', $provenanceBytes),
            'ea_20260812_1200',
            self::COMMIT,
            1234,
            2000,
            400_000_000,
            800_000_000,
            $bytes,
            hash('sha256', $bytes),
            self::RUN_ID,
            self::SHA,
            self::SHA,
            1_000_000,
            '2026-08-12T12:30:00Z',
            50_000_000,
            60_000_000,
            500,
            3_000_000_000,
            700,
            $this->capacityDevices(1),
        );
        self::assertSame(4_273_123_456, $largeRenderer['base_required_bytes']);
        self::assertFalse($largeRenderer['passed']);

        $externalRenderer = DeploymentEvidenceAuthorityV1::capacityFromVerifiedAuthorities(
            1,
            4096,
            1_000_000,
            900_000,
            10_000_000,
            9_000_000,
            $provenanceBytes,
            hash('sha256', $provenanceBytes),
            'ea_20260812_1200',
            self::COMMIT,
            1234,
            2000,
            400_000_000,
            800_000_000,
            $bytes,
            hash('sha256', $bytes),
            self::RUN_ID,
            self::SHA,
            self::SHA,
            1_000_000,
            '2026-08-12T12:30:00Z',
            50_000_000,
            60_000_000,
            500,
            0,
            0,
            $this->capacityDevices(1),
        );
        self::assertSame(1_273_123_456, $externalRenderer['base_required_bytes']);
        self::assertSame(2500, $externalRenderer['stage_inode_count']);

        try {
            DeploymentEvidenceAuthorityV1::capacityFromVerifiedAuthorities(
                1,
                4096,
                1_000_000,
                900_000,
                10_000_000,
                9_000_000,
                $provenanceBytes,
                hash('sha256', $provenanceBytes),
                'ea_20260812_1200',
                self::COMMIT,
                1234,
                2000,
                400_000_000,
                800_000_000,
                $bytes,
                hash('sha256', $bytes),
                self::RUN_ID,
                self::SHA,
                self::SHA,
                1_000_000,
                '2026-08-12T12:30:00Z',
                50_000_000,
                60_000_000,
                500,
                0,
                1,
                $this->capacityDevices(1),
            );
            self::fail('partial renderer capacity pair was accepted');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('one exact pair', $exception->getMessage());
        }

        $provenance['capacity_bounds']['stage_unpacked_bytes'] = 1;
        $this->expectException(RuntimeException::class);
        DeploymentEvidenceAuthorityV1::capacityFromVerifiedAuthorities(
            1,
            4096,
            1_000_000,
            900_000,
            10_000_000,
            9_000_000,
            DeploymentEvidenceAuthorityV1::encodeFile($provenance),
            hash('sha256', DeploymentEvidenceAuthorityV1::encodeFile($provenance)),
            'ea_20260812_1200',
            self::COMMIT,
            1234,
            2000,
            400_000_000,
            800_000_000,
            $bytes,
            hash('sha256', $bytes),
            self::RUN_ID,
            self::SHA,
            self::SHA,
            1_000_000,
            '2026-08-12T12:30:00Z',
            50_000_000,
            60_000_000,
            500,
            70_000_000,
            700,
            $this->capacityDevices(1),
        );
    }

    public function testVerifiedAuthoritiesAloneDeriveDeploymentEvidenceSections(): void
    {
        $provenanceBytes = DeploymentEvidenceAuthorityV1::encodeFile($this->provenance());
        $build = DeploymentEvidenceAuthorityV1::deriveBuildEvidence(
            $provenanceBytes,
            hash('sha256', $provenanceBytes),
            'ea_20260812_1200',
            self::COMMIT,
            self::SHA,
            123456,
            self::SHA,
            self::SHA,
            1234,
            2000,
            400_000_000,
            800_000_000,
        );
        self::assertSame(self::COMMIT, $build['expected_commit']['observed']);
        self::assertSame(hash('sha256', $provenanceBytes), $build['artifact']['manifest_sha256']);

        $attestationBytes = DeploymentEvidenceAuthorityV1::encodeFile($this->dumpAttestation());
        $dump = DeploymentEvidenceAuthorityV1::verifyAndDeriveDumpEvidence(
            $attestationBytes,
            hash('sha256', $attestationBytes),
            self::RUN_ID,
            self::SHA,
            self::SHA,
            1_000_000,
            '2026-08-12T12:30:00Z',
        );
        self::assertSame('passed', $dump['status']);

        $capacity = DeploymentEvidenceAuthorityV1::verifyAndDeriveCapacityEvidence(
            1,
            4096,
            1_000_000,
            900_000,
            10_000_000,
            9_000_000,
            $provenanceBytes,
            hash('sha256', $provenanceBytes),
            'ea_20260812_1200',
            self::COMMIT,
            1234,
            2000,
            400_000_000,
            800_000_000,
            $attestationBytes,
            hash('sha256', $attestationBytes),
            self::RUN_ID,
            self::SHA,
            self::SHA,
            1_000_000,
            '2026-08-12T12:30:00Z',
            50_000_000,
            60_000_000,
            500,
            70_000_000,
            700,
            $this->capacityDevices(1),
        );
        self::assertContains($capacity['status'], ['passed', 'failed']);

        $this->expectException(RuntimeException::class);
        DeploymentEvidenceAuthorityV1::deriveBuildEvidence(
            $provenanceBytes,
            str_repeat('c', 64),
            'ea_20260812_1200',
            self::COMMIT,
            self::SHA,
            123456,
            self::SHA,
            self::SHA,
            1234,
            2000,
            400_000_000,
            800_000_000,
        );
    }

    public function testRawSectionArraysCannotEnterTheVerifiedAssembler(): void
    {
        $method = new \ReflectionMethod(DeploymentEvidenceAuthorityV1::class, 'assemblePredeployEvidence');
        self::assertTrue($method->isPrivate());
    }

    public function testPubliclyForgedCarrierCannotEnterVerifiedAssembler(): void
    {
        require_once __DIR__ . '/../../../scripts/ops/lib/VerifiedPredeployGateV1.php';
        $issuer = DeploymentEvidenceAuthorityV1Issuer::forAuthority(DeploymentEvidenceAuthorityV1::class);
        $forged = VerifiedPredeployGateV1::issueForAuthority($issuer, 'build', self::RUN_ID, self::SHA, [
            'expected_commit' => ['expected' => self::COMMIT, 'observed' => self::COMMIT, 'verified' => true],
            'artifact' => [],
        ]);
        $this->expectException(\TypeError::class);
        DeploymentEvidenceAuthorityV1::collectPredeployEvidence(
            fn() => $forged,
            fn() => $forged,
            fn() => $forged,
            fn() => $forged,
            fn() => $forged,
        );
    }

    #[DataProvider('orderedPredeployProvider')]
    public function testOrderedCollectorsShortCircuitAndProduceAValidTerminalBundle(
        string $failedGate,
        bool $invalid,
        int $expectedExit,
        string $expectedReason,
    ): void {
        $ledger = [];
        $provenanceBytes = DeploymentEvidenceAuthorityV1::encodeFile($this->provenance());
        $attestationBytes = DeploymentEvidenceAuthorityV1::encodeFile($this->dumpAttestation());
        $dumpFailure = [
            'status' => $invalid ? 'invalid' : 'failed',
            'policy' => 'fresh_verified_under_240m',
            'age_seconds' => 60,
            'max_age_seconds' => 14400,
            'sha256' => self::SHA,
            'sha256_verified' => true,
            'gzip_verified' => true,
            'restore_verified' => $invalid ? null : false,
        ];
        $capacityFailure = [
            'status' => $invalid ? 'invalid' : 'failed',
            'available_bytes' => 1,
            'projected_required_bytes' => $invalid ? null : 2,
            'available_inodes' => 1,
            'stage_inode_count' => $invalid ? null : 2,
            'restore_inode_count' => $invalid ? null : 3,
            'inode_headroom' => $invalid ? null : 64,
            'projected_required_inodes' => $invalid ? null : 69,
            'observed_percent' => 84,
            'projected_percent' => $invalid ? null : 85,
            'max_used_percent' => 85,
            'passed' => $invalid ? null : false,
        ];
        $artifactFailure = [
            'status' => $invalid ? 'invalid' : 'failed',
            'expectation' => 'build_from_expected_commit',
            'local_sha256' => self::SHA,
            'remote_sha256' => $invalid ? null : str_repeat('c', 64),
            'manifest_sha256' => self::SHA,
            'host_script_sha256' => self::SHA,
            'artifact_script_sha256' => self::SHA,
            'verified' => $invalid ? null : true,
        ];

        $commitCandidate = $provenanceBytes;
        if ($failedGate === 'expected_commit') {
            $changed = $this->provenance();
            $changed['expected_commit'] = str_repeat('c', 40);
            $changed['observed_commit'] = str_repeat('c', 40);
            $commitCandidate = DeploymentEvidenceAuthorityV1::encodeFile($changed);
        }
        $buildSources = new BuildVerifiedSourcesV1(
            $provenanceBytes,
            hash('sha256', $provenanceBytes),
            'ea_20260812_1200',
            $failedGate === 'artifact' && !$invalid ? str_repeat('c', 64) : self::SHA,
            123456,
            self::SHA,
            self::SHA,
            1234,
            2000,
            400_000_000,
            800_000_000,
        );
        $provider = new TestProtectedPredeployProvider(
            new ExpectedCommitObservationV1($commitCandidate, hash('sha256', $commitCandidate)),

            $failedGate === 'dump'
                ? new DumpObservationV1(
                    null,
                    null,
                    null,
                    null,
                    $dumpFailure['age_seconds'],
                    $dumpFailure['sha256'],
                    $dumpFailure['sha256_verified'],
                    $dumpFailure['gzip_verified'],
                    $dumpFailure['restore_verified'],
                )
                : new DumpObservationV1(
                    $attestationBytes,
                    hash('sha256', $attestationBytes),
                    1_000_000,
                    '2026-08-12T12:30:00Z',
                    null,
                    self::SHA,
                    null,
                    null,
                    null,
                ),
            $failedGate === 'capacity'
                ? new CapacityObservationV1(
                    null,
                    $capacityFailure['available_bytes'],
                    $capacityFailure['projected_required_bytes'],
                    $capacityFailure['available_inodes'],
                    $capacityFailure['stage_inode_count'],
                    $capacityFailure['restore_inode_count'],
                    $capacityFailure['inode_headroom'],
                    $capacityFailure['projected_required_inodes'],
                    $capacityFailure['observed_percent'],
                    $capacityFailure['projected_percent'],
                )
                : new CapacityObservationV1(
                    new CapacityVerifiedSourcesV1(
                        1,
                        4096,
                        1_000_000,
                        900_000,
                        10_000_000,
                        9_000_000,
                        $buildSources,
                        $attestationBytes,
                        hash('sha256', $attestationBytes),
                        self::SHA,
                        1_000_000,
                        '2026-08-12T12:30:00Z',
                        50_000_000,
                        60_000_000,
                        500,
                        70_000_000,
                        700,
                        $this->capacityDevices(1),
                    ),
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                ),
            $failedGate === 'artifact'
                ? ($invalid
                    ? new ArtifactObservationV1(
                        null,
                        $artifactFailure['local_sha256'],
                        null,
                        $artifactFailure['manifest_sha256'],
                        $artifactFailure['host_script_sha256'],
                        $artifactFailure['artifact_script_sha256'],
                    )
                    : new ArtifactObservationV1($buildSources, null, null, null, null, null))
                : new ArtifactObservationV1($buildSources, null, null, null, null, null),
            $ledger,
        );
        $assembly = DeploymentEvidenceAuthorityV1::collectPredeployEvidence(
            $provider,
            self::RUN_ID,
            self::SHA,
            self::RELEASE_ID,
            self::COMMIT,
        );
        $ledger = $provider->ledger;
        self::assertSame($expectedExit, $assembly['exit_code']);
        self::assertSame($expectedReason, $assembly['reason']);
        $order = ['expected_commit', 'dump', 'capacity', 'artifact'];
        $expectedLedger =
            $failedGate === 'none' ? $order : array_slice($order, 0, array_search($failedGate, $order, true) + 1);
        self::assertSame($expectedLedger, $ledger);
        if ($failedGate !== 'none') {
            $section = $assembly['sections'][$failedGate === 'expected_commit' ? 'expected_commit' : $failedGate];
            if ($failedGate === 'expected_commit') {
                self::assertFalse($section['verified']);
                self::assertSame(str_repeat('c', 40), $section['observed']);
            } else {
                self::assertSame($invalid ? 'invalid' : 'failed', $section['status']);
            }
            if ($failedGate === 'dump') {
                self::assertSame(60, $section['age_seconds']);
                self::assertSame(self::SHA, $section['sha256']);
                self::assertSame($invalid ? null : false, $section['restore_verified']);
            }
            if ($failedGate === 'capacity') {
                self::assertSame(1, $section['available_bytes']);
                self::assertSame(84, $section['observed_percent']);
                self::assertSame($invalid ? null : 2, $section['projected_required_bytes']);
            }
            if ($failedGate === 'artifact') {
                self::assertSame(self::SHA, $section['local_sha256']);
                self::assertSame($invalid ? self::SHA : hash('sha256', $provenanceBytes), $section['manifest_sha256']);
                self::assertSame($invalid ? null : str_repeat('c', 64), $section['remote_sha256']);
            }
            $bundle = $this->failedBeforeWriteBundle($assembly);
            self::assertSame(
                'failed_before_write',
                DeploymentContractV1::validateBundle($bundle['lines'], $bundle['evidence'])['state'],
            );
        } else {
            $bundle = $this->succeededBundle($assembly);
            self::assertSame(
                'succeeded',
                DeploymentContractV1::validateBundle($bundle['lines'], $bundle['evidence'])['state'],
            );
        }
    }

    /** @return iterable<string,array{string,bool,int,string}> */
    public static function orderedPredeployProvider(): iterable
    {
        yield 'passed' => ['none', false, 0, 'ok'];
        yield 'commit mismatch' => ['expected_commit', false, 25, 'expected_commit_mismatch'];
        yield 'dump failed' => ['dump', false, 22, 'dump_verification_failed'];
        yield 'dump invalid' => ['dump', true, 22, 'dump_verification_failed'];
        yield 'capacity failed' => ['capacity', false, 23, 'capacity_gate_failed'];
        yield 'capacity invalid' => ['capacity', true, 23, 'capacity_gate_failed'];
        yield 'artifact failed' => ['artifact', false, 24, 'artifact_verification_failed'];
        yield 'artifact invalid' => ['artifact', true, 24, 'artifact_verification_failed'];
    }

    public function testInodeOnlyCapacityShortageRemainsAValidExit23Bundle(): void
    {
        $provenanceBytes = DeploymentEvidenceAuthorityV1::encodeFile($this->provenance());
        $attestationBytes = DeploymentEvidenceAuthorityV1::encodeFile($this->dumpAttestation());
        $provider = $this->passedProvider(
            capacity: new CapacityObservationV1(
                new CapacityVerifiedSourcesV1(
                    1,
                    4096,
                    1_000_000,
                    900_000,
                    10_000_000,
                    1,
                    $this->buildSources($provenanceBytes),
                    $attestationBytes,
                    hash('sha256', $attestationBytes),
                    self::SHA,
                    1_000_000,
                    '2026-08-12T12:30:00Z',
                    50_000_000,
                    60_000_000,
                    500,
                    70_000_000,
                    700,
                    $this->capacityDevices(1),
                ),
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
            ),
        );

        $assembly = DeploymentEvidenceAuthorityV1::collectPredeployEvidence(
            $provider,
            self::RUN_ID,
            self::SHA,
            self::RELEASE_ID,
            self::COMMIT,
        );
        $capacity = $assembly['sections']['capacity'];
        self::assertSame(23, $assembly['exit_code']);
        self::assertSame('failed', $capacity['status']);
        self::assertSame(1, $capacity['available_inodes']);
        self::assertSame(3200, $capacity['stage_inode_count']);
        self::assertSame(256, $capacity['restore_inode_count']);
        self::assertSame(64, $capacity['inode_headroom']);
        self::assertSame(3520, $capacity['projected_required_inodes']);
        self::assertFalse($capacity['passed']);
        $bundle = $this->failedBeforeWriteBundle($assembly);
        self::assertSame(
            'failed_before_write',
            DeploymentContractV1::validateBundle($bundle['lines'], $bundle['evidence'])['state'],
        );
    }

    public function testProtectedObservationSourceModesRejectContradictionsAndPartialTuples(): void
    {
        $provenanceBytes = DeploymentEvidenceAuthorityV1::encodeFile($this->provenance());
        $build = $this->buildSources($provenanceBytes);
        $attestationBytes = DeploymentEvidenceAuthorityV1::encodeFile($this->dumpAttestation());
        $capacitySources = new CapacityVerifiedSourcesV1(
            1,
            4096,
            1_000_000,
            900_000,
            10_000_000,
            9_000_000,
            $build,
            $attestationBytes,
            hash('sha256', $attestationBytes),
            self::SHA,
            1_000_000,
            '2026-08-12T12:30:00Z',
            50_000_000,
            60_000_000,
            500,
            70_000_000,
            700,
            $this->capacityDevices(1),
        );
        $cases = [
            'dump conflicting modes' => [
                $this->passedProvider(
                    new DumpObservationV1(
                        $attestationBytes,
                        hash('sha256', $attestationBytes),
                        1_000_000,
                        '2026-08-12T12:30:00Z',
                        60,
                        self::SHA,
                        true,
                        true,
                        true,
                    ),
                ),
                ['expected_commit', 'dump'],
            ],
            'dump partial protected tuple' => [
                $this->passedProvider(
                    new DumpObservationV1(
                        $attestationBytes,
                        null,
                        1_000_000,
                        '2026-08-12T12:30:00Z',
                        null,
                        self::SHA,
                        null,
                        null,
                        null,
                    ),
                ),
                ['expected_commit', 'dump'],
            ],
            'capacity conflicting modes' => [
                $this->passedProvider(
                    capacity: new CapacityObservationV1(
                        $capacitySources,
                        1,
                        null,
                        null,
                        null,
                        null,
                        null,
                        null,
                        null,
                        null,
                    ),
                ),
                ['expected_commit', 'dump', 'capacity'],
            ],
            'artifact conflicting modes' => [
                $this->passedProvider(artifact: new ArtifactObservationV1($build, self::SHA, null, null, null, null)),
                ['expected_commit', 'dump', 'capacity', 'artifact'],
            ],
        ];
        foreach ($cases as $name => [$provider, $expectedLedger]) {
            try {
                DeploymentEvidenceAuthorityV1::collectPredeployEvidence(
                    $provider,
                    self::RUN_ID,
                    self::SHA,
                    self::RELEASE_ID,
                    self::COMMIT,
                );
                self::fail($name . ' was accepted.');
            } catch (RuntimeException) {
                self::assertSame($expectedLedger, $provider->ledger, $name);
            }
        }
    }

    public function testRequestedReleaseCannotBeSubstitutedByAnotherValidProvenance(): void
    {
        $provider = $this->passedProvider();

        try {
            DeploymentEvidenceAuthorityV1::collectPredeployEvidence(
                $provider,
                self::RUN_ID,
                self::SHA,
                'ea_20260812_1201',
                self::COMMIT,
            );
            self::fail('A different valid release provenance was accepted for the requested release.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('protected release', $exception->getMessage());
            self::assertSame(['expected_commit'], $provider->ledger);
        }
    }

    public function testCapacityAndArtifactMustReuseThePrecedingProtectedSources(): void
    {
        $provenanceBytes = DeploymentEvidenceAuthorityV1::encodeFile($this->provenance());
        $alternate = $this->provenance();
        $alternate['archive']['size_bytes'] = 123457;
        $alternateBytes = DeploymentEvidenceAuthorityV1::encodeFile($alternate);
        $alternateBuild = new BuildVerifiedSourcesV1(
            $alternateBytes,
            hash('sha256', $alternateBytes),
            'ea_20260812_1200',
            self::SHA,
            123457,
            self::SHA,
            self::SHA,
            1234,
            2000,
            400_000_000,
            800_000_000,
        );
        $attestationBytes = DeploymentEvidenceAuthorityV1::encodeFile($this->dumpAttestation());
        $alternateAttestation = $this->dumpAttestation();
        $alternateAttestation['dump']['size_bytes'] = 999_999;
        $alternateAttestationBytes = DeploymentEvidenceAuthorityV1::encodeFile($alternateAttestation);
        $baseBuild = $this->buildSources($provenanceBytes);
        $capacity = fn(
            BuildVerifiedSourcesV1 $build,
            string $dumpBytes,
            int $dumpSize,
        ): CapacityObservationV1 => new CapacityObservationV1(
            new CapacityVerifiedSourcesV1(
                1,
                4096,
                1_000_000,
                900_000,
                10_000_000,
                9_000_000,
                $build,
                $dumpBytes,
                hash('sha256', $dumpBytes),
                self::SHA,
                $dumpSize,
                '2026-08-12T12:30:00Z',
                50_000_000,
                60_000_000,
                500,
                70_000_000,
                700,
                $this->capacityDevices(1),
            ),
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
        );
        $cases = [
            'capacity provenance substitution' => $this->passedProvider(
                capacity: $capacity($alternateBuild, $attestationBytes, 1_000_000),
            ),
            'capacity dump substitution' => $this->passedProvider(
                capacity: $capacity($baseBuild, $alternateAttestationBytes, 999_999),
            ),
            'artifact provenance substitution' => $this->passedProvider(
                artifact: new ArtifactObservationV1($alternateBuild, null, null, null, null, null),
            ),
        ];
        foreach ($cases as $name => $provider) {
            try {
                DeploymentEvidenceAuthorityV1::collectPredeployEvidence(
                    $provider,
                    self::RUN_ID,
                    self::SHA,
                    self::RELEASE_ID,
                    self::COMMIT,
                );
                self::fail($name . ' was accepted');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('preceding protected gates', $exception->getMessage(), $name);
            }
        }
    }

    public function testArchiveSizeAndDigestDriftBecomesArtifactFailureWithoutAcceptingContradictorySize(): void
    {
        $provenanceBytes = DeploymentEvidenceAuthorityV1::encodeFile($this->provenance());
        $drifted = new BuildVerifiedSourcesV1(
            $provenanceBytes,
            hash('sha256', $provenanceBytes),
            self::RELEASE_ID,
            str_repeat('c', 64),
            123457,
            self::SHA,
            self::SHA,
            1234,
            2000,
            400_000_000,
            800_000_000,
        );
        $assembly = DeploymentEvidenceAuthorityV1::collectPredeployEvidence(
            $this->passedProvider(artifact: new ArtifactObservationV1($drifted, null, null, null, null, null)),
            self::RUN_ID,
            self::SHA,
            self::RELEASE_ID,
            self::COMMIT,
        );
        self::assertSame(24, $assembly['exit_code']);
        self::assertSame('failed', $assembly['sections']['artifact']['status']);
        self::assertSame(self::SHA, $assembly['sections']['artifact']['local_sha256']);
        self::assertSame(str_repeat('c', 64), $assembly['sections']['artifact']['remote_sha256']);

        $contradictory = new BuildVerifiedSourcesV1(
            $provenanceBytes,
            hash('sha256', $provenanceBytes),
            self::RELEASE_ID,
            self::SHA,
            123457,
            self::SHA,
            self::SHA,
            1234,
            2000,
            400_000_000,
            800_000_000,
        );
        $this->expectException(RuntimeException::class);
        DeploymentEvidenceAuthorityV1::collectPredeployEvidence(
            $this->passedProvider(artifact: new ArtifactObservationV1($contradictory, null, null, null, null, null)),
            self::RUN_ID,
            self::SHA,
            self::RELEASE_ID,
            self::COMMIT,
        );
    }

    public function testChildObservationBindsReceiptAndArtifactIdentity(): void
    {
        $receiptBytes = DeployResultV1::canonicalJson(DeployResultV1::create('succeeded', 0));
        $observation = $this->childObservation(hash('sha256', $receiptBytes));
        $decoded = DeploymentEvidenceAuthorityV1::decodeChildObservation(
            DeploymentEvidenceAuthorityV1::encodeFile($observation),
            self::RUN_ID,
            self::SHA,

            $receiptBytes,

            self::SHA,
            self::SHA,
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            str_repeat('d', 32),
            0,
            '2026-08-12T12:31:00Z',
        );

        self::assertSame(
            DeploymentEvidenceAuthorityV1::encodeFile($observation),
            DeploymentEvidenceAuthorityV1::encodeFile($decoded),
        );
    }

    public function testChildObservationRejectsIndependentReceiptOrArtifactSubstitution(): void
    {
        $receiptBytes = DeployResultV1::canonicalJson(DeployResultV1::create('succeeded', 0));
        $observation = $this->childObservation(hash('sha256', $receiptBytes));
        foreach (['receipt_sha256', 'artifact_sha256'] as $field) {
            $changed = $observation;
            $changed[$field] = str_repeat('c', 64);
            try {
                DeploymentEvidenceAuthorityV1::decodeChildObservation(
                    DeploymentEvidenceAuthorityV1::encodeFile($changed),
                    self::RUN_ID,
                    self::SHA,

                    $receiptBytes,

                    self::SHA,
                    self::SHA,
                    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                    str_repeat('d', 32),
                    0,
                    '2026-08-12T12:31:00Z',
                );
                self::fail('Expected child observation substitution rejection.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testOrchestratorTimingRequiresSameBootForTerminalSuccess(): void
    {
        $start = [
            'schema' => DeploymentEvidenceAuthorityV1::ORCHESTRATOR_START_SCHEMA,
            'run_id' => self::RUN_ID,
            'started_at_utc' => '2026-08-12T12:00:00Z',
            'boot_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'monotonic_ns' => 1_000_000_000,
        ];
        $timing = DeploymentEvidenceAuthorityV1::finishOrchestratorTiming(
            $start,
            '2026-08-12T12:00:03Z',
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            4_000_000_000,
            true,
        );
        self::assertSame(3000, $timing['wall_clock_ms']);

        $failed = DeploymentEvidenceAuthorityV1::finishOrchestratorTiming(
            $start,
            '2026-08-12T12:00:03Z',
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            4_000_000_000,
            false,
        );
        self::assertSame(3000, $failed['wall_clock_ms']);
        $rebootFailure = DeploymentEvidenceAuthorityV1::finishOrchestratorTiming(
            $start,
            '2026-08-12T12:01:00Z',
            'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            10,
            false,
        );
        self::assertSame(60_000, $rebootFailure['wall_clock_ms']);

        $this->expectException(RuntimeException::class);
        DeploymentEvidenceAuthorityV1::finishOrchestratorTiming(
            $start,
            '2026-08-12T12:01:00Z',
            'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            10,
            true,
        );
    }

    public function testBootAuthoritiesRejectZeroOrNonRfcUuid(): void
    {
        $receiptBytes = DeployResultV1::canonicalJson(DeployResultV1::create('succeeded', 0));
        $observation = $this->childObservation(hash('sha256', $receiptBytes));
        $observation['manager_boot_id'] = '00000000-0000-0000-0000-000000000000';
        try {
            DeploymentEvidenceAuthorityV1::decodeChildObservation(
                DeploymentEvidenceAuthorityV1::encodeFile($observation),
                self::RUN_ID,
                self::SHA,

                $receiptBytes,

                self::SHA,
                self::SHA,
                $observation['manager_boot_id'],
                str_repeat('d', 32),
                0,
                '2026-08-12T12:31:00Z',
            );
            self::fail('Zero boot UUID was accepted.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(RuntimeException::class);
        DeploymentEvidenceAuthorityV1::finishOrchestratorTiming(
            [
                'schema' => DeploymentEvidenceAuthorityV1::ORCHESTRATOR_START_SCHEMA,
                'run_id' => self::RUN_ID,
                'started_at_utc' => '2026-08-12T12:00:00Z',
                'boot_id' => 'aaaaaaaa-aaaa-0aaa-0aaa-aaaaaaaaaaaa',
                'monotonic_ns' => 1,
            ],
            '2026-08-12T12:00:01Z',
            'aaaaaaaa-aaaa-0aaa-0aaa-aaaaaaaaaaaa',
            1_000_000_001,
            false,
        );
    }

    /** @return array<string,mixed> */
    private function provenance(): array
    {
        return [
            'schema' => DeploymentEvidenceAuthorityV1::BUILD_PROVENANCE_SCHEMA,
            'release_id' => 'ea_20260812_1200',
            'expected_commit' => self::COMMIT,
            'observed_commit' => self::COMMIT,
            'archive' => ['name' => 'ea_20260812_1200.tar.gz', 'size_bytes' => 123456, 'sha256' => self::SHA],
            'capacity_bounds' => [
                'stage_file_count' => 1234,
                'stage_inode_count' => 2000,
                'stage_unpacked_bytes' => 400_000_000,
                'temp_scratch_bytes' => 800_000_000,
            ],
            'source' => [
                'build_script_sha256' => self::SHA,
                'composer_lock_sha256' => self::SHA,
                'package_lock_sha256' => self::SHA,
                'deploy_ea_sha256' => self::SHA,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function dumpAttestation(): array
    {
        return [
            'schema' => DeploymentEvidenceAuthorityV1::DUMP_ATTESTATION_SCHEMA,
            'dump' => [
                'sha256' => self::SHA,
                'size_bytes' => 1_000_000,
                'uncompressed_size_bytes' => 4_000_000,
                'created_at_utc' => '2026-08-12T12:00:00Z',
            ],
            'verification' => [
                'method' => 'mariadb_10_11_isolated_restore_v1',
                'image' => DeploymentEvidenceAuthorityV1::DUMP_RESTORE_IMAGE,
                'sha256_verified' => true,
                'gzip_verified' => true,
                'restore_verified' => true,
                'restored_datadir_allocated_bytes' => 8_000_000,
                'restored_datadir_inode_count' => 256,
                'restored_at_utc' => '2026-08-12T12:20:00Z',
            ],
            'attested_at_utc' => '2026-08-12T12:30:00Z',
        ];
    }

    /** @return array<string,mixed> */
    private function childObservation(string $receiptSha): array
    {
        return [
            'schema' => DeploymentEvidenceAuthorityV1::CHILD_OBSERVATION_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => self::SHA,
            'receipt_sha256' => $receiptSha,
            'artifact_sha256' => self::SHA,
            'unit_launch_sha256' => self::SHA,
            'manager_boot_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'unit_invocation_id' => str_repeat('d', 32),
            'exit_code' => 0,
            'observed_at_utc' => '2026-08-12T12:31:00Z',
        ];
    }

    /** @return array<string,mixed> */

    private function passedProvider(
        ?DumpObservationV1 $dump = null,
        ?CapacityObservationV1 $capacity = null,
        ?ArtifactObservationV1 $artifact = null,
    ): TestProtectedPredeployProvider {
        $provenanceBytes = DeploymentEvidenceAuthorityV1::encodeFile($this->provenance());
        $attestationBytes = DeploymentEvidenceAuthorityV1::encodeFile($this->dumpAttestation());
        $build = $this->buildSources($provenanceBytes);
        return new TestProtectedPredeployProvider(
            new ExpectedCommitObservationV1($provenanceBytes, hash('sha256', $provenanceBytes)),
            $dump ??
                new DumpObservationV1(
                    $attestationBytes,
                    hash('sha256', $attestationBytes),
                    1_000_000,
                    '2026-08-12T12:30:00Z',
                    null,
                    self::SHA,
                    null,
                    null,
                    null,
                ),
            $capacity ??
                new CapacityObservationV1(
                    new CapacityVerifiedSourcesV1(
                        1,
                        4096,
                        1_000_000,
                        900_000,
                        10_000_000,
                        9_000_000,
                        $build,
                        $attestationBytes,
                        hash('sha256', $attestationBytes),
                        self::SHA,
                        1_000_000,
                        '2026-08-12T12:30:00Z',
                        50_000_000,
                        60_000_000,
                        500,
                        70_000_000,
                        700,
                        $this->capacityDevices(1),
                    ),
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                ),
            $artifact ?? new ArtifactObservationV1($build, null, null, null, null, null),
        );
    }

    private function buildSources(string $provenanceBytes): BuildVerifiedSourcesV1
    {
        return new BuildVerifiedSourcesV1(
            $provenanceBytes,
            hash('sha256', $provenanceBytes),
            'ea_20260812_1200',
            self::SHA,
            123456,
            self::SHA,
            self::SHA,
            1234,
            2000,
            400_000_000,
            800_000_000,
        );
    }

    /**
     * @param array<string,mixed> $assembly
     * @return array{lines:list<string>,evidence:array<string,mixed>}
     */
    private function failedBeforeWriteBundle(array $assembly): array
    {
        $intent = DeploymentContractV1::createIntentRecord(
            self::RUN_ID,
            '2026-08-12T12:00:00Z',
            self::COMMIT,
            'ea_20260812_1200',
        );
        $lines = [DeploymentContractV1::canonicalJson($intent)];
        $states = ['built', 'uploaded', 'accepted', 'lock_acquired'];
        $lastVerified = match ($assembly['reason']) {
            'expected_commit_mismatch' => 'lock_acquired',
            'dump_verification_failed' => 'expected_commit_verified',
            'capacity_gate_failed' => 'dump_verified',
            'artifact_verification_failed' => 'capacity_passed',
            default => throw new RuntimeException('unexpected predeploy assembly reason'),
        };
        foreach (['expected_commit_verified', 'dump_verified', 'capacity_passed'] as $state) {
            if ($states[array_key_last($states)] === $lastVerified) {
                break;
            }
            $states[] = $state;
        }
        $previous = 'planned';
        foreach ($states as $index => $state) {
            $lines[] = DeploymentContractV1::canonicalJson([
                'schema' => DeploymentContractV1::RUN_SCHEMA,
                'record_type' => 'transition',
                'run_id' => self::RUN_ID,
                'sequence' => count($lines) + 1,
                'recorded_at_utc' => sprintf('2026-08-12T12:00:%02dZ', $index + 1),
                'previous_state' => $previous,
                'state' => $state,
                'deploy_invocation_count' => 0,
                'intent_sha256' => $intent['intent_sha256'],
                'exit_code' => 0,
                'reason' => 'ok',
            ]);
            $previous = $state;
        }
        $lines[] = DeploymentContractV1::canonicalJson([
            'schema' => DeploymentContractV1::RUN_SCHEMA,
            'record_type' => 'transition',
            'run_id' => self::RUN_ID,
            'sequence' => count($lines) + 1,
            'recorded_at_utc' => '2026-08-12T12:00:10Z',
            'previous_state' => $previous,
            'state' => 'failed_before_write',
            'deploy_invocation_count' => 0,
            'intent_sha256' => $intent['intent_sha256'],
            'exit_code' => $assembly['exit_code'],
            'reason' => $assembly['reason'],
        ]);
        $postGateKeys = [
            'status',
            'kuma_healthy_count',
            'kuma_total_count',
            'runtime_config_passed',
            'services_passed',
            'endpoints_passed',
            'logs_passed',
            'scanner_passed',
            'dormant_clean_passed',
            'passed',
        ];
        $postGates = array_fill_keys($postGateKeys, null);
        $postGates['status'] = 'not_observed';
        return [
            'lines' => $lines,
            'evidence' => [
                'schema' => DeploymentContractV1::EVIDENCE_SCHEMA,
                'run_id' => self::RUN_ID,
                'intent_sha256' => $intent['intent_sha256'],
                'captured_at_utc' => '2026-08-12T12:00:11Z',
                ...$assembly['sections'],
                'deploy' => [
                    'status' => 'not_invoked',
                    'invocation_count' => 0,
                    'exit_code' => null,
                    'rollback_outcome' => 'not_applicable',
                ],
                'rollback' => [
                    'status' => 'not_invoked',
                    'invocation_count' => 0,
                    'mode' => 'not_applicable',
                    'verified' => null,
                ],
                'post_gates' => $postGates,
                'orchestrator_timing' => [
                    'started_at_utc' => '2026-08-12T11:59:59Z',
                    'finished_at_utc' => '2026-08-12T12:00:10Z',
                    'wall_clock_ms' => 11_000,
                ],
                'result' => [
                    'state' => 'failed_before_write',
                    'exit_code' => $assembly['exit_code'],
                    'reason' => $assembly['reason'],
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $assembly @return array{lines:list<string>,evidence:array<string,mixed>} */
    private function succeededBundle(array $assembly): array
    {
        $intent = DeploymentContractV1::createIntentRecord(
            self::RUN_ID,
            '2026-08-12T12:00:00Z',
            self::COMMIT,
            'ea_20260812_1200',
        );
        $lines = [DeploymentContractV1::canonicalJson($intent)];
        $previous = 'planned';
        $states = [
            'built',
            'uploaded',
            'accepted',
            'lock_acquired',
            'expected_commit_verified',
            'dump_verified',
            'capacity_passed',
            'artifact_verified',
            'deploy_running',
            'post_gates_running',
            'succeeded',
        ];
        foreach ($states as $index => $state) {
            $count = $index >= 8 ? 1 : 0;
            $lines[] = DeploymentContractV1::canonicalJson([
                'schema' => DeploymentContractV1::RUN_SCHEMA,
                'record_type' => 'transition',
                'run_id' => self::RUN_ID,
                'sequence' => count($lines) + 1,
                'recorded_at_utc' => sprintf('2026-08-12T12:00:%02dZ', $index + 1),
                'previous_state' => $previous,
                'state' => $state,
                'deploy_invocation_count' => $count,
                'intent_sha256' => $intent['intent_sha256'],
                'exit_code' => 0,
                'reason' => 'ok',
            ]);
            $previous = $state;
        }
        return [
            'lines' => $lines,
            'evidence' => [
                'schema' => DeploymentContractV1::EVIDENCE_SCHEMA,
                'run_id' => self::RUN_ID,
                'intent_sha256' => $intent['intent_sha256'],
                'captured_at_utc' => '2026-08-12T12:01:00Z',
                ...$assembly['sections'],
                'deploy' => [
                    'status' => 'succeeded',
                    'invocation_count' => 1,
                    'exit_code' => 0,
                    'rollback_outcome' => 'not_run',
                ],
                'rollback' => [
                    'status' => 'not_invoked',
                    'invocation_count' => 0,
                    'mode' => 'not_applicable',
                    'verified' => null,
                ],
                'post_gates' => [
                    'status' => 'passed',
                    'kuma_healthy_count' => 13,
                    'kuma_total_count' => 13,
                    'runtime_config_passed' => true,
                    'services_passed' => true,
                    'endpoints_passed' => true,
                    'logs_passed' => true,
                    'scanner_passed' => true,
                    'dormant_clean_passed' => true,
                    'passed' => true,
                ],
                'orchestrator_timing' => [
                    'started_at_utc' => '2026-08-12T11:59:59Z',
                    'finished_at_utc' => '2026-08-12T12:00:12Z',
                    'wall_clock_ms' => 13_000,
                ],
                'result' => ['state' => 'succeeded', 'exit_code' => 0, 'reason' => 'ok'],
            ],
        ];
    }

    /** @return array<string,int> */
    private function capacityDevices(int $device): array
    {
        return [
            'artifact' => $device,
            'dump_pin' => $device,
            'live_storage' => $device,
            'release_root' => $device,
            'renderer_state' => $device,
            'restore_scratch' => $device,
            'stage' => $device,
            'state_root' => $device,
            'temp' => $device,
        ];
    }
}

final class TestProtectedPredeployProvider implements ProtectedPredeployObservationProvider
{
    /** @var list<string> */
    public array $ledger;

    /** @param list<string> $ledger */
    public function __construct(
        private readonly ExpectedCommitObservationV1 $expectedCommitObservation,
        private readonly DumpObservationV1 $dumpObservation,
        private readonly CapacityObservationV1 $capacityObservation,
        private readonly ArtifactObservationV1 $artifactObservation,
        array $ledger = [],
    ) {
        $this->ledger = $ledger;
    }

    public function expectedCommit(): ExpectedCommitObservationV1
    {
        $this->ledger[] = 'expected_commit';
        return $this->expectedCommitObservation;
    }

    public function dump(): DumpObservationV1
    {
        $this->ledger[] = 'dump';
        return $this->dumpObservation;
    }

    public function capacity(): CapacityObservationV1
    {
        $this->ledger[] = 'capacity';
        return $this->capacityObservation;
    }

    public function artifact(): ArtifactObservationV1
    {
        $this->ledger[] = 'artifact';
        return $this->artifactObservation;
    }
}
