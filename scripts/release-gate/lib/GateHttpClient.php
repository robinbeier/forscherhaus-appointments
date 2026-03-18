<?php

declare(strict_types=1);

namespace ReleaseGate;

require_once __DIR__ . '/PlaywrightCookieRecords.php';

use RuntimeException;

final class GateHttpResponse
{
    /**
     * @param array<string, string[]> $headers
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly array $headers,
        public readonly string $body,
        public readonly float $durationMs,
        public readonly string $url,
    ) {}

    public function header(string $name): ?string
    {
        $key = strtolower($name);

        if (!isset($this->headers[$key]) || $this->headers[$key] === []) {
            return null;
        }

        return $this->headers[$key][0];
    }
}

final class GateHttpClient
{
    /**
     * @var array<string, string>
     */
    private array $cookies = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $cookieRecords = [];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $indexPage = 'index.php',
        private readonly int $defaultTimeoutSeconds = 15,
        private readonly string $userAgent = 'dashboard-release-gate/1.0',
        private readonly string $csrfCookieName = 'csrf_cookie',
        private readonly string $csrfTokenName = 'csrf_token',
    ) {}

    public function get(string $path, array $query = [], ?int $timeoutSeconds = null): GateHttpResponse
    {
        $url = $this->buildAppUrl($path, $query);

        return $this->request('GET', $url, null, $timeoutSeconds ?? $this->defaultTimeoutSeconds);
    }

    public function post(
        string $path,
        array $form = [],
        ?int $timeoutSeconds = null,
        bool $withCsrfToken = true,
    ): GateHttpResponse {
        if ($withCsrfToken && !array_key_exists($this->csrfTokenName, $form)) {
            $csrfToken = $this->getCookie($this->csrfCookieName);

            if ($csrfToken === null || $csrfToken === '') {
                throw new RuntimeException(
                    'Missing ' . $this->csrfCookieName . ' before POST request to "' . $path . '".',
                );
            }

            $form[$this->csrfTokenName] = $csrfToken;
        }

        $url = $this->buildAppUrl($path);

        return $this->request('POST', $url, $form, $timeoutSeconds ?? $this->defaultTimeoutSeconds);
    }

    public function getAbsolute(string $url, ?int $timeoutSeconds = null): GateHttpResponse
    {
        return $this->request('GET', $url, null, $timeoutSeconds ?? $this->defaultTimeoutSeconds, false, false);
    }

    public function getCookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function cookieRecords(): array
    {
        return array_values($this->cookieRecords);
    }

    /**
     * @param array<string, mixed>|null $form
     */
    private function request(
        string $method,
        string $url,
        ?array $form,
        int $timeoutSeconds,
        bool $useCookieJar = true,
        bool $consumeResponseCookies = true,
    ): GateHttpResponse {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('ext-curl is required for the release gate.');
        }

        $curl = curl_init();

        if ($curl === false) {
            throw new RuntimeException('Failed to initialize cURL.');
        }

        $headers = [];
        $responseBlocks = [];
        $currentBlockStarted = false;
        $currentBlockHeaders = [];
        $currentBlockSetCookies = [];

        $headerFn = function ($ch, string $headerLine) use (
            &$headers,
            &$responseBlocks,
            &$currentBlockStarted,
            &$currentBlockHeaders,
            &$currentBlockSetCookies,
            $consumeResponseCookies,
        ): int {
            $trimmed = trim($headerLine);

            if ($trimmed === '') {
                return strlen($headerLine);
            }

            if (str_starts_with($trimmed, 'HTTP/')) {
                if ($currentBlockStarted) {
                    $responseBlocks[] = [
                        'headers' => $currentBlockHeaders,
                        'set_cookies' => $currentBlockSetCookies,
                    ];
                }

                $currentBlockStarted = true;
                $currentBlockHeaders = [];
                $currentBlockSetCookies = [];

                return strlen($headerLine);
            }

            $parts = explode(':', $trimmed, 2);

            if (count($parts) !== 2) {
                return strlen($headerLine);
            }

            $name = strtolower(trim($parts[0]));
            $value = trim($parts[1]);

            $currentBlockHeaders[$name] ??= [];
            $currentBlockHeaders[$name][] = $value;

            if ($consumeResponseCookies && $name === 'set-cookie') {
                $currentBlockSetCookies[] = $value;
            }

            return strlen($headerLine);
        };

        $requestHeaders = ['Accept: */*'];

        if ($useCookieJar) {
            $cookieHeader = $this->buildCookieHeader($url);
            if ($cookieHeader !== null) {
                $requestHeaders[] = 'Cookie: ' . $cookieHeader;
            }
        }

        $timeoutSeconds = max(1, $timeoutSeconds);
        $connectTimeout = min(5, $timeoutSeconds);

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_HEADERFUNCTION => $headerFn,
            CURLOPT_USERAGENT => $this->userAgent,
        ]);

        if ($form !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($form, '', '&', PHP_QUERY_RFC3986));
        }

        $startedAt = microtime(true);
        $body = curl_exec($curl);
        $durationMs = (microtime(true) - $startedAt) * 1000;

        if ($body === false) {
            $error = curl_error($curl);

            throw new RuntimeException('HTTP request failed for "' . $url . '": ' . $error);
        }

        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $effectiveUrl = (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);

        if ($currentBlockStarted) {
            $responseBlocks[] = [
                'headers' => $currentBlockHeaders,
                'set_cookies' => $currentBlockSetCookies,
            ];
        }

        if ($responseBlocks !== []) {
            $finalBlock = $responseBlocks[count($responseBlocks) - 1];
            $headers = is_array($finalBlock['headers'] ?? null) ? $finalBlock['headers'] : [];
        }

        $response = new GateHttpResponse(
            $statusCode,
            $headers,
            (string) $body,
            round($durationMs, 2),
            $effectiveUrl !== '' ? $effectiveUrl : $url,
        );

        if ($consumeResponseCookies) {
            $this->consumeResponseCookieBlocks($responseBlocks, $url, $response->url);
        }

        return $response;
    }

    /**
     * @return string|null
     */
    private function buildCookieHeader(string $requestUrl): ?string
    {
        if ($this->cookieRecords !== []) {
            $requestParts = parse_url($requestUrl);

            if (is_array($requestParts) && isset($requestParts['host'])) {
                $pairs = [];
                $order = 0;

                foreach ($this->cookieRecords as $record) {
                    if (!$this->cookieRecordMatchesRequest($record, $requestParts)) {
                        $order++;
                        continue;
                    }

                    $pairs[] = [
                        'header' => (string) $record['name'] . '=' . (string) $record['value'],
                        'path_length' => strlen($this->extractCookieRecordPath($record)),
                        'order' => $order,
                    ];
                    $order++;
                }

                if ($pairs === []) {
                    return null;
                }

                usort($pairs, static function (array $left, array $right): int {
                    $pathCompare = $right['path_length'] <=> $left['path_length'];
                    if ($pathCompare !== 0) {
                        return $pathCompare;
                    }

                    return $left['order'] <=> $right['order'];
                });

                return implode('; ', array_map(static fn(array $pair): string => (string) $pair['header'], $pairs));
            }
        }

        if ($this->cookies === []) {
            return null;
        }

        $pairs = [];
        foreach ($this->cookies as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        return implode('; ', $pairs);
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $requestParts
     */
    private function cookieRecordMatchesRequest(array $record, array $requestParts): bool
    {
        $requestScheme = strtolower((string) ($requestParts['scheme'] ?? 'http'));
        $requestHost = strtolower((string) ($requestParts['host'] ?? ''));
        $requestPath = (string) ($requestParts['path'] ?? '/');

        if ($requestHost === '') {
            return false;
        }

        if (!empty($record['secure']) && $requestScheme !== 'https') {
            return false;
        }

        if (isset($record['url'])) {
            $cookieUrlParts = parse_url((string) $record['url']);
            if (!is_array($cookieUrlParts) || !isset($cookieUrlParts['host'])) {
                return false;
            }

            $cookieHost = strtolower((string) $cookieUrlParts['host']);
            if ($cookieHost !== $requestHost) {
                return false;
            }

            return $this->cookiePathMatchesRequestPath((string) ($cookieUrlParts['path'] ?? '/'), $requestPath);
        }

        $cookieDomain = strtolower((string) ($record['domain'] ?? ''));
        if ($cookieDomain === '' || !$this->cookieDomainMatchesHost($cookieDomain, $requestHost)) {
            return false;
        }

        return $this->cookiePathMatchesRequestPath($this->extractCookieRecordPath($record), $requestPath);
    }

    private function cookieDomainMatchesHost(string $cookieDomain, string $requestHost): bool
    {
        $cookieDomain = ltrim(strtolower($cookieDomain), '.');
        $requestHost = strtolower($requestHost);

        if ($cookieDomain === '' || $requestHost === '') {
            return false;
        }

        return $requestHost === $cookieDomain || str_ends_with($requestHost, '.' . $cookieDomain);
    }

    private function cookiePathMatchesRequestPath(string $cookiePath, string $requestPath): bool
    {
        if ($cookiePath === '') {
            $cookiePath = '/';
        }

        if ($requestPath === '') {
            $requestPath = '/';
        }

        if ($cookiePath === '/') {
            return true;
        }

        if (!str_starts_with($requestPath, $cookiePath)) {
            return false;
        }

        if (str_ends_with($cookiePath, '/')) {
            return true;
        }

        return strlen($requestPath) === strlen($cookiePath) ||
            (isset($requestPath[strlen($cookiePath)]) && $requestPath[strlen($cookiePath)] === '/');
    }

    /**
     * @param array<string, mixed> $record
     */
    private function extractCookieRecordPath(array $record): string
    {
        if (isset($record['path']) && trim((string) $record['path']) !== '') {
            return (string) $record['path'];
        }

        if (isset($record['url'])) {
            $parts = parse_url((string) $record['url']);
            if (is_array($parts) && isset($parts['path']) && trim((string) $parts['path']) !== '') {
                return (string) $parts['path'];
            }
        }

        return '/';
    }

    /**
     * @param string[] $setCookies
     */
    private function consumeSetCookies(array $setCookies, string $responseUrl): void
    {
        $responseParts = parse_url($responseUrl);
        $defaultPath = $this->resolveDefaultCookiePath($responseUrl);
        $defaultSecure = is_array($responseParts) && ($responseParts['scheme'] ?? '') === 'https';

        foreach ($setCookies as $setCookie) {
            $segments = array_map('trim', explode(';', $setCookie));
            $pair = array_shift($segments) ?? '';

            if ($pair === '' || !str_contains($pair, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $pair, 2);
            $name = trim($name);

            if ($name === '') {
                continue;
            }

            $record = [
                'name' => $name,
                'value' => trim($value),
            ];
            $domainExplicitlySet = false;
            $pathExplicitlySet = false;

            foreach ($segments as $segment) {
                if ($segment === '') {
                    continue;
                }

                $attributeParts = explode('=', $segment, 2);
                $attributeName = strtolower(trim($attributeParts[0] ?? ''));
                $attributeValue = trim($attributeParts[1] ?? '');

                switch ($attributeName) {
                    case 'domain':
                        if ($attributeValue !== '') {
                            $record['domain'] = $attributeValue;
                            $domainExplicitlySet = true;
                        }
                        break;
                    case 'path':
                        if ($attributeValue !== '') {
                            $record['path'] = $attributeValue;
                            $pathExplicitlySet = true;
                        }
                        break;
                    case 'secure':
                        $record['secure'] = true;
                        break;
                    case 'httponly':
                        $record['httpOnly'] = true;
                        break;
                    case 'samesite':
                        if ($attributeValue !== '') {
                            $record['sameSite'] = ucfirst(strtolower($attributeValue));
                        }
                        break;
                }
            }

            if (!$pathExplicitlySet && $defaultPath !== '') {
                $record['path'] = $defaultPath;
            }

            if (!isset($record['secure']) && $defaultSecure) {
                $record['secure'] = true;
            }

            if (!$domainExplicitlySet) {
                $cookieUrl = $this->buildCookieScopeUrl($responseUrl, (string) ($record['path'] ?? $defaultPath));
                if ($cookieUrl !== null) {
                    $record['url'] = $cookieUrl;
                }

                unset($record['domain']);
            }

            $this->cookies[$name] = (string) $record['value'];
            $this->cookieRecords[$this->buildCookieRecordScopeKey($record)] = $record;
        }
    }

    /**
     * @param array<int, array{headers?: array<string, string[]>, set_cookies?: string[]}> $responseBlocks
     */
    private function consumeResponseCookieBlocks(array $responseBlocks, string $requestUrl, string $effectiveUrl): void
    {
        if ($responseBlocks === []) {
            return;
        }

        $currentUrl = $requestUrl;
        $lastBlockIndex = count($responseBlocks) - 1;

        foreach ($responseBlocks as $index => $block) {
            $setCookies = array_values(
                array_filter(
                    is_array($block['set_cookies'] ?? null) ? $block['set_cookies'] : [],
                    static fn($cookie): bool => is_string($cookie) && trim($cookie) !== '',
                ),
            );

            if ($setCookies !== []) {
                $this->consumeSetCookies($setCookies, $currentUrl);
            }

            if ($index === $lastBlockIndex) {
                continue;
            }

            $location = $block['headers']['location'][0] ?? null;
            if (!is_string($location) || trim($location) === '') {
                $currentUrl = $effectiveUrl !== '' ? $effectiveUrl : $currentUrl;

                continue;
            }

            $resolvedLocation = $this->resolveRedirectTargetUrl($currentUrl, $location);
            if ($resolvedLocation !== null) {
                $currentUrl = $resolvedLocation;
            }
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private function buildCookieRecordScopeKey(array $record): string
    {
        if (isset($record['url'])) {
            return (string) $record['name'] . '|url|' . (string) $record['url'];
        }

        return sprintf(
            '%s|domain|%s|path|%s',
            (string) $record['name'],
            (string) ($record['domain'] ?? ''),
            (string) ($record['path'] ?? '/'),
        );
    }

    private function buildCookieScopeUrl(string $responseUrl, string $path): ?string
    {
        $parts = parse_url($responseUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $port = isset($parts['port']) ? ':' . (string) $parts['port'] : '';
        $normalizedPath = trim($path);

        if ($normalizedPath === '') {
            $normalizedPath = '/';
        } elseif ($normalizedPath[0] !== '/') {
            $normalizedPath = '/' . ltrim($normalizedPath, '/');
        }

        return $parts['scheme'] . '://' . $parts['host'] . $port . $normalizedPath;
    }

    private function resolveRedirectTargetUrl(string $currentUrl, string $location): ?string
    {
        $location = trim($location);
        if ($location === '') {
            return null;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $location) === 1) {
            return $location;
        }

        $currentParts = parse_url($currentUrl);
        if (!is_array($currentParts) || !isset($currentParts['scheme'], $currentParts['host'])) {
            return null;
        }

        $origin = $currentParts['scheme'] . '://' . $currentParts['host'];
        if (isset($currentParts['port'])) {
            $origin .= ':' . (string) $currentParts['port'];
        }

        if (str_starts_with($location, '//')) {
            return (string) $currentParts['scheme'] . ':' . $location;
        }

        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        if (str_starts_with($location, '?') || str_starts_with($location, '#')) {
            $currentPath = (string) ($currentParts['path'] ?? '/');

            return $origin . $currentPath . $location;
        }

        $currentPath = (string) ($currentParts['path'] ?? '/');
        $basePath = str_ends_with($currentPath, '/')
            ? $currentPath
            : substr($currentPath, 0, (int) strrpos($currentPath, '/') + 1);

        return $origin . $this->normalizeUrlPath($basePath . $location);
    }

    private function normalizeUrlPath(string $path): string
    {
        $suffix = '';
        $suffixPosition = strcspn($path, '?#');

        if ($suffixPosition < strlen($path)) {
            $suffix = substr($path, $suffixPosition);
            $path = substr($path, 0, $suffixPosition);
        }

        $segments = explode('/', $path);
        $normalizedSegments = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($normalizedSegments);

                continue;
            }

            $normalizedSegments[] = $segment;
        }

        return '/' . implode('/', $normalizedSegments) . $suffix;
    }

    private function resolveDefaultCookiePath(string $responseUrl): string
    {
        $appScopePath = $this->resolveAppCookieScopePath();
        if ($appScopePath !== '/') {
            return $appScopePath;
        }

        $parts = parse_url($responseUrl);
        if (!is_array($parts)) {
            return '/';
        }

        $path = trim((string) ($parts['path'] ?? '/'));
        if ($path === '' || $path === '/') {
            return '/';
        }

        $slashPosition = strrpos($path, '/');
        if ($slashPosition === false || $slashPosition === 0) {
            return '/';
        }

        return substr($path, 0, $slashPosition + 1);
    }

    private function resolveAppCookieScopePath(): string
    {
        $basePath = trim((string) parse_url($this->baseUrl, PHP_URL_PATH), '/');
        $indexPage = trim($this->indexPage, '/');
        $segments = [];

        if ($basePath !== '') {
            $segments[] = $basePath;
        }

        if ($indexPage !== '') {
            $segments[] = $indexPage;
        }

        if ($segments === []) {
            return '/';
        }

        return '/' . implode('/', $segments) . '/';
    }

    /**
     * @param array<string, mixed> $query
     */
    private function buildAppUrl(string $path, array $query = []): string
    {
        $base = rtrim($this->baseUrl, '/');
        $segments = [$base];

        $indexPage = trim($this->indexPage, '/');
        if ($indexPage !== '') {
            $segments[] = $indexPage;
        }

        $normalizedPath = trim($path, '/');
        if ($normalizedPath !== '') {
            $segments[] = $normalizedPath;
        }

        $url = implode('/', $segments);

        if ($query !== []) {
            $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            if ($queryString !== '') {
                $url .= '?' . $queryString;
            }
        }

        return $url;
    }
}
