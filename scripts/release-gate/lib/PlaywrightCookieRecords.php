<?php

declare(strict_types=1);

namespace ReleaseGate {
    /**
     * @param array<int, array<string, mixed>> $cookieRecords
     * @return array<int, array<string, mixed>>
     */
    function normalizeCookieRecordsForPlaywright(array $cookieRecords, string $targetUrl): array
    {
        $normalizedCookies = [];
        $fallbackCookieUrl = resolvePlaywrightCookieUrl($targetUrl);
        $targetHost = (string) (parse_url($fallbackCookieUrl, PHP_URL_HOST) ?? '');

        foreach ($cookieRecords as $cookie) {
            $normalizedName = trim((string) ($cookie['name'] ?? ''));
            $normalizedValue = trim((string) ($cookie['value'] ?? ''));

            if ($normalizedName === '' || $normalizedValue === '') {
                continue;
            }

            $record = [
                'name' => $normalizedName,
                'value' => $normalizedValue,
            ];

            $normalizedUrl = trim((string) ($cookie['url'] ?? ''));
            $normalizedDomain = trim((string) ($cookie['domain'] ?? ''));
            $normalizedPath = trim((string) ($cookie['path'] ?? ''));
            $normalizedSameSite = trim((string) ($cookie['sameSite'] ?? ''));

            if ($normalizedUrl !== '') {
                $record['url'] = $normalizedUrl;
            } elseif ($normalizedDomain !== '') {
                $record['domain'] = $normalizedDomain;
                $record['path'] = $normalizedPath !== '' ? $normalizedPath : '/';
            } elseif ($normalizedPath !== '') {
                $record['url'] = resolvePlaywrightCookieUrl($targetUrl, $normalizedPath);
            } elseif ($targetHost !== '') {
                $record['url'] = $fallbackCookieUrl;
            }

            if ($normalizedSameSite !== '') {
                $record['sameSite'] = $normalizedSameSite;
            }

            foreach (['secure', 'httpOnly'] as $field) {
                if (array_key_exists($field, $cookie)) {
                    $record[$field] = (bool) $cookie[$field];
                }
            }

            $normalizedCookies[] = $record;
        }

        return $normalizedCookies;
    }

    function resolvePlaywrightCookieUrl(string $targetUrl, string $path = '/'): string
    {
        $parts = parse_url($targetUrl);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $targetUrl;
        }

        $port = isset($parts['port']) ? ':' . (string) $parts['port'] : '';
        $basePath = trim((string) ($parts['path'] ?? '/'), '/');

        if ($basePath !== '') {
            $segments = explode('/', $basePath);
            array_pop($segments);
            $basePath = implode('/', $segments);
        }

        $normalizedPath = trim($path);
        if ($normalizedPath === '') {
            $normalizedPath = '/';
        } elseif ($normalizedPath[0] !== '/') {
            $normalizedPath = '/' . ltrim($normalizedPath, '/');
        }

        if ($normalizedPath === '/' && $basePath !== '') {
            $normalizedPath = '/' . $basePath . '/';
        }

        return $parts['scheme'] . '://' . $parts['host'] . $port . $normalizedPath;
    }
}

namespace {
    /**
     * @param array<int, array<string, mixed>> $cookieRecords
     * @return array<int, array<string, mixed>>
     */
    function normalizeCookieRecordsForPlaywright(array $cookieRecords, string $targetUrl): array
    {
        return \ReleaseGate\normalizeCookieRecordsForPlaywright($cookieRecords, $targetUrl);
    }

    function resolvePlaywrightCookieUrl(string $targetUrl, string $path = '/'): string
    {
        return \ReleaseGate\resolvePlaywrightCookieUrl($targetUrl, $path);
    }
}
