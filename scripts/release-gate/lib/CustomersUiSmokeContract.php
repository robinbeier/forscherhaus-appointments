<?php

declare(strict_types=1);

namespace ReleaseGate;

use InvalidArgumentException;
use RuntimeException;

final class CustomersUiSmokeContract
{
    public const SEARCH_MARKER = '__EA_CUSTOMERS_UI_SMOKE_V1_EMPTY_SEARCH__';

    /**
     * @var array<string, string>
     */
    public const USERNAMES_BY_ROLE = [
        'admin' => '__ea_customers_ui_smoke_admin_v1',
        'provider' => '__ea_customers_ui_smoke_provider_v1',
        'secretary' => '__ea_customers_ui_smoke_secretary_v1',
        'customer' => '__ea_customers_ui_smoke_customer_v1',
    ];

    /**
     * @var list<string>
     */
    public const AUTHORIZED_ROLES = ['admin', 'provider', 'secretary'];

    /**
     * @var list<string>
     */
    public const FORBIDDEN_KEYS = [
        'customer_filter_providers',
        'google_client_id',
        'google_client_secret',
        'google_token',
        'google_calendar',
        'google_calendar_id',
        'google_sync',
        'caldav_url',
        'caldav_username',
        'caldav_password',
        'caldav_calendar',
        'caldav_calendar_id',
        'caldav_sync',
        'webhook_url',
        'webhook_secret',
    ];

    /**
     * Translation dictionaries legitimately contain generic integration labels,
     * so raw HTML/JSON scans are limited to customer-filter and secret-bearing markers.
     *
     * @var list<string>
     */
    public const FORBIDDEN_RESPONSE_MARKERS = [
        'customer_filter_providers',
        'google_client_secret',
        'google_token',
        'caldav_url',
        'caldav_username',
        'caldav_password',
        'webhook_url',
        'webhook_secret',
    ];

    private function __construct() {}

    public static function buildBrowserSessionId(string $role): string
    {
        if (!array_key_exists($role, self::USERNAMES_BY_ROLE)) {
            throw new InvalidArgumentException('Unknown Customers UI smoke role.');
        }

        return 'cui-' . substr($role, 0, 1) . '-' . bin2hex(random_bytes(3));
    }

