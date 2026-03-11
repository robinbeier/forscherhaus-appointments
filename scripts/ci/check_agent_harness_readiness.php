<?php

declare(strict_types=1);

require_once __DIR__ . '/check_harness_report_dates.php';

const AGENT_HARNESS_READINESS_EXIT_SUCCESS = 0;
const AGENT_HARNESS_READINESS_EXIT_POLICY_FAILURE = 1;
const AGENT_HARNESS_READINESS_EXIT_RUNTIME_ERROR = 2;

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(runAgentHarnessReadinessCli($argv));
}

/**
 * @param array<int, string> $argv
 */
function runAgentHarnessReadinessCli(array $argv): int
{
    $config = agentHarnessReadinessDefaultConfig();
    $report = [
        'schema_version' => 1,
        'status' => 'error',
        'generated_at_utc' => gmdate('c'),
        'policy_file' => $config['policy'],
        'target_score' => null,
        'overall' => null,
        'dimensions' => [],
        'messages' => [],
    ];
    $exitCode = AGENT_HARNESS_READINESS_EXIT_RUNTIME_ERROR;

    try {
        parseAgentHarnessReadinessCliOptions($argv, $config);

        if ($config['help'] === true) {
            fwrite(STDOUT, agentHarnessReadinessUsage());

            return AGENT_HARNESS_READINESS_EXIT_SUCCESS;
        }

        $policy = loadAgentHarnessReadinessPolicy($config['policy']);
        $evaluation = evaluateAgentHarnessReadiness(
            $config['root'],
            $policy,
            $config['today'],
            $config['report_date_max_future_days'],
        );

        $report = array_merge($report, $evaluation, [
            'generated_at_utc' => gmdate('c'),
            'policy_file' => $config['policy'],
            'target_score' => $policy['target_score'],
        ]);

        $summary = renderAgentHarnessReadinessSummary($report);
        if ($config['output_summary'] !== null && $summary !== '') {
            agentHarnessReadinessWriteTextFile($config['output_summary'], $summary);
        }

        if ($report['status'] === 'pass') {
            fwrite(
                STDOUT,
                sprintf(
                    '[PASS] agent-harness-readiness score %.2f/5.00 meets target %.2f.',
                    $report['overall']['score'],
                    $policy['target_score'],
                ) . PHP_EOL,
            );
            $exitCode = AGENT_HARNESS_READINESS_EXIT_SUCCESS;
        } else {
            fwrite(
                STDERR,
                sprintf(
                    '[FAIL] agent-harness-readiness score %.2f/5.00 is below target %.2f or contains failed checks.',
                    $report['overall']['score'],
                    $policy['target_score'],
                ) . PHP_EOL,
            );
            $exitCode = AGENT_HARNESS_READINESS_EXIT_POLICY_FAILURE;
        }
    } catch (Throwable $e) {
        $report['status'] = 'error';
        $report['error'] = [
            'message' => $e->getMessage(),
            'exception' => get_class($e),
        ];

        fwrite(STDERR, '[ERROR] agent-harness-readiness failed: ' . $e->getMessage() . PHP_EOL);
        $exitCode = AGENT_HARNESS_READINESS_EXIT_RUNTIME_ERROR;
    }

    try {
        agentHarnessReadinessWriteJsonFile($config['output_json'], $report);
        fwrite(STDOUT, '[INFO] Report: ' . $config['output_json'] . PHP_EOL);
    } catch (Throwable $e) {
        fwrite(STDERR, '[WARN] Failed to write agent-harness-readiness report: ' . $e->getMessage() . PHP_EOL);

        if ($exitCode === AGENT_HARNESS_READINESS_EXIT_SUCCESS) {
            $exitCode = AGENT_HARNESS_READINESS_EXIT_RUNTIME_ERROR;
        }
    }

    return $exitCode;
}

function agentHarnessReadinessUsage(): string
{
    return implode(PHP_EOL, [
        'Usage: php scripts/ci/check_agent_harness_readiness.php [options]',
        '',
        'Options:',
        '  --policy=PATH                   Policy config PHP file path.',
        '  --output-json=PATH              JSON report path.',
        '  --output-summary=PATH           Optional markdown summary output path.',
        '  --today=YYYY-MM-DD              Override today for deterministic testing.',
        '  --report-date-max-future-days=N Allow report dates up to N days in the future.',
        '  --help                          Show this help text.',
        '',
    ]);
}

/**
 * @return array{
 *   root:string,
 *   policy:string,
 *   output_json:string,
 *   output_summary:?string,
 *   today:DateTimeImmutable,
 *   report_date_max_future_days:int,
 *   help:bool
 * }
 */
