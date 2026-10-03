<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Open Source Web Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) 2013 - 2020, Alex Tselegidis
 * @license     http://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        http://easyappointments.org
 * @since       v1.1.0
 * ---------------------------------------------------------------------------- */

if (!function_exists('rate_limit')) {
    function rate_limit_fail_closed(): void
    {
        header('HTTP/1.0 503 Service Unavailable');
        exit();
    }

    function rate_limit_is_local_loopback_request(string $ip, ?string $host = null): bool
    {
        $normalizedIp = trim($ip);

        if (!in_array($normalizedIp, ['127.0.0.1', '::1'], true)) {
            return false;
        }

        $normalizedHost = strtolower(trim((string) $host));

        if (in_array($normalizedHost, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        if (preg_match('/^\[(.+)\](?::\d+)?$/', $normalizedHost, $matches) === 1) {
            $normalizedHost = $matches[1];
        } else {
            $normalizedHost = preg_replace('/:\d+$/', '', $normalizedHost) ?? $normalizedHost;
        }

        return in_array($normalizedHost, ['localhost', '127.0.0.1', '::1'], true);
    }

    /**
     * Rate-limit the application requests.
     *
     * Example:
     *
     * rate_limit($CI->input->ip_address(), 100, 300);
     *
     * @link https://github.com/alexandrugaidei-atomate/ratelimit-codeigniter-filebased
     *
     * @param string $ip Client IP address.
     * @param int $max_requests Number of allowed requests, defaults to 100.
     * @param int $duration In seconds, defaults to 2 minutes.
     */
    function rate_limit(string $ip, int $max_requests = 100, int $duration = 120): void
    {
        /** @var EA_Controller $CI */
        $CI = &get_instance();

        $rate_limiting = $CI->config->item('rate_limiting');

        if (
            !$rate_limiting ||
            is_cli() ||
            rate_limit_is_local_loopback_request($ip, $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? null))
        ) {
            return;
        }

        $CI->load->driver('cache', ['adapter' => 'file']);

        $cache_path = (string) $CI->config->item('cache_path');
        if ($cache_path === '') {
            $cache_path = APPPATH . 'cache' . DIRECTORY_SEPARATOR;
        }
        $cache_path = rtrim($cache_path, DIRECTORY_SEPARATOR);
        $bucket = hexdec(substr(hash('sha256', $ip), 0, 8)) % 64;
        $lock_path = dirname($cache_path) . DIRECTORY_SEPARATOR . sprintf('rate_limit.lock.%02x', $bucket);
        $lock_handle = @fopen($lock_path, 'c');

        if ($lock_handle === false) {
            rate_limit_fail_closed();
        }

        $lock_deadline = microtime(true) + 2;
        $lock_acquired = false;
        do {
            if (@flock($lock_handle, LOCK_EX | LOCK_NB)) {
                $lock_acquired = true;
                break;
            }

            usleep(5000);
        } while (microtime(true) < $lock_deadline);

        if (!$lock_acquired) {
            @fclose($lock_handle);
            rate_limit_fail_closed();
        }

        $cache_key = str_replace(':', '', 'rate_limit_key_' . $ip);

        $cache_remain_time_key = str_replace(':', '', 'rate_limit_tmp_' . $ip);

        $current_time = date('Y-m-d H:i:s');

        $requests = $CI->cache->get($cache_key);

        if ($requests === false) {
            $requests = 1;
            $current_time_plus = date('Y-m-d H:i:s', strtotime('+' . $duration . ' seconds'));

            if (
                !$CI->cache->save($cache_key, $requests, $duration) ||
                !$CI->cache->save($cache_remain_time_key, $current_time_plus, $duration * 2)
            ) {
                @flock($lock_handle, LOCK_UN);
                fclose($lock_handle);
                rate_limit_fail_closed();
            }
        } else {
            $time_lost = $CI->cache->get($cache_remain_time_key);

            if (!is_string($time_lost) || strtotime($time_lost) === false) {
                @flock($lock_handle, LOCK_UN);
                fclose($lock_handle);
                rate_limit_fail_closed();
            }

            if ($current_time > $time_lost) {
                $requests = 1;
                $current_time_plus = date('Y-m-d H:i:s', strtotime('+' . $duration . ' seconds'));

                if (
                    !$CI->cache->save($cache_key, $requests, $duration) ||
                    !$CI->cache->save($cache_remain_time_key, $current_time_plus, $duration * 2)
                ) {
                    @flock($lock_handle, LOCK_UN);
                    fclose($lock_handle);
                    rate_limit_fail_closed();
                }
            } else {
                $requests++;

                if (!$CI->cache->save($cache_key, $requests, $duration)) {
                    @flock($lock_handle, LOCK_UN);
                    fclose($lock_handle);
                    rate_limit_fail_closed();
                }
            }
        }

        $exceeded = $requests > $max_requests;

        @flock($lock_handle, LOCK_UN);
        fclose($lock_handle);

        if ($exceeded) {
            header('HTTP/1.0 429 Too Many Requests');
            exit();
        }
    }
}
