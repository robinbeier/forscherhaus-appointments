<?php

declare(strict_types=1);

const COVERAGE_DELTA_EXIT_SUCCESS = 0;
const COVERAGE_DELTA_EXIT_ASSERTION_FAILURE = 1;
const COVERAGE_DELTA_EXIT_RUNTIME_ERROR = 2;

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(runCoverageDeltaCli($argv));
}

/**
 * @param array<int, string> $argv
 */
function runCoverageDeltaCli(array $argv): int
{
    $config = coverageDeltaDefaultConfig();

    $report = [
        'status' => 'error',
        'timestamp_utc' => gmdate('c'),
        'clover_file' => $config['clover'],
        'policy_file' => $config['policy'],
    ];
    $exitCode = COVERAGE_DELTA_EXIT_RUNTIME_ERROR;

    try {
        parseCoverageDeltaCliOptions($argv, $config);

        if ($config['help'] === true) {
            fwrite(STDOUT, coverageDeltaUsage());

            return COVERAGE_DELTA_EXIT_SUCCESS;
        }

        $report['clover_file'] = $config['clover'];
        $report['policy_file'] = $config['policy'];

        $policy = loadCoverageDeltaPolicy($config['policy']);
        $metrics = readCloverCoverageMetrics($config['clover']);
        $evaluation = evaluateCoverageDelta($metrics, $policy);

        $report = array_merge($report, [
            'status' => $evaluation['status'],
            'coverage' => $evaluation['coverage'],
            'policy' => $policy,
            'delta_pct_points' => $evaluation['delta_pct_points'],
            'thresholds' => $evaluation['thresholds'],
            'checks' => $evaluation['checks'],
            'messages' => $evaluation['messages'],
            'timestamp_utc' => gmdate('c'),
        ]);

        $summary = sprintf(
            'current=%.4f%% baseline=%.4f%% delta=%.4fpp',
            $evaluation['coverage']['line_pct'],
            $policy['baseline_line_coverage_pct'],
            $evaluation['delta_pct_points'],
        );

        if ($evaluation['status'] === 'pass') {
            $exitCode = COVERAGE_DELTA_EXIT_SUCCESS;
            fwrite(STDOUT, '[PASS] coverage-delta ' . $summary . PHP_EOL);
        } else {
            $exitCode = COVERAGE_DELTA_EXIT_ASSERTION_FAILURE;
            fwrite(STDERR, '[FAIL] coverage-delta ' . $summary . PHP_EOL);

            foreach ($evaluation['messages'] as $message) {
                fwrite(STDERR, ' - ' . $message . PHP_EOL);
            }
        }
    } catch (Throwable $e) {
        $report['status'] = 'error';
        $report['error'] = [
            'message' => $e->getMessage(),
            'exception' => get_class($e),
        ];
        fwrite(STDERR, '[ERROR] coverage-delta check failed: ' . $e->getMessage() . PHP_EOL);
        $exitCode = COVERAGE_DELTA_EXIT_RUNTIME_ERROR;
    }

    try {
        writeCoverageDeltaReport($config['output_json'], $report);
        fwrite(STDOUT, '[INFO] Report: ' . $config['output_json'] . PHP_EOL);
    } catch (Throwable $e) {
        fwrite(STDERR, '[WARN] Failed to write coverage-delta report: ' . $e->getMessage() . PHP_EOL);

        if ($exitCode === COVERAGE_DELTA_EXIT_SUCCESS) {
            $exitCode = COVERAGE_DELTA_EXIT_RUNTIME_ERROR;
        }
    }

    return $exitCode;
}

function coverageDeltaUsage(): string
{
    return implode(PHP_EOL, [
        'Usage: php scripts/ci/check_coverage_delta.php [options]',
        '',
        'Options:',
        '  --clover=PATH       Clover XML file path.',
        '  --policy=PATH       Policy config PHP file path.',
        '  --output-json=PATH  Coverage delta JSON report path.',
        '  --help              Show this help text.',
        '',
    ]);
}

/**
 * @return array{clover:string,policy:string,output_json:string,help:bool}
 */
