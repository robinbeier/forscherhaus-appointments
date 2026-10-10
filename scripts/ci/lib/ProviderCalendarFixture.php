<?php

declare(strict_types=1);

namespace CiContract;

use ReleaseGate\GateAssertionException;
use ReleaseGate\GateHttpClient;

require_once dirname(__DIR__, 2) . '/release-gate/lib/GateHttpClient.php';

/** Owns one private provider fixture for the host-side provider calendar smoke. */
final class ProviderCalendarFixture
{
    /** @return array{provider_id:int,username:string,password:string,marker:string} */
    public static function create(GateHttpClient $client, string $baseUrl, int $serviceId): array
    {
        self::assertAllowedTarget($baseUrl);
        if ($serviceId <= 0) {
            throw new GateAssertionException('Provider calendar fixture requires a positive service ID.');
        }

        $marker = 'rob784_provider_' . bin2hex(random_bytes(10));
        $username = $marker;
        $password = bin2hex(random_bytes(24));
        $payload = [
            'first_name' => 'Synthetic',
            'last_name' => 'Provider',
            'email' => $marker . '@synthetic.invalid',
            'phone_number' => '0000000000',
            'notes' => $marker,
            'is_private' => 1,
            'services' => [$serviceId],
            'settings' => ['username' => $username, 'password' => $password],
        ];

        $providerId = null;
        $reportedId = null;
        try {
            $response = $client->post('providers/store', ['provider' => $payload]);
            $created = json_decode($response->body, true);
            if (
                is_array($created) &&
                filter_var($created['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false
            ) {
                $reportedId = (int) $created['id'];
            }
            $providerId = self::recoverProviderId($client, $marker);
            if ($providerId === null) {
                throw new GateAssertionException('Provider calendar fixture creation did not yield an owned provider.');
            }
            if ($response->statusCode !== 200) {
                throw new GateAssertionException('Provider calendar fixture creation failed.');
            }

            $booking = $client->get('booking');
            if ($booking->statusCode !== 200 || self::bookingContainsProvider($booking->body, $providerId, $marker)) {
                throw new GateAssertionException('Private provider appeared in the public booking bootstrap.');
            }

            return [
                'provider_id' => $providerId,
                'username' => $username,
                'password' => $password,
                'marker' => $marker,
            ];
        } catch (\Throwable $error) {
            $ownedId = $providerId;
            if ($ownedId === null) {
                try {
                    $ownedId = self::recoverProviderId($client, $marker);
                } catch (\Throwable $lookupError) {
                    $ownedId = self::verifyReportedId($client, $reportedId, $marker);
                    if ($ownedId === null) {
                        throw new GateAssertionException(
                            'Provider calendar fixture creation failed and cleanup identity could not be confirmed.',
                            0,
                            $lookupError,
                        );
                    }
                }
            }
            if ($ownedId !== null) {
                self::cleanup($client, $baseUrl, $ownedId, $marker);
            }
            throw $error;
        }
    }

    public static function cleanup(
        GateHttpClient $client,
        string $baseUrl,
        int $providerId,
        string $marker,
        ?string $unavailabilityMarker = null,
    ): void {
        self::assertAllowedTarget($baseUrl);
        if ($providerId <= 0 || $marker === '') {
            throw new GateAssertionException('Provider calendar fixture identity is incomplete.');
        }
        if ($unavailabilityMarker !== null && $unavailabilityMarker !== '') {
            try {
                foreach (self::ownedUnavailabilityRows($providerId, $unavailabilityMarker) as $row) {
                    try {
                        $client->post('calendar/delete_unavailability', ['unavailability_id' => (int) $row['id']]);
                    } catch (\Throwable) {
                        // Verify the final DB state after the independent provider cleanup.
                    }
                }
            } catch (\Throwable) {
                // The provider cleanup is still attempted for this owned fixture.
            }
        }

        try {
            $client->post('providers/destroy', ['provider_id' => $providerId]);
        } catch (\Throwable) {
            // A lost response does not establish whether the delete committed.
        }
        $failures = [];
        if ($unavailabilityMarker !== null && $unavailabilityMarker !== '') {
            try {
                if (self::ownedUnavailabilityRows($providerId, $unavailabilityMarker) !== []) {
                    $failures[] = 'unavailability remains';
                }
            } catch (\Throwable) {
                $failures[] = 'unavailability state unknown';
            }
        }
        try {
            if (self::recoverProviderId($client, $marker) !== null) {
                $failures[] = 'provider remains';
            }
        } catch (\Throwable) {
            $failures[] = 'provider state unknown';
        }
        if ($failures !== []) {
            throw new GateAssertionException(
                'Provider calendar fixture cleanup was not confirmed: ' . implode(', ', $failures),
            );
        }
    }

    public static function assertUnavailabilityAbsent(int $providerId, string $marker): void
    {
        if (self::ownedUnavailabilityRows($providerId, $marker) !== []) {
            throw new GateAssertionException('Provider calendar fixture delete was not confirmed in the database.');
        }
    }

    public static function resetIsolatedRateLimitCache(string $baseUrl): void
    {
        // The added real browser pass makes this sequential CI suite exceed the
        // application's shared per-IP limit. Reset only this test client's
        // counter under the same lock as rate_limit(); production is unchanged.
        self::assertAllowedTarget($baseUrl);
        $cacheDirectory = dirname(__DIR__, 3) . '/storage/cache';
        if (!is_dir($cacheDirectory) || is_link($cacheDirectory)) {
            throw new GateAssertionException('Isolated rate-limit cache directory is unavailable.');
        }

        $host = (string) parse_url($baseUrl, PHP_URL_HOST);
        $port = (int) (parse_url($baseUrl, PHP_URL_PORT) ?: 80);
        $connection = @stream_socket_client("tcp://{$host}:{$port}", $errorCode, $errorMessage, 2);
        if ($connection === false) {
            throw new GateAssertionException('Isolated rate-limit client address could not be determined.');
        }
        try {
            $localEndpoint = stream_socket_get_name($connection, false);
        } finally {
            fclose($connection);
        }
        $clientIp = is_string($localEndpoint) ? parse_url('tcp://' . $localEndpoint, PHP_URL_HOST) : false;
        if (!is_string($clientIp) || filter_var($clientIp, FILTER_VALIDATE_IP) === false) {
            throw new GateAssertionException('Isolated rate-limit client address is invalid.');
        }

        $cacheKey = str_replace(':', '', 'rate_limit_key_' . $clientIp);
        $temporaryKey = str_replace(':', '', 'rate_limit_tmp_' . $clientIp);
        $bucket = hexdec(substr(hash('sha256', $cacheKey), 0, 8)) % 64;
        $lockPath = dirname($cacheDirectory) . '/rate_limit.lock.' . sprintf('%02x', $bucket);
        if (is_link($lockPath)) {
            throw new GateAssertionException('Isolated rate-limit lock path is unsafe.');
        }
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            throw new GateAssertionException('Isolated rate-limit lock is unavailable.');
        }
        $acquired = false;
        try {
            $deadline = microtime(true) + 2;
            do {
                if (@flock($lock, LOCK_EX | LOCK_NB)) {
                    $acquired = true;
                    break;
                }
                usleep(5000);
            } while (microtime(true) < $deadline);
            if (!$acquired) {
                throw new GateAssertionException('Isolated rate-limit lock could not be acquired.');
            }

            foreach ([$cacheKey, $temporaryKey] as $name) {
                $entry = $cacheDirectory . '/' . $name;
                if (!file_exists($entry) && !is_link($entry)) {
                    continue;
                }
                if (is_link($entry) || !is_file($entry) || !unlink($entry)) {
                    throw new GateAssertionException('Isolated rate-limit cache reset failed.');
                }
            }
        } finally {
            if ($acquired) {
                @flock($lock, LOCK_UN);
            }
            fclose($lock);
        }
    }

    /** @return list<array<string, mixed>> */
    private static function ownedUnavailabilityRows(int $providerId, string $marker): array
    {
        if (!function_exists('get_instance')) {
            throw new GateAssertionException('Provider calendar fixture DB cleanup is unavailable.');
        }

        $rows = get_instance()
            ->db->where([
                'id_users_provider' => $providerId,
                'notes' => $marker,
                'is_unavailability' => 1,
            ])
            ->get('appointments')
            ->result_array();
        if (count($rows) > 1) {
            throw new GateAssertionException('Provider calendar fixture unavailability marker was ambiguous.');
        }
        return $rows;
    }

    private static function recoverProviderId(GateHttpClient $client, string $marker): ?int
    {
        $response = $client->post('providers/search', ['keyword' => $marker]);
        if ($response->statusCode !== 200) {
            throw new GateAssertionException('Provider calendar fixture ownership search failed.');
        }
        $rows = json_decode($response->body, true);
        if (!is_array($rows)) {
            throw new GateAssertionException('Provider calendar fixture ownership search returned invalid JSON.');
        }
        $matches = array_values(
            array_filter(
                $rows,
                static fn(mixed $row): bool => is_array($row) &&
                    (($row['notes'] ?? null) === $marker || ($row['email'] ?? null) === $marker . '@synthetic.invalid'),
            ),
        );
        if (count($matches) > 1) {
            throw new GateAssertionException('Provider calendar fixture marker was ambiguous.');
        }
        if (
            $matches !== [] &&
            (($matches[0]['email'] ?? null) !== $marker . '@synthetic.invalid' ||
                ($matches[0]['notes'] ?? null) !== $marker ||
                (int) ($matches[0]['is_private'] ?? 0) !== 1)
        ) {
            throw new GateAssertionException('Provider calendar fixture marker did not bind a private provider.');
        }
        $id = (int) ($matches[0]['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    private static function verifyReportedId(GateHttpClient $client, ?int $reportedId, string $marker): ?int
    {
        if ($reportedId === null) {
            return null;
        }
        $response = $client->post('providers/find', ['provider_id' => $reportedId]);
        if ($response->statusCode !== 200) {
            return null;
        }
        $found = json_decode($response->body, true);
        if (
            !is_array($found) ||
            (int) ($found['id'] ?? 0) !== $reportedId ||
            ($found['email'] ?? null) !== $marker . '@synthetic.invalid' ||
            ($found['notes'] ?? null) !== $marker ||
            (int) ($found['is_private'] ?? 0) !== 1
        ) {
            throw new GateAssertionException('Provider calendar fixture response ID did not bind the owned provider.');
        }
        return $reportedId;
    }

    private static function bookingContainsProvider(string $html, int $providerId, string $marker): bool
    {
        $markerPosition = strpos($html, 'const vars =');
        $braceStart = $markerPosition === false ? false : strpos($html, '{', $markerPosition);
        if ($braceStart === false) {
            throw new GateAssertionException('Booking bootstrap marker is missing.');
        }
        $depth = 0;
        $inString = false;
        $escaped = false;
        for ($index = $braceStart, $length = strlen($html); $index < $length; $index++) {
            $char = $html[$index];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}' && --$depth === 0) {
                $bootstrap = json_decode(substr($html, $braceStart, $index - $braceStart + 1), true);
                if (!is_array($bootstrap)) {
                    throw new GateAssertionException('Booking bootstrap is invalid.');
                }
                foreach ($bootstrap['available_providers'] ?? [] as $provider) {
                    if (
                        is_array($provider) &&
                        ((int) ($provider['id'] ?? 0) === $providerId || ($provider['notes'] ?? null) === $marker)
                    ) {
                        return true;
                    }
                }
                return false;
            }
        }
        throw new GateAssertionException('Booking bootstrap JSON is incomplete.');
    }

    private static function assertAllowedTarget(string $baseUrl): void
    {
        $baseUrl = rtrim($baseUrl, '/');
        $parsed = parse_url($baseUrl);
        $host = is_array($parsed) ? $parsed['host'] ?? '' : '';
        $dockerLocal = $baseUrl === 'http://nginx' && defined('Config::DB_HOST') && \Config::DB_HOST === 'mysql';
        $actionsLocal =
            getenv('GITHUB_ACTIONS') === 'true' &&
            getenv('CI') === 'true' &&
            $baseUrl === 'http://127.0.0.1:8080' &&
            defined('Config::DB_HOST') &&
            \Config::DB_HOST === '127.0.0.1';
        if (
            !is_array($parsed) ||
            ($parsed['scheme'] ?? '') !== 'http' ||
            !in_array($host, ['localhost', '127.0.0.1', 'nginx'], true) ||
            (!$dockerLocal && !$actionsLocal)
        ) {
            throw new GateAssertionException('Provider calendar fixture requires the isolated local testing target.');
        }
    }
}
