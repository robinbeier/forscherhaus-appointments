<?php

declare(strict_types=1);

const CSP_ACTIVATION_VALIDATOR_SCHEMA = 'csp_report_only_activation.v2';
const CSP_ACTIVATION_VALIDATOR_CLASSES = [
    'preflight_ready',
    'activation_installed',
    'segment_inspected',
    'activation_removed',
    'activation_already_absent',
    'activation_directory_invalid',
    'activation_already_present',
    'activation_create_failed',
    'activation_install_failed',
    'activation_install_cleanup_unverified',
    'activation_identity_mismatch',
    'activation_remove_failed',
    'activation_remove_sync_failed',
    'candidate_invalid',
    'candidate_identity_changed',
    'release_binding_unavailable',
    'release_binding_mismatch',
    'production_lock_unavailable',
    'runtime_precondition_unavailable',
    'run_state_already_present',
    'run_state_directory_invalid',
    'run_state_create_failed',
    'run_state_write_failed',
    'run_state_missing',
    'run_state_mismatch',
    'run_state_identity_mismatch',
    'run_state_remove_failed',
    'run_state_remove_sync_failed',
];

$action = '';
$actionSeen = false;
$runId = null;
$expectedBinding = null;
$durationSeconds = null;
$expectedCandidateHash = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--action=')) {
        if ($actionSeen) {
            exit(2);
        }
        $actionSeen = true;
        $action = substr($argument, strlen('--action='));
        continue;
    }
    if (str_starts_with($argument, '--run-id=')) {
        if ($runId !== null) {
            exit(2);
        }
        $runId = substr($argument, strlen('--run-id='));
        continue;
    }
    if (str_starts_with($argument, '--expected-release-binding=')) {
        if ($expectedBinding !== null) {
            exit(2);
        }
        $expectedBinding = substr($argument, strlen('--expected-release-binding='));
        continue;
    }
    if (str_starts_with($argument, '--duration-seconds=')) {
        if ($durationSeconds !== null) {
            exit(2);
        }
        $rawDuration = substr($argument, strlen('--duration-seconds='));
        if ($rawDuration === '' || preg_match('/\A[0-9]+\z/', $rawDuration) !== 1) {
            exit(2);
        }
        $durationSeconds = (int) $rawDuration;
        continue;
    }
    if (str_starts_with($argument, '--expected-candidate-sha256=')) {
        if ($expectedCandidateHash !== null) {
            exit(2);
        }
        $expectedCandidateHash = substr($argument, strlen('--expected-candidate-sha256='));
        continue;
    }
    exit(2);
}
if (!in_array($action, ['preflight', 'install', 'install-segment', 'inspect-segment', 'remove'], true)) {
    exit(2);
}
if (
    in_array($action, ['install-segment', 'inspect-segment'], true) !== ($durationSeconds !== null) ||
    ($durationSeconds !== null && ($durationSeconds < 900 || $durationSeconds > 14400))
) {
    exit(2);
}
if (
    ($expectedCandidateHash !== null && $action !== 'remove') ||
    ($expectedCandidateHash !== null && preg_match('/\A[a-f0-9]{64}\z/', $expectedCandidateHash) !== 1)
) {
    exit(2);
}
$validBinding = is_string($expectedBinding) && preg_match('/\A[a-f0-9]{64}\z/', $expectedBinding) === 1;
$validRun = is_string($runId) && preg_match('/\A[a-f0-9]{32}\z/', $runId) === 1;
if (!$validBinding || ($action === 'preflight' ? $runId !== null : !$validRun)) {
    exit(2);
}
$candidateHash = @hash_file('sha256', __DIR__ . '/config/csp_report_only.production.v1.json');
if (!is_string($candidateHash) || preg_match('/\A[a-f0-9]{64}\z/', $candidateHash) !== 1) {
    exit(2);
}
$boundCandidateHash = $expectedCandidateHash ?? $candidateHash;
$candidateBytes = @file_get_contents(__DIR__ . '/config/csp_report_only.production.v1.json');
$input = stream_get_contents(STDIN, 4097);
if (!is_string($input) || $input === '' || strlen($input) > 4096) {
    exit(1);
}
try {
    $receipt = json_decode($input, true, 8, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    exit(1);
}
$keys = is_array($receipt) ? array_keys($receipt) : [];
sort($keys);
$resultClass = is_array($receipt) && is_string($receipt['result_class'] ?? null) ? $receipt['result_class'] : '';
$releaseValue = is_array($receipt) ? $receipt['release_binding'] ?? null : null;
$releaseValid = match ($resultClass) {
    'release_binding_unavailable' => $releaseValue === null,
    'release_binding_mismatch' => is_string($releaseValue) &&
        preg_match('/\A[a-f0-9]{64}\z/', $releaseValue) === 1 &&
        !hash_equals((string) $expectedBinding, $releaseValue),
    default => is_string($releaseValue) && hash_equals((string) $expectedBinding, $releaseValue),
};
$candidateValue = is_array($receipt) ? $receipt['candidate_sha256'] ?? null : null;
$candidateValid = match ($action) {
    'install-segment', 'inspect-segment' => ($receipt['status'] ?? null) === 'passed'
        ? is_string($candidateValue) && preg_match('/\A[a-f0-9]{64}\z/', $candidateValue) === 1
        : $candidateValue === null ||
            (is_string($candidateValue) && preg_match('/\A[a-f0-9]{64}\z/', $candidateValue) === 1),
    'remove' => match ($resultClass) {
        'production_lock_unavailable', 'run_state_missing' => $candidateValue === null ||
            (is_string($candidateValue) && hash_equals($boundCandidateHash, $candidateValue)),
        default => is_string($candidateValue) && hash_equals($boundCandidateHash, $candidateValue),
    },
    default => match ($resultClass) {
        'candidate_invalid' => $candidateValue === null,
        'release_binding_unavailable', 'production_lock_unavailable', 'run_state_missing' => $candidateValue === null ||
            (is_string($candidateValue) && hash_equals($boundCandidateHash, $candidateValue)),
        default => is_string($candidateValue) && hash_equals($boundCandidateHash, $candidateValue),
    },
};
$baseKeys = ['action', 'candidate_sha256', 'release_binding', 'result_class', 'run_id', 'schema', 'status'];
sort($baseKeys);
$hasSegmentWindow =
    in_array($action, ['install-segment', 'inspect-segment'], true) &&
    is_array($receipt) &&
    array_key_exists('starts_at_unix', $receipt) &&
    array_key_exists('expires_at_unix', $receipt);
$expectedKeys = $baseKeys;
if ($hasSegmentWindow) {
    $expectedKeys[] = 'expires_at_unix';
    $expectedKeys[] = 'starts_at_unix';
    sort($expectedKeys);
}
$segmentWindowValid =
    !in_array($action, ['install-segment', 'inspect-segment'], true) ||
    !$hasSegmentWindow ||
    (is_int($receipt['starts_at_unix'] ?? null) &&
        is_int($receipt['expires_at_unix'] ?? null) &&
        $receipt['expires_at_unix'] - $receipt['starts_at_unix'] === $durationSeconds &&
        $durationSeconds >= 900 &&
        $durationSeconds <= 14400);
if (
    in_array($action, ['install-segment', 'inspect-segment'], true) &&
    ($receipt['status'] ?? null) === 'passed' &&
    !$hasSegmentWindow
) {
    exit(1);
}
$segmentHashValid = true;
if (
    in_array($action, ['install-segment', 'inspect-segment'], true) &&
    $hasSegmentWindow &&
    $receipt['status'] === 'passed'
) {
    $segmentHashValid = false;
    if (is_string($candidateBytes) && $candidateBytes !== '' && $segmentWindowValid) {
        try {
            $decodedCandidate = json_decode($candidateBytes, true, 8, JSON_THROW_ON_ERROR);
            if (is_array($decodedCandidate) && ($decodedCandidate['schema'] ?? null) === 'csp_report_only_config.v1') {
                $decodedCandidate['schema'] = 'csp_report_only_config.v2';
                $decodedCandidate['starts_at_unix'] = $receipt['starts_at_unix'];
                $decodedCandidate['expires_at_unix'] = $receipt['expires_at_unix'];
                $expectedSegmentHash = hash(
                    'sha256',
                    json_encode($decodedCandidate, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n",
                );
                $segmentHashValid = is_string($candidateValue) && hash_equals($expectedSegmentHash, $candidateValue);
            }
        } catch (Throwable) {
            $segmentHashValid = false;
        }
    }
}
if (
    !is_array($receipt) ||
    $keys !== $expectedKeys ||
    ($receipt['schema'] ?? null) !== CSP_ACTIVATION_VALIDATOR_SCHEMA ||
    ($receipt['action'] ?? null) !== $action ||
    !in_array($receipt['status'] ?? null, ['passed', 'failed'], true) ||
    !in_array($receipt['result_class'] ?? null, CSP_ACTIVATION_VALIDATOR_CLASSES, true) ||
    !$releaseValid ||
    ($action === 'preflight'
        ? $receipt['run_id'] !== null
        : !is_string($receipt['run_id'] ?? null) || !hash_equals((string) $runId, $receipt['run_id'])) ||
    !$candidateValid ||
    !$segmentWindowValid ||
    !$segmentHashValid
) {
    exit(1);
}
$passedClass = match ($action) {
    'preflight' => $receipt['result_class'] === 'preflight_ready',
    'install', 'install-segment' => $receipt['result_class'] === 'activation_installed',
    'inspect-segment' => $receipt['result_class'] === 'segment_inspected',
    default => in_array($receipt['result_class'], ['activation_removed', 'activation_already_absent'], true),
};
if (($receipt['status'] === 'passed') !== $passedClass) {
    exit(1);
}

echo json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($receipt['status'] === 'passed' ? 0 : 3);
