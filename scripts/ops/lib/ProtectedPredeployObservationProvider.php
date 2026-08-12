<?php

declare(strict_types=1);

namespace Ops;

interface ProtectedPredeployObservationProvider
{
    public function expectedCommit(): ExpectedCommitObservationV1;
    public function traffic(): TrafficObservationV1;
    public function dump(): DumpObservationV1;
    public function capacity(): CapacityObservationV1;
    public function artifact(): ArtifactObservationV1;
}

final readonly class ExpectedCommitObservationV1
{
    public function __construct(public string $provenanceBytes, public string $pinnedProvenanceSha256) {}
}

final readonly class TrafficObservationV1
{
    public function __construct(
        public ?string $pinnedReportBytes,
        public ?string $pinnedReportSha256,
        public string $expectedProducerSha256,
        public string $expectedCatalogVersion,
        public int $windowStartEpoch,
        public int $windowEndEpoch,
    ) {}
}

final readonly class DumpObservationV1
{
    public function __construct(
        public ?string $attestationBytes,
        public ?string $pinnedAttestationSha256,
        public ?int $stableDumpSizeBytes,
        public ?string $observedAtUtc,
        public ?int $ageSeconds,
        public ?string $dumpSha256,
        public ?bool $sha256Verified,
        public ?bool $gzipVerified,
        public ?bool $restoreVerified,
    ) {}
}

final readonly class CapacityObservationV1
{
    public function __construct(
        public ?CapacityVerifiedSourcesV1 $verifiedSources,
        public ?int $availableBytes,
        public ?int $projectedRequiredBytes,
        public ?int $availableInodes,
        public ?int $stageInodeCount,
        public ?int $restoreInodeCount,
        public ?int $inodeHeadroom,
        public ?int $projectedRequiredInodes,
        public ?int $observedPercent,
        public ?int $projectedPercent,
    ) {}
}

final readonly class ArtifactObservationV1
{
    public function __construct(
        public ?BuildVerifiedSourcesV1 $verifiedSources,
        public ?string $localSha256,
        public ?string $remoteSha256,
        public ?string $manifestSha256,
        public ?string $hostScriptSha256,
        public ?string $artifactScriptSha256,
    ) {}
}

final readonly class BuildVerifiedSourcesV1
{
    public function __construct(
        public string $provenanceBytes,
        public string $authorizedProvenanceSha256,
        public string $releaseId,
        public string $archiveSha256,
        public int $archiveSizeBytes,
        public string $hostDeployScriptSha256,
        public string $artifactDeployScriptSha256,
        public int $stageFileCount,
        public int $stageInodeCount,
        public int $stageUnpackedBytes,
        public int $tempScratchBytes,
    ) {}
}

final readonly class CapacityVerifiedSourcesV1
{
    public function __construct(
        public int $filesystemDevice,
        public int $blockSize,
        public int $blocks,
        public int $blocksAvailable,
        public int $inodes,
        public int $inodesAvailable,
        public BuildVerifiedSourcesV1 $build,
        public string $attestationBytes,
        public string $attestationSha256,
        public string $dumpSha256,
        public int $dumpSizeBytes,
        public string $observedAtUtc,
        /** @var array<string,int> */ public array $componentDevices,
    ) {}
}