function agentHarnessReadinessDefaultConfig(): array
{
    $root = dirname(__DIR__, 2);
    $summaryPath = getenv('GITHUB_STEP_SUMMARY');

    return [
        'root' => $root,
        'policy' => $root . '/scripts/ci/config/agent_harness_readiness_policy.php',
        'output_json' => $root . '/storage/logs/ci/agent-harness-readiness-latest.json',
        'output_summary' => $summaryPath !== false && $summaryPath !== '' ? (string) $summaryPath : null,
        'today' => new DateTimeImmutable('today', new DateTimeZone('UTC')),
        'report_date_max_future_days' => 0,
        'help' => false,
    ];
}

/**
 * @param array<int, string> $argv
 * @param array{
 *   root:string,
 *   policy:string,
 *   output_json:string,
 *   output_summary:?string,
 *   today:DateTimeImmutable,
 *   report_date_max_future_days:int,
 *   help:bool
 * } $config
 */
function parseAgentHarnessReadinessCliOptions(array $argv, array &$config): void
{
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help') {
            $config['help'] = true;
            continue;
        }

        if (str_starts_with($arg, '--policy=')) {
            $config['policy'] = agentHarnessReadinessRequireNonEmptyCliValue($arg, '--policy=');
            continue;
        }

        if (str_starts_with($arg, '--output-json=')) {
            $config['output_json'] = agentHarnessReadinessRequireNonEmptyCliValue($arg, '--output-json=');
            continue;
        }

        if (str_starts_with($arg, '--output-summary=')) {
            $config['output_summary'] = agentHarnessReadinessRequireNonEmptyCliValue($arg, '--output-summary=');
            continue;
        }

        if (str_starts_with($arg, '--today=')) {
            $config['today'] = harnessReportDateSanityParseIsoDate(
                agentHarnessReadinessRequireNonEmptyCliValue($arg, '--today='),
                '--today',
            );
            continue;
        }

        if (str_starts_with($arg, '--report-date-max-future-days=')) {
            $config['report_date_max_future_days'] = agentHarnessReadinessNormalizeNonNegativeInt(
                agentHarnessReadinessRequireNonEmptyCliValue($arg, '--report-date-max-future-days='),
                '--report-date-max-future-days',
            );
            continue;
        }

        throw new InvalidArgumentException('Unknown CLI option: ' . $arg);
    }
}

/**
 * @param array<string, mixed> $policy
 * @return array{
 *   status:string,
 *   overall:array{points:float,max_points:float,score:float,target_score:float},
 *   dimensions:array<int, array<string, mixed>>,
 *   messages:array<int, string>
 * }
 */
function evaluateAgentHarnessReadiness(
    string $root,
    array $policy,
    DateTimeImmutable $today,
    int $reportDateMaxFutureDays,
): array {
    $steeringDimension = agentHarnessReadinessScoreDimension(
        $policy,
        'steering_sources',
        agentHarnessReadinessEvaluateSteeringSources($root, $policy['required_sources']),
    );

    $ciWorkflow = agentHarnessReadinessLoadWorkflowYaml($root . '/.github/workflows/ci.yml');
    $blockingGatesDimension = agentHarnessReadinessScoreDimension(
        $policy,
        'blocking_gates',
        agentHarnessReadinessEvaluateBlockingJobs($ciWorkflow, $policy['blocking_jobs']),
    );

    $generatedTopologyDimension = agentHarnessReadinessScoreDimension(
        $policy,
        'generated_topology',
        agentHarnessReadinessEvaluateGeneratedTopology($root, $policy['generated_topology_commands']),
    );

    $reportSanityDimension = agentHarnessReadinessScoreDimension(
        $policy,
        'report_sanity',
        agentHarnessReadinessEvaluateReportSanity($root, $today, $reportDateMaxFutureDays),
    );

    $hygieneWorkflow = agentHarnessReadinessLoadWorkflowYaml(
        $root . '/' . ltrim((string) $policy['hygiene_workflow']['path'], '/'),
    );
    $scheduledHygieneDimension = agentHarnessReadinessScoreDimension(
        $policy,
        'scheduled_hygiene',
        agentHarnessReadinessEvaluateHygieneWorkflow($hygieneWorkflow, $policy['hygiene_workflow']),
    );

    $dimensions = [
        $steeringDimension,
        $blockingGatesDimension,
        $generatedTopologyDimension,
        $reportSanityDimension,
        $scheduledHygieneDimension,
    ];

    $totalPoints = 0.0;
    $maxPoints = 0.0;
    $failedChecks = 0;

    foreach ($dimensions as $dimension) {
        $totalPoints += $dimension['points'];
        $maxPoints += $dimension['max_points'];

        foreach ($dimension['checks'] as $check) {
            if (($check['status'] ?? 'fail') !== 'pass') {
                $failedChecks++;
            }
        }
    }

    $score = $maxPoints > 0.0 ? round(($totalPoints / $maxPoints) * 5.0, 2) : 0.0;
    $messages = [];
    if ($failedChecks > 0) {
        $messages[] = sprintf(
            'Fix %d failing readiness checks before treating the repo as harness-stable.',
            $failedChecks,
        );
    }

    if ($score < (float) $policy['target_score']) {
        $messages[] = sprintf('Overall score %.2f/5.00 is below target %.2f.', $score, $policy['target_score']);
    } else {
        $messages[] = sprintf('Overall score %.2f/5.00 meets target %.2f.', $score, $policy['target_score']);
    }

    return [
        'status' => $failedChecks === 0 && $score >= (float) $policy['target_score'] ? 'pass' : 'fail',
        'overall' => [
            'points' => round($totalPoints, 2),
            'max_points' => round($maxPoints, 2),
            'score' => $score,
            'target_score' => (float) $policy['target_score'],
        ],
        'dimensions' => $dimensions,
        'messages' => $messages,
    ];
}

