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
}
