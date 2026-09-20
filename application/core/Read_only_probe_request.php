<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Identifies only the two anonymous routes used by the local read-only probe.
 * This accepts only loopback clients, loopback hosts, GET, and exact routes.
 */
final class Read_only_probe_request
{
    public static function is(): bool
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'GET') {
            return false;
        }

        if (!in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true)) {
            return false;
        }

        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        if (!in_array(trim($host, '[]'), ['127.0.0.1', '::1', 'localhost'], true)) {
            return false;
        }

        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (!is_string($path)) {
            return false;
        }

        $path = '/' . ltrim($path, '/');
        $route = preg_replace('#^/index\.php(?=/|$)#', '', $path);
        if (!is_string($route)) {
            return false;
        }

        return preg_match('#^/(?:booking_confirmation/of|appointments/ics)/(?:[0-9a-f]{12}|[0-9a-f]{64})$#', $route) ===
            1;
    }

    /** @return array{root:string,release:string} */
    public static function binding(): array
    {
        if (!self::is()) {
            return ['root' => '', 'release' => ''];
        }

        $root = realpath(APPPATH . '..');
        if ($root === false || is_link(APPPATH . '..')) {
            return ['root' => '', 'release' => ''];
        }
        $stat = @stat($root);
        $identity = is_array($stat) && isset($stat['dev'], $stat['ino']) ? $stat['dev'] . ':' . $stat['ino'] : '';
        $release = 'unreleased';
        $marker = $root . '/_RELEASE';
        if (is_file($marker) && !is_link($marker)) {
            $firstToken = explode(' ', trim((string) @file_get_contents($marker)), 2)[0] ?? '';
            if (preg_match('/^ea_[A-Za-z0-9_]+$/', $firstToken) === 1) {
                $release = $firstToken;
            }
        }

        return ['root' => $identity === '' ? '' : 'dev:' . $identity, 'release' => $release];
    }
}