    /**
     * @return array<string, mixed>
     */
    public static function extractScriptVars(string $html): array
    {
        $markerPosition = strpos($html, 'const vars =');

        if ($markerPosition === false) {
            throw new RuntimeException('Customers UI smoke response is missing the script-vars marker.');
        }

        $braceStart = strpos($html, '{', $markerPosition);

        if ($braceStart === false) {
            throw new RuntimeException('Customers UI smoke response is missing the script-vars object.');
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($html);

        for ($index = $braceStart; $index < $length; $index++) {
            $character = $html[$index];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ($character === '\\') {
                    $escaped = true;
                    continue;
                }

                if ($character === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($character === '"') {
                $inString = true;
                continue;
            }

            if ($character === '{') {
                $depth++;
                continue;
            }

            if ($character !== '}') {
                continue;
            }

            $depth--;

            if ($depth !== 0) {
                continue;
            }

            $decoded = json_decode(
                substr($html, $braceStart, $index - $braceStart + 1),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            if (!is_array($decoded)) {
                throw new RuntimeException('Customers UI smoke script-vars payload is not an object.');
            }

            return $decoded;
        }

        throw new RuntimeException('Customers UI smoke script-vars object is incomplete.');
    }

    /**
     * @return array{password: string, usernames: array<string, string>}
     */
    public static function readCredentials(string $source): array
    {
        $contents = $source === '-' ? stream_get_contents(STDIN, 16_385) : self::readProtectedFile($source);

        if (!is_string($contents) || $contents === '' || strlen($contents) > 16_384 || str_contains($contents, "\0")) {
            throw new InvalidArgumentException('Customers UI smoke credentials are missing or invalid.');
        }

        $parsed = @parse_ini_string($contents, false, INI_SCANNER_RAW);
        $expected = ['CUSTOMERS_UI_SMOKE_PASSWORD'];

        foreach (array_keys(self::USERNAMES_BY_ROLE) as $role) {
            $expected[] = self::credentialUsernameKey($role);
        }

        if (!is_array($parsed)) {
            throw new InvalidArgumentException('Customers UI smoke credentials are not valid INI.');
        }

        $actual = array_keys($parsed);
        sort($actual);
        sort($expected);

        if ($actual !== $expected) {
            throw new InvalidArgumentException(
                'Customers UI smoke credentials must contain exactly the required keys.',
            );
        }

        $usernames = [];

        foreach (self::USERNAMES_BY_ROLE as $role => $expectedUsername) {
            $username = trim((string) ($parsed[self::credentialUsernameKey($role)] ?? ''));

            if (!hash_equals($expectedUsername, $username)) {
                throw new InvalidArgumentException('Customers UI smoke username does not match the reserved role.');
            }

            $usernames[$role] = $username;
        }

        $password = trim((string) ($parsed['CUSTOMERS_UI_SMOKE_PASSWORD'] ?? ''));

        if (preg_match('/^[a-f0-9]{64}$/D', $password) !== 1) {
            throw new InvalidArgumentException('Customers UI smoke password has an invalid shape.');
        }

        return ['password' => $password, 'usernames' => $usernames];
    }

    public static function createPrivateTempDirectory(): string
    {
        $path = sys_get_temp_dir() . '/fh-customers-ui-smoke-' . bin2hex(random_bytes(8));

        if (!mkdir($path, 0700) || !chmod($path, 0700) || (((int) fileperms($path)) & 0777) !== 0700) {
            throw new RuntimeException('Customers UI smoke private temporary directory could not be created.');
        }

        return $path;
    }

    public static function removePrivateTempDirectory(string $path): bool
    {
        $prefix = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'fh-customers-ui-smoke-';

        if (!str_starts_with($path, $prefix) || !is_dir($path) || is_link($path)) {
            return false;
        }

        $items = scandir($path);

        if (!is_array($items)) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $item;

            if (!is_file($child) || is_link($child) || !unlink($child)) {
                return false;
            }
        }

        return rmdir($path);
    }

    private static function credentialUsernameKey(string $role): string
    {
        return 'CUSTOMERS_UI_SMOKE_' . strtoupper($role) . '_USERNAME';
    }

    private static function readProtectedFile(string $path): string
    {
        if ($path === '' || is_link($path) || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Customers UI smoke credential file is not a readable regular file.');
        }

        $expected = lstat($path);

        if (!is_array($expected)) {
            throw new RuntimeException('Customers UI smoke credential file could not be inspected.');
        }

        $mode = ((int) ($expected['mode'] ?? 0)) & 0777;

        if (
            (int) ($expected['uid'] ?? -1) !== 0 ||
            ($mode & 0077) !== 0 ||
            (int) ($expected['nlink'] ?? 0) !== 1 ||
            (int) ($expected['size'] ?? -1) <= 0 ||
            (int) ($expected['size'] ?? -1) > 16_384
        ) {
            throw new RuntimeException('Customers UI smoke credential file does not satisfy the root-only contract.');
        }

        $handle = @fopen($path, 'rb');

        if (!is_resource($handle)) {
            throw new RuntimeException('Customers UI smoke credential file could not be opened.');
        }

        try {
            $opened = fstat($handle);

            foreach (['dev', 'ino', 'uid', 'mode', 'nlink', 'size'] as $field) {
                if ((int) ($expected[$field] ?? -1) !== (int) ($opened[$field] ?? -2)) {
                    throw new RuntimeException('Customers UI smoke credential file changed during secure open.');
                }
            }

            $contents = stream_get_contents($handle, 16_385);

            if (!is_string($contents)) {
                throw new RuntimeException('Customers UI smoke credential file could not be read.');
            }

            return $contents;
        } finally {
            fclose($handle);
        }
    }
}
