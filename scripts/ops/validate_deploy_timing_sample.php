<?php
declare(strict_types=1);

namespace Ops;

use JsonException;
use RuntimeException;

require_once __DIR__ . '/lib/DeployTimingSampleValidator.php';

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $file = null;
    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--file=')) {
            $file = substr($argument, strlen('--file='));
            continue;
        }
        fwrite(STDERR, "Usage: php scripts/ops/validate_deploy_timing_sample.php --file=/absolute/path.jsonl\n");
        exit(2);
    }
    if (!is_string($file) || $file === '') {
        fwrite(STDERR, "Usage: php scripts/ops/validate_deploy_timing_sample.php --file=/absolute/path.jsonl\n");
        exit(2);
    }
    try {
        $result = DeployTimingSampleValidator::validateFile($file);
        fwrite(
            STDOUT,
            json_encode(
                [
                    'schema' => 'deploy_timing_validation.v1',
                    'valid' => true,
                    'run_id' => $result['run_id'],
                    'records' => $result['records'],
                    'total_ms' => $result['total_ms'],
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ) . PHP_EOL,
        );
        exit(0);
    } catch (RuntimeException | JsonException $exception) {
        fwrite(STDERR, 'INVALID: ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}
