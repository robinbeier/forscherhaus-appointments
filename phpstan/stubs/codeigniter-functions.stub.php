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
function session(string $key = null, mixed $value = null)
{
}

/**
 * @return mixed
 */
function env(string $key, mixed $default = null)
{
}
