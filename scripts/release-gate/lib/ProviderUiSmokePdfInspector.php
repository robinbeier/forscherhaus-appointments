<?php

declare(strict_types=1);

namespace ReleaseGate;

use RuntimeException;

require_once __DIR__ . '/GateAssertions.php';
require_once __DIR__ . '/GateProcessRunner.php';

/**
 * @return array{pages:int,landscape:bool}
 */
function parseProviderUiSmokePdfInfo(string $pdfInfo): array
{
    $pagesMatch = [];
    $pageSizeMatch = [];

    if (preg_match('/^Pages:\s+(\d+)\s*$/mi', $pdfInfo, $pagesMatch) !== 1) {
        throw new GateAssertionException('Provider UI smoke PDF metadata is missing a page count.');
    }

    if (
        preg_match(
            '/^Page size:\s+([0-9]+(?:\.[0-9]+)?)\s+x\s+([0-9]+(?:\.[0-9]+)?)\s+pts\b/mi',
            $pdfInfo,
            $pageSizeMatch,
        ) !== 1
    ) {
        throw new GateAssertionException('Provider UI smoke PDF metadata is missing a page size.');
    }

    $pages = (int) ($pagesMatch[1] ?? 0);
    $width = (float) ($pageSizeMatch[1] ?? 0);
    $height = (float) ($pageSizeMatch[2] ?? 0);

    if ($pages <= 0 || $width <= 0 || $height <= 0) {
        throw new GateAssertionException('Provider UI smoke PDF metadata contains invalid dimensions.');
    }

    return [
        'pages' => $pages,
        'landscape' => $width > $height,
    ];
}

/**
 * @param list<string> $requiredFragments
 * @param list<string> $forbiddenFragments
 */
function assertProviderUiSmokePdfText(
    string $text,
    array $requiredFragments,
    array $forbiddenFragments,
    string $context,
): void {
    $normalizedText = normalizeProviderUiSmokePdfText($text);

    foreach ($requiredFragments as $fragment) {
        $normalizedFragment = normalizeProviderUiSmokePdfText($fragment);

        if ($normalizedFragment === '' || !str_contains($normalizedText, $normalizedFragment)) {
            throw new GateAssertionException($context . ' is missing required synthetic fixture content.');
        }
    }

    foreach ($forbiddenFragments as $fragment) {
        $normalizedFragment = normalizeProviderUiSmokePdfText($fragment);

        if ($normalizedFragment !== '' && str_contains($normalizedText, $normalizedFragment)) {
            throw new GateAssertionException($context . ' exposes forbidden synthetic fixture content.');
        }
    }
}

/**
 * Require at least one member of every provided alternatives group.
 *
 * @param list<list<string>> $alternativeGroups
 */
function assertProviderUiSmokePdfTextAlternatives(string $text, array $alternativeGroups, string $context): void
{
    $normalizedText = normalizeProviderUiSmokePdfText($text);

    foreach ($alternativeGroups as $alternatives) {
        $matched = false;

        foreach ($alternatives as $alternative) {
            $normalizedAlternative = normalizeProviderUiSmokePdfText($alternative);
            if ($normalizedAlternative !== '' && str_contains($normalizedText, $normalizedAlternative)) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            throw new GateAssertionException($context . ' is missing required localized fixture content.');
        }
    }
}

function normalizeProviderUiSmokePdfText(string $text): string
{
    $normalized = preg_replace('/\s+/u', ' ', $text);

    return strtolower(trim(is_string($normalized) ? $normalized : $text));
}

function countProviderUiSmokePdfFragment(string $text, string $fragment): int
{
    $normalizedText = normalizeProviderUiSmokePdfText($text);
    $normalizedFragment = normalizeProviderUiSmokePdfText($fragment);

    return $normalizedFragment === '' ? 0 : substr_count($normalizedText, $normalizedFragment);
}

function countProviderUiSmokeAppointmentRows(string $text): int
{
    $rows = 0;
    $timePattern = '/\b(?:[01]?\d|2[0-3]):[0-5]\d(?:\s*(?:am|pm|uhr))?\b/iu';

    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
        $matches = [];
        $count = preg_match_all($timePattern, (string) $line, $matches);

        if (is_int($count) && $count >= 2) {
            $rows++;
        }
    }

    return $rows;
}

