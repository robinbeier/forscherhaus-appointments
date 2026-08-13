<?php

declare(strict_types=1);

use Ops\DeploymentContractV1;

$installedContract = __DIR__ . '/DeploymentContractV1.php';
$repositoryContract = __DIR__ . '/../lib/DeploymentContractV1.php';
require_once is_file($installedContract) ? $installedContract : $repositoryContract;

try {
    $input = stream_get_contents(STDIN, 1_500_001);
    if (!is_string($input) || $input === '' || strlen($input) > 1_500_000) {
        throw new RuntimeException('terminal bundle envelope is invalid');
    }
    $envelope = json_decode($input, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($envelope) || array_is_list($envelope) || array_keys($envelope) !== ['events', 'evidence']) {
        throw new RuntimeException('terminal bundle envelope is invalid');
    }
    $events = is_string($envelope['events']) ? base64_decode($envelope['events'], true) : false;
    $evidenceBytes = is_string($envelope['evidence']) ? base64_decode($envelope['evidence'], true) : false;
    if (!is_string($events) || !is_string($evidenceBytes) || $events === '' || $evidenceBytes === '') {
        throw new RuntimeException('terminal bundle bytes are invalid');
    }
    if (!str_ends_with($events, "\n") || str_ends_with($events, "\n\n")) {
        throw new RuntimeException('terminal journal encoding is invalid');
    }
    $lines = explode("\n", substr($events, 0, -1));
    $evidence = json_decode($evidenceBytes, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($evidence) || array_is_list($evidence)) {
        throw new RuntimeException('terminal evidence is invalid');
    }
    $canonical = DeploymentContractV1::canonicalJson($evidence) . "\n";
    if (!hash_equals($canonical, $evidenceBytes)) {
        throw new RuntimeException('terminal evidence is not canonical');
    }
    $result = DeploymentContractV1::validateBundle($lines, $evidence);
    fwrite(
        STDOUT,
        json_encode(
            [
                'intent_sha256' => $result['intent_sha256'],
                'records' => $result['records'],
                'run_id' => $result['run_id'],
                'schema' => 'deployment_terminal_bundle_validation.v1',
                'state' => $result['state'],
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ) . "\n",
    );
    exit(0);
} catch (Throwable) {
    fwrite(STDERR, "terminal bundle rejected\n");
    exit(70);
}
