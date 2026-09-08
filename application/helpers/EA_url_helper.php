<?php defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Build a public link for messages and shared appointment details.
 *
 * The installation's configured origin is trusted; the current request host is not.
 * Keep the normal route formatting (index page, suffix and query strings) while
 * replacing only the request-derived installation prefix.
 */
function public_site_url(string $uri): string
{
    $request_base = rtrim((string) config('base_url'), '/') . '/';
    $route = substr(site_url($uri), strlen($request_base));

    return rtrim(Config::BASE_URL, '/') . '/' . $route;
}