function coverageDeltaDefaultConfig(): array
{
    $root = dirname(__DIR__, 2);

    return [
        'clover' => $root . '/storage/logs/ci/coverage-unit-clover.xml',
        'policy' => $root . '/scripts/ci/config/coverage_delta_policy.php',
        'output_json' => $root . '/storage/logs/ci/coverage-delta-latest.json',
        'help' => false,
    ];
}

/**
 * @param array<int, string> $argv
 * @param array{clover:string,policy:string,output_json:string,help:bool} $config
 */
function parseCoverageDeltaCliOptions(array $argv, array &$config): void
{
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help') {
            $config['help'] = true;
            continue;
        }

        if (str_starts_with($arg, '--clover=')) {
            $value = substr($arg, strlen('--clover='));
            if ($value === '') {
                throw new RuntimeException('CLI option --clover requires a non-empty value.');
            }
            $config['clover'] = $value;
            continue;
        }

        if (str_starts_with($arg, '--policy=')) {
            $value = substr($arg, strlen('--policy='));
            if ($value === '') {
                throw new RuntimeException('CLI option --policy requires a non-empty value.');
            }
            $config['policy'] = $value;
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
 * @param array<string, mixed> $metrics
 * @param array<string, float> $policy
 * @return array{
 *     status:string,
 *     coverage:array{statements:int,covered_statements:int,line_pct:float},
 *     delta_pct_points:float,
 *     thresholds:array{
 *         baseline_line_coverage_pct:float,
 *         max_drop_pct_points:float,
 *         absolute_min_line_coverage_pct:float,
 *         epsilon_pct_points:float,
 *         min_allowed_delta_pct_points:float
 *     },
 *     checks:array{absolute_min_pass:bool,delta_pass:bool},
 *     messages:array<int,string>
 * }
 */
function evaluateCoverageDelta(array $metrics, array $policy): array
{
    $linePct = ($metrics['coveredstatements'] / $metrics['statements']) * 100.0;
    $deltaPctPoints = $linePct - $policy['baseline_line_coverage_pct'];
    $absoluteMinFailed = $linePct + $policy['epsilon_pct_points'] < $policy['absolute_min_line_coverage_pct'];
    $deltaFailed = $deltaPctPoints + $policy['epsilon_pct_points'] < -1.0 * $policy['max_drop_pct_points'];

    $messages = [];

    if ($absoluteMinFailed) {
        $messages[] = sprintf(
            'line coverage %.4f%% is below absolute minimum %.4f%% (epsilon %.4f).',
            $linePct,
            $policy['absolute_min_line_coverage_pct'],
            $policy['epsilon_pct_points'],
        );
    }

    if ($deltaFailed) {
        $messages[] = sprintf(
            'line coverage delta %.4fpp is below allowed minimum %.4fpp (epsilon %.4f).',
            $deltaPctPoints,
            -1.0 * $policy['max_drop_pct_points'],
            $policy['epsilon_pct_points'],
        );
    }

    return [
        'status' => $absoluteMinFailed || $deltaFailed ? 'fail' : 'pass',
        'coverage' => [
            'statements' => $metrics['statements'],
            'covered_statements' => $metrics['coveredstatements'],
            'line_pct' => round($linePct, 4),
        ],
        'delta_pct_points' => round($deltaPctPoints, 4),
        'thresholds' => [
            'baseline_line_coverage_pct' => $policy['baseline_line_coverage_pct'],
            'max_drop_pct_points' => $policy['max_drop_pct_points'],
            'absolute_min_line_coverage_pct' => $policy['absolute_min_line_coverage_pct'],
            'epsilon_pct_points' => $policy['epsilon_pct_points'],
            'min_allowed_delta_pct_points' => -1.0 * $policy['max_drop_pct_points'],
        ],
        'checks' => [
            'absolute_min_pass' => !$absoluteMinFailed,
            'delta_pass' => !$deltaFailed,
        ],
        'messages' => $messages,
    ];
}

/**
 * @return array{statements:int,coveredstatements:int}
 */
function readCloverCoverageMetrics(string $cloverFile): array
{
    if (!is_file($cloverFile)) {
        throw new RuntimeException('Missing Clover XML file: ' . $cloverFile);
    }

    $xmlContent = file_get_contents($cloverFile);

    if ($xmlContent === false) {
        throw new RuntimeException('Failed to read Clover XML file: ' . $cloverFile);
    }

    $useInternalErrorsBefore = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $xml = simplexml_load_string($xmlContent);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($useInternalErrorsBefore);

    if ($xml === false) {
        $errors = array_map(static fn(LibXMLError $error): string => trim($error->message), $errors);

        $details = $errors === [] ? 'unknown XML parser error' : implode('; ', $errors);
        throw new RuntimeException('Failed to parse Clover XML: ' . $details);
    }

    $nodes = [];
    foreach (['/coverage/project/metrics', '/coverage/metrics', '//project/metrics'] as $xpath) {
        $matches = $xml->xpath($xpath);
        if (is_array($matches)) {
            $nodes = array_merge($nodes, $matches);
        }
    }

    foreach ($nodes as $node) {
        if (!$node instanceof SimpleXMLElement) {
            continue;
        }

        $attributes = $node->attributes();

        if ($attributes === null) {
            continue;
        }

        $statementsRaw = (string) ($attributes['statements'] ?? '');
        $coveredRaw = (string) ($attributes['coveredstatements'] ?? '');

        if (!ctype_digit($statementsRaw) || !ctype_digit($coveredRaw)) {
            continue;
        }

        $statements = (int) $statementsRaw;
        $coveredStatements = (int) $coveredRaw;

        if ($statements <= 0) {
            throw new RuntimeException('Clover statements value must be greater than zero.');
        }

        if ($coveredStatements < 0 || $coveredStatements > $statements) {
            throw new RuntimeException('Clover coveredstatements must be between 0 and statements.');
        }

        return [
            'statements' => $statements,
            'coveredstatements' => $coveredStatements,
        ];
    }

    throw new RuntimeException(
        'Clover XML does not contain numeric statements and coveredstatements metrics at project level.',
    );
}

/**
 * @return array{
 *     baseline_line_coverage_pct:float,
 *     max_drop_pct_points:float,
 *     absolute_min_line_coverage_pct:float,
 *     epsilon_pct_points:float
 * }
 */
function loadCoverageDeltaPolicy(string $policyFile): array
{
    if (!is_file($policyFile)) {
        throw new RuntimeException('Missing coverage policy config: ' . $policyFile);
    }

    $policy = require $policyFile;

    if (!is_array($policy)) {
        throw new RuntimeException('Coverage policy config must return an array.');
    }

    $normalized = [
        'baseline_line_coverage_pct' => asFloatPolicyValue($policy, 'baseline_line_coverage_pct'),
        'max_drop_pct_points' => asFloatPolicyValue($policy, 'max_drop_pct_points'),
        'absolute_min_line_coverage_pct' => asFloatPolicyValue($policy, 'absolute_min_line_coverage_pct'),
        'epsilon_pct_points' => asFloatPolicyValue($policy, 'epsilon_pct_points'),
    ];

    if ($normalized['max_drop_pct_points'] < 0.0) {
        throw new RuntimeException('Policy max_drop_pct_points must be >= 0.');
    }

    if ($normalized['epsilon_pct_points'] < 0.0) {
        throw new RuntimeException('Policy epsilon_pct_points must be >= 0.');
    }

    return $normalized;
}

/**
 * @param array<string, mixed> $policy
 */
function asFloatPolicyValue(array $policy, string $key): float
{
    if (!array_key_exists($key, $policy)) {
        throw new RuntimeException('Coverage policy is missing key: ' . $key);
    }

    $value = $policy[$key];

    if (!is_int($value) && !is_float($value)) {
        throw new RuntimeException('Coverage policy key "' . $key . '" must be numeric.');
    }

    return (float) $value;
}

/**
 * @param array<string, mixed> $report
 */
function writeCoverageDeltaReport(string $outputPath, array $report): void
{
    $directory = dirname($outputPath);

    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Failed to create report directory: ' . $directory);
    }

    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        throw new RuntimeException('Failed to encode coverage-delta report as JSON.');
    }

    if (file_put_contents($outputPath, $json . PHP_EOL) === false) {
        throw new RuntimeException('Failed to write report file: ' . $outputPath);
    }
}
