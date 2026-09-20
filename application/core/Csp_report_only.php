<?php defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Root controlled, report-only Content-Security-Policy support.
 *
 * This class deliberately keeps configuration, policy construction, report
 * classification, and the bounded aggregate writer independent of the web
 * framework.  In particular, no value supplied by a browser is ever written
 * to the aggregate file or to an error message.
 */
final class Csp_report_only
{
    public const CONFIG_PATH = '/var/lib/fh-app-config/csp-report-only.json';
    public const AGGREGATE_FILENAME = 'csp-report-only-aggregate-v1.json';
    public const CONFIG_SCHEMA = 'csp_report_only_config.v1';
    public const AGGREGATE_SCHEMA = 'csp_report_only_aggregate.v1';
    public const MAX_BODY_BYTES = 32768;
    public const MAX_REPORTS_PER_BATCH = 20;
    public const MAX_AGGREGATE_BYTES = 2_000_000;
    public const DEFAULT_MAX_REPORTS_PER_MINUTE = 120;
    public const DEFAULT_RETENTION_HOURS = 48;

    private const MAX_COUNTER_VALUE = 1_000_000_000;

    private const ALLOWED_CONFIG_KEYS = [
        'schema',
        'enabled',
        'app_host',
        'www_host',
        'google_analytics_enabled',
        'matomo_origin',
        'max_reports_per_minute',
        'retention_hours',
    ];

    private const REPORT_DIRECTIVES = [
        'default-src',
        'script-src',
        'style-src',
        'img-src',
        'font-src',
        'connect-src',
        'frame-src',
        'object-src',
        'base-uri',
        'form-action',
        'frame-ancestors',
        'media-src',
        'worker-src',
        'manifest-src',
        'child-src',
        'plugin-types',
        'sandbox',
        'script-src-elem',
        'script-src-attr',
        'style-src-elem',
        'style-src-attr',
        'report-uri',
    ];

    private const BLOCKED_ORIGIN_CLASSES = [
        'self',
        'inline',
        'eval',
        'data',
        'blob',
        'google-analytics',
        'google-tag-manager',
        'matomo',
        'unknown-external',
        'none',
    ];

    /** @return array<string,mixed>|null */
    public static function load(?string $path = null): ?array
    {
        $path = $path ?? self::CONFIG_PATH;

        if (!self::isSecureConfigPath($path) || !is_file($path)) {
            return null;
        }

        $json = @file_get_contents($path);

        return is_string($json) ? self::parseConfig($json) : null;
    }

    public static function aggregatePath(): string
    {
        return dirname(APPPATH) . '/storage/logs/' . self::AGGREGATE_FILENAME;
    }

    /**
     * Parse the activation document without touching /etc or the filesystem.
     * Disabled documents are valid but never produce an active policy.
     *
     * @return array<string,mixed>|null
     */
    public static function parseConfig(string $json): ?array
    {
        if (strlen($json) > 8192) {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            return null;
        }

        if (
            !is_array($decoded) ||
            array_keys($decoded) !== array_values(array_filter(array_keys($decoded), 'is_string'))
        ) {
            return null;
        }

        $keys = array_keys($decoded);
        $allowedKeys = self::ALLOWED_CONFIG_KEYS;
        sort($keys);
        sort($allowedKeys);
        if ($keys !== $allowedKeys) {
            return null;
        }

        if (($decoded['schema'] ?? null) !== self::CONFIG_SCHEMA || !is_bool($decoded['enabled'] ?? null)) {
            return null;
        }

        $appHost = self::canonicalHost($decoded['app_host'] ?? null);
        $wwwHost = self::canonicalHost($decoded['www_host'] ?? null);

        if ($appHost === null || $wwwHost === null || $appHost === $wwwHost) {
            return null;
        }

        if (!is_bool($decoded['google_analytics_enabled'])) {
            return null;
        }

        $matomo = null;
        if ($decoded['matomo_origin'] !== null) {
            $matomo = self::strictOrigin($decoded['matomo_origin']);
            if ($matomo === null) {
                return null;
            }
        }

        $rate = $decoded['max_reports_per_minute'];
        $retention = $decoded['retention_hours'];

        if (!is_int($rate) || $rate < 1 || $rate > 10000 || !is_int($retention) || $retention < 1 || $retention > 168) {
            return null;
        }

        return [
            'schema' => self::CONFIG_SCHEMA,
            'enabled' => $decoded['enabled'],
            'app_host' => $appHost,
            'www_host' => $wwwHost,
            'google_analytics_enabled' => $decoded['google_analytics_enabled'],
            'matomo_origin' => $matomo,
            'max_reports_per_minute' => $rate,
            'retention_hours' => $retention,
        ];
    }

