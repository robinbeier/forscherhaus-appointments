<?php

declare(strict_types=1);

namespace ReleaseGate;

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
        $setCookies = [];

        $headerFn = function ($ch, string $headerLine) use (&$headers, &$setCookies, $consumeResponseCookies): int {
            $trimmed = trim($headerLine);

            if ($trimmed === '') {
                return strlen($headerLine);
            }

            if (str_starts_with($trimmed, 'HTTP/')) {
                $headers = [];

                return strlen($headerLine);
            }

            $parts = explode(':', $trimmed, 2);

            if (count($parts) !== 2) {
                return strlen($headerLine);
            }

            $name = strtolower(trim($parts[0]));
            $value = trim($parts[1]);

            $headers[$name] ??= [];
            $headers[$name][] = $value;

            if ($consumeResponseCookies && $name === 'set-cookie') {
                $setCookies[] = $value;
            }

            return strlen($headerLine);
        };

        $requestHeaders = ['Accept: */*'];

        if ($useCookieJar) {
            $cookieHeader = $this->buildCookieHeader();
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

        if ($consumeResponseCookies) {
            $this->consumeSetCookies($setCookies);
        }

        return new GateHttpResponse(
            $statusCode,
            $headers,
            (string) $body,
            round($durationMs, 2),
            $effectiveUrl !== '' ? $effectiveUrl : $url,
        );
    }

    /**
     * @return string|null
     */
    private function buildCookieHeader(): ?string
    {
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
     * @param string[] $setCookies
     */
    private function consumeSetCookies(array $setCookies): void
    {
        foreach ($setCookies as $setCookie) {
            $pair = explode(';', $setCookie, 2)[0] ?? '';

            if ($pair === '' || !str_contains($pair, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $pair, 2);
            $name = trim($name);

            if ($name === '') {
                continue;
            }

            $this->cookies[$name] = trim($value);
        }
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
