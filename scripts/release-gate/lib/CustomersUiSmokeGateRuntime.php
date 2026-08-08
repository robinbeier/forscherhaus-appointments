<?php

declare(strict_types=1);

namespace ReleaseGate;

use RuntimeException;
use Throwable;

require_once __DIR__ . '/CustomersUiSmokeContract.php';

/**
 * @param array<int, array<string, mixed>> $cookies
 */
function customersUiSmokeWithStorageState(
    string $tempDirectory,
    string $role,
    array $cookies,
    callable $callback,
): mixed {
    $statePath = $tempDirectory . '/' . $role . '-storage-state.json';
    $state = json_encode(['cookies' => $cookies, 'origins' => []], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    if (file_put_contents($statePath, $state) === false || !chmod($statePath, 0600)) {
        throw new RuntimeException('Customers UI smoke browser storage state could not be written safely.');
    }

    try {
        return $callback($statePath);
    } finally {
        if (is_file($statePath) && !unlink($statePath)) {
            throw new RuntimeException('Customers UI smoke browser storage state could not be removed.');
        }
    }
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
