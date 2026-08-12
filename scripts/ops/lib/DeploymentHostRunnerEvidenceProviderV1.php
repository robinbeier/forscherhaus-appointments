<?php

declare(strict_types=1);

namespace Ops;

use RuntimeException;

require_once __DIR__ . '/DeploymentHostRunnerContractV1.php';
require_once __DIR__ . '/ProtectedPredeployObservationProvider.php';

/**
 * Privileged measurement boundary implemented by the fixed helper transport.
 * It exposes raw protected observations, never evidence statuses or verdicts.
 */
interface HostRunnerProtectedObservationSource
{
    public function buildProvenance(string $runId, string $releaseId, string $authorizedSha256): ExpectedCommitObservationV1;

    public function traffic(
        string $runId,
        string $intentSha256,
        string $mode,
    ): TrafficObservationV1;

    /** @param array<string,mixed> $dumpReference */
    public function dump(string $runId, string $intentSha256, array $dumpReference): DumpObservationV1;

    /** @param array<string,mixed> $input */
    public function capacity(
        string $runId,
        string $intentSha256,
        array $input,
        ExpectedCommitObservationV1 $provenance,
        DumpObservationV1 $dump,
    ): CapacityObservationV1;

    public function artifact(
        string $runId,
        string $intentSha256,
        ExpectedCommitObservationV1 $provenance,
        CapacityObservationV1 $capacity,
    ): ArtifactObservationV1;
}

/**
 * Sole production-shaped implementation of the frozen provider interface.
 * All coordinator-facing request values are independently supplied to the
 * authority verifier by HostRunnerPredeployOrchestrator.
 */
final class ProtectedHostPredeployObservationProvider implements ProtectedPredeployObservationProvider
{
    private ?ExpectedCommitObservationV1 $provenance = null;
    private ?DumpObservationV1 $dumpObservation = null;
    private ?CapacityObservationV1 $capacityObservation = null;
    private int $nextGate = 0;

    /** @param array<string,mixed> $request @param array<string,mixed> $input */
    public function __construct(
        private readonly HostRunnerProtectedObservationSource $source,
        private readonly array $request,
        private readonly array $input,
    ) {
        DeploymentHostRunnerContractV1::validateDeployExecutionBundle($request, $input);
    }

    public function expectedCommit(): ExpectedCommitObservationV1
    {
        $this->expectGate(0, 'expected_commit');
        $value = $this->source->buildProvenance(
            $this->request['run_id'],
            $this->request['release_id'],
            $this->input['parameters']['artifact_provenance_sha256'],
        );
        if (!hash_equals($value->pinnedProvenanceSha256, $this->input['parameters']['artifact_provenance_sha256'])) {
            throw new RuntimeException('protected build provenance does not match execution authority');
        }
        $this->provenance = $value;
        return $value;
    }

    public function traffic(): TrafficObservationV1
    {
        $this->expectGate(1, 'traffic_gate');
        return $this->source->traffic(
            $this->request['run_id'],
            $this->request['intent_sha256'],
            $this->request['traffic_mode'],
        );
    }

    public function dump(): DumpObservationV1
    {
        $this->expectGate(2, 'dump');
        $value = $this->source->dump(
            $this->request['run_id'],
            $this->request['intent_sha256'],
            $this->input['parameters']['zero_surprise_dump'],
        );
        if (
            $value->dumpSha256 !== null &&
            !hash_equals($value->dumpSha256, $this->input['parameters']['zero_surprise_dump']['sha256'])
        ) {
            throw new RuntimeException('protected dump observation contradicts execution authority');
        }
        $this->dumpObservation = $value;
        return $value;
    }

    public function capacity(): CapacityObservationV1
    {
        $this->expectGate(3, 'capacity');
        if ($this->provenance === null || $this->dumpObservation === null) {
            throw new RuntimeException('capacity observation lacks its prior protected authorities');
        }
        $value = $this->source->capacity(
            $this->request['run_id'],
            $this->request['intent_sha256'],
            $this->input,
            $this->provenance,
            $this->dumpObservation,
        );
        if ($value->verifiedSources !== null) {
            $sources = $value->verifiedSources;
            if (
                !hash_equals($sources->build->authorizedProvenanceSha256, $this->provenance->pinnedProvenanceSha256) ||
                !hash_equals($sources->build->provenanceBytes, $this->provenance->provenanceBytes) ||
                $sources->attestationBytes !== $this->dumpObservation->attestationBytes ||
                $sources->attestationSha256 !== $this->dumpObservation->pinnedAttestationSha256 ||
                $sources->dumpSha256 !== $this->dumpObservation->dumpSha256 ||
                $sources->dumpSizeBytes !== $this->dumpObservation->stableDumpSizeBytes
            ) {
                throw new RuntimeException('capacity observation substitutes a prior protected authority');
            }
        }
        $this->capacityObservation = $value;
        return $value;
    }

    public function artifact(): ArtifactObservationV1
    {
        $this->expectGate(4, 'artifact');
        if ($this->provenance === null || $this->capacityObservation === null) {
            throw new RuntimeException('artifact observation lacks its prior protected authorities');
        }
        $value = $this->source->artifact(
            $this->request['run_id'],
            $this->request['intent_sha256'],
            $this->provenance,
            $this->capacityObservation,
        );
        if (
            $value->verifiedSources !== null &&
            (!hash_equals($value->verifiedSources->provenanceBytes, $this->provenance->provenanceBytes) ||
                !hash_equals(
                    $value->verifiedSources->authorizedProvenanceSha256,
                    $this->provenance->pinnedProvenanceSha256,
                ))
        ) {
            throw new RuntimeException('artifact observation substitutes protected provenance');
        }
        return $value;
    }

    private function expectGate(int $expected, string $gate): void
    {
        if ($this->nextGate !== $expected) {
            throw new RuntimeException('protected provider gate order is invalid at ' . $gate);
        }
        $this->nextGate++;
    }
}
