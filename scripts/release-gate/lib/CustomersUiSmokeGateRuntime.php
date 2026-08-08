<?php

declare(strict_types=1);

namespace ReleaseGate;

use RuntimeException;
use Throwable;

require_once __DIR__ . '/CustomersUiSmokeContract.php';

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
