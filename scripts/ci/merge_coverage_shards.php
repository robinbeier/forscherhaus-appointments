<?php

declare(strict_types=1);

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Report\Clover;

const COVERAGE_SHARD_MERGE_EXIT_SUCCESS = 0;
const COVERAGE_SHARD_MERGE_EXIT_RUNTIME_ERROR = 1;

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(runCoverageShardMergeCli($argv));
}

/**
 * @param array<int, string> $argv
 */
function runCoverageShardMergeCli(array $argv): int
{
    $config = coverageShardMergeDefaultConfig();
    $report = [
        'status' => 'error',
        'timestamp_utc' => gmdate('c'),
        'inputs' => [],
        'output_clover' => $config['output_clover'],
    ];
    $exitCode = COVERAGE_SHARD_MERGE_EXIT_RUNTIME_ERROR;

    try {
        parseCoverageShardMergeCliOptions($argv, $config);

        if ($config['help'] === true) {
            fwrite(STDOUT, coverageShardMergeUsage());

            return COVERAGE_SHARD_MERGE_EXIT_SUCCESS;
        }

        if (!is_file($config['autoload'])) {
            throw new RuntimeException('Missing Composer autoload file: ' . $config['autoload']);
        }

        require_once $config['autoload'];

        if (count($config['inputs']) < 2) {
            throw new RuntimeException('At least two --input=PATH options are required for coverage merge.');
        }

        $mergedCoverage = null;

        foreach ($config['inputs'] as $input) {
            $coverage = loadCoverageShardObject($input);

            if ($mergedCoverage === null) {
                $mergedCoverage = $coverage;
                continue;
            }

            $mergedCoverage->merge($coverage);
        }

        if (!$mergedCoverage instanceof CodeCoverage) {
            throw new RuntimeException('Failed to initialize merged coverage object.');
        }

        ensureParentDirectoryExists($config['output_clover']);
        (new Clover())->process($mergedCoverage, $config['output_clover']);

        $report = [
            'status' => 'pass',
            'timestamp_utc' => gmdate('c'),
            'inputs' => $config['inputs'],
            'output_clover' => $config['output_clover'],
            'files_merged' => count($config['inputs']),
        ];

        fwrite(STDOUT, '[PASS] coverage-shard-merge merged ' . count($config['inputs']) . ' inputs.' . PHP_EOL);
        $exitCode = COVERAGE_SHARD_MERGE_EXIT_SUCCESS;
    } catch (Throwable $e) {
        $report['status'] = 'error';
        $report['error'] = [
            'message' => $e->getMessage(),
            'exception' => get_class($e),
        ];

        fwrite(STDERR, '[ERROR] coverage-shard-merge failed: ' . $e->getMessage() . PHP_EOL);
        $exitCode = COVERAGE_SHARD_MERGE_EXIT_RUNTIME_ERROR;
    }

    if ($config['output_json'] !== '') {
        try {
            ensureParentDirectoryExists($config['output_json']);
            $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            if ($encoded === false) {
                throw new RuntimeException('Failed to encode coverage shard merge report as JSON.');
            }

            if (file_put_contents($config['output_json'], $encoded . PHP_EOL) === false) {
                throw new RuntimeException('Failed to write coverage shard merge report: ' . $config['output_json']);
            }

            fwrite(STDOUT, '[INFO] Report: ' . $config['output_json'] . PHP_EOL);
        } catch (Throwable $e) {
            fwrite(STDERR, '[WARN] Failed to write coverage shard merge report: ' . $e->getMessage() . PHP_EOL);

            if ($exitCode === COVERAGE_SHARD_MERGE_EXIT_SUCCESS) {
                $exitCode = COVERAGE_SHARD_MERGE_EXIT_RUNTIME_ERROR;
            }
        }
    }

    return $exitCode;
}

