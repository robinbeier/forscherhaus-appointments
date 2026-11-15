<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.3.0
 * ---------------------------------------------------------------------------- */

/**
 * Assets URL helper function.
 *
 * This function will create an asset file URL that includes a cache busting parameter in order
 * to invalidate the browser cache in case of an update.
 *
 * @param string $uri Relative URI (just like the one used in the base_url helper).
 * @param string|null $protocol Valid URI protocol.
 *
 * @return string Returns the final asset URL.
 */
function asset_url(string $uri = '', ?string $protocol = null): string
{
    $debug = config('debug');

    $cache_busting_token = '?' . config('cache_busting_token');

    if (str_contains(basename($uri), '.js') && !str_contains(basename($uri), '.min.js') && !$debug) {
        $uri = str_replace('.js', '.min.js', $uri);
    }

    if (str_contains(basename($uri), '.css') && !str_contains(basename($uri), '.min.css') && !$debug) {
        $uri = str_replace('.css', '.min.css', $uri);
    }

    return base_url($uri . $cache_busting_token, $protocol);
}

/**
 * Check whether a theme value names a stylesheet shipped by this install.
 */
function is_valid_theme_slug(mixed $theme): bool
{
    if (!is_string($theme) || !preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/', $theme)) {
        return false;
    }

    $theme_directory = defined('FCPATH') ? FCPATH . 'assets/css/themes' : dirname(__DIR__, 2) . '/assets/css/themes';

    $compiled_files = array_merge(glob($theme_directory . '/*.css') ?: [], glob($theme_directory . '/*.min.css') ?: []);

    foreach ($compiled_files as $file) {
        $name = basename($file);
        $name = preg_replace('/\.min\.css$/', '', $name);
        $name = preg_replace('/\.(?:css|scss)$/', '', (string) $name);
        if ($name === $theme) {
            return true;
        }
    }

    // Source-only checkouts do not contain generated CSS; keep the built-in
    // default safe and deterministic until the frontend build runs.
    if ($theme === 'default' && $compiled_files === []) {
        return true;
    }

    return false;
}

/**
 * Return a safe stylesheet URL for the configured application theme.
 */
function theme_asset_url(mixed $theme, ?string $protocol = null): string
{
    $theme = is_valid_theme_slug($theme) ? $theme : 'default';

    return asset_url('assets/css/themes/' . $theme . '.css', $protocol);
}
