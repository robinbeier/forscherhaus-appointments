<?php

declare(strict_types=1);

use Ops\DeploymentDumpAttestationProducerV1;
use Ops\DeploymentDumpAttestationBusyV1;

require_once __DIR__ . '/lib/DeploymentDumpAttestationProducerV1.php';

if ($argc !== 2 || !is_string($argv[1])) {
    fwrite(STDERR, "usage: verify_deployment_dump_v1.php YYYYMMDDTHHMMSSZ\n");
    exit(64);
}

try {
    $result = DeploymentDumpAttestationProducerV1::produce($argv[1]);
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
} catch (DeploymentDumpAttestationBusyV1) {
    fwrite(STDERR, "dump attestation busy\n");
    exit(75);
} catch (Throwable) {
    fwrite(STDERR, "dump attestation rejected\n");
    exit(70);
}
