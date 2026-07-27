<?php

declare(strict_types=1);

namespace ReleaseGate;

use InvalidArgumentException;
use RuntimeException;

require_once __DIR__ . '/ProviderUiSmokeContract.php';

/**
 * @return array{username:string,password:string}
 */
function readProviderUiSmokeCredentials(string $source): array
{
    if ($source === '-') {
        $contents = stream_get_contents(STDIN, 16_385);
    } else {
        $contents = readRootOnlyProviderUiSmokeCredentialFile($source);
    }

    if (!is_string($contents) || $contents === '' || strlen($contents) > 16_384) {
        throw new InvalidArgumentException('Provider UI smoke credentials are missing or invalid.');
    }

    return parseProviderUiSmokeCredentials($contents);
}

/**
 * @return array{username:string,password:string}
 */
function parseProviderUiSmokeCredentials(string $contents): array
{
    if ($contents === '' || strlen($contents) > 16_384 || str_contains($contents, "\0")) {
        throw new InvalidArgumentException('Provider UI smoke credentials are missing or invalid.');
    }

    $parsed = @parse_ini_string($contents, false, INI_SCANNER_RAW);

    if (!is_array($parsed)) {
        throw new InvalidArgumentException('Provider UI smoke credentials are not valid INI.');
    }

    $expectedKeys = ['PROVIDER_UI_SMOKE_USERNAME', 'PROVIDER_UI_SMOKE_PASSWORD'];
    $actualKeys = array_keys($parsed);
    sort($expectedKeys);
    sort($actualKeys);

    if ($actualKeys !== $expectedKeys) {
        throw new InvalidArgumentException('Provider UI smoke credentials must contain exactly the required keys.');
    }

    $username = trim((string) ($parsed['PROVIDER_UI_SMOKE_USERNAME'] ?? ''));
    $password = trim((string) ($parsed['PROVIDER_UI_SMOKE_PASSWORD'] ?? ''));

    if (!hash_equals(ProviderUiSmokeContract::USERNAME, $username)) {
        throw new InvalidArgumentException('Provider UI smoke username does not match the reserved fixture account.');
    }

    if (preg_match('/^[a-f0-9]{64}$/D', $password) !== 1) {
        throw new InvalidArgumentException('Provider UI smoke password has an invalid shape.');
    }

    return [
        'username' => $username,
        'password' => $password,
    ];
}

function assertRootOnlyProviderUiSmokeCredentialFile(string $path): void
{
    inspectRootOnlyProviderUiSmokeCredentialFile($path);
}

function readRootOnlyProviderUiSmokeCredentialFile(string $path): string
{
    $expectedStat = inspectRootOnlyProviderUiSmokeCredentialFile($path);
    $handle = @fopen($path, 'rb');

    if (!is_resource($handle)) {
        throw new RuntimeException('Explicit provider UI smoke credential file could not be opened.');
    }

    try {
        $openedStat = fstat($handle);

        if (!is_array($openedStat) || !providerUiSmokeCredentialStatsMatch($expectedStat, $openedStat)) {
            throw new RuntimeException('Explicit provider UI smoke credential file changed during secure open.');
        }

        $contents = stream_get_contents($handle, 16_385);
        if (!is_string($contents)) {
            throw new RuntimeException('Explicit provider UI smoke credential file could not be read.');
        }

        return $contents;
    } finally {
        fclose($handle);
    }
}

/**
 * @return array<string|int, mixed>
 */
function inspectRootOnlyProviderUiSmokeCredentialFile(string $path): array
{
    if ($path === '' || is_link($path) || !is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Explicit provider UI smoke credential file is not a readable regular file.');
    }

    $stat = lstat($path);

    if (!is_array($stat)) {
        throw new RuntimeException('Could not inspect explicit provider UI smoke credential file.');
    }

    $mode = ((int) ($stat['mode'] ?? 0)) & 0777;
    $owner = (int) ($stat['uid'] ?? -1);
    $links = (int) ($stat['nlink'] ?? 0);
    $size = (int) ($stat['size'] ?? -1);

    if ($owner !== 0 || ($mode & 0077) !== 0 || $links !== 1 || $size <= 0 || $size > 16_384) {
        throw new RuntimeException(
            'Explicit provider UI smoke credential file does not satisfy the root-only contract.',
        );
    }

    return $stat;
}

/**
 * @param array<string|int, mixed> $expected
 * @param array<string|int, mixed> $actual
 */
function providerUiSmokeCredentialStatsMatch(array $expected, array $actual): bool
{
    foreach (['dev', 'ino', 'uid', 'mode', 'nlink', 'size'] as $field) {
        if ((int) ($expected[$field] ?? -1) !== (int) ($actual[$field] ?? -2)) {
            return false;
        }
    }

    return true;
}
