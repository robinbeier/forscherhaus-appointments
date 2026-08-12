<?php

declare(strict_types=1);

namespace Ops;

use RuntimeException;

require_once __DIR__ . '/DeploymentHostRunnerBuildV1.php';
require_once __DIR__ . '/DeploymentHostRunnerCapacityV1.php';
require_once __DIR__ . '/DeploymentHostRunnerDumpV1.php';
require_once __DIR__ . '/DeploymentHostRunnerEvidenceProviderV1.php';
require_once __DIR__ . '/DeploymentHostRunnerTrafficV1.php';

/**
 * Concrete protected source used by the Host Runner. It composes only fixed
 * helper-backed collectors and retains prior gate objects for exact binding.
 */
final class SystemHostRunnerProtectedObservationSource implements HostRunnerProtectedObservationSource
{
    private readonly ProtectedHostBuildCollector $buildCollector;
    private readonly ProtectedHostTrafficCollector $trafficCollector;
    private readonly ProtectedHostDumpCollector $dumpCollector;
    private readonly ProtectedHostCapacityCollector $capacityCollector;
    private ?HostRunnerBuildAuthorityV1 $buildAuthority = null;
    private ?DumpObservationV1 $dumpAuthority = null;
    private ?string $runId = null;
    private ?string $intentSha256 = null;

    public function __construct(
        private readonly string $expectedCommit,
        HostRunnerStorage $storage,
        ?ProtectedHostBuildCollector $buildCollector = null,
        ?ProtectedHostTrafficCollector $trafficCollector = null,
        ?ProtectedHostDumpCollector $dumpCollector = null,
        ?ProtectedHostCapacityCollector $capacityCollector = null,
    ) {
        if (preg_match('/^[0-9a-f]{40}$/D', $expectedCommit) !== 1) {
            throw new RuntimeException('protected source expected commit is invalid');
        }
        $this->buildCollector = $buildCollector ?? new ProtectedHostBuildCollector();
        $this->trafficCollector = $trafficCollector ?? new ProtectedHostTrafficCollector();
        $this->dumpCollector = $dumpCollector ?? new ProtectedHostDumpCollector($storage);
        $this->capacityCollector = $capacityCollector ?? new ProtectedHostCapacityCollector();
    }

    public function buildProvenance(string $runId, string $releaseId, string $authorizedSha256): ExpectedCommitObservationV1
    {
        $this->bindRun($runId);
        if ($this->buildAuthority !== null) {
            throw new RuntimeException('protected build authority was requested twice');
        }
        $this->buildAuthority = $this->buildCollector->collect($releaseId, $authorizedSha256);
        return $this->buildAuthority->expectedCommit;
    }

    public function traffic(string $runId, string $intentSha256, string $mode): TrafficObservationV1
    {
        $this->bindRunAndIntent($runId, $intentSha256);
        return $this->trafficCollector->collect($runId, $mode);
    }

    public function dump(string $runId, string $intentSha256, array $dumpReference): DumpObservationV1
    {
        $this->bindRunAndIntent($runId, $intentSha256);
        $this->dumpAuthority = $this->dumpCollector->collect($runId, $dumpReference);
        return $this->dumpAuthority;
    }

    public function capacity(
        string $runId,
        string $intentSha256,
        array $input,
        ExpectedCommitObservationV1 $provenance,
        DumpObservationV1 $dump,
    ): CapacityObservationV1 {
        $this->bindRunAndIntent($runId, $intentSha256);
        if ($this->buildAuthority === null || $this->dumpAuthority === null) {
            throw new RuntimeException('capacity lacks prior protected source authorities');
        }
        if (
            !hash_equals($this->buildAuthority->expectedCommit->provenanceBytes, $provenance->provenanceBytes) ||
            !hash_equals($this->buildAuthority->expectedCommit->pinnedProvenanceSha256, $provenance->pinnedProvenanceSha256) ||
            $this->dumpAuthority !== $dump
        ) {
            throw new RuntimeException('capacity substitutes a prior protected source authority');
        }
        return $this->capacityCollector->collect(
            $runId,
            $intentSha256,
            $input['parameters']['release_id'],
            $this->expectedCommit,
            $input['parameters']['renderer_deploy_mode'],
            $this->buildAuthority->verifiedSources,
            $dump,
        );
    }

    public function artifact(
        string $runId,
        string $intentSha256,
        ExpectedCommitObservationV1 $provenance,
        CapacityObservationV1 $capacity,
    ): ArtifactObservationV1 {
        $this->bindRunAndIntent($runId, $intentSha256);
        if (
            $this->buildAuthority === null || $capacity->verifiedSources === null ||
            $capacity->verifiedSources->build !== $this->buildAuthority->verifiedSources ||
            !hash_equals($provenance->provenanceBytes, $this->buildAuthority->expectedCommit->provenanceBytes) ||
            !hash_equals($provenance->pinnedProvenanceSha256, $this->buildAuthority->expectedCommit->pinnedProvenanceSha256)
        ) {
            throw new RuntimeException('artifact lacks its exact protected build authority');
        }
        return new ArtifactObservationV1($this->buildAuthority->verifiedSources, null, null, null, null, null);
    }

    private function bindRun(string $runId): void
    {
        if ($this->runId === null) {
            $this->runId = $runId;
            return;
        }
        if ($this->runId !== $runId) {
            throw new RuntimeException('protected source cannot switch deployment run');
        }
    }

    private function bindRunAndIntent(string $runId, string $intentSha256): void
    {
        $this->bindRun($runId);
        if ($this->intentSha256 === null) {
            $this->intentSha256 = $intentSha256;
            return;
        }
        if (!hash_equals($this->intentSha256, $intentSha256)) {
            throw new RuntimeException('protected source cannot switch deployment intent');
        }
    }
}