    public static function canonicalHost(mixed $host): ?string
    {
        if (!is_string($host) || $host === '' || $host !== strtolower($host) || strlen($host) > 253) {
            return null;
        }

        if (preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/', $host) !== 1) {
            return null;
        }

        return $host;
    }

    public static function strictOrigin(mixed $origin): ?string
    {
        if (!is_string($origin) || strlen($origin) > 255 || preg_match('/[\x00-\x20\\\\]/', $origin)) {
            return null;
        }

        $parts = parse_url($origin);
        if (
            !is_array($parts) ||
            !in_array($parts['scheme'] ?? null, ['http', 'https'], true) ||
            !isset($parts['host'])
        ) {
            return null;
        }

        foreach (['user', 'pass', 'path', 'query', 'fragment'] as $forbidden) {
            if (array_key_exists($forbidden, $parts)) {
                return null;
            }
        }

        $host = self::canonicalHost($parts['host']);
        if ($host === null) {
            return null;
        }

        $port = $parts['port'] ?? null;
        if ($port !== null && (!is_int($port) || $port < 1 || $port > 65535)) {
            return null;
        }

        return strtolower($parts['scheme']) . '://' . $host . ($port === null ? '' : ':' . $port);
    }

    /** @return array{header:string,host:string}|null */
    public static function policyForRequest(array $server, array $config, ?string $contentType = 'text/html'): ?array
    {
        $contentType = $contentType === null ? null : strtolower(trim(explode(';', $contentType, 2)[0]));
        if (($config['enabled'] ?? false) !== true || $contentType !== 'text/html') {
            return null;
        }

        $host = self::requestHost($server);
        $scheme = self::requestScheme($server);
        if ($host === null || $scheme !== 'https' || self::isCollectorRequest($server)) {
            return null;
        }

        if (!in_array($host, [$config['app_host'], $config['www_host']], true)) {
            return null;
        }

        $scriptOrigins = [];
        $imgOrigins = [];
        $connectOrigins = [];
        if (($config['google_analytics_enabled'] ?? false) === true) {
            $scriptOrigins = ['https://www.google-analytics.com', 'https://www.googletagmanager.com'];
            $imgOrigins = ['https://www.google-analytics.com'];
            $connectOrigins = ['https://www.google-analytics.com'];
        }
        if (is_string($config['matomo_origin'] ?? null)) {
            $scriptOrigins[] = $config['matomo_origin'];
            $imgOrigins[] = $config['matomo_origin'];
            $connectOrigins[] = $config['matomo_origin'];
        }

        $scriptSources = implode(' ', $scriptOrigins);
        $imgSources = implode(' ', $imgOrigins);
        $connectSources = implode(' ', $connectOrigins);
        $policy =
            "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; object-src 'none'; " .
            "script-src 'self' 'unsafe-inline' {$scriptSources}; style-src 'self' 'unsafe-inline'; font-src 'self' data:; " .
            "img-src 'self' data: {$imgSources}; connect-src 'self' {$connectSources}; report-uri /csp-report";

        return ['header' => 'Content-Security-Policy-Report-Only: ' . $policy, 'host' => $host];
    }

    public static function isCollectorRequest(array $server): bool
    {
        $path = parse_url((string) ($server['REQUEST_URI'] ?? ''), PHP_URL_PATH);

        return is_string($path) && in_array($path, ['/csp-report', '/index.php/csp-report'], true);
    }

    public static function requestHost(array $server): ?string
    {
        $raw = strtolower(trim((string) ($server['HTTP_HOST'] ?? '')));
        if ($raw === '' || str_contains($raw, ':')) {
            return null;
        }

        return self::canonicalHost($raw);
    }

    public static function requestScheme(array $server): string
    {
        if (strtolower((string) ($server['HTTPS'] ?? '')) === 'on' || (int) ($server['SERVER_PORT'] ?? 0) === 443) {
            return 'https';
        }

        $remote = filter_var((string) ($server['REMOTE_ADDR'] ?? ''), FILTER_VALIDATE_IP);
        $loopback = $remote === '127.0.0.1' || $remote === '::1';

        return $loopback && strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            ? 'https'
            : 'http';
    }

