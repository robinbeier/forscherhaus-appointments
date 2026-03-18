<?php

declare(strict_types=1);

namespace ReleaseGate;

require_once __DIR__ . '/GateAssertions.php';

/**
 * @return array<string, mixed>
 */
function parsePlaywrightRunCodeJsonPayload(string $output, string $prefix, string $contextLabel): array
{
    if ($output === '') {
        throw new GateAssertionException('Playwright run-code produced no output.');
    }

    $attempts = [];
    $prefixPosition = strpos($output, $prefix);
    if ($prefixPosition !== false) {
        $rawTail = trim(substr($output, $prefixPosition + strlen($prefix)));
        $lineCandidates = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $linePrefixPosition = strpos((string) $line, $prefix);
            if ($linePrefixPosition === false) {
                continue;
            }

            $lineCandidates[] = trim(substr((string) $line, $linePrefixPosition + strlen($prefix)));
        }

        $attempts = [
            ...$attempts,
            ...$lineCandidates,
            $rawTail,
            trim($rawTail, "\"'"),
            preg_replace('/"\s*$/', '', $rawTail) ?: '',
            stripcslashes($rawTail),
            stripcslashes(trim($rawTail, "\"'")),
            stripcslashes((string) (preg_replace('/"\s*$/', '', $rawTail) ?: '')),
        ];
    }

    $legacyMatches = [];
    if (preg_match('/(?:^|\R)### Result\s*\R(.+?)(?:\R###\s+[^\r\n]+|\z)/s', $output, $legacyMatches) === 1) {
        $legacyPayload = trim((string) ($legacyMatches[1] ?? ''));
        $attempts = [
            ...$attempts,
            $legacyPayload,
            trim($legacyPayload, "\"'"),
            stripcslashes($legacyPayload),
            stripcslashes(trim($legacyPayload, "\"'")),
        ];
    }

    $attempts = array_values(array_unique(array_filter($attempts)));

    if ($attempts === []) {
        throw new GateAssertionException(
            sprintf(
                'Could not parse %s payload from Playwright output: %s',
                $contextLabel,
                substr(trim($output), 0, 500),
            ),
        );
    }

    foreach ($attempts as $attempt) {
        $normalizedAttempt = trim((string) $attempt);
        $directDecoded = json_decode($normalizedAttempt, true);
        if (is_array($directDecoded)) {
            return $directDecoded;
        }

        $matches = [];
        if (preg_match('/\{.*\}/s', $normalizedAttempt, $matches) === 1) {
            $decoded = json_decode((string) $matches[0], true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }

    throw new GateAssertionException(sprintf('Playwright run-code %s payload is not valid JSON.', $contextLabel));
}
