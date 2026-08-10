<?php

declare(strict_types=1);

namespace Ops;

use JsonException;
use RuntimeException;

require_once __DIR__ . '/lib/DeploymentContractV1.php';

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

$runPath = null;
$evidencePath = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--run-jsonl=')) {
        $runPath = substr($argument, strlen('--run-jsonl='));
        continue;
    }
    if (str_starts_with($argument, '--evidence-json=')) {
        $evidencePath = substr($argument, strlen('--evidence-json='));
        continue;
    }
    fwrite(
        STDERR,
        "Usage: php scripts/ops/validate_deployment_contract_v1.php --run-jsonl=<path> --evidence-json=<path>\n",
    );
    exit(64);
}

if (!is_string($runPath) || $runPath === '' || !is_string($evidencePath) || $evidencePath === '') {
    fwrite(
        STDERR,
        "Usage: php scripts/ops/validate_deployment_contract_v1.php --run-jsonl=<path> --evidence-json=<path>\n",
    );
    exit(64);
}

try {
    $runBytes = file_get_contents($runPath);
    $evidenceBytes = file_get_contents($evidencePath);
    if (!is_string($runBytes) || $runBytes === '' || !is_string($evidenceBytes) || $evidenceBytes === '') {
        throw new RuntimeException('contract input is empty or unreadable');
    }
    $lines = preg_split('/\r\n|\n|\r/', $runBytes);
    if (!is_array($lines)) {
        throw new RuntimeException('deployment run cannot be split into records');
    }
    if ($lines[array_key_last($lines)] === '') {
        array_pop($lines);
    }
    if ($lines === [] || in_array('', $lines, true)) {
        throw new RuntimeException('deployment run contains an empty record');
    }
    $evidence = json_decode($evidenceBytes, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($evidence) || array_is_list($evidence)) {
        throw new RuntimeException('deployment evidence must be an object');
    }
    $canonicalEvidenceBytes = DeploymentContractV1::canonicalJson($evidence);
    if ($evidenceBytes !== $canonicalEvidenceBytes && $evidenceBytes !== $canonicalEvidenceBytes . PHP_EOL) {
        throw new RuntimeException('deployment evidence is not canonical JSON');
    }
    $result = DeploymentContractV1::validateBundle($lines, $evidence);
    fwrite(
        STDOUT,
        json_encode(
            ['schema' => 'deployment_contract_validation.v1', 'valid' => true, ...$result],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ) . PHP_EOL,
    );
    exit(0);
} catch (RuntimeException | JsonException $exception) {
    fwrite(STDERR, 'INVALID: ' . $exception->getMessage() . PHP_EOL);
    exit(70);
}
