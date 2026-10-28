<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$environment = $argv[1] ?? 'production';
$mode = $argv[2] ?? 'http';

define('BASEPATH', $root . '/system/');
define('APPPATH', $root . '/application/');
define('VIEWPATH', APPPATH . 'views/');
define('ENVIRONMENT', $environment);

function is_cli(): bool
{
    return ($GLOBALS['probe_mode'] ?? 'http') === 'cli';
}

function config_item($key)
{
    return null;
}

function set_status_header($code): void
{
    $GLOBALS['probe_status'] = $code;
}

$GLOBALS['probe_mode'] = $mode;
require_once BASEPATH . 'core/Exceptions.php';
require_once APPPATH . 'core/EA_Exceptions.php';

$exceptions = new EA_Exceptions();
$db = $exceptions->show_error('Sensitive heading', 'Sensitive SQL and connection details', 'error_db', 500);
$other = $exceptions->show_error('Other heading', 'Other message', 'error_general', 418);

echo json_encode(
    [
        'db' => $db,
        'other' => $other,
        'status' => $GLOBALS['probe_status'] ?? null,
    ],
    JSON_THROW_ON_ERROR,
);
