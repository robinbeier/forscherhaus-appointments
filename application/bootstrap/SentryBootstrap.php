<?php
declare(strict_types=1);

final class SentryBootstrap
{
    /**
     * @param array<string, string> $server
     */
    public static function bootFromGlobals(string $environment, string $releaseFile, array $server = []): void
    {
        $options = self::buildOptionsFromGlobals($environment, $releaseFile, $server);

        if ($options === [] || !function_exists('\Sentry\init')) {
            return;
        }

        \Sentry\init($options);
    }

    /**
     * @param array<string, string> $server
     * @return array<string, mixed>
     */
    public static function buildOptionsFromGlobals(string $environment, string $releaseFile, array $server = []): array
    {
        return self::buildOptionsFromEnvironmentMap(
            self::readEnvironmentMap($server),
            $environment,
            $releaseFile,
            $server,
        );
    }

    /**
     * @param array<string, mixed> $env
     * @param array<string, string> $server
     * @return array<string, mixed>
     */
    public static function buildOptionsFromEnvironmentMap(
        array $env,
        string $environment,
        string $releaseFile,
        array $server = [],
    ): array {
        $dsn = trim((string) ($env['SENTRY_DSN'] ?? ''));

        if ($dsn === '') {
            return [];
        }

        $options = [
            'dsn' => $dsn,
            'environment' => $environment,
            'send_default_pii' => self::parseBooleanValue($env['SENTRY_SEND_DEFAULT_PII'] ?? null, false),
        ];

        $release = self::readReleaseIdentifier($releaseFile);
        if ($release !== null) {
            $options['release'] = $release;
        }

        $tracesSampleRate = self::parseOptionalFloat($env['SENTRY_TRACES_SAMPLE_RATE'] ?? null);
        if ($tracesSampleRate !== null) {
            $options['traces_sample_rate'] = $tracesSampleRate;
        }

        $serverName = trim((string) ($env['SENTRY_SERVER_NAME'] ?? ($server['HTTP_HOST'] ?? '')));
        if ($serverName !== '') {
            $options['server_name'] = $serverName;
        }

        return $options;
    }

    public static function readReleaseIdentifier(string $releaseFile): ?string
    {
        if (!is_file($releaseFile) || !is_readable($releaseFile)) {
            return null;
        }

        $raw = trim((string) file_get_contents($releaseFile));
        if ($raw === '') {
            return null;
        }

        $parts = preg_split('/\s+/', $raw);
        $release = is_array($parts) ? trim((string) ($parts[0] ?? '')) : '';

        return $release !== '' ? $release : null;
    }

    /**
     * @param array<string, scalar|null> $tags
     * @param array<string, mixed> $extra
     */
    public static function captureException(Throwable $exception, array $tags = [], array $extra = []): void
    {
        if (!function_exists('\Sentry\captureException') || !function_exists('\Sentry\withScope')) {
            return;
        }

        try {
            \Sentry\withScope(function ($scope) use ($exception, $tags, $extra): void {
                foreach ($tags as $key => $value) {
                    $normalizedKey = trim((string) $key);
                    $normalizedValue = trim((string) $value);

                    if ($normalizedKey === '' || $normalizedValue === '') {
                        continue;
                    }

                    $scope->setTag($normalizedKey, $normalizedValue);
                }

                foreach ($extra as $key => $value) {
                    $normalizedKey = trim((string) $key);

                    if ($normalizedKey === '') {
                        continue;
                    }

                    $scope->setExtra($normalizedKey, $value);
                }

                \Sentry\captureException($exception);
            });
        } catch (Throwable) {
            // Never let observability code break the request path.
        }
    }

    private static function parseOptionalFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }

    private static function parseBooleanValue(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => $default,
        };
    }

    /**
     * @param array<string, string> $server
     * @return array<string, mixed>
     */
    private static function readEnvironmentMap(array $server = []): array
    {
        $keys = ['SENTRY_DSN', 'SENTRY_TRACES_SAMPLE_RATE', 'SENTRY_SEND_DEFAULT_PII', 'SENTRY_SERVER_NAME'];

        $values = $_ENV;

        foreach ($keys as $key) {
            if (array_key_exists($key, $values) && trim((string) $values[$key]) !== '') {
                continue;
            }

            $value = getenv($key);
            if ($value !== false && trim((string) $value) !== '') {
                $values[$key] = $value;
                continue;
            }

            $serverValue = self::readServerEnvironmentValue($server, $key);
            if ($serverValue !== null) {
                $values[$key] = $serverValue;
            }
        }

        return $values;
    }

    /**
     * @param array<string, string> $server
     */
    private static function readServerEnvironmentValue(array $server, string $key): ?string
    {
        foreach ([$key, 'REDIRECT_' . $key] as $candidate) {
            if (!array_key_exists($candidate, $server)) {
                continue;
            }

            $value = trim((string) $server[$candidate]);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
