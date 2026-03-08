<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$originalArgv = $_SERVER['argv'] ?? null;
$originalArgc = $_SERVER['argc'] ?? null;

// PHPUnit 10 loads the bootstrap before consuming CLI options, so CodeIgniter
// would otherwise treat flags like --filter as URI segments.
$_SERVER['argv'] = [$_SERVER['argv'][0] ?? 'phpunit', 'healthz'];
$_SERVER['argc'] = count($_SERVER['argv']);
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/healthz';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';

ob_start();
require_once $root . '/index.php';
$bootstrapOutput = ob_get_clean();

if ($originalArgv !== null) {
    $_SERVER['argv'] = $originalArgv;
} else {
    unset($_SERVER['argv']);
}

if ($originalArgc !== null) {
    $_SERVER['argc'] = $originalArgc;
} else {
    unset($_SERVER['argc']);
}

$CI = &get_instance();

if (isset($CI->output)) {
    $CI->output->set_output('');
}

unset($bootstrapOutput);
