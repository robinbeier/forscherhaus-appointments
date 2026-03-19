<?php

declare(strict_types=1);

use ReleaseGate\ZeroSurpriseStageConfigPreparer;

require_once __DIR__ . '/lib/ZeroSurpriseStageConfigPreparer.php';

$options = getopt('', ['config:', 'base-url:', 'help']);

if (isset($options['help'])) {
    fwrite(
        STDOUT,
        "Usage: php scripts/release-gate/prepare_zero_surprise_stage_config.php --config=PATH --base-url=URL\n",
    );
    exit(0);
}

$configPath = $options['config'] ?? null;
$baseUrl = $options['base-url'] ?? null;

if (!is_string($configPath) || trim($configPath) === '') {
    fwrite(STDERR, "Missing required option: --config\n");
    exit(1);
}

if (!is_string($baseUrl) || trim($baseUrl) === '') {
    fwrite(STDERR, "Missing required option: --base-url\n");
    exit(1);
}

try {
    ZeroSurpriseStageConfigPreparer::prepare($configPath, $baseUrl);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