function normalizeProviderUiSmokeSha256(string $digest): string
{
    $normalized = strtolower(trim($digest));

    if (preg_match('/\A[a-f0-9]{64}\z/D', $normalized) !== 1) {
        throw new \InvalidArgumentException('Provider UI smoke deployed-view SHA-256 is invalid.');
    }

    return $normalized;
}

/**
 * Bind the locally reviewed four-line template to the exact bytes in the active
 * deployment. The operator brackets the browser run with remote SHA-256 reads,
 * so this function never reads or executes production source on the workstation.
 *
 * @return array{active_deployment_matched:bool,note_line_count:int}
 */
function assertProviderUiSmokeDeployedPreparationView(string $reviewedViewPath, string $deployedSha256): array
{
    $expectedSha256 = normalizeProviderUiSmokeSha256($deployedSha256);

    if (!is_file($reviewedViewPath) || is_link($reviewedViewPath) || !is_readable($reviewedViewPath)) {
        throw new RuntimeException('Provider preparation PDF reviewed checkout view is unavailable or unsafe.');
    }

    $view = file_get_contents($reviewedViewPath);
    if (!is_string($view)) {
        throw new RuntimeException('Provider preparation PDF reviewed checkout view is not readable.');
    }

    if (!hash_equals($expectedSha256, hash('sha256', $view))) {
        throw new GateAssertionException(
            'Provider preparation PDF reviewed checkout does not match the active deployment.',
        );
    }

    $noteLineCount = substr_count($view, 'class="notes__line"');
    if ($noteLineCount !== 4 || !str_contains($view, 'grid-template-rows:repeat(4,1fr)')) {
        throw new GateAssertionException('Provider preparation PDF four-line source regression is missing.');
    }

    return [
        'active_deployment_matched' => true,
        'note_line_count' => $noteLineCount,
    ];
}

/**
 * @return array{bytes:int,pages:int,landscape:bool,text:string}
 */
function inspectProviderUiSmokePdf(string $pdfPath, string $textPath, int $minimumBytes, int $timeoutSeconds): array
{
    if (!is_file($pdfPath) || !is_readable($pdfPath)) {
        throw new GateAssertionException('Expected provider UI smoke PDF was not downloaded.');
    }

    $bytes = filesize($pdfPath);
    if (!is_int($bytes) || $bytes < $minimumBytes) {
        throw new GateAssertionException('Provider UI smoke PDF is smaller than the configured minimum.');
    }

    $handle = fopen($pdfPath, 'rb');
    $magic = is_resource($handle) ? fread($handle, 5) : false;
    if (is_resource($handle)) {
        fclose($handle);
    }

    if ($magic !== '%PDF-') {
        throw new GateAssertionException('Provider UI smoke PDF does not have a PDF signature.');
    }

    $pdfInfo = GateProcessRunner::run(['pdfinfo', $pdfPath], null, null, $timeoutSeconds);
    assertProviderUiSmokeToolSucceeded($pdfInfo, 'pdfinfo');
    $metadata = parseProviderUiSmokePdfInfo((string) ($pdfInfo['stdout'] ?? ''));

    $pdfToText = GateProcessRunner::run(['pdftotext', '-layout', $pdfPath, $textPath], null, null, $timeoutSeconds);
    assertProviderUiSmokeToolSucceeded($pdfToText, 'pdftotext');

    if (!is_file($textPath) || !chmod($textPath, 0600) || (((int) fileperms($textPath)) & 0777) !== 0600) {
        throw new RuntimeException('Provider UI smoke PDF text permissions could not be secured.');
    }

    $text = file_get_contents($textPath);
    if (!is_string($text)) {
        throw new GateAssertionException('Provider UI smoke PDF text could not be inspected.');
    }

    return [
        'bytes' => $bytes,
        'pages' => $metadata['pages'],
        'landscape' => $metadata['landscape'],
        'text' => $text,
    ];
}

/**
 * @param array<string, mixed> $result
 */
function assertProviderUiSmokeToolSucceeded(array $result, string $tool): void
{
    if ((int) ($result['exit_code'] ?? 1) !== 0 || (bool) ($result['timed_out'] ?? false)) {
        throw new RuntimeException('Provider UI smoke ' . $tool . ' inspection failed.');
    }
}
