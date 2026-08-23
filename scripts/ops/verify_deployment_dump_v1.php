<?php

declare(strict_types=1);

use Ops\DeploymentDumpAttestationProducerV1;
use Ops\DeploymentDumpAttestationBusyV1;
use Ops\DeploymentDumpAttestationExpiredV1;

require_once __DIR__ . '/lib/DeploymentDumpAttestationProducerV1.php';

if ($argc !== 2 || !is_string($argv[1])) {
    fwrite(STDERR, "usage: verify_deployment_dump_v1.php YYYYMMDDTHHMMSSZ|--latest-handoff|--continuity-state\n");
    exit(64);
}

try {
    $latestHandoff = $argv[1] === '--latest-handoff';
    $continuityState = $argv[1] === '--continuity-state';
    $result = match (true) {
        $latestHandoff => DeploymentDumpAttestationProducerV1::produceLatestHandoff(),
        $continuityState => DeploymentDumpAttestationProducerV1::produceContinuityState(),
        default => DeploymentDumpAttestationProducerV1::produce($argv[1]),
    };
    if ($latestHandoff || $continuityState) {
        $result = [
            'schema' => 'deployment_dump_handoff_attestation_result.v1',
            'status' => $result['status'],
        ];
    }
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
} catch (DeploymentDumpAttestationBusyV1) {
    fwrite(STDERR, "dump attestation busy\n");
    exit(75);
} catch (DeploymentDumpAttestationExpiredV1) {
    fwrite(STDERR, "dump attestation continuity state is stale\n");
    exit(76);
} catch (Throwable) {
    fwrite(STDERR, "dump attestation rejected\n");
    exit(70);
}
