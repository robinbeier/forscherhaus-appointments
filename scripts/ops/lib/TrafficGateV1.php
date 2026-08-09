<?php

declare(strict_types=1);

namespace Ops;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;

final class TrafficGateV1
{
    public const SCHEMA = 'traffic_gate.v1';
    public const POLICY_VERSION = 'traffic_gate_policy.v1';
    public const PURPOSES = ['customers-ui-smoke', 'deploy'];
    public const MODES = ['normal', 'no-business-traffic'];
    public const CLASSES = [
        'documented_health',
        'documented_periodic_ops',
        'denied_external',
        'public_read',
        'business_or_authenticated',
        'unclassified',
    ];

    private const SAFE_METHODS = ['GET', 'HEAD'];

    /**
     * @return array<string, mixed>
     */
    public static function loadCatalog(string $path): array
    {
        if ($path === '' || is_link($path) || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('traffic catalog is unavailable');
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('traffic catalog is unreadable');
        }

        try {
            $catalog = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('traffic catalog is invalid');
        }

        if (
            !is_array($catalog) ||
            array_is_list($catalog) ||
            ($catalog['schema'] ?? null) !== 'traffic_gate_catalog.v1'
        ) {
            throw new RuntimeException('traffic catalog schema is invalid');
        }
        if (
            !is_string($catalog['version'] ?? null) ||
            preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}\.[1-9][0-9]*$/', $catalog['version']) !== 1
        ) {
            throw new RuntimeException('traffic catalog version is invalid');
        }

        foreach (['documented_health', 'documented_periodic_ops'] as $ruleSet) {
            if (!is_array($catalog[$ruleSet] ?? null) || $catalog[$ruleSet] === []) {
                throw new RuntimeException('traffic catalog rules are incomplete');
            }
            foreach ($catalog[$ruleSet] as $rule) {
                self::assertCatalogRule($rule);
            }
        }

        if (($catalog['documented_sources']['loopback_cidrs'] ?? null) !== ['127.0.0.0/8', '::1/128']) {
            throw new RuntimeException('traffic catalog source contract is invalid');
        }

