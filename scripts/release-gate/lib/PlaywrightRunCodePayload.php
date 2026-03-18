<?php

declare(strict_types=1);

namespace ReleaseGate;

/**
 * @return array<string, mixed>
 */
function parsePlaywrightRunCodeJsonPayload(string $output, string $prefix, string $contextLabel): array
{
    if ($output === '') {
        throw new GateAssertionException('Playwright run-code produced no output.');
    }

    $prefixPosition = strpos($output, $prefix);
    if ($prefixPosition === false) {
        throw new GateAssertionException(
            sprintf(
                'Could not parse %s payload from Playwright output: %s',
                $contextLabel,
                substr(trim($output), 0, 500),
            ),
        );
    }

    $rawTail = trim(substr($output, $prefixPosition + strlen($prefix)));
    $lineCandidates = [];

    foreach (preg_split('/\R/', $output) ?: [] as $line) {
        $linePrefixPosition = strpos((string) $line, $prefix);
        if ($linePrefixPosition === false) {
            continue;
        }

        $lineCandidates[] = trim(substr((string) $line, $linePrefixPosition + strlen($prefix)));
    }

    $attempts = array_values(
        array_unique(
            array_filter([
                ...$lineCandidates,
                $rawTail,
                trim($rawTail, "\"'"),
                preg_replace('/"\s*$/', '', $rawTail) ?: '',
                stripcslashes($rawTail),
                stripcslashes(trim($rawTail, "\"'")),
                stripcslashes((string) (preg_replace('/"\s*$/', '', $rawTail) ?: '')),
            ]),
        ),
    );

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