/**
 * @param array<string, array<int, string>> $requiredSources
 * @return array<int, array<string, mixed>>
 */
function agentHarnessReadinessEvaluateSteeringSources(string $root, array $requiredSources): array
{
    $checks = [];

    foreach ($requiredSources as $path => $requiredNeedles) {
        $absolutePath = $root . '/' . $path;
        if (!is_file($absolutePath)) {
            $checks[] = [
                'id' => 'file_' . md5($path),
                'label' => $path . ' exists',
                'status' => 'fail',
                'message' => 'Required steering source is missing.',
            ];
            continue;
        }

        $checks[] = [
            'id' => 'file_' . md5($path),
            'label' => $path . ' exists',
            'status' => 'pass',
            'message' => 'Required steering source exists.',
        ];

        $content = (string) file_get_contents($absolutePath);
        foreach ($requiredNeedles as $needle) {
            $checks[] = [
                'id' => 'contains_' . md5($path . ':' . $needle),
                'label' => $path . ' references ' . $needle,
                'status' => str_contains($content, $needle) ? 'pass' : 'fail',
                'message' => str_contains($content, $needle)
                    ? 'Required canonical reference found.'
                    : 'Required canonical reference missing.',
            ];
        }
    }

    return $checks;
}

/**
 * @param array<string, mixed> $ciWorkflow
 * @param array<int, string> $blockingJobs
 * @return array<int, array<string, mixed>>
 */
function agentHarnessReadinessEvaluateBlockingJobs(array $ciWorkflow, array $blockingJobs): array
{
    $jobs = $ciWorkflow['jobs'] ?? [];
    if (!is_array($jobs)) {
        throw new RuntimeException('CI workflow does not contain a valid top-level "jobs" map.');
    }

    $checks = [];
    foreach ($blockingJobs as $jobName) {
        $jobConfig = $jobs[$jobName] ?? null;
        $exists = is_array($jobConfig);
        $isBlocking = $exists && ($jobConfig['continue-on-error'] ?? false) !== true;

        $checks[] = [
            'id' => 'job_' . $jobName,
            'label' => $jobName . ' exists and is blocking',
            'status' => $exists && $isBlocking ? 'pass' : 'fail',
            'message' => !$exists
                ? 'Required blocking CI job is missing.'
                : ($isBlocking
                    ? 'Job exists without top-level continue-on-error.'
                    : 'Job is configured as non-blocking.'),
        ];
    }

    return $checks;
}

/**
 * @param array<int, array{id:string,label:string,command:array<int, string>}> $commands
 * @return array<int, array<string, mixed>>
 */
function agentHarnessReadinessEvaluateGeneratedTopology(string $root, array $commands): array
{
    $checks = [];
    foreach ($commands as $commandSpec) {
        $result = agentHarnessReadinessRunCommand($commandSpec['command'], $root);
        $checks[] = [
            'id' => $commandSpec['id'],
            'label' => $commandSpec['label'],
            'status' => $result['exit_code'] === 0 ? 'pass' : 'fail',
            'message' =>
                $result['exit_code'] === 0
                    ? 'Check passed.'
                    : trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']),
            'command' => implode(' ', $commandSpec['command']),
        ];
    }

    return $checks;
}

