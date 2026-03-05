<?php

$root = dirname(__DIR__);

if (!defined('FCPATH')) {
    define('FCPATH', $root . '/');
}

if (!defined('SELF')) {
    define('SELF', 'phpunit');
}

if (!defined('BASEPATH')) {
    define('BASEPATH', $root . '/system/');
}

if (!defined('APPPATH')) {
    define('APPPATH', $root . '/application/');
}

if (!defined('VIEWPATH')) {
    define('VIEWPATH', APPPATH . 'views/');
}

if (!defined('ENVIRONMENT')) {
    define('ENVIRONMENT', getenv('APP_ENV') ?: 'testing');
}

$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';

require_once $root . '/vendor/autoload.php';
require_once APPPATH . 'config/constants.php';

if (!function_exists('lang')) {
    function lang(string $line, string $for = '', array $attributes = []): string
    {
        if ($for !== '') {
            return '<label for="' . $for . '">' . $line . '</label>';
        }

        return $line;
    }
}
