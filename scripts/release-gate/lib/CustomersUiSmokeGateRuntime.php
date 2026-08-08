<?php

declare(strict_types=1);

namespace ReleaseGate;

use RuntimeException;
use Throwable;

require_once __DIR__ . '/CustomersUiSmokeContract.php';
require_once __DIR__ . '/GateAssertions.php';

final class CustomersUiSmokeBrowserFlowException extends RuntimeException
{
    /**
     * @param array<string, bool|int> $safeDetails
     */
    public function __construct(public readonly array $safeDetails)
    {
        $validatedDetails = customersUiSmokeValidateBrowserResult($safeDetails);

        if ($validatedDetails['ok'] !== false) {
            throw new GateAssertionException('Customers UI smoke browser failure result is unexpectedly successful.');
        }

        parent::__construct('Customers UI smoke browser assertions failed.');
    }
}

/**
 * @param array<string, mixed> $result
 * @return array<string, bool|int>
 */
function customersUiSmokeValidateBrowserResult(array $result): array
{
    $booleanFields = [
        'ok',
        'network_policy_installed',
        'page_loaded',
        'initial_search_empty',
        'synthetic_search_empty',
        'empty_state_visible',
        'script_vars_safe',
        'dom_safe',
        'response_bodies_safe',
    ];
    $integerFields = [
        'search_response_count',
        'blocked_request_count',
        'page_error_count',
        'console_error_count',
        'flow_error_count',
    ];
    $expectedFields = [...$booleanFields, ...$integerFields];
    $actualFields = array_keys($result);
    sort($expectedFields);
    sort($actualFields);

    if ($actualFields !== $expectedFields) {
        throw new GateAssertionException('Customers UI smoke browser result has an unexpected field set.');
    }

    foreach ($booleanFields as $field) {
        if (!is_bool($result[$field] ?? null)) {
            throw new GateAssertionException('Customers UI smoke browser result contains a non-boolean field.');
        }
    }

    foreach ($integerFields as $field) {
        if (!is_int($result[$field] ?? null) || (int) $result[$field] < 0) {
            throw new GateAssertionException('Customers UI smoke browser result contains an invalid count.');
        }
    }

    /** @var array<string, bool|int> $result */
    return $result;
}

/**
 * @param array<int, array<string, mixed>> $cookies
 * @param callable(string): array<string, bool|int|float> $callback
 * @param null|callable(string, int): bool $chmodFile
 * @return array<string, bool|int|float>
 */
function customersUiSmokeWithStorageState(
    string $tempDirectory,
    string $role,
    array $cookies,
    callable $callback,
    ?callable $chmodFile = null,
): array {
    $statePath = $tempDirectory . '/' . $role . '-storage-state.json';
    $state = json_encode(['cookies' => $cookies, 'origins' => []], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $chmodFile ??= static fn(string $path, int $mode): bool => chmod($path, $mode);

    try {
        if (file_put_contents($statePath, $state) === false || !$chmodFile($statePath, 0600)) {
            throw new RuntimeException('Customers UI smoke browser storage state could not be written safely.');
        }

        $details = $callback($statePath);
    } finally {
        if ((is_file($statePath) || is_link($statePath)) && !unlink($statePath)) {
            throw new RuntimeException('Customers UI smoke browser storage state could not be removed.');
        }

        if (file_exists($statePath) || is_link($statePath)) {
            throw new RuntimeException('Customers UI smoke browser storage state could not be removed.');
        }
    }

    $details['storage_state_removed'] = true;

    return $details;
}

function customersUiSmokeTemporaryArtifactsRemoved(?string $tempDirectory): bool
{
    return !is_string($tempDirectory) || (!file_exists($tempDirectory) && !is_link($tempDirectory));
}

function customersUiSmokeStorageStatesRemoved(?string $tempDirectory): bool
{
    if (!is_string($tempDirectory)) {
        return true;
    }

    foreach (CustomersUiSmokeContract::AUTHORIZED_ROLES as $role) {
        $statePath = $tempDirectory . '/' . $role . '-storage-state.json';

        if (file_exists($statePath) || is_link($statePath)) {
            return false;
        }
    }

    return true;
}

/**
 * @param array<string, string> $sessions
 */
function customersUiSmokeFinalizeCleanup(array $sessions, ?string $tempDirectory, callable $closeSession): bool
{
    $cleanupOk = true;

    foreach ($sessions as $sessionId) {
        try {
            $closeSession($sessionId);
        } catch (Throwable) {
            $cleanupOk = false;
        }
    }

    if (is_string($tempDirectory) && !CustomersUiSmokeContract::removePrivateTempDirectory($tempDirectory)) {
        $cleanupOk = false;
    }

    return $cleanupOk;
}