/**
 * @return array<int, array<string, mixed>>
 */
function agentHarnessReadinessEvaluateReportSanity(string $root, DateTimeImmutable $today, int $maxFutureDays): array
{
    $evaluation = evaluateHarnessReportDateSanity($root, $today, $maxFutureDays);

    return [
        [
            'id' => 'report_date_sanity',
            'label' => 'Readiness/audit reports use plausible dates',
            'status' => $evaluation['status'] === 'pass' ? 'pass' : 'fail',
            'message' =>
                $evaluation['status'] === 'pass'
                    ? 'Report dates are plausible.'
                    : implode(
                        ' ',
                        array_map(
                            static fn(array $violation): string => (string) $violation['message'],
                            $evaluation['violations'],
                        ),
                    ),
            'details' => $evaluation,
        ],
    ];
}

/**
 * @param array<string, mixed> $workflow
 * @param array<string, mixed> $policy
 * @return array<int, array<string, mixed>>
 */
function agentHarnessReadinessEvaluateHygieneWorkflow(array $workflow, array $policy): array
{
    $checks = [];
    $on = $workflow['on'] ?? null;
    $jobs = $workflow['jobs'] ?? [];
    $jobName = (string) $policy['job'];
    $job = is_array($jobs) ? $jobs[$jobName] ?? null : null;

    $checks[] = [
        'id' => 'workflow_dispatch',
        'label' => 'Hygiene workflow supports workflow_dispatch',
        'status' => is_array($on) && array_key_exists('workflow_dispatch', $on) ? 'pass' : 'fail',
        'message' =>
            is_array($on) && array_key_exists('workflow_dispatch', $on)
                ? 'workflow_dispatch is configured.'
                : 'workflow_dispatch trigger is missing.',
    ];

    $scheduleConfigured =
        is_array($on) && isset($on['schedule']) && is_array($on['schedule']) && $on['schedule'] !== [];
    $checks[] = [
        'id' => 'schedule',
        'label' => 'Hygiene workflow is scheduled',
        'status' => $scheduleConfigured ? 'pass' : 'fail',
        'message' => $scheduleConfigured
            ? 'At least one schedule trigger is configured.'
            : 'schedule trigger is missing.',
    ];

    $jobExists = is_array($job);
    $checks[] = [
        'id' => 'job_exists',
        'label' => 'Hygiene workflow exposes job ' . $jobName,
        'status' => $jobExists ? 'pass' : 'fail',
        'message' => $jobExists ? 'Expected hygiene job exists.' : 'Expected hygiene job is missing.',
    ];

    $stepNames = [];
    if ($jobExists) {
        foreach ($job['steps'] ?? [] as $step) {
            if (is_array($step) && isset($step['name']) && is_string($step['name'])) {
                $stepNames[] = $step['name'];
            }
        }
    }

    foreach ((array) ($policy['required_steps'] ?? []) as $requiredStep) {
        $checks[] = [
            'id' => 'step_' . md5((string) $requiredStep),
            'label' => 'Hygiene workflow includes step "' . $requiredStep . '"',
            'status' => in_array($requiredStep, $stepNames, true) ? 'pass' : 'fail',
            'message' => in_array($requiredStep, $stepNames, true)
                ? 'Required hygiene step is present.'
                : 'Required hygiene step is missing.',
        ];
    }

    return $checks;
}

/**
 * @param array<string, mixed> $policy
 * @param array<int, array<string, mixed>> $checks
 * @return array<string, mixed>
 */
function agentHarnessReadinessScoreDimension(array $policy, string $dimensionId, array $checks): array
{
    $definition = $policy['dimensions'][$dimensionId] ?? null;
    if (!is_array($definition)) {
        throw new InvalidArgumentException('Unknown readiness dimension: ' . $dimensionId);
    }

    $maxPoints = (float) ($definition['weight'] ?? 0.0);
    $passedChecks = 0;
    foreach ($checks as $check) {
        if (($check['status'] ?? 'fail') === 'pass') {
            $passedChecks++;
        }
    }

    $points = $checks === [] ? 0.0 : round(($passedChecks / count($checks)) * $maxPoints, 2);

    return [
        'id' => $dimensionId,
        'label' => (string) ($definition['label'] ?? $dimensionId),
        'status' => $passedChecks === count($checks) ? 'pass' : 'fail',
        'points' => $points,
        'max_points' => $maxPoints,
        'score' => $maxPoints > 0.0 ? round(($points / $maxPoints) * 5.0, 2) : 0.0,
        'checks' => $checks,
    ];
}

