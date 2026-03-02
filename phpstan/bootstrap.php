<?php

declare(strict_types=1);

$repoRoot = dirname(__DIR__);

if (!defined('BASEPATH')) {
    define('BASEPATH', $repoRoot . '/system/');
}

if (!defined('FCPATH')) {
    define('FCPATH', $repoRoot . '/');
}

if (!defined('APPPATH')) {
    define('APPPATH', $repoRoot . '/application/');
}

if (!defined('ENVIRONMENT')) {
    define('ENVIRONMENT', 'testing');
}

if (!defined('UTF8_ENABLED')) {
    define('UTF8_ENABLED', true);
}

require_once $repoRoot . '/vendor/autoload.php';
require_once $repoRoot . '/config-sample.php';
require_once $repoRoot . '/system/core/Common.php';
require_once $repoRoot . '/application/config/constants.php';

if (!class_exists('JetBrains\\PhpStorm\\NoReturn')) {
    eval('namespace JetBrains\\PhpStorm; #[\\Attribute(\\Attribute::TARGET_ALL)] final class NoReturn {}');
}