    /** Resolve the final response MIME type, including raw headers added by controllers. */
    public static function responseContentType(object $output, array $server = []): ?string
    {
        if (method_exists($output, 'get_header')) {
            $header = $output->get_header('Content-Type');
            if (is_string($header) && preg_match('/\A([^;\s]+)/', trim($header), $match) === 1) {
                return strtolower($match[1]);
            }
        }

        $path = parse_url((string) ($server['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (is_string($path) && preg_match('#\A(?:/index\.php)?/api(?:/|\z)#', $path) === 1) {
            return null;
        }

        if (method_exists($output, 'get_output') && trim((string) $output->get_output()) === '') {
            return null;
        }

        if (method_exists($output, 'get_content_type')) {
            return strtolower(trim((string) $output->get_content_type()));
        }

        return null;
    }

    /** @return list<array{surface:string,directive:string,blocked_origin:string,disposition:string}>|null */
    public static function classifyReports(mixed $payload, array $config): ?array
    {
        $reports = [];
        if (is_array($payload) && isset($payload['csp-report']) && is_array($payload['csp-report'])) {
            $reports[] = $payload['csp-report'];
        } elseif (is_array($payload) && array_is_list($payload)) {
            if ($payload === [] || count($payload) > self::MAX_REPORTS_PER_BATCH) {
                return null;
            }
            foreach ($payload as $entry) {
                if (
                    !is_array($entry) ||
                    ($entry['type'] ?? null) !== 'csp-violation' ||
                    !is_array($entry['body'] ?? null)
                ) {
                    return null;
                }
                $reports[] = $entry['body'];
            }
        } elseif (
            is_array($payload) &&
            ($payload['type'] ?? null) === 'csp-violation' &&
            is_array($payload['body'] ?? null)
        ) {
            $reports[] = $payload['body'];
        } else {
            return null;
        }

        $classified = [];
        foreach ($reports as $report) {
            $item = self::classifyReportBody($report, $config);
            if ($item === null) {
                return null;
            }
            $classified[] = $item;
        }

        return $classified;
    }

    /** @return array{surface:string,directive:string,blocked_origin:string,disposition:string}|null */
    public static function classifyReport(mixed $payload, array $config): ?array
    {
        $classified = self::classifyReports($payload, $config);

        return is_array($classified) && count($classified) === 1 ? $classified[0] : null;
    }

    /** @return array{surface:string,directive:string,blocked_origin:string,disposition:string}|null */
    private static function classifyReportBody(array $report, array $config): ?array
    {
        if (
            ($report['disposition'] ?? null) !== 'report' ||
            !is_string($report['document-uri'] ?? ($report['documentURL'] ?? null))
        ) {
            return null;
        }

        $document = $report['document-uri'] ?? $report['documentURL'];
        $parts = parse_url($document);
        if (
            !is_array($parts) ||
            ($parts['scheme'] ?? null) !== 'https' ||
            array_key_exists('port', $parts) ||
            array_key_exists('user', $parts) ||
            array_key_exists('pass', $parts)
        ) {
            return null;
        }

        $host = self::canonicalHost($parts['host'] ?? null);
        if ($host === null) {
            return null;
        }

        $surface =
            $host === ($config['app_host'] ?? '') ? 'app' : ($host === ($config['www_host'] ?? '') ? 'www' : null);
        if ($surface === null) {
            return null;
        }

        $directiveValue =
            $report['effective-directive'] ??
            ($report['effectiveDirective'] ?? ($report['violated-directive'] ?? 'other'));
        $directiveRaw = is_string($directiveValue) ? $directiveValue : 'other';
        $directive = in_array($directiveRaw, self::REPORT_DIRECTIVES, true) ? $directiveRaw : 'other';
        $blockedValue = $report['blocked-uri'] ?? ($report['blockedURL'] ?? '');
        if (!is_string($blockedValue)) {
            return null;
        }
        $blockedOrigin = self::blockedOriginClass($blockedValue, $config, 'https://' . $host);

        return [
            'surface' => $surface,
            'directive' => $directive,
            'blocked_origin' => $blockedOrigin,
            'disposition' => 'report',
        ];
    }

    public static function blockedOriginClass(string $value, array $config, ?string $documentOrigin = null): string
    {
        if ($value === '') {
            return 'none';
        }
        if (in_array($value, ['inline', 'eval', 'data', 'blob', 'self'], true)) {
            return $value;
        }
        $origin = self::originFromUrl($value);
        if ($origin === null) {
            return 'unknown-external';
        }
        if ($documentOrigin !== null && $origin === $documentOrigin) {
            return 'self';
        }
        if (($config['google_analytics_enabled'] ?? false) === true && $origin === 'https://www.google-analytics.com') {
            return 'google-analytics';
        }
        if (($config['google_analytics_enabled'] ?? false) === true && $origin === 'https://www.googletagmanager.com') {
            return 'google-tag-manager';
        }
        if (is_string($config['matomo_origin'] ?? null) && $origin === $config['matomo_origin']) {
            return 'matomo';
        }
        return 'unknown-external';
    }

    private static function originFromUrl(string $value): ?string
    {
        $parts = parse_url($value);
        if (
            !is_array($parts) ||
            !isset($parts['scheme'], $parts['host']) ||
            !in_array($parts['scheme'], ['http', 'https'], true) ||
            isset($parts['user']) ||
            isset($parts['pass'])
        ) {
            return null;
        }
        $host = self::canonicalHost($parts['host']);
        $port = $parts['port'] ?? null;
        if ($host === null || ($port !== null && (!is_int($port) || $port < 1 || $port > 65535))) {
            return null;
        }

        return $parts['scheme'] . '://' . $host . ($port === null ? '' : ':' . $port);
    }

    /** @return array{status:string,reason:string} */
    public static function record(array $report, array $config, ?string $path = null, ?int $now = null): array
    {
        if (
            ($report['disposition'] ?? null) !== 'report' ||
            !in_array($report['surface'] ?? null, ['app', 'www'], true) ||
            !in_array($report['directive'] ?? null, [...self::REPORT_DIRECTIVES, 'other'], true) ||
            !in_array($report['blocked_origin'] ?? null, self::BLOCKED_ORIGIN_CLASSES, true)
        ) {
            return ['status' => 'error', 'reason' => 'invalid_report'];
        }
        $path = $path ?? self::aggregatePath();
        $now = $now ?? time();
        $handle = self::openAggregate($path);
        if (!is_resource($handle)) {
            return ['status' => 'error', 'reason' => 'storage_unavailable'];
        }

        try {
            $stat = fstat($handle);
            if (!is_array($stat) || (($stat['mode'] ?? 0) & 0170000) !== 0100000 || (int) ($stat['nlink'] ?? 0) !== 1) {
                return ['status' => 'error', 'reason' => 'storage_unavailable'];
            }

            $raw = stream_get_contents($handle);
            $state = $raw !== false && trim($raw) !== '' ? json_decode($raw, true) : null;
            if ($raw !== false && trim($raw) !== '' && (!is_array($state) || !self::validAggregateShape($state))) {
                return ['status' => 'error', 'reason' => 'storage_invalid'];
            }
            if (!is_array($state)) {
                $state = self::emptyAggregate($now);
            }
            $state = self::normalizeAggregate($state, $config, $now);

            if ($state['rate_window']['started_at_utc'] <= gmdate('Y-m-d\TH:i:00\Z', $now - 60)) {
                $state['rate_window'] = ['started_at_utc' => gmdate('Y-m-d\TH:i:00\Z', $now), 'count' => 0];
            }
            if ($state['rate_window']['count'] >= $config['max_reports_per_minute']) {
                return ['status' => 'rate_limited', 'reason' => 'rate_limit'];
            }

            $hour = gmdate('Y-m-d\TH:00:00\Z', $now);
            if (!isset($state['buckets'][$hour])) {
                $state['buckets'][$hour] = self::emptyBucket($hour);
            }
            $bucket = &$state['buckets'][$hour];
            $bucket['accepted']++;
            $bucket['counts'][$report['surface']][$report['directive']][$report['blocked_origin']]++;
            $state['rate_window']['count']++;
            $state['updated_at_utc'] = gmdate('c', $now);
            if (!self::writeState($handle, $state)) {
                return ['status' => 'error', 'reason' => 'storage_failed'];
            }
            return ['status' => 'accepted', 'reason' => 'stored'];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Return an aggregate-only status suitable for a read-only operations probe. */
    public static function summarizeAggregateJson(string $json, ?array $config = null, ?int $now = null): ?array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !self::validAggregateShape($decoded)) {
            return null;
        }
        $summaryConfig = is_array($config) ? $config : ['retention_hours' => 168];
        $state = self::normalizeAggregate($decoded, $summaryConfig, $now ?? time());
        $classes = ['surface' => ['app' => 0, 'www' => 0], 'directive' => [], 'blocked_origin' => []];
        $accepted = 0;
        foreach ($state['buckets'] as $bucket) {
            $accepted += $bucket['accepted'];
            foreach ($bucket['counts'] as $surface => $directives) {
                foreach ($directives as $directive => $origins) {
                    foreach ($origins as $origin => $count) {
                        $classes['surface'][$surface] += $count;
                        $classes['directive'][$directive] = ($classes['directive'][$directive] ?? 0) + $count;
                        $classes['blocked_origin'][$origin] = ($classes['blocked_origin'][$origin] ?? 0) + $count;
                    }
                }
            }
        }
        $now = $now ?? time();
        $updated = strtotime($state['updated_at_utc']);
        return [
            'schema' => self::AGGREGATE_SCHEMA,
            'status' => 'ok',
            'updated_at_utc' => $state['updated_at_utc'],
            'age_seconds' => $updated === false ? null : max(0, $now - $updated),
            'bucket_count' => count($state['buckets']),
            'accepted' => $accepted,
            'dropped' => $state['dropped'],
            'classes' => $classes,
        ];
    }

    /** @return resource|null */
    private static function openAggregate(string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir) || !is_writable($dir)) {
            return null;
        }
        $current = '';
        foreach (explode('/', trim($dir, '/')) as $part) {
            $current .= '/' . $part;
            if (is_link($current)) {
                return null;
            }
        }
        if (is_link($path)) {
            return null;
        }
        $before = file_exists($path) ? @lstat($path) : null;
        if (is_array($before) && ((int) ($before['mode'] ?? 0) & 0170000) !== 0100000) {
            return null;
        }
        $handle = @fopen($path, 'c+');
        if (!is_resource($handle)) {
            return null;
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return null;
        }
        $after = @fstat($handle);
        $identity = @lstat($path);
        if (
            !is_array($after) ||
            !is_array($identity) ||
            (int) ($after['nlink'] ?? 0) !== 1 ||
            (int) ($identity['nlink'] ?? 0) !== 1 ||
            (int) ($after['size'] ?? -1) < 0 ||
            (int) ($after['size'] ?? 0) > self::MAX_AGGREGATE_BYTES ||
            (int) ($after['ino'] ?? -1) !== (int) ($identity['ino'] ?? -2) ||
            (int) ($after['dev'] ?? -1) !== (int) ($identity['dev'] ?? -2)
        ) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return null;
        }
        return $handle;
    }

    private static function validAggregateShape(array $state): bool
    {
        if (
            ($state['schema'] ?? null) !== self::AGGREGATE_SCHEMA ||
            !is_string($state['updated_at_utc'] ?? null) ||
            !self::isUtcTimestamp($state['updated_at_utc']) ||
            !is_array($state['rate_window'] ?? null) ||
            !is_array($state['dropped'] ?? null) ||
            !is_array($state['buckets'] ?? null)
        ) {
            return false;
        }
        if (array_diff(array_keys($state), ['schema', 'updated_at_utc', 'rate_window', 'dropped', 'buckets']) !== []) {
            return false;
        }
        if (
            array_diff(array_keys($state['rate_window']), ['started_at_utc', 'count']) !== [] ||
            array_diff(['started_at_utc', 'count'], array_keys($state['rate_window'])) !== [] ||
            array_diff(array_keys($state['dropped']), ['invalid', 'rate_limited', 'storage_failed']) !== [] ||
            array_diff(['invalid', 'rate_limited', 'storage_failed'], array_keys($state['dropped'])) !== []
        ) {
            return false;
        }
        foreach (['invalid', 'rate_limited', 'storage_failed'] as $key) {
            if (
                !is_int($state['dropped'][$key] ?? null) ||
                $state['dropped'][$key] < 0 ||
                $state['dropped'][$key] > self::MAX_COUNTER_VALUE
            ) {
                return false;
            }
        }
        if (
            !is_string($state['rate_window']['started_at_utc'] ?? null) ||
            !self::isUtcMinute($state['rate_window']['started_at_utc']) ||
            !is_int($state['rate_window']['count'] ?? null) ||
            $state['rate_window']['count'] < 0 ||
            $state['rate_window']['count'] > 10000
        ) {
            return false;
        }
        foreach ($state['buckets'] as $hour => $bucket) {
            if (
                !is_string($hour) ||
                !self::isUtcHour($hour) ||
                !is_array($bucket) ||
                array_diff(['hour_utc', 'accepted', 'counts'], array_keys($bucket)) !== [] ||
                array_diff(array_keys($bucket), ['hour_utc', 'accepted', 'counts']) !== [] ||
                $bucket['hour_utc'] !== $hour ||
                !is_int($bucket['accepted']) ||
                $bucket['accepted'] < 0 ||
                $bucket['accepted'] > self::MAX_COUNTER_VALUE ||
                !is_array($bucket['counts'])
            ) {
                return false;
            }
            $expected = self::emptyBucket($hour)['counts'];
            if (
                array_diff(array_keys($expected), array_keys($bucket['counts'])) !== [] ||
                array_diff(array_keys($bucket['counts']), array_keys($expected)) !== []
            ) {
                return false;
            }
            $sum = 0;
            foreach ($expected as $surface => $directives) {
                if (
                    !is_array($bucket['counts'][$surface]) ||
                    array_diff(array_keys($directives), array_keys($bucket['counts'][$surface])) !== [] ||
                    array_diff(array_keys($bucket['counts'][$surface]), array_keys($directives)) !== []
                ) {
                    return false;
                }
                foreach ($directives as $directive => $origins) {
                    $actualOrigins = $bucket['counts'][$surface][$directive] ?? null;
                    if (
                        !is_array($actualOrigins) ||
                        array_diff(array_keys($origins), array_keys($actualOrigins)) !== [] ||
                        array_diff(array_keys($actualOrigins), array_keys($origins)) !== []
                    ) {
                        return false;
                    }
                    foreach ($origins as $origin => $unused) {
                        if (
                            !is_int($actualOrigins[$origin]) ||
                            $actualOrigins[$origin] < 0 ||
                            $actualOrigins[$origin] > self::MAX_COUNTER_VALUE - $sum
                        ) {
                            return false;
                        }
                        $sum += $actualOrigins[$origin];
                    }
                }
            }
            if ($sum !== $bucket['accepted']) {
                return false;
            }
        }
        return true;
    }

    private static function isUtcTimestamp(string $value): bool
    {
        return str_ends_with($value, '+00:00') && self::matchesDateFormat($value, 'Y-m-d\TH:i:sP');
    }

    private static function isUtcMinute(string $value): bool
    {
        return self::matchesDateFormat($value, 'Y-m-d\TH:i:00\Z');
    }

    private static function isUtcHour(string $value): bool
    {
        return self::matchesDateFormat($value, 'Y-m-d\TH:00:00\Z');
    }

    private static function matchesDateFormat(string $value, string $format): bool
    {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        return $date instanceof DateTimeImmutable &&
            ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) &&
            $date->format($format) === $value;
    }

    /** @return array<string,mixed> */
    private static function emptyAggregate(int $now): array
    {
        return [
            'schema' => self::AGGREGATE_SCHEMA,
            'updated_at_utc' => gmdate('c', $now),
            'rate_window' => ['started_at_utc' => gmdate('Y-m-d\TH:i:00\Z', $now), 'count' => 0],
            'dropped' => ['invalid' => 0, 'rate_limited' => 0, 'storage_failed' => 0],
            'buckets' => [],
        ];
    }

    /** @return array<string,mixed> */
    private static function emptyBucket(string $hour): array
    {
        $empty = [];
        foreach (['app', 'www'] as $surface) {
            $empty[$surface] = [];
            foreach (self::REPORT_DIRECTIVES as $directive) {
                $empty[$surface][$directive] = array_fill_keys(self::BLOCKED_ORIGIN_CLASSES, 0);
            }
            $empty[$surface]['other'] = array_fill_keys(self::BLOCKED_ORIGIN_CLASSES, 0);
        }
        return ['hour_utc' => $hour, 'accepted' => 0, 'counts' => $empty];
    }

    /** @return array<string,mixed> */
    private static function normalizeAggregate(array $state, array $config, int $now): array
    {
        $fresh = self::emptyAggregate($now);
        if (is_string($state['updated_at_utc'] ?? null)) {
            $fresh['updated_at_utc'] = substr($state['updated_at_utc'], 0, 32);
        }

        if (is_array($state['rate_window'] ?? null)) {
            $started = $state['rate_window']['started_at_utc'] ?? null;
            $count = $state['rate_window']['count'] ?? null;
            if (is_string($started) && is_int($count) && $count >= 0 && $count <= 10000) {
                $fresh['rate_window'] = ['started_at_utc' => substr($started, 0, 32), 'count' => $count];
            }
        }

        if (is_array($state['dropped'] ?? null)) {
            foreach (array_keys($fresh['dropped']) as $key) {
                if (is_int($state['dropped'][$key] ?? null) && $state['dropped'][$key] >= 0) {
                    $fresh['dropped'][$key] = min($state['dropped'][$key], PHP_INT_MAX);
                }
            }
        }

        if (is_array($state['buckets'] ?? null)) {
            $currentHour = intdiv($now, 3600) * 3600;
            $earliestHour = $currentHour - ($config['retention_hours'] - 1) * 3600;
            foreach ($state['buckets'] as $hour => $bucket) {
                if (
                    !is_string($hour) ||
                    !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:00:00Z\z/', $hour) ||
                    !is_array($bucket)
                ) {
                    continue;
                }
                $bucketHour = strtotime($hour);
                if ($bucketHour === false || $bucketHour < $earliestHour || $bucketHour > $currentHour) {
                    continue;
                }
                $normalized = self::emptyBucket($hour);
                if (is_int($bucket['accepted'] ?? null) && $bucket['accepted'] >= 0) {
                    $normalized['accepted'] = $bucket['accepted'];
                }
                foreach ($normalized['counts'] as $surface => $directives) {
                    foreach ($directives as $directive => $origins) {
                        foreach ($origins as $origin => $value) {
                            if (
                                is_int($bucket['counts'][$surface][$directive][$origin] ?? null) &&
                                $bucket['counts'][$surface][$directive][$origin] >= 0
                            ) {
                                $normalized['counts'][$surface][$directive][$origin] =
                                    $bucket['counts'][$surface][$directive][$origin];
                            }
                        }
                    }
                }
                $fresh['buckets'][$hour] = $normalized;
            }
            ksort($fresh['buckets']);
            $fresh['buckets'] = array_slice($fresh['buckets'], -$config['retention_hours'], null, true);
        }

        return $fresh;
    }

    private static function writeState($handle, array $state): bool
    {
        try {
            $encoded = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            return false;
        }
        if (strlen($encoded) > self::MAX_AGGREGATE_BYTES || !ftruncate($handle, 0)) {
            return false;
        }
        rewind($handle);
        $written = fwrite($handle, $encoded);
        if ($written !== strlen($encoded) || !fflush($handle)) {
            return false;
        }
        if (function_exists('fsync') && !@fsync($handle)) {
            return false;
        }
        return true;
    }

    private static function isSecureConfigPath(string $path): bool
    {
        if ($path === '' || $path[0] !== '/' || is_link($path)) {
            return false;
        }
        $parts = explode('/', trim($path, '/'));
        $current = '';
        foreach ($parts as $part) {
            $current .= '/' . $part;
            $stat = @lstat($current);
            if (
                !is_array($stat) ||
                (($stat['mode'] ?? 0) & 0170000) === 0120000 ||
                (int) ($stat['uid'] ?? -1) !== 0 ||
                (int) ($stat['gid'] ?? -1) !== 0 ||
                (((int) ($stat['mode'] ?? 0)) & 0022) !== 0
            ) {
                return false;
            }
        }
        $stat = @lstat($path);
        return is_array($stat) &&
            (($stat['mode'] ?? 0) & 0170000) === 0100000 &&
            (int) ($stat['uid'] ?? -1) === 0 &&
            (int) ($stat['gid'] ?? -1) === 0 &&
            (((int) ($stat['mode'] ?? 0)) & 0777) === 0644 &&
            (int) ($stat['nlink'] ?? 0) === 1;
    }
}
