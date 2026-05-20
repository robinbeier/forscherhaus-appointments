<?php
declare(strict_types=1);

final class SentryBootstrap
{
    private const SENSITIVE_CONTEXT_KEY_PATTERN =
        '/(^|_)(' .
        'appointment_hash|confirmation_hash|recovery_token|token|secret|password|passwd|authorization|cookie|dsn|' .
        'push_url|raw_request_body|request_body|email|phone|customer_name|provider_name|db_password|database_url' .
        ')(_|$)/i';

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
            'before_send' => [self::class, 'scrubEvent'],
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

        $safeExtra = self::scrubContextArray($extra);

        try {
            \Sentry\withScope(function ($scope) use ($exception, $tags, $safeExtra): void {
                foreach ($tags as $key => $value) {
                    $normalizedKey = trim((string) $key);
                    $normalizedValue = trim((string) $value);

                    if ($normalizedKey === '' || $normalizedValue === '') {
                        continue;
                    }

                    $scope->setTag($normalizedKey, $normalizedValue);
                }

                foreach ($safeExtra as $key => $value) {
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

    public static function scrubEvent(\Sentry\Event $event, ?\Sentry\EventHint $hint = null): \Sentry\Event
    {
        $event->setExtra(self::scrubContextArray($event->getExtra()));
        $event->setRequest(self::scrubContextArray($event->getRequest()));
        $event->setUser(null);

        foreach ($event->getContexts() as $name => $context) {
            $event->setContext((string) $name, self::scrubContextArray($context));
        }

        return $event;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function scrubContextArray(array $context): array
    {
        $scrubbed = [];

        foreach ($context as $key => $value) {
            $normalizedKey = trim((string) $key);

            if ($normalizedKey === '') {
                continue;
            }

            $scrubbed[$normalizedKey] = self::scrubContextValue($normalizedKey, $value);
        }

        return $scrubbed;
    }

    public static function safeDigest(?string $value): ?string
    {
        $normalized = trim((string) $value);

        if ($normalized === '') {
            return null;
        }

        return substr(hash('sha256', $normalized), 0, 16);
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

    private static function scrubContextValue(string $key, mixed $value): mixed
    {
        if (self::isSensitiveContextKey($key)) {
            return '[redacted]';
        }

        if (is_array($value)) {
            return self::scrubContextArray($value);
        }

        if (is_string($value)) {
            return self::scrubString($value);
        }

        return $value;
    }

    private static function isSensitiveContextKey(string $key): bool
    {
        if (preg_match('/(^|_)(appointment_hash|confirmation_hash)_(digest|present)$/i', $key) === 1) {
            return false;
        }

        return preg_match(self::SENSITIVE_CONTEXT_KEY_PATTERN, $key) === 1;
    }

    private static function scrubString(string $value): string
    {
        $scrubbed = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted-email]', $value);
        $scrubbed = is_string($scrubbed) ? $scrubbed : $value;

        $scrubbed = preg_replace(
            '~/(booking_confirmation/of|appointments/ics|booking|calendar/(?:index|reschedule)|backend/index)/[^/?#]+~i',
            '/$1/[redacted]',
            $scrubbed,
        );
        $scrubbed = is_string($scrubbed) ? $scrubbed : $value;

        $scrubbed = preg_replace('#/push/[A-Za-z0-9_-]+#', '/push/[redacted]', $scrubbed);
        $scrubbed = is_string($scrubbed) ? $scrubbed : $value;

        if (str_contains($scrubbed, '?')) {
            $parts = explode('?', $scrubbed, 2);
            $scrubbed = $parts[0] . '?[redacted-query]';
        }

        return $scrubbed;
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
