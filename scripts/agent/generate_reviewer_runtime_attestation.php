<?php

declare(strict_types=1);

/**
 * Generate the committed code-side reviewer boundary attestation.
 * This tool intentionally emits no policy values of its own.
 */
$repoRoot = dirname(__DIR__, 2);
$contractPath = $repoRoot . '/.codex/contracts/agent-workflow.json';
$runtimeContractPath = $repoRoot . '/scripts/agent/lib/ReadonlyReviewerContract.php';
$runtimeAttestationStart = '    // BEGIN GENERATED REVIEWER RUNTIME BOUNDARY ATTESTATION';
$runtimeAttestationEnd = '    // END GENERATED REVIEWER RUNTIME BOUNDARY ATTESTATION';

$arguments = array_slice($argv, 1);
if ($arguments !== [] && $arguments !== ['--check'] && $arguments !== ['--stdout']) {
    fwrite(STDERR, "Usage: generate_reviewer_runtime_attestation.php [--check|--stdout]\n");
    exit(2);
}
if (!is_file($contractPath) || is_link($contractPath) || realpath($contractPath) !== $contractPath) {
    fwrite(STDERR, "Reviewer policy authority is not a canonical regular file.\n");
    exit(1);
}
if (
    !is_file($runtimeContractPath) ||
    is_link($runtimeContractPath) ||
    realpath($runtimeContractPath) !== $runtimeContractPath
) {
    fwrite(STDERR, "Reviewer runtime boundary target is not a canonical regular file.\n");
    exit(1);
}

$contract = json_decode((string) file_get_contents($contractPath), true, 512, JSON_THROW_ON_ERROR);
$policy = $contract['authority']['reviewer'] ?? null;
if (!is_array($policy)) {
    fwrite(STDERR, "Reviewer authority is missing.\n");
    exit(1);
}

$attestedBoundary = $policy;
ksort($attestedBoundary, SORT_STRING);
$attestationKeys = array_keys($attestedBoundary);
$attestationDigest = hash('sha256', json_encode($attestedBoundary, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
$renderedAttestationKeys = implode(
    "\n",
    array_map(static fn(string $key): string => '        ' . var_export($key, true) . ',', $attestationKeys),
);
$runtimeAttestation = <<<PHP
{$runtimeAttestationStart}
    private const RUNTIME_BOUNDARY_ATTESTATION_KEYS = [
{$renderedAttestationKeys}
    ];

    private const RUNTIME_BOUNDARY_ATTESTATION_SHA256 = '{$attestationDigest}';
{$runtimeAttestationEnd}
PHP;

if ($arguments === ['--stdout']) {
    fwrite(STDOUT, $runtimeAttestation . "\n");
    exit(0);
}

$runtimeSource = (string) file_get_contents($runtimeContractPath);
$startOffset = strpos($runtimeSource, $runtimeAttestationStart);
$endOffset = strpos($runtimeSource, $runtimeAttestationEnd);
if (
    $startOffset === false ||
    $endOffset === false ||
    $endOffset < $startOffset ||
    strpos($runtimeSource, $runtimeAttestationStart, $startOffset + 1) !== false ||
    strpos($runtimeSource, $runtimeAttestationEnd, $endOffset + 1) !== false
) {
    fwrite(STDERR, "Reviewer runtime boundary attestation markers are invalid.\n");
    exit(1);
}
$runtimeAttestationLength = $endOffset + strlen($runtimeAttestationEnd) - $startOffset;
$actualRuntimeAttestation = substr($runtimeSource, $startOffset, $runtimeAttestationLength);

if ($arguments === ['--check']) {
    if (!hash_equals($runtimeAttestation, $actualRuntimeAttestation)) {
        fwrite(STDERR, "Generated reviewer runtime boundary attestation is stale.\n");
        exit(1);
    }
    exit(0);
}

$updatedRuntimeSource =
    substr($runtimeSource, 0, $startOffset) .
    $runtimeAttestation .
    substr($runtimeSource, $startOffset + $runtimeAttestationLength);
if (
    !hash_equals($runtimeSource, $updatedRuntimeSource) &&
    file_put_contents($runtimeContractPath, $updatedRuntimeSource, LOCK_EX) === false
) {
    fwrite(STDERR, "Unable to update reviewer runtime boundary attestation.\n");
    exit(1);
}
