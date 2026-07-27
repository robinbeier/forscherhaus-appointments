<?php

declare(strict_types=1);

namespace ReleaseGate;

require_once __DIR__ . '/GateAssertions.php';

const PROVIDER_UI_SMOKE_RESULT_PREFIX = '__PROVIDER_UI_SMOKE_GATE__';

/**
 * @param array<string, mixed> $processResult
 * @return array<string, bool|int>
 */
function parseProviderUiSmokeRunCodeResult(array $processResult): array
{
    $output = (string) ($processResult['stdout'] ?? '');

    if ($output === '') {
        throw new GateAssertionException('Provider UI smoke browser flow produced no structured result.');
    }

    $decoded = null;

    foreach (preg_split('/\R/', $output) ?: [] as $line) {
        $prefixPosition = strpos((string) $line, PROVIDER_UI_SMOKE_RESULT_PREFIX);

        if ($prefixPosition === false) {
            continue;
        }

        $candidate = trim(substr((string) $line, $prefixPosition + strlen(PROVIDER_UI_SMOKE_RESULT_PREFIX)));
        $attempts = [
            $candidate,
            trim($candidate, "\"'"),
            stripcslashes($candidate),
            stripcslashes(trim($candidate, "\"'")),
        ];

        foreach ($attempts as $attempt) {
            $matches = [];
            if (preg_match('/\{.*\}/s', (string) $attempt, $matches) !== 1) {
                continue;
            }

            $payload = json_decode((string) $matches[0], true);
            if (is_array($payload)) {
                $decoded = $payload;
                break 2;
            }
        }
    }

    if (!is_array($decoded)) {
        throw new GateAssertionException('Provider UI smoke browser flow did not emit a valid structured result.');
    }

    $booleanFields = [
        'ok',
        'network_policy_installed',
        'dashboard_loaded',
        'buttons_present',
        'script_vars_safe',
        'primary_metrics_status_ok',
        'primary_row_matches',
        'preparation_downloaded',
        'parent_downloaded',
        'empty_metrics_status_ok',
        'empty_state_visible',
        'empty_preparation_downloaded',
        'restore_metrics_status_ok',
    ];
    $integerFields = [
        'primary_row_count',
        'empty_row_count',
        'blocked_request_count',
        'page_error_count',
        'console_error_count',
        'flow_error_count',
    ];
    $expectedFields = [...$booleanFields, ...$integerFields];
    $actualFields = array_keys($decoded);
    sort($expectedFields);
    sort($actualFields);

    if ($actualFields !== $expectedFields) {
        throw new GateAssertionException('Provider UI smoke browser result has an unexpected field set.');
    }

    foreach ($booleanFields as $field) {
        if (!is_bool($decoded[$field] ?? null)) {
            throw new GateAssertionException('Provider UI smoke browser result contains a non-boolean field.');
        }
    }

    foreach ($integerFields as $field) {
        if (!is_int($decoded[$field] ?? null) || (int) $decoded[$field] < 0) {
            throw new GateAssertionException('Provider UI smoke browser result contains an invalid count.');
        }
    }

    /** @var array<string, bool|int> $decoded */
    return $decoded;
}