function coverageShardMergeUsage(): string
{
    return implode(PHP_EOL, [
        'Usage: php scripts/ci/merge_coverage_shards.php [options]',
        '',
        'Options:',
        '  --input=PATH          Coverage shard .phpcov input file (repeatable, min 2).',
        '  --output-clover=PATH  Output Clover XML file path.',
        '  --output-json=PATH    Optional JSON report path.',
        '  --help                Show this help text.',
        '',
    ]);
}

/**
 * @return array{
 *     inputs:array<int, string>,
 *     output_clover:string,
 *     output_json:string,
 *     autoload:string,
 *     help:bool
 * }
 */
function coverageShardMergeDefaultConfig(): array
{
    $root = dirname(__DIR__, 2);

    return [
        'inputs' => [],
        'output_clover' => $root . '/storage/logs/ci/coverage-unit-clover.xml',
        'output_json' => $root . '/storage/logs/ci/coverage-merge-latest.json',
        'autoload' => $root . '/vendor/autoload.php',
        'help' => false,
    ];
}

/**
 * @param array<int, string> $argv
 * @param array{
 *     inputs:array<int, string>,
 *     output_clover:string,
 *     output_json:string,
 *     autoload:string,
 *     help:bool
 * } $config
 */
function parseCoverageShardMergeCliOptions(array $argv, array &$config): void
{
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help') {
            $config['help'] = true;
            continue;
        }

        if (str_starts_with($arg, '--input=')) {
            $value = substr($arg, strlen('--input='));
            if ($value === '') {
                throw new RuntimeException('CLI option --input requires a non-empty value.');
            }
            $config['inputs'][] = $value;
            continue;
        }

        if (str_starts_with($arg, '--output-clover=')) {
            $value = substr($arg, strlen('--output-clover='));
            if ($value === '') {
                throw new RuntimeException('CLI option --output-clover requires a non-empty value.');
            }
            $config['output_clover'] = $value;
            continue;
        }

        if (str_starts_with($arg, '--output-json=')) {
            $value = substr($arg, strlen('--output-json='));
            if ($value === '') {
                throw new RuntimeException('CLI option --output-json requires a non-empty value.');
            }
            $config['output_json'] = $value;
            continue;
        }

        throw new RuntimeException('Unknown CLI option: ' . $arg);
    }
}

function loadCoverageShardObject(string $path): CodeCoverage
{
    if (!is_file($path)) {
        throw new RuntimeException('Missing coverage shard file: ' . $path);
    }

    $payload = file_get_contents($path);

    if ($payload === false || $payload === '') {
        throw new RuntimeException('Failed to read coverage shard file: ' . $path);
    }

    if (str_starts_with(ltrim($payload), '<?php')) {
        $serialized = extractSerializedCoveragePayload($payload, $path);
        $coverage = @unserialize($serialized, ['allowed_classes' => true]);
    } else {
        $coverage = @unserialize($payload, ['allowed_classes' => true]);
    }

    if (!$coverage instanceof CodeCoverage) {
        throw new RuntimeException('Invalid coverage shard payload: ' . $path);
    }

    return $coverage;
}

function extractSerializedCoveragePayload(string $payload, string $path): string
{
    $matches = [];
    $matched = preg_match(
        "/^<\\?php\\s+return\\s+\\\\?unserialize\\(<<<'END_OF_COVERAGE_SERIALIZATION'\\R(.*)\\REND_OF_COVERAGE_SERIALIZATION\\R\\);\\s*$/s",
        $payload,
        $matches,
    );

    if ($matched !== 1 || !isset($matches[1]) || $matches[1] === '') {
        throw new RuntimeException('Unsupported PHP coverage shard format: ' . $path);
    }

    return $matches[1];
}

function ensureParentDirectoryExists(string $filePath): void
{
    $directory = dirname($filePath);

    if ($directory === '' || $directory === '.') {
        return;
    }

    if (is_dir($directory)) {
        return;
    }

    if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Failed to create directory: ' . $directory);
    }
}
