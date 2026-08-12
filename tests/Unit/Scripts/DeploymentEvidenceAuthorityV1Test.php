<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Ops\DeploymentEvidenceAuthorityV1;
use Ops\DeploymentContractV1;
use Ops\DeployTimingSampleValidator;
use Ops\DeploymentEvidenceAuthorityV1Issuer;
use Ops\VerifiedPredeployGateV1;
use Ops\ArtifactObservationV1;
use Ops\BuildVerifiedSourcesV1;
use Ops\CapacityObservationV1;
use Ops\CapacityVerifiedSourcesV1;
use Ops\DumpObservationV1;
use Ops\ExpectedCommitObservationV1;
use Ops\ProtectedPredeployObservationProvider;
use Ops\TrafficObservationV1;
use Ops\DeployResultV1;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../../scripts/ops/lib/DeploymentEvidenceAuthorityV1.php';
require_once __DIR__ . '/../../../scripts/ops/lib/DeploymentContractV1.php';
require_once __DIR__ . '/../../../scripts/ops/lib/DeployTimingSampleValidator.php';
require_once __DIR__ . '/../../../scripts/ops/lib/ProtectedPredeployObservationProvider.php';
require_once __DIR__ . '/../../../scripts/ops/lib/DeployResultV1.php';

final class DeploymentEvidenceAuthorityV1Test extends TestCase
{
    private const RUN_ID = '018f6f52-4c87-4d4e-8b19-6a66e6e1af25';
    private const TIMING_RUN_ID = '128f6f52-4c87-4d4e-8b19-6a66e6e1af25';
    private const COMMIT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SHA = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

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
    }

    public function testDumpAttestationIsBuiltOnlyFromExactInternalRestoreObservation(): void
    {
        $attestation = DeploymentEvidenceAuthorityV1::createDumpAttestation(
            [
                'sha256' => self::SHA,
                'size_bytes' => 1_000_000,
                'uncompressed_size_bytes' => 4_000_000,
                'created_at_utc' => '2026-08-12T12:00:00Z',
            ],
            [
                'method' => 'mariadb_10_11_isolated_restore_v1',
                'dump_sha256' => self::SHA,
                'dump_size_bytes' => 1_000_000,
                'uncompressed_size_bytes' => 4_000_000,
                'gzip_exit_code' => 0,
                'restore_exit_code' => 0,
                'restored_at_utc' => '2026-08-12T12:20:00Z',
            ],
            '2026-08-12T12:30:00Z',
        );
        self::assertSame(
            DeploymentEvidenceAuthorityV1::encodeFile($this->dumpAttestation()),
            DeploymentEvidenceAuthorityV1::encodeFile($attestation),
        );

        $this->expectException(RuntimeException::class);
        DeploymentEvidenceAuthorityV1::createDumpAttestation(
            [
                'sha256' => self::SHA,
                'size_bytes' => 1_000_000,
                'uncompressed_size_bytes' => 4_000_000,
                'created_at_utc' => '2026-08-12T12:00:00Z',
            ],
            [
                'method' => 'mariadb_10_11_isolated_restore_v1',
                'dump_sha256' => str_repeat('c', 64),
                'dump_size_bytes' => 1_000_000,
                'uncompressed_size_bytes' => 4_000_000,
                'gzip_exit_code' => 0,
                'restore_exit_code' => 0,
                'restored_at_utc' => '2026-08-12T12:20:00Z',
            ],
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

    /** @return iterable<string,array{string}> */
    public static function invalidDumpAttestationProvider(): iterable
    {
        foreach (['sha', 'size', 'future', 'stale', 'gzip', 'restore'] as $case) {
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
        self::assertSame(60, $result['observed_percent']);
        self::assertSame(82, $result['projected_percent']);
        self::assertTrue($result['passed']);
    }

    public function testCapacityRejectsMissingBoundsOverflowAndThresholdEquality(): void
    {
        foreach (
            [
                [4096, 1_000_000, 400_000, null, 1, 1, 1, 0, $this->capacityDevices(1)],
                [PHP_INT_MAX, 2, 1, 1, 1, 1, 1, 0, $this->capacityDevices(1)],
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
        DeploymentEvidenceAuthorityV1::capacityFromStatvfs(1, 4096, 1_000_000, 400_000, 1, 1, 1, 1, 0, $devices);
    }

    public function testCapacityDerivesStageAndScratchOnlyFromVerifiedAuthorities(): void
    {
        $provenance = $this->provenance();
        $provenanceBytes = DeploymentEvidenceAuthorityV1::encodeFile($provenance);
        $bytes = DeploymentEvidenceAuthorityV1::encodeFile($this->dumpAttestation());
        $result = DeploymentEvidenceAuthorityV1::capacityFromVerifiedAuthorities(
            1,
            4096,
            1_000_000,
            900_000,
            $provenanceBytes,
            hash('sha256', $provenanceBytes),
            'ea_20260812_1200',
            self::COMMIT,
            self::SHA,
            123456,
            self::SHA,
            self::SHA,
            1234,
            400_000_000,
            800_000_000,
            $bytes,
            hash('sha256', $bytes),
            self::RUN_ID,
            self::SHA,
            self::SHA,
            1_000_000,
            '2026-08-12T12:30:00Z',
            $this->capacityDevices(1),
        );

        self::assertSame(1_205_123_456, $result['base_required_bytes']);

        $provenance['capacity_bounds']['stage_unpacked_bytes'] = 1;
        $this->expectException(RuntimeException::class);
        DeploymentEvidenceAuthorityV1::capacityFromVerifiedAuthorities(
            1,
            4096,
            1_000_000,
            900_000,
            DeploymentEvidenceAuthorityV1::encodeFile($provenance),
            hash('sha256', DeploymentEvidenceAuthorityV1::encodeFile($provenance)),
            'ea_20260812_1200',
            self::COMMIT,
            self::SHA,
            123456,
            self::SHA,
            self::SHA,
            1234,
            400_000_000,
            800_000_000,
            $bytes,
            hash('sha256', $bytes),
            self::RUN_ID,
            self::SHA,
            self::SHA,
            1_000_000,
            '2026-08-12T12:30:00Z',
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
            $provenanceBytes,
            hash('sha256', $provenanceBytes),
            'ea_20260812_1200',
            self::COMMIT,
            self::SHA,
            123456,
            self::SHA,
            self::SHA,
            1234,
            400_000_000,
            800_000_000,
            $attestationBytes,
            hash('sha256', $attestationBytes),
            self::RUN_ID,
            self::SHA,
            self::SHA,
            1_000_000,
            '2026-08-12T12:30:00Z',
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
            400_000_000,
            800_000_000,
        );
    }

    public function testPinnedTrafficReportAndAllVerifiedCollectorsAssemblePassedPredeployEvidence(): void
    {
        $trafficBytes = $this->trafficReportBytes('allow', 0);
        $traffic = DeploymentEvidenceAuthorityV1::verifyAndDeriveTrafficEvidence(
            $trafficBytes,
            hash('sha256', $trafficBytes),
            self::RUN_ID,
            self::SHA,
            'normal',
            self::SHA,
            '2026-08-09.1',
            1,
            91,
        );
        $provenanceBytes = DeploymentEvidenceAuthorityV1::encodeFile($this->provenance());
        $attestationBytes = DeploymentEvidenceAuthorityV1::encodeFile($this->dumpAttestation());
        self::assertSame('traffic_gate', $traffic->gate());
        self::assertSame(hash('sha256', $trafficBytes), $traffic->section()['report_sha256']);
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
        $trafficBytes = $this->trafficReportBytes(
            $failedGate === 'traffic_gate' && !$invalid ? 'hard_stop' : 'allow',
            $failedGate === 'traffic_gate' && !$invalid ? 20 : 0,
        );
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
        $trafficInvalid = $this->notObservedTraffic();
        $trafficInvalid['status'] = 'invalid';
        $trafficInvalid['report_sha256'] = self::SHA;
        $trafficInvalid['exit_code'] = 21;

        $commitCandidate = $provenanceBytes;
        if ($failedGate === 'expected_commit') {
            $changed = $this->provenance();
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
            400_000_000,
            800_000_000,
        );
        $provider = new TestProtectedPredeployProvider(
            new ExpectedCommitObservationV1($commitCandidate, hash('sha256', $commitCandidate)),
            new TrafficObservationV1(
                $failedGate === 'traffic_gate' && $invalid ? "malformed\n" : $trafficBytes,
                hash('sha256', $failedGate === 'traffic_gate' && $invalid ? "malformed\n" : $trafficBytes),
                self::SHA,
                '2026-08-09.1',
                1,
                91,
            ),
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
                    $capacityFailure['observed_percent'],
                    $capacityFailure['projected_percent'],
                )
                : new CapacityObservationV1(
                    new CapacityVerifiedSourcesV1(
                        1,
                        4096,
                        1_000_000,
                        900_000,
                        $buildSources,
                        $attestationBytes,
                        hash('sha256', $attestationBytes),
                        self::SHA,
                        1_000_000,
                        '2026-08-12T12:30:00Z',
                        $this->capacityDevices(1),
                    ),
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
            self::COMMIT,
            'normal',
        );
        $ledger = $provider->ledger;
        self::assertSame($expectedExit, $assembly['exit_code']);
        self::assertSame($expectedReason, $assembly['reason']);
        $order = ['expected_commit', 'traffic_gate', 'dump', 'capacity', 'artifact'];
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
            if ($failedGate === 'traffic_gate' && $invalid) {
                self::assertSame(hash('sha256', "malformed\n"), $section['report_sha256']);
                self::assertSame(21, $section['exit_code']);
                self::assertNull($section['counts']);
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
        yield 'traffic hard stop' => ['traffic_gate', false, 20, 'traffic_hard_stop'];
        yield 'traffic invalid' => ['traffic_gate', true, 21, 'traffic_evidence_invalid'];
        yield 'dump failed' => ['dump', false, 22, 'dump_verification_failed'];
        yield 'dump invalid' => ['dump', true, 22, 'dump_verification_failed'];
        yield 'capacity failed' => ['capacity', false, 23, 'capacity_gate_failed'];
        yield 'capacity invalid' => ['capacity', true, 23, 'capacity_gate_failed'];
        yield 'artifact failed' => ['artifact', false, 24, 'artifact_verification_failed'];
        yield 'artifact invalid' => ['artifact', true, 24, 'artifact_verification_failed'];
    }

    public function testTrafficPinModeAndCanonicalBytesCannotBeSubstituted(): void
    {
        $bytes = $this->trafficReportBytes('allow', 0);
        foreach (
            [
                [$bytes . ' ', hash('sha256', $bytes . ' '), 'normal'],
                [$bytes, str_repeat('c', 64), 'normal'],
                [$bytes, hash('sha256', $bytes), 'no-business-traffic'],
            ]
            as [$candidate, $sha, $mode]
        ) {
            try {
                DeploymentEvidenceAuthorityV1::verifyAndDeriveTrafficEvidence(
                    $candidate,
                    $sha,
                    self::RUN_ID,
                    self::SHA,
                    $mode,
                    self::SHA,
                    '2026-08-09.1',
                    1,
                    91,
                );
                self::fail('Expected traffic authority substitution rejection.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }

        $report = json_decode(substr($bytes, 0, -1), true, 64, JSON_THROW_ON_ERROR);
        $report['future_field'] = 'forbidden';
        ksort($report);
        $withExtraKey = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        try {
            DeploymentEvidenceAuthorityV1::verifyAndDeriveTrafficEvidence(
                $withExtraKey,
                hash('sha256', $withExtraKey),
                self::RUN_ID,
                self::SHA,
                'normal',
                self::SHA,
                '2026-08-09.1',
                1,
                91,
            );
            self::fail('Traffic report with an extra top-level key was accepted.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }
    }

    public function testProviderNormalizesParseableTrafficEvidenceFailuresToExit21(): void
    {
        $base = json_decode(substr($this->trafficReportBytes('allow', 0), 0, -1), true, 64, JSON_THROW_ON_ERROR);
        $cases = [];
        $withExtra = $base;
        $withExtra['future_field'] = 'forbidden';
        $cases['closed schema'] = $withExtra;
        $inconsistent = $base;
        $inconsistent['parse_complete'] = false;
        $cases['inconsistent core'] = $inconsistent;

        foreach ($cases as $name => $report) {
            ksort($report);
            $bytes = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            $provider = $this->passedProviderWithTraffic(
                new TrafficObservationV1($bytes, hash('sha256', $bytes), self::SHA, '2026-08-09.1', 1, 91),
            );
            $assembly = DeploymentEvidenceAuthorityV1::collectPredeployEvidence(
                $provider,
                self::RUN_ID,
                self::SHA,
                self::COMMIT,
                'normal',
            );
            self::assertSame(21, $assembly['exit_code'], $name);
            self::assertSame(hash('sha256', $bytes), $assembly['sections']['traffic_gate']['report_sha256']);
            self::assertSame(['expected_commit', 'traffic_gate'], $provider->ledger);
            $bundle = $this->failedBeforeWriteBundle($assembly);
            self::assertSame(
                'failed_before_write',
                DeploymentContractV1::validateBundle($bundle['lines'], $bundle['evidence'])['state'],
            );
        }
    }

    public function testTrafficAbsenceCannotInventAPinnedDigest(): void
    {
        $provider = $this->passedProviderWithTraffic(
            new TrafficObservationV1(null, null, self::SHA, '2026-08-09.1', 1, 91),
        );
        $assembly = DeploymentEvidenceAuthorityV1::collectPredeployEvidence(
            $provider,
            self::RUN_ID,
            self::SHA,
            self::COMMIT,
            'normal',
        );
        self::assertSame(21, $assembly['exit_code']);
        self::assertNull($assembly['sections']['traffic_gate']['report_sha256']);

        $this->expectException(RuntimeException::class);
        DeploymentEvidenceAuthorityV1::collectPredeployEvidence(
            $this->passedProviderWithTraffic(
                new TrafficObservationV1(null, self::SHA, self::SHA, '2026-08-09.1', 1, 91),
            ),
            self::RUN_ID,
            self::SHA,
            self::COMMIT,
            'normal',
        );
    }

    public function testProtectedObservationSourceModesRejectContradictionsAndPartialTuples(): void
    {
        $trafficBytes = $this->trafficReportBytes('allow', 0);
        $traffic = new TrafficObservationV1(
            $trafficBytes,
            hash('sha256', $trafficBytes),
            self::SHA,
            '2026-08-09.1',
            1,
            91,
        );
        $provenanceBytes = DeploymentEvidenceAuthorityV1::encodeFile($this->provenance());
        $build = $this->buildSources($provenanceBytes);
        $attestationBytes = DeploymentEvidenceAuthorityV1::encodeFile($this->dumpAttestation());
        $capacitySources = new CapacityVerifiedSourcesV1(
            1,
            4096,
            1_000_000,
            900_000,
            $build,
            $attestationBytes,
            hash('sha256', $attestationBytes),
            self::SHA,
            1_000_000,
            '2026-08-12T12:30:00Z',
            $this->capacityDevices(1),
        );
        $cases = [
            'dump conflicting modes' => [
                $this->passedProviderWithTraffic(
                    $traffic,
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
                ['expected_commit', 'traffic_gate', 'dump'],
            ],
            'dump partial protected tuple' => [
                $this->passedProviderWithTraffic(
                    $traffic,
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
                ['expected_commit', 'traffic_gate', 'dump'],
            ],
            'capacity conflicting modes' => [
                $this->passedProviderWithTraffic(
                    $traffic,
                    capacity: new CapacityObservationV1($capacitySources, 1, null, null, null),
                ),
                ['expected_commit', 'traffic_gate', 'dump', 'capacity'],
            ],
            'artifact conflicting modes' => [
                $this->passedProviderWithTraffic(
                    $traffic,
                    artifact: new ArtifactObservationV1($build, self::SHA, null, null, null, null),
                ),
                ['expected_commit', 'traffic_gate', 'dump', 'capacity', 'artifact'],
            ],
        ];
        foreach ($cases as $name => [$provider, $expectedLedger]) {
            try {
                DeploymentEvidenceAuthorityV1::collectPredeployEvidence(
                    $provider,
                    self::RUN_ID,
                    self::SHA,
                    self::COMMIT,
                    'normal',
                );
                self::fail($name . ' was accepted.');
            } catch (RuntimeException) {
                self::assertSame($expectedLedger, $provider->ledger, $name);
            }
        }
    }

    public function testAuthoritativeTimingRequiresCanonicalFinalLineFeed(): void
    {
        $bytes = $this->timingBytes(0);
        self::assertSame(6, DeployTimingSampleValidator::validateBytes($bytes)['records']);

        foreach ([substr($bytes, 0, -1), $bytes . "\n"] as $nonCanonical) {
            try {
                DeployTimingSampleValidator::validateBytes($nonCanonical);
                self::fail('Non-canonical timing bytes were accepted.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testChildObservationBindsReceiptTimingAndArtifactIdentity(): void
    {
        $receiptBytes = DeployResultV1::canonicalJson(DeployResultV1::create('succeeded', 0));
        $timingBytes = $this->timingBytes(0);
        $observation = $this->childObservation(hash('sha256', $receiptBytes), hash('sha256', $timingBytes));
        $decoded = DeploymentEvidenceAuthorityV1::decodeChildObservation(
            DeploymentEvidenceAuthorityV1::encodeFile($observation),
            self::RUN_ID,
            self::SHA,
            self::TIMING_RUN_ID,
            $receiptBytes,
            $timingBytes,
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

    public function testChildObservationRejectsIndependentTimingOrReceiptSubstitution(): void
    {
        $receiptBytes = DeployResultV1::canonicalJson(DeployResultV1::create('succeeded', 0));
        $timingBytes = $this->timingBytes(0);
        $observation = $this->childObservation(hash('sha256', $receiptBytes), hash('sha256', $timingBytes));
        foreach (['receipt_sha256', 'artifact_sha256'] as $field) {
            $changed = $observation;
            $changed[$field] = str_repeat('c', 64);
            try {
                DeploymentEvidenceAuthorityV1::decodeChildObservation(
                    DeploymentEvidenceAuthorityV1::encodeFile($changed),
                    self::RUN_ID,
                    self::SHA,
                    self::TIMING_RUN_ID,
                    $receiptBytes,
                    $timingBytes,
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
        $changed = $observation;
        $changed['timing']['authoritative_sha256'] = str_repeat('c', 64);
        $this->expectException(RuntimeException::class);
        DeploymentEvidenceAuthorityV1::decodeChildObservation(
            DeploymentEvidenceAuthorityV1::encodeFile($changed),
            self::RUN_ID,
            self::SHA,
            self::TIMING_RUN_ID,
            $receiptBytes,
            $timingBytes,
            self::SHA,
            self::SHA,
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            str_repeat('d', 32),
            0,
            '2026-08-12T12:31:00Z',
        );
    }

    public function testChildObservationAcceptsMissingOrInvalidTimingWithoutChangingReceiptVerdict(): void
    {
        $receiptBytes = DeployResultV1::canonicalJson(DeployResultV1::create('succeeded', 0));
        foreach (
            [
                [
                    '',
                    ['status' => 'not_observed', 'authoritative_sha256' => null, 'run_id' => null, 'total_ms' => null],
                ],
                [
                    "not-json\n",
                    [
                        'status' => 'invalid',
                        'authoritative_sha256' => hash('sha256', "not-json\n"),
                        'run_id' => null,
                        'total_ms' => null,
                    ],
                ],
            ]
            as [$timingBytes, $timing]
        ) {
            $observation = $this->childObservation(hash('sha256', $receiptBytes), self::SHA);
            $observation['timing'] = $timing;
            $decoded = DeploymentEvidenceAuthorityV1::decodeChildObservation(
                DeploymentEvidenceAuthorityV1::encodeFile($observation),
                self::RUN_ID,
                self::SHA,
                self::TIMING_RUN_ID,
                $receiptBytes,
                $timingBytes,
                self::SHA,
                self::SHA,
                'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                str_repeat('d', 32),
                0,
                '2026-08-12T12:31:00Z',
            );
            self::assertSame($timing['status'], $decoded['timing']['status']);
        }
    }

    public function testInvalidTimingCannotInventParsedIdentityOrTotal(): void
    {
        $receiptBytes = DeployResultV1::canonicalJson(DeployResultV1::create('succeeded', 0));
        $timingBytes = "not-json\n";
        $observation = $this->childObservation(hash('sha256', $receiptBytes), self::SHA);
        $observation['timing'] = [
            'status' => 'invalid',
            'authoritative_sha256' => hash('sha256', $timingBytes),
            'run_id' => self::TIMING_RUN_ID,
            'total_ms' => 1,
        ];

        $this->expectException(RuntimeException::class);
        DeploymentEvidenceAuthorityV1::decodeChildObservation(
            DeploymentEvidenceAuthorityV1::encodeFile($observation),
            self::RUN_ID,
            self::SHA,
            self::TIMING_RUN_ID,
            $receiptBytes,
            $timingBytes,
            self::SHA,
            self::SHA,
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            str_repeat('d', 32),
            0,
            '2026-08-12T12:31:00Z',
        );
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
        $timingBytes = $this->timingBytes(0);
        $observation = $this->childObservation(hash('sha256', $receiptBytes), hash('sha256', $timingBytes));
        $observation['manager_boot_id'] = '00000000-0000-0000-0000-000000000000';
        try {
            DeploymentEvidenceAuthorityV1::decodeChildObservation(
                DeploymentEvidenceAuthorityV1::encodeFile($observation),
                self::RUN_ID,
                self::SHA,
                self::TIMING_RUN_ID,
                $receiptBytes,
                $timingBytes,
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
                'sha256_verified' => true,
                'gzip_verified' => true,
                'restore_verified' => true,
                'restored_at_utc' => '2026-08-12T12:20:00Z',
            ],
            'attested_at_utc' => '2026-08-12T12:30:00Z',
        ];
    }

    /** @return array<string,mixed> */
    private function childObservation(string $receiptSha, string $timingSha): array
    {
        return [
            'schema' => DeploymentEvidenceAuthorityV1::CHILD_OBSERVATION_SCHEMA,
            'run_id' => self::RUN_ID,
            'intent_sha256' => self::SHA,
            'timing' => [
                'status' => 'valid',
                'authoritative_sha256' => $timingSha,
                'run_id' => self::TIMING_RUN_ID,
                'total_ms' => 60,
            ],
            'receipt_sha256' => $receiptSha,
            'artifact_sha256' => self::SHA,
            'unit_launch_sha256' => self::SHA,
            'manager_boot_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'unit_invocation_id' => str_repeat('d', 32),
            'exit_code' => 0,
            'observed_at_utc' => '2026-08-12T12:31:00Z',
        ];
    }

    private function timingBytes(int $exitCode): string
    {
        $lines = [];
        foreach (
            ['preparation_artifact', 'predeploy', 'permissions_stage', 'switch', 'postdeploy_validation']
            as $index => $phase
        ) {
            $lines[] = json_encode(
                [
                    'schema' => 'deploy_timing.v1',
                    'run_id' => self::TIMING_RUN_ID,
                    'sequence' => $index + 1,
                    'event' => 'phase',
                    'mode' => 'deploy',
                    'phase' => $phase,
                    'status' => 'ok',
                    'duration_ms' => 10,
                    'elapsed_ms' => ($index + 1) * 10,
                    'dry_run' => false,
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        }
        $lines[] = json_encode(
            [
                'schema' => 'deploy_timing.v1',
                'run_id' => self::TIMING_RUN_ID,
                'sequence' => 6,
                'event' => 'summary',
                'mode' => 'deploy',
                'outcome' => 'succeeded',
                'exit_code' => $exitCode,
                'total_ms' => 60,
                'dry_run' => false,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        return implode("\n", $lines) . "\n";
    }

    private function trafficReportBytes(string $decision, int $exitCode): string
    {
        $counts = array_fill_keys(
            [
                'documented_health',
                'documented_periodic_ops',
                'public_read',
                'denied_external',
                'business_or_authenticated',
                'unclassified',
                'status_5xx',
                'write',
                'authenticated',
                'customers_or_sensitive',
                'scanner_success',
                'source_unknown',
                'method_unknown',
                'target_unknown',
                'pre_window_completion',
                'lines_seen',
                'lines_in_window',
                'parse_errors',
                'rotation_errors',
                'total',
            ],
            0,
        );
        $counts['documented_health'] = 1;
        $counts['lines_seen'] = 1;
        $counts['lines_in_window'] = 1;
        $counts['total'] = 1;
        if ($decision === 'hard_stop') {
            $counts['documented_health'] = 0;
            $counts['public_read'] = 1;
        }
        return json_encode(
            [
                'schema' => 'traffic_gate.v1',
                'producer_sha256' => self::SHA,
                'policy_version' => 'traffic_gate_policy.v1',
                'catalog_version' => '2026-08-09.1',
                'purpose' => 'deploy',
                'mode' => 'normal',
                'window_start_epoch' => 1,
                'window_end_epoch' => 91,
                'window_seconds' => 90,
                'log_set_sha256' => self::SHA,
                'rotation_complete' => true,
                'parse_complete' => true,
                'evidence_complete' => true,
                'decision' => $decision,
                'exit_code' => $exitCode,
                'counts' => $counts,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    /** @return array<string,mixed> */
    private function notObservedTraffic(): array
    {
        $keys = [
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
        ];
        $section = array_fill_keys($keys, null);
        $section['status'] = 'not_observed';
        return $section;
    }

    private function passedProviderWithTraffic(
        TrafficObservationV1 $traffic,
        ?DumpObservationV1 $dump = null,
        ?CapacityObservationV1 $capacity = null,
        ?ArtifactObservationV1 $artifact = null,
    ): TestProtectedPredeployProvider {
        $provenanceBytes = DeploymentEvidenceAuthorityV1::encodeFile($this->provenance());
        $attestationBytes = DeploymentEvidenceAuthorityV1::encodeFile($this->dumpAttestation());
        $build = $this->buildSources($provenanceBytes);
        return new TestProtectedPredeployProvider(
            new ExpectedCommitObservationV1($provenanceBytes, hash('sha256', $provenanceBytes)),
            $traffic,
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
                        $build,
                        $attestationBytes,
                        hash('sha256', $attestationBytes),
                        self::SHA,
                        1_000_000,
                        '2026-08-12T12:30:00Z',
                        $this->capacityDevices(1),
                    ),
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
            'normal',
        );
        $lines = [DeploymentContractV1::canonicalJson($intent)];
        $states = ['built', 'uploaded', 'accepted', 'lock_acquired'];
        $lastVerified = match ($assembly['reason']) {
            'expected_commit_mismatch' => 'lock_acquired',
            'traffic_hard_stop', 'traffic_evidence_invalid' => 'expected_commit_verified',
            'dump_verification_failed' => 'traffic_gate_passed',
            'capacity_gate_failed' => 'dump_verified',
            'artifact_verification_failed' => 'capacity_passed',
            default => throw new RuntimeException('unexpected predeploy assembly reason'),
        };
        foreach (['expected_commit_verified', 'traffic_gate_passed', 'dump_verified', 'capacity_passed'] as $state) {
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
                'deploy_timing' => [
                    'status' => 'not_observed',
                    'authoritative_sha256' => null,
                    'run_id' => null,
                    'total_ms' => null,
                ],
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
            'normal',
        );
        $lines = [DeploymentContractV1::canonicalJson($intent)];
        $previous = 'planned';
        $states = [
            'built',
            'uploaded',
            'accepted',
            'lock_acquired',
            'expected_commit_verified',
            'traffic_gate_passed',
            'dump_verified',
            'capacity_passed',
            'artifact_verified',
            'deploy_running',
            'post_gates_running',
            'succeeded',
        ];
        foreach ($states as $index => $state) {
            $count = $index >= 9 ? 1 : 0;
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
                'deploy_timing' => [
                    'status' => 'not_observed',
                    'authoritative_sha256' => null,
                    'run_id' => null,
                    'total_ms' => null,
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
            'release_root' => $device,
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
        private readonly TrafficObservationV1 $trafficObservation,
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

    public function traffic(): TrafficObservationV1
    {
        $this->ledger[] = 'traffic_gate';
        return $this->trafficObservation;
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