/**
 * @return array{exit_code:int,stdout:string,stderr:string}
 */
function agentHarnessReadinessRunCommand(array $command, string $root): array
{
    $descriptorSpec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptorSpec, $pipes, $root);
    if (!is_resource($process)) {
        throw new RuntimeException('Failed to start command: ' . implode(' ', $command));
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'exit_code' => $exitCode,
        'stdout' => $stdout === false ? '' : $stdout,
        'stderr' => $stderr === false ? '' : $stderr,
    ];
}

/**
 * @return array<string, mixed>
 */
function loadAgentHarnessReadinessPolicy(string $path): array
{
    if (!is_file($path)) {
        throw new InvalidArgumentException('Policy file does not exist: ' . $path);
    }

    $policy = require $path;
    if (!is_array($policy)) {
        throw new RuntimeException('Policy file must return an array.');
    }

    return $policy;
}

/**
 * @return array<string, mixed>
 */
function agentHarnessReadinessLoadWorkflowYaml(string $path): array
{
    if (!is_file($path)) {
        throw new InvalidArgumentException('Workflow file does not exist: ' . $path);
    }

    agentHarnessReadinessEnsureYamlSupport();

    $parsed = Symfony\Component\Yaml\Yaml::parseFile($path);
    if (!is_array($parsed)) {
        throw new RuntimeException('Workflow YAML did not parse into an array: ' . $path);
    }

    return $parsed;
}

function agentHarnessReadinessEnsureYamlSupport(): void
{
    if (class_exists(Symfony\Component\Yaml\Yaml::class)) {
        return;
    }

    $autoloadPath = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (!is_file($autoloadPath)) {
        throw new RuntimeException('Symfony YAML support requires vendor/autoload.php. Run composer install first.');
    }

    require_once $autoloadPath;

    if (!class_exists(Symfony\Component\Yaml\Yaml::class)) {
        throw new RuntimeException('Failed to load Symfony YAML support from vendor/autoload.php.');
    }
}

function agentHarnessReadinessRequireNonEmptyCliValue(string $arg, string $prefix): string
{
    $value = substr($arg, strlen($prefix));
    if ($value === false || trim($value) === '') {
        throw new InvalidArgumentException(sprintf('Option %s requires a non-empty value.', rtrim($prefix, '=')));
    }

    return $value;
}

function agentHarnessReadinessNormalizeNonNegativeInt(string $value, string $optionName): int
{
    if (!preg_match('/^\d+$/', $value)) {
        throw new InvalidArgumentException(
            sprintf('Option %s expects a non-negative integer, got %s.', $optionName, $value),
        );
    }

    return (int) $value;
}

/**
 * @param array<string, mixed> $report
 */
function renderAgentHarnessReadinessSummary(array $report): string
{
    $overall = $report['overall'];
    if (!is_array($overall)) {
        return '';
    }

    $lines = [
        '# Agent Harness Readiness',
        '',
        sprintf('- Status: `%s`', (string) ($report['status'] ?? 'error')),
        sprintf(
            '- Overall score: `%.2f / 5.00` (target `%.2f`)',
            (float) ($overall['score'] ?? 0.0),
            (float) ($overall['target_score'] ?? 0.0),
        ),
        '',
        '| Dimension | Score | Status |',
        '| --- | ---: | --- |',
    ];

    foreach ((array) ($report['dimensions'] ?? []) as $dimension) {
        if (!is_array($dimension)) {
            continue;
        }

        $lines[] = sprintf(
            '| %s | %.2f | %s |',
            (string) ($dimension['label'] ?? 'Unknown'),
            (float) ($dimension['score'] ?? 0.0),
            (string) ($dimension['status'] ?? 'error'),
        );
    }

    $lines[] = '';
    foreach ((array) ($report['messages'] ?? []) as $message) {
        $lines[] = '- ' . $message;
    }

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

/**
 * @param array<string, mixed> $report
 */
function agentHarnessReadinessWriteJsonFile(string $path, array $report): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Failed to create directory for report: ' . $directory);
    }

    $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        throw new RuntimeException('Failed to encode report JSON.');
    }

    if (file_put_contents($path, $encoded . PHP_EOL) === false) {
        throw new RuntimeException('Failed to write report: ' . $path);
    }
}

function agentHarnessReadinessWriteTextFile(string $path, string $contents): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Failed to create directory for summary: ' . $directory);
    }

    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Failed to write summary: ' . $path);
    }
}
