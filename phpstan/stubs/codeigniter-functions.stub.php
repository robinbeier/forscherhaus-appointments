<?php

/**
 * @return object
 */
function get_instance()
{
}

/**
 * @return mixed
 */
function config_item(string $item = '')
{
}

function log_message(string $level, string $message): void
{
}

function is_cli(): bool
{
    return true;
}

/**
 * @return string
 */
function site_url($uri = '', ?string $protocol = null)
{
}

/**
 * @return string
 */
function base_url($uri = '', ?string $protocol = null)
{
}

/**
 * @return string
 */
function current_url()
{
}

function redirect(string $uri = '', string $method = 'auto', ?int $code = null): void
{
}

function can(string $action, string $resource, ?int $user_id = null): bool
{
    return true;
}

function cannot(string $action, string $resource, ?int $user_id = null): bool
{
    return false;
}

/**
 * @param mixed $var
 *
 * @return mixed
 */
function html_escape($var)
{
}

function show_error(string $message, int $status_code = 500, string $heading = 'An Error Was Encountered'): void
{
}

/**
 * @return mixed
 */
function request(?string $key = null, mixed $default = null)
{
}

function response(string $content = '', int $status = 200, array $headers = []): void
{
}

function json_response(mixed $content = [], int $status = 200, array $headers = []): void
{
}

function json_exception(Throwable $e): void
{
}

function abort(int $code, string $message = '', array $headers = []): void
{
}

/**
 * @return mixed
 */
function script_vars(array|string|null $key = null, mixed $default = null)
{
}

/**
 * @return mixed
 */
function html_vars(array|string|null $key = null, mixed $default = null)
{
}

function format_date(DateTimeInterface|string $value): string
{
    return '';
}

function format_time(DateTimeInterface|string $value): string
{
    return '';
}

function build_google_calendar_link(array $event): string
{
    return '';
}

function build_outlook_calendar_link(array $event): string
{
    return '';
}

function lang(string $line): string
{
    return '';
}

/**
 * @return mixed
 */
function setting(string $name, mixed $default = null)
{
}

/**
 * @return mixed
 */
function config(string $key, mixed $default = null)
{
}

/**
 * @return mixed
 */
function session(?string $key = null, mixed $value = null)
{
}

/**
 * @return mixed
 */
function env(string $key, mixed $default = null)
{
}
