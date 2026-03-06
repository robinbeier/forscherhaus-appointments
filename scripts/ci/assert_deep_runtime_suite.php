<?php

declare(strict_types=1);

const ASSERT_DEEP_RUNTIME_SUITE_EXIT_SUCCESS = 0;
const ASSERT_DEEP_RUNTIME_SUITE_EXIT_FAILURE = 1;

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(runAssertDeepRuntimeSuiteCli($argv));
}

/**
 * @param array<int, string> $argv
 */
function runAssertDeepRuntimeSuiteCli(array $argv): int
{
    try {
        $config = parseAssertDeepRuntimeSuiteCliOptions($argv);

        if ($config['help'] === true) {
            fwrite(STDOUT, assertDeepRuntimeSuiteUsage());

            return ASSERT_DEEP_RUNTIME_SUITE_EXIT_SUCCESS;
        }

        $manifest = loadDeepRuntimeSuiteManifest($config['manifest']);
        assertDeepRuntimeSuiteResult($manifest, $config['suite']);

        $suite = $manifest['suites'][$config['suite']];
        fwrite(
            STDOUT,
            sprintf(
                '[PASS] deep-runtime verdict %s (%ss)%s',
                $config['suite'],
                (string) ($suite['duration_seconds'] ?? '0'),
                PHP_EOL,
            ),
        );

        return ASSERT_DEEP_RUNTIME_SUITE_EXIT_SUCCESS;
    } catch (Throwable $e) {
        fwrite(STDERR, '[FAIL] deep-runtime verdict failed: ' . $e->getMessage() . PHP_EOL);

        return ASSERT_DEEP_RUNTIME_SUITE_EXIT_FAILURE;
    }
}

function assertDeepRuntimeSuiteUsage(): string
{
    return implode(PHP_EOL, [
        'Usage: php scripts/ci/assert_deep_runtime_suite.php [options]',
        '',
        'Options:',
        '  --manifest=PATH   Path to deep runtime suite manifest JSON.',
        '  --suite=ID        Suite ID to assert.',
        '  --help            Show this help text.',
        '',
    ]);
}

/**
 * @param array<int, string> $argv
 * @return array{manifest:string,suite:string,help:bool}
 */
function parseAssertDeepRuntimeSuiteCliOptions(array $argv): array
{
    $config = [
        'manifest' => dirname(__DIR__, 2) . '/storage/logs/ci/deep-runtime-suite/manifest.json',
        'suite' => '',
        'help' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help') {
            $config['help'] = true;
            continue;
        }

        if (str_starts_with($arg, '--manifest=')) {
            $config['manifest'] = requireNonEmptyAssertCliValue($arg, '--manifest');
            continue;
        }

        if (str_starts_with($arg, '--suite=')) {
            $config['suite'] = requireNonEmptyAssertCliValue($arg, '--suite');
            continue;
        }

        throw new RuntimeException('Unknown CLI option: ' . $arg);
    }

    if ($config['help'] === false && $config['suite'] === '') {
        throw new RuntimeException('CLI option --suite is required.');
    }

    return $config;
}

function requireNonEmptyAssertCliValue(string $arg, string $option): string
{
    $value = substr($arg, strlen($option . '='));

    if ($value === '') {
        throw new RuntimeException('CLI option ' . $option . ' requires a non-empty value.');
    }

    return $value;
}

/**
 * @return array<string, mixed>
 */
function loadDeepRuntimeSuiteManifest(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Deep runtime suite manifest not found: ' . $path);
    }

    $content = file_get_contents($path);

    if ($content === false || $content === '') {
        throw new RuntimeException('Failed to read deep runtime suite manifest: ' . $path);
    }

    $decoded = json_decode($content, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('Deep runtime suite manifest is not valid JSON: ' . $path);
    }

    return $decoded;
}

/**
 * @param array<string, mixed> $manifest
 */
function assertDeepRuntimeSuiteResult(array $manifest, string $suiteId): void
{
    $suites = $manifest['suites'] ?? null;

    if (!is_array($suites)) {
        throw new RuntimeException('Deep runtime suite manifest is missing the suites map.');
    }

    if (!array_key_exists($suiteId, $suites) || !is_array($suites[$suiteId])) {
        throw new RuntimeException('Deep runtime suite manifest does not contain suite "' . $suiteId . '".');
    }

    $suite = $suites[$suiteId];
    $status = (string) ($suite['status'] ?? '');

    if ($status !== 'pass') {
        $detailPath = trim((string) ($suite['report_path'] ?? '')) ?: trim((string) ($suite['log_path'] ?? ''));
        $message = 'Suite "' . $suiteId . '" finished with status "' . $status . '".';

        if ($detailPath !== '') {
            $message .= ' See ' . $detailPath . '.';
        }

        throw new RuntimeException($message);
    }
}