        return $catalog;
    }

    /**
     * @return list<array{path:string,slot:string,device:int,inode:int,size:int,mtime:int}>
     */
    public static function captureLogSet(string $logDirectory): array
    {
        $canonicalDirectory = realpath($logDirectory);
        if (
            $canonicalDirectory === false ||
            $canonicalDirectory !== rtrim($logDirectory, '/') ||
            is_link($logDirectory)
        ) {
            throw new RuntimeException('traffic log directory is invalid');
        }

        $paths = [];
        foreach (['*access.log', '*access.log.1', '*access.log.*.gz'] as $pattern) {
            $matches = glob($canonicalDirectory . '/' . $pattern, GLOB_NOSORT);
            if ($matches === false) {
                throw new RuntimeException('traffic log discovery failed');
            }
            foreach ($matches as $path) {
                $paths[$path] = true;
            }
        }
        $paths = array_keys($paths);
        sort($paths, SORT_STRING);
        if ($paths === []) {
            throw new RuntimeException('traffic log set is empty');
        }

        $entries = [];
        $identities = [];
        foreach ($paths as $path) {
            $basename = basename($path);
            if (preg_match('/^[A-Za-z0-9._-]*access\.log(?:\.1|\.[1-9][0-9]*\.gz)?$/', $basename) !== 1) {
                throw new RuntimeException('traffic log filename is unsupported');
            }
            if (is_link($path) || !is_file($path) || !is_readable($path)) {
                throw new RuntimeException('traffic log member is unsafe');
            }
            $stat = lstat($path);
            if (!is_array($stat) || ($stat['nlink'] ?? null) !== 1) {
                throw new RuntimeException('traffic log member identity is unsafe');
            }
            $identity = (int) $stat['dev'] . ':' . (int) $stat['ino'];
            if (isset($identities[$identity])) {
                throw new RuntimeException('traffic log set contains a duplicate identity');
            }
            $identities[$identity] = true;
            $entries[] = [
                'path' => $path,
                'slot' => $basename,
                'device' => (int) $stat['dev'],
                'inode' => (int) $stat['ino'],
                'size' => (int) $stat['size'],
                'mtime' => (int) $stat['mtime'],
            ];
        }

        return $entries;
    }

    /**
     * @param list<array{path:string,slot:string,device:int,inode:int,size:int,mtime:int}> $before
     * @param list<array{path:string,slot:string,device:int,inode:int,size:int,mtime:int}> $after
     * @param array<string, mixed> $catalog
     * @return array<string, mixed>
     */
    public static function evaluate(
        array $before,
        array $after,
        array $catalog,
        string $purpose,
        string $mode,
        int $windowStartEpoch,
        int $windowEndEpoch,
        string $producerSha256,
    ): array {
        if (!in_array($purpose, self::PURPOSES, true) || !in_array($mode, self::MODES, true)) {
            throw new RuntimeException('traffic gate purpose or mode is invalid');
        }
        if ($windowStartEpoch <= 0 || $windowEndEpoch < $windowStartEpoch) {
            throw new RuntimeException('traffic gate window is invalid');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $producerSha256) !== 1) {
            throw new RuntimeException('traffic gate producer fingerprint is invalid');
        }

        $rotationComplete = self::rotationIsComplete($before, $after);
        $counts = array_fill_keys(self::CLASSES, 0);
        $counts += [
            'total' => 0,
            'lines_seen' => 0,
            'lines_in_window' => 0,
            'parse_errors' => 0,
            'source_unknown' => 0,
            'method_unknown' => 0,
            'target_unknown' => 0,
            'status_5xx' => 0,
            'write' => 0,
            'authenticated' => 0,
            'customers_or_sensitive' => 0,
            'scanner_success' => 0,
            'rotation_errors' => $rotationComplete ? 0 : 1,
        ];

        $parseableEvidence = false;
        foreach ($after as $entry) {
            self::readEntry($entry, function (string $line) use (
                &$counts,
                &$parseableEvidence,
                $catalog,
                $windowStartEpoch,
                $windowEndEpoch,
            ): void {
                $counts['lines_seen']++;
                $parsed = self::parseLine($line);
                if ($parsed === null) {
                    $counts['parse_errors']++;
                    return;
                }
                $parseableEvidence = true;
                if ($parsed['epoch'] > $windowEndEpoch) {
                    $counts['parse_errors']++;
                    return;
                }
                if ($parsed['epoch'] < $windowStartEpoch) {
                    return;
                }

                $counts['lines_in_window']++;
                $classification = self::classify($parsed, $catalog);
                $counts[$classification['class']]++;
                foreach ($classification['overlays'] as $overlay) {
                    $counts[$overlay]++;
                }
            });
        }
        $counts['total'] = $counts['lines_in_window'];

        $classTotal = 0;
        foreach (self::CLASSES as $class) {
            $classTotal += $counts[$class];
        }
        if ($classTotal !== $counts['lines_in_window']) {
            throw new RuntimeException('traffic gate class invariant failed');
        }

        $parseComplete = $counts['parse_errors'] === 0 && $parseableEvidence;
        $evidenceComplete = $rotationComplete && $parseComplete;
        [$decision, $exitCode] = self::decide($counts, $mode, $evidenceComplete);

        return [
            'schema' => self::SCHEMA,
            'producer_sha256' => $producerSha256,
            'policy_version' => self::POLICY_VERSION,
            'catalog_version' => $catalog['version'],
            'purpose' => $purpose,
            'mode' => $mode,
            'window_start_epoch' => $windowStartEpoch,
            'window_end_epoch' => $windowEndEpoch,
            'window_seconds' => $windowEndEpoch - $windowStartEpoch,
            'log_set_sha256' => self::logSetFingerprint($after),
            'rotation_complete' => $rotationComplete,
            'parse_complete' => $parseComplete,
            'evidence_complete' => $evidenceComplete,
            'decision' => $decision,
            'exit_code' => $exitCode,
            'counts' => $counts,
        ];
    }

    /**
     * @param array<string, int> $counts
     * @return array{string,int}
     */
    private static function decide(array $counts, string $mode, bool $evidenceComplete): array
    {
        if (!$evidenceComplete) {
            return ['invalid', 21];
        }
        $alwaysHard =
            $counts['business_or_authenticated'] > 0 ||
            $counts['unclassified'] > 0 ||
            $counts['status_5xx'] > 0 ||
            $counts['write'] > 0 ||
            $counts['authenticated'] > 0 ||
            $counts['customers_or_sensitive'] > 0 ||
            $counts['scanner_success'] > 0 ||
            $counts['source_unknown'] > 0 ||
            $counts['method_unknown'] > 0 ||
            $counts['target_unknown'] > 0;
        if ($alwaysHard || ($mode === 'normal' && $counts['public_read'] > 0)) {
            return ['hard_stop', 20];
        }
        if ($counts['public_read'] > 0 || $counts['denied_external'] > 0) {
            return ['advisory', 0];
        }

        return ['allow', 0];
    }

    /**
     * @param array<string, mixed> $parsed
     * @param array<string, mixed> $catalog
     * @return array{class:string,overlays:list<string>}
     */
    private static function classify(array $parsed, array $catalog): array
    {
        $overlays = [];
        $method = $parsed['method'];
        $path = $parsed['path'];
        $status = $parsed['status'];
        $queryPresent = $parsed['query_present'];
        $loopback = self::isLoopback($parsed['source']);

        if ($status >= 500) {
            $overlays[] = 'status_5xx';
        }
        if (!in_array($method, self::SAFE_METHODS, true)) {
            $overlays[] = 'write';
        }
        if ($parsed['authenticated']) {
            $overlays[] = 'authenticated';
        }
        if ($parsed['source_unknown']) {
            $overlays[] = 'source_unknown';
        }
        if ($parsed['method_unknown']) {
            $overlays[] = 'method_unknown';
        }
        if ($parsed['target_unknown']) {
            $overlays[] = 'target_unknown';
        }

        $sensitive = self::isCustomersOrSensitive($path);
        if ($sensitive) {
            $overlays[] = 'customers_or_sensitive';
        }

        if ($overlays !== []) {
            return [
                'class' =>
                    $parsed['source_unknown'] || $parsed['method_unknown'] || $parsed['target_unknown']
                        ? 'unclassified'
                        : 'business_or_authenticated',
                'overlays' => array_values(array_unique($overlays)),
            ];
        }

        $scanner = self::isScannerTarget($path, $queryPresent, $parsed['query']);
        if ($scanner) {
            if ($status === 403 || $status === 404) {
                if (
                    $loopback &&
                    !$queryPresent &&
                    self::matchesCatalogRules($catalog['documented_periodic_ops'], $method, $path, $status)
                ) {
                    return ['class' => 'documented_periodic_ops', 'overlays' => []];
                }
                return [
                    'class' => $loopback ? 'business_or_authenticated' : 'denied_external',
                    'overlays' => [],
                ];
            }

            return [
                'class' => 'business_or_authenticated',
                'overlays' => $status >= 200 && $status < 300 ? ['scanner_success'] : [],
            ];
        }

        if (
            $loopback &&
            !$queryPresent &&
            self::matchesCatalogRules($catalog['documented_health'], $method, $path, $status)
        ) {
            return ['class' => 'documented_health', 'overlays' => []];
        }
        if (
            $loopback &&
            !$queryPresent &&
            self::matchesCatalogRules($catalog['documented_periodic_ops'], $method, $path, $status)
        ) {
            return ['class' => 'documented_periodic_ops', 'overlays' => []];
        }

        if ($queryPresent || $sensitive) {
            return ['class' => 'business_or_authenticated', 'overlays' => $sensitive ? ['customers_or_sensitive'] : []];
        }

        return ['class' => 'public_read', 'overlays' => []];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function parseLine(string $line): ?array
    {
        $pattern =
            '/^(?<prefix>[^\r\n]+?)\s+\[(?<time>[^\]\r\n]+)\]\s+"(?<method>[^\s"\r\n]+)\s+(?<target>[^\s"\r\n]+)\s+HTTP\/(?<http>[0-9.]+)"\s+(?<status>[0-9]{3})\s+(?<bytes>\S+)(?:\s+"[^"\r\n]*"\s+"[^"\r\n]*")?\s*$/';
        if (preg_match($pattern, $line, $match) !== 1) {
            return null;
        }

        $tokens = preg_split('/\s+/', trim($match['prefix']));
        if (!is_array($tokens) || !in_array(count($tokens), [3, 4], true)) {
            return null;
        }
        if (count($tokens) === 4) {
            if (preg_match('/^[A-Za-z0-9.:-]+$/', $tokens[0]) !== 1) {
                return null;
            }
            array_shift($tokens);
        }
        [$source, $identity, $authenticatedUser] = $tokens;
        if ($identity !== '-') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!d/M/Y:H:i:s O', $match['time'], new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        $status = (int) $match['status'];
        if ($status < 100 || $status > 599) {
            return null;
        }

        $method = strtoupper($match['method']);
        $methodUnknown =
            preg_match('/^[A-Z]+$/', $method) !== 1 ||
            !in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true);
        $sourceUnknown = filter_var($source, FILTER_VALIDATE_IP) === false;
        [$path, $query, $queryPresent, $targetUnknown] = self::parseTarget($match['target']);

        return [
            'epoch' => $date->getTimestamp(),
            'source' => $source,
            'source_unknown' => $sourceUnknown,
            'authenticated' => $authenticatedUser !== '-',
            'method' => $method,
            'method_unknown' => $methodUnknown,
            'path' => $path,
            'query' => $query,
            'query_present' => $queryPresent,
            'target_unknown' => $targetUnknown,
            'status' => $status,
        ];
    }

    /**
     * @return array{string,string,bool,bool}
     */
    private static function parseTarget(string $target): array
    {
        if ($target === '*' || str_starts_with($target, 'http://') || str_starts_with($target, 'https://')) {
            return ['', '', false, true];
        }
        if (!str_starts_with($target, '/') || preg_match('/[\x00-\x20\x7f]/', $target) === 1) {
            return ['', '', false, true];
        }
        $queryPresent = str_contains($target, '?');
        $path = $queryPresent ? strstr($target, '?', true) : $target;
        $query = $queryPresent ? substr($target, strpos($target, '?') + 1) : '';
        if (!is_string($path) || $path === '') {
            return ['', '', $queryPresent, true];
        }

        return [$path, $query, $queryPresent, false];
    }

    private static function isLoopback(string $source): bool
    {
        if (filter_var($source, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return str_starts_with($source, '127.');
        }

        return $source === '::1';
    }

    private static function isCustomersOrSensitive(string $path): bool
    {
        return preg_match(
            '#^/(?:index\.php/)?(?:customers|login|logout|recovery|account|calendar|appointments|providers|admins|secretaries|services|settings|backend|api|installation|booking/register)(?:/|$)#i',
            $path,
        ) === 1;
    }

    private static function isScannerTarget(string $path, bool $queryPresent, string $query): bool
    {
        if (
            preg_match(
                '#^/(?:wp-admin(?:/|$)|wp-login(?:\.php)?(?:$|[./])|xmlrpc\.php(?:$|[./])|\.env(?:$|[./_~?-])|vendor/phpunit(?:/|$)|phpinfo(?:\.php)?(?:$|[./])|config\.php(?:$|[./])|server-status(?:$|[./])|boaform(?:/|$)|HNAP1(?:/|$)|cgi-bin(?:/|$))#i',
                $path,
            ) === 1
        ) {
            return true;
        }

        return $queryPresent &&
            preg_match(
                '#(?:wp-admin|wp-login|xmlrpc\.php|(?:^|[^A-Za-z0-9_.-])\.env(?:$|[^A-Za-z0-9_.-])|vendor/phpunit|phpinfo|config\.php|server-status|boaform|HNAP1|cgi-bin/)#i',
                $query,
            ) === 1;
    }

    /**
     * @param list<array<string, mixed>> $rules
     */
    private static function matchesCatalogRules(array $rules, string $method, string $path, int $status): bool
    {
        foreach ($rules as $rule) {
            if (
                in_array($method, $rule['methods'], true) &&
                in_array($path, $rule['paths'], true) &&
                in_array($status, $rule['statuses'], true)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $rule
     */
    private static function assertCatalogRule(mixed $rule): void
    {
        if (!is_array($rule) || array_is_list($rule)) {
            throw new RuntimeException('traffic catalog rule is invalid');
        }
        foreach (['methods', 'paths', 'statuses'] as $key) {
            if (!is_array($rule[$key] ?? null) || $rule[$key] === []) {
                throw new RuntimeException('traffic catalog rule is incomplete');
            }
        }
        foreach ($rule['methods'] as $method) {
            if (!is_string($method) || !in_array($method, self::SAFE_METHODS, true)) {
                throw new RuntimeException('traffic catalog method is invalid');
            }
        }
        foreach ($rule['paths'] as $path) {
            if (!is_string($path) || !str_starts_with($path, '/') || str_contains($path, '?')) {
                throw new RuntimeException('traffic catalog path is invalid');
            }
        }
        foreach ($rule['statuses'] as $status) {
            if (!is_int($status) || $status < 200 || $status > 499) {
                throw new RuntimeException('traffic catalog status is invalid');
            }
        }
    }

    /**
     * @param list<array{path:string,slot:string,device:int,inode:int,size:int,mtime:int}> $before
     * @param list<array{path:string,slot:string,device:int,inode:int,size:int,mtime:int}> $after
     */
    private static function rotationIsComplete(array $before, array $after): bool
    {
        if ($before === [] || $after === []) {
            return false;
        }
        $afterByIdentity = [];
        foreach ($after as $entry) {
            $afterByIdentity[$entry['device'] . ':' . $entry['inode']] = $entry;
        }
        foreach ($before as $entry) {
            $identity = $entry['device'] . ':' . $entry['inode'];
            if (!isset($afterByIdentity[$identity]) || $afterByIdentity[$identity]['size'] < $entry['size']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{path:string,slot:string,device:int,inode:int,size:int,mtime:int} $entry
     */
    private static function readEntry(array $entry, callable $consumeLine): void
    {
        $path = $entry['path'];
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('traffic log member changed during evaluation');
        }
        $before = lstat($path);
        if (
            !is_array($before) ||
            (int) $before['dev'] !== $entry['device'] ||
            (int) $before['ino'] !== $entry['inode'] ||
            (int) $before['size'] < $entry['size']
        ) {
            throw new RuntimeException('traffic log rotation changed during evaluation');
        }

        $gzip = str_ends_with($path, '.gz');
        if ($gzip) {
            $compressed = file_get_contents($path);
            if (
                !is_string($compressed) ||
                !str_starts_with($compressed, "\x1f\x8b") ||
                @gzdecode($compressed) === false
            ) {
                throw new RuntimeException('traffic gzip member is incomplete');
            }
            unset($compressed);
        }
        $handle = $gzip ? gzopen($path, 'rb') : fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('traffic log member could not be opened');
        }
        try {
            if ($gzip) {
                while (($line = gzgets($handle)) !== false) {
                    $consumeLine(rtrim($line, "\r\n"));
                }
                if (!gzeof($handle)) {
                    throw new RuntimeException('traffic gzip member is incomplete');
                }
            } else {
                $remaining = $entry['size'];
                while ($remaining > 0) {
                    $line = fgets($handle, $remaining + 1);
                    if ($line === false || $line === '' || strlen($line) > $remaining) {
                        throw new RuntimeException('traffic log member is incomplete');
                    }
                    $remaining -= strlen($line);
                    if (!str_ends_with($line, "\n")) {
                        throw new RuntimeException('traffic log record was partial at cutoff');
                    }
                    $consumeLine(rtrim($line, "\r\n"));
                }
            }
        } finally {
            $gzip ? gzclose($handle) : fclose($handle);
        }

        clearstatcache(true, $path);
        $after = lstat($path);
        if (
            !is_array($after) ||
            (int) $after['dev'] !== $entry['device'] ||
            (int) $after['ino'] !== $entry['inode'] ||
            (int) $after['size'] < (int) $before['size']
        ) {
            throw new RuntimeException('traffic log rotation changed during evaluation');
        }
    }

    /**
     * @param list<array{path:string,slot:string,device:int,inode:int,size:int,mtime:int}> $entries
     */
    private static function logSetFingerprint(array $entries): string
    {
        $normalized = [];
        foreach ($entries as $entry) {
            $normalized[] = [
                'slot' => $entry['slot'],
                'device' => $entry['device'],
                'inode' => $entry['inode'],
                'size' => $entry['size'],
                'mtime' => $entry['mtime'],
            ];
        }
        usort($normalized, static fn(array $left, array $right): int => $left['slot'] <=> $right['slot']);

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
