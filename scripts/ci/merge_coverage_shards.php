<?php

declare(strict_types=1);

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

        if (count($config['inputs']) < 2) {
            throw new RuntimeException('At least two --input=PATH options are required for coverage merge.');
        }

        $mergedLineCoverage = [];

        foreach ($config['inputs'] as $input) {
            $lineCoverage = loadCoverageShardLineCoverage($input);
            mergeLineCoverage($mergedLineCoverage, $lineCoverage);
        }

        $metrics = calculateMergedMetrics($mergedLineCoverage);

        if ($metrics['statements'] <= 0) {
            throw new RuntimeException('Merged coverage does not contain any executable statements.');
        }

        ensureParentDirectoryExists($config['output_clover']);
        writeMergedCloverFile($config['output_clover'], $mergedLineCoverage, $metrics);

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
        '  --input=PATH          Coverage shard Clover XML input file (repeatable, min 2).',
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
        'help' => false,
    ];
}

/**
 * @param array<int, string> $argv
 * @param array{
 *     inputs:array<int, string>,
 *     output_clover:string,
 *     output_json:string,
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

/**
 * @return array<string, array<int, int>>
 */
function loadCoverageShardLineCoverage(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Missing coverage shard input file: ' . $path);
    }

    $content = file_get_contents($path);

    if ($content === false || $content === '') {
        throw new RuntimeException('Failed to read coverage shard input file: ' . $path);
    }

    $xml = parseCloverXml($content, $path);
    $lineCoverage = [];

    $fileNodes = $xml->xpath('//file');
    if (!is_array($fileNodes)) {
        throw new RuntimeException('Clover input does not expose file nodes: ' . $path);
    }

    foreach ($fileNodes as $fileNode) {
        if (!$fileNode instanceof SimpleXMLElement) {
            continue;
        }

        $filePath = trim((string) ($fileNode['name'] ?? ''));
        if ($filePath === '') {
            continue;
        }

        $lineNodes = $fileNode->xpath('./line');
        if (!is_array($lineNodes)) {
            continue;
        }

        foreach ($lineNodes as $lineNode) {
            if (!$lineNode instanceof SimpleXMLElement) {
                continue;
            }

            $type = (string) ($lineNode['type'] ?? '');
            if ($type !== 'stmt') {
                continue;
            }

            $lineNumberRaw = (string) ($lineNode['num'] ?? '');
            if (!ctype_digit($lineNumberRaw)) {
                continue;
            }

            $lineNumber = (int) $lineNumberRaw;

            if ($lineNumber <= 0) {
                continue;
            }

            $countRaw = (string) ($lineNode['count'] ?? '0');
            $executionCount = is_numeric($countRaw) ? (int) $countRaw : 0;
            $covered = $executionCount > 0 ? 1 : 0;

            $existing = $lineCoverage[$filePath][$lineNumber] ?? 0;
            $lineCoverage[$filePath][$lineNumber] = max($existing, $covered);
        }
    }

    if ($lineCoverage === []) {
        throw new RuntimeException('Clover input does not contain statement coverage lines: ' . $path);
    }

    return $lineCoverage;
}

function parseCloverXml(string $content, string $path): SimpleXMLElement
{
    $useInternalErrorsBefore = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $xml = simplexml_load_string($content);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($useInternalErrorsBefore);

    if ($xml === false) {
        $messages = array_map(static fn(LibXMLError $error): string => trim($error->message), $errors);
        $detail = $messages === [] ? 'unknown XML parser error' : implode('; ', $messages);
        throw new RuntimeException('Failed to parse Clover XML input "' . $path . '": ' . $detail);
    }

    return $xml;
}

/**
 * @param array<string, array<int, int>> $target
 * @param array<string, array<int, int>> $source
 */
function mergeLineCoverage(array &$target, array $source): void
{
    foreach ($source as $file => $lines) {
        foreach ($lines as $lineNumber => $covered) {
            $existing = $target[$file][$lineNumber] ?? 0;
            $target[$file][$lineNumber] = max($existing, $covered);
        }
    }
}

/**
 * @param array<string, array<int, int>> $lineCoverage
 * @return array{files:int,statements:int,covered_statements:int}
 */
function calculateMergedMetrics(array $lineCoverage): array
{
    $statementCount = 0;
    $coveredStatementCount = 0;

    foreach ($lineCoverage as $lines) {
        $statementCount += count($lines);

        foreach ($lines as $covered) {
            if ($covered > 0) {
                $coveredStatementCount++;
            }
        }
    }

    return [
        'files' => count($lineCoverage),
        'statements' => $statementCount,
        'covered_statements' => $coveredStatementCount,
    ];
}

/**
 * @param array<string, array<int, int>> $lineCoverage
 * @param array{files:int,statements:int,covered_statements:int} $metrics
 */
function writeMergedCloverFile(string $path, array $lineCoverage, array $metrics): void
{
    $timestamp = (string) time();

    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;

    $coverage = $dom->createElement('coverage');
    $coverage->setAttribute('generated', $timestamp);

    $project = $dom->createElement('project');
    $project->setAttribute('timestamp', $timestamp);

    ksort($lineCoverage);

    foreach ($lineCoverage as $filePath => $lines) {
        ksort($lines);

        $fileElement = $dom->createElement('file');
        $fileElement->setAttribute('name', $filePath);

        $fileStatementCount = count($lines);
        $fileCoveredStatementCount = 0;

        foreach ($lines as $lineNumber => $covered) {
            if ($covered > 0) {
                $fileCoveredStatementCount++;
            }

            $lineElement = $dom->createElement('line');
            $lineElement->setAttribute('num', (string) $lineNumber);
            $lineElement->setAttribute('type', 'stmt');
            $lineElement->setAttribute('count', (string) ($covered > 0 ? 1 : 0));

            $fileElement->appendChild($lineElement);
        }

        $fileMetrics = $dom->createElement('metrics');
        applyMetricsAttributes($fileMetrics, 1, $fileStatementCount, $fileCoveredStatementCount);
        $fileElement->appendChild($fileMetrics);

        $project->appendChild($fileElement);
    }

    $projectMetrics = $dom->createElement('metrics');
    applyMetricsAttributes($projectMetrics, $metrics['files'], $metrics['statements'], $metrics['covered_statements']);
    $project->appendChild($projectMetrics);

    $coverage->appendChild($project);
    $dom->appendChild($coverage);

    if ($dom->save($path) === false) {
        throw new RuntimeException('Failed to write merged Clover XML: ' . $path);
    }
}

function applyMetricsAttributes(DOMElement $metrics, int $files, int $statements, int $coveredStatements): void
{
    $metrics->setAttribute('files', (string) $files);
    $metrics->setAttribute('loc', '0');
    $metrics->setAttribute('ncloc', '0');
    $metrics->setAttribute('classes', '0');
    $metrics->setAttribute('methods', '0');
    $metrics->setAttribute('coveredmethods', '0');
    $metrics->setAttribute('conditionals', '0');
    $metrics->setAttribute('coveredconditionals', '0');
    $metrics->setAttribute('statements', (string) $statements);
    $metrics->setAttribute('coveredstatements', (string) $coveredStatements);
    $metrics->setAttribute('elements', (string) $statements);
    $metrics->setAttribute('coveredelements', (string) $coveredStatements);
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
