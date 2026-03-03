<?php

declare(strict_types=1);

$repo_root = dirname(__DIR__);

if (!defined('BASEPATH')) {
    define('BASEPATH', $repo_root . '/system/');
}

if (!defined('APPPATH')) {
    define('APPPATH', $repo_root . '/application/');
}

if (!defined('FCPATH')) {
    define('FCPATH', $repo_root . '/');
}

if (!defined('ANY_PROVIDER')) {
    define('ANY_PROVIDER', 'any-provider');
}

if (!function_exists('lang')) {
    function lang(string $line): string
    {
        return $line;
    }
}
