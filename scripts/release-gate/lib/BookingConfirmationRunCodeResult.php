<?php

declare(strict_types=1);

namespace ReleaseGate;

require_once __DIR__ . '/GateAssertions.php';

/**
 * @param array{stdout?: mixed} $runCodeResult
 * @return array<string, mixed>
 */
function parseBookingConfirmationRunCodeResult(array $runCodeResult): array
{
    $output = (string) ($runCodeResult['stdout'] ?? '');
    $errorText = extractPlaywrightErrorSection($output);
    if ($errorText !== null) {
        throw new GateAssertionException($errorText);
    }

    return parseBookingConfirmationJsonPayload($output);
}

function extractPlaywrightErrorSection(string $output): ?string
{
    if (trim($output) === '') {
        return null;
    }

    $matches = [];
    if (preg_match('/(?:^|\R)### Error\s*\R(.+?)(?:\R###\s+[^\r\n]+|\z)/s', $output, $matches) !== 1) {
        return null;
    }

    $message = trim((string) ($matches[1] ?? ''));

    return $message !== '' ? $message : 'Unknown Playwright error.';
}

/**
 * @return array<string, mixed>
 */
function parseBookingConfirmationJsonPayload(string $output): array
{
    $legacyMatches = [];
    $legacyPayload = null;
    if (preg_match('/(?:^|\R)### Result\s*\R(.+?)(?:\R###\s+[^\r\n]+|\z)/s', $output, $legacyMatches) === 1) {
        $legacyPayload = trim((string) ($legacyMatches[1] ?? ''));
    }

    $attempts = collectBookingConfirmationPayloadCandidates($output, $legacyPayload);

    if ($attempts === []) {
        throw new GateAssertionException(
            sprintf(
                'Could not parse booking confirmation run-code payload from Playwright output: %s',
                substr(trim($output), 0, 500),
            ),
        );
    }

    foreach ($attempts as $attempt) {
        $decoded = decodeBookingConfirmationPayloadAttempt((string) $attempt);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    throw new GateAssertionException('Playwright run-code booking confirmation run-code payload is not valid JSON.');
}

/**
 * @return list<string>
 */
function collectBookingConfirmationPayloadCandidates(string $output, ?string $legacyPayload): array
{
    $attempts = collectBookingConfirmationSentinelCandidates($output);

    if ($legacyPayload !== null && $legacyPayload !== '') {
        $attempts[] = $legacyPayload;

        foreach (collectBookingConfirmationSentinelCandidates($legacyPayload) as $candidate) {
            $attempts[] = $candidate;
        }
    }

    return array_values(array_unique(array_filter($attempts, static fn($attempt): bool => $attempt !== '')));
}

/**
 * @return list<string>
 */
function collectBookingConfirmationSentinelCandidates(string $output): array
{
    $prefix = '__BOOKING_CONFIRMATION_PDF_GATE__';
    $attempts = [];
    $prefixPosition = strpos($output, $prefix);
    if ($prefixPosition !== false) {
        $attempts[] = trim(substr($output, $prefixPosition + strlen($prefix)));
    }

    foreach (preg_split('/\R/', $output) ?: [] as $line) {
        $linePrefixPosition = strpos((string) $line, $prefix);
        if ($linePrefixPosition === false) {
            continue;
        }

        $attempts[] = trim(substr((string) $line, $linePrefixPosition + strlen($prefix)));
    }

    return array_values(array_unique(array_filter($attempts, static fn($attempt): bool => $attempt !== '')));
}

/**
 * @return array<string, mixed>|null
 */
function decodeBookingConfirmationPayloadAttempt(string $attempt): ?array
{
    $trimmedAttempt = trim($attempt);
    $variants = array_values(
        array_unique(
            array_filter([
                $trimmedAttempt,
                trim($trimmedAttempt, "\"'"),
                preg_replace('/"\s*$/', '', $trimmedAttempt) ?: '',
                stripcslashes($trimmedAttempt),
                stripcslashes(trim($trimmedAttempt, "\"'")),
                stripcslashes((string) (preg_replace('/"\s*$/', '', $trimmedAttempt) ?: '')),
            ]),
        ),
    );

    foreach ($variants as $variant) {
        $decoded = json_decode((string) $variant, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return null;
}
