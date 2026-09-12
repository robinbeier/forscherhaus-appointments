<?php

declare(strict_types=1);

namespace ReleaseGate;

use RuntimeException;

/** Verified, short lived credentials and fixture identity for the live canary. */
final class ZeroSurpriseCanaryContext
{
    public const SCHEMA = 'zero_surprise_canary.v1';
    public const DEFAULT_PATH = '/var/lib/fh-zero-surprise-canary/active.json';

    /** @return array<string, mixed> */
    public static function loadVerified(string $path = self::DEFAULT_PATH, ?int $now = null): array
    {
        if ($path !== self::DEFAULT_PATH) {
            throw new RuntimeException('Canary context path must be the fixed active state path.');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Canary context is missing or unreadable.');
        }
        $mode = fileperms($path);
        if ($mode === false || ($mode & 0777) !== 0600) {
            throw new RuntimeException('Canary context permissions must be root-only (0600).');
        }
        $stat = @lstat($path);
        $parent = @lstat(dirname($path));
        if (
            !is_array($stat) ||
            ($stat['nlink'] ?? 0) !== 1 ||
            ($stat['uid'] ?? -1) !== 0 ||
            (($stat['mode'] ?? 0) & 0170000) !== 0100000 ||
            !is_array($parent) ||
            ($parent['uid'] ?? -1) !== 0 ||
            (($parent['mode'] ?? 0) & 0777) !== 0700
        ) {
            throw new RuntimeException('Canary context must be a root-owned regular file.');
        }
        $raw = file_get_contents($path);
        $after = @lstat($path);
        if (
            !is_array($after) ||
            ($after['nlink'] ?? 0) !== 1 ||
            ($after['ino'] ?? null) !== ($stat['ino'] ?? null) ||
            ($after['size'] ?? null) !== ($stat['size'] ?? null) ||
            ($after['mtime'] ?? null) !== ($stat['mtime'] ?? null)
        ) {
            throw new RuntimeException('Canary context changed while being read.');
        }
        try {
            $value = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new RuntimeException('Canary context is not valid JSON.', 0, $e);
        }
        if (!is_array($value)) {
            throw new RuntimeException('Canary context must be a JSON object.');
        }
        self::validatePayload($value, $now);
        return $value;
    }

    /** @param array<string,mixed> $value */
    public static function validatePayload(array $value, ?int $now = null): void
    {
        $required = [
            'schema',
            'run_id',
            'actor_id',
            'actor_username',
            'actor_password',
            'provider_id',
            'service_id',
            'token',
            'expires_at',
            'created_at',
        ];
        if (array_diff(array_keys($value), $required) !== [] || count($value) !== count($required)) {
            throw new RuntimeException('Canary context contains unsupported fields.');
        }
        foreach (['schema', 'run_id', 'actor_username', 'actor_password', 'token'] as $field) {
            if (!is_string($value[$field] ?? null)) {
                throw new RuntimeException('Canary context field must be a string.');
            }
        }
        if (
            $value['schema'] !== self::SCHEMA ||
            !preg_match('/^zs-canary-[0-9a-f]{32}$/D', (string) $value['run_id'])
        ) {
            throw new RuntimeException('Canary context identity is malformed.');
        }
        if (
            $value['actor_username'] !== '__ea_zero_surprise_canary_v1' ||
            !preg_match('/^[0-9a-f]{64}$/D', (string) $value['actor_password']) ||
            !preg_match('/^[0-9a-f]{64}$/D', (string) $value['token'])
        ) {
            throw new RuntimeException('Canary context credentials are malformed.');
        }
        foreach (['actor_id', 'provider_id', 'service_id', 'expires_at', 'created_at'] as $field) {
            if (!is_int($value[$field] ?? null) || $value[$field] <= 0) {
                throw new RuntimeException('Canary context field must be a positive integer: ' . $field);
            }
        }
        $now ??= time();
        if (
            $value['created_at'] > $now ||
            $value['expires_at'] <= $now ||
            $value['expires_at'] - $value['created_at'] > 600
        ) {
            throw new RuntimeException('Canary context lease is stale or invalid.');
        }
    }

    public static function assertUnchanged(array $context, string $path = self::DEFAULT_PATH): void
    {
        $current = self::loadVerified($path);
        foreach (
            [
                'run_id',
                'actor_id',
                'actor_username',
                'actor_password',
                'provider_id',
                'service_id',
                'token',
                'expires_at',
                'created_at',
            ]
            as $field
        ) {
            if (($current[$field] ?? null) !== ($context[$field] ?? null)) {
                throw new RuntimeException('Canary context changed during run.');
            }
        }
    }
}
