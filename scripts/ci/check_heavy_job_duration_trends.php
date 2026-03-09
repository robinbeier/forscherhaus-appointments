<?php

declare(strict_types=1);

const HEAVY_JOB_DURATION_TRENDS_EXIT_SUCCESS = 0;
const HEAVY_JOB_DURATION_TRENDS_EXIT_RUNTIME_ERROR = 2;

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(runHeavyJobDurationTrendsCli($argv));
}

/**
 * @param array<int, string> $argv
 */
function runHeavyJobDurationTrendsCli(array $argv): int
{
    $config = heavyJobDurationTrendsDefaultConfig();
    $report = [
        'schema_version' => 1,
        'status' => 'error',
        'generated_at_utc' => gmdate('c'),
        'repository' => $config['repo'],
        'workflow_file' => $config['workflow_file'],
        'branch' => $config['branch'],
        'event' => $config['event'],
        'current_run_id' => $config['current_run_id'],
        'policy_file' => $config['policy'],
    ];
    $exitCode = HEAVY_JOB_DURATION_TRENDS_EXIT_RUNTIME_ERROR;

    try {
        parseHeavyJobDurationTrendsCliOptions($argv, $config);

        if ($config['help'] === true) {
            fwrite(STDOUT, heavyJobDurationTrendsUsage());

            return HEAVY_JOB_DURATION_TRENDS_EXIT_SUCCESS;
        }

        $policy = loadHeavyJobDurationTrendPolicy($config['policy']);
        $targetJobs = array_column($policy['jobs'], 'job_name');
        $request = buildHeavyJobDurationTrendsGitHubApiRequestClosure($config);

        $currentRunJobs = [];
        if ($config['current_run_id'] !== null) {
            $currentRunJobs = fetchWorkflowRunJobSamples(
                $request,
                $config['repo'],
                $config['current_run_id'],
                $targetJobs,
            );
        }

        $history = fetchWorkflowRunHistory(
            $request,
            $config['repo'],
            $config['workflow_file'],
            $config['branch'],
            $config['event'],
            $targetJobs,
            $config['per_page'],
            $config['max_runs'],
            $config['current_run_id'],
        );

        $evaluation = evaluateHeavyJobDurationTrends($policy, $currentRunJobs, $history['job_history']);
        $report = array_merge($report, [
            'status' => $evaluation['status'],
            'generated_at_utc' => gmdate('c'),
            'repository' => $config['repo'],
            'workflow_file' => $config['workflow_file'],
            'branch' => $config['branch'],
            'event' => $config['event'],
            'current_run_id' => $config['current_run_id'],
            'policy_file' => $config['policy'],
            'runs_scanned' => $history['runs_scanned'],
            'workflow_runs_considered' => $history['workflow_runs_considered'],
            'policy' => buildHeavyJobDurationTrendPolicySummary($policy),
            'jobs' => $evaluation['jobs'],
            'messages' => $evaluation['messages'],
        ]);

        emitHeavyJobDurationTrendsMessages($report['jobs']);
        $summary = renderHeavyJobDurationTrendsSummary($report);

        if ($summary !== '' && $config['output_summary'] !== null) {
            writeHeavyJobDurationTrendsTextFile($config['output_summary'], $summary);
        }

        if ($evaluation['status'] === 'alert') {
            fwrite(STDOUT, '[WARN] heavy-job-duration-trends detected runtime regressions.' . PHP_EOL);
        } elseif ($evaluation['status'] === 'insufficient_data') {
            fwrite(STDOUT, '[INFO] heavy-job-duration-trends needs more successful runs.' . PHP_EOL);
        } else {
            fwrite(STDOUT, '[PASS] heavy-job-duration-trends found no sustained regression.' . PHP_EOL);
        }

        $exitCode = HEAVY_JOB_DURATION_TRENDS_EXIT_SUCCESS;
    } catch (Throwable $e) {
        $report['status'] = 'error';
        $report['error'] = [
            'message' => $e->getMessage(),
            'exception' => get_class($e),
        ];

        fwrite(STDERR, '[ERROR] heavy-job-duration-trends failed: ' . $e->getMessage() . PHP_EOL);
        $exitCode = HEAVY_JOB_DURATION_TRENDS_EXIT_RUNTIME_ERROR;
    }

    try {
        writeHeavyJobDurationTrendsJsonFile($config['output_json'], $report);
        fwrite(STDOUT, '[INFO] Report: ' . $config['output_json'] . PHP_EOL);
    } catch (Throwable $e) {
        fwrite(STDERR, '[WARN] Failed to write heavy-job-duration-trends report: ' . $e->getMessage() . PHP_EOL);

        if ($exitCode === HEAVY_JOB_DURATION_TRENDS_EXIT_SUCCESS) {
            $exitCode = HEAVY_JOB_DURATION_TRENDS_EXIT_RUNTIME_ERROR;
        }
    }

    return $exitCode;
}

function heavyJobDurationTrendsUsage(): string
{
    return implode(PHP_EOL, [
        'Usage: php scripts/ci/check_heavy_job_duration_trends.php [options]',
        '',
        'Options:',
        '  --repo=OWNER/REPO      GitHub repository. Defaults to GITHUB_REPOSITORY.',
        '  --workflow-file=FILE   Workflow file name (default: ci.yml).',
        '  --branch=BRANCH        Branch to use for the rolling baseline (default: main).',
        '  --event=EVENT          Workflow event filter (default: push).',
        '  --current-run-id=ID    Optional current workflow run ID to fold into the recent window.',
        '  --policy=PATH          Policy config PHP file path.',
        '  --output-json=PATH     JSON report path.',
        '  --output-summary=PATH  Optional markdown summary output path.',
        '  --per-page=N           Workflow runs requested per GitHub API page.',
        '  --max-runs=N           Maximum successful workflow runs to scan.',
        '  --token-env=NAME       Environment variable holding the GitHub token (default: GITHUB_TOKEN).',
        '  --help                 Show this help text.',
        '',
    ]);
}

/**
 * @return array{
 *     repo:?string,
 *     workflow_file:string,
 *     branch:string,
 *     event:string,
 *     current_run_id:?int,
 *     policy:string,
 *     output_json:string,
 *     output_summary:?string,
 *     per_page:int,
 *     max_runs:int,
 *     token_env:string,
 *     github_api_url:string,
 *     help:bool
 * }
 */
function heavyJobDurationTrendsDefaultConfig(): array
{
    $root = dirname(__DIR__, 2);
    $summaryPath = getenv('GITHUB_STEP_SUMMARY');

    return [
        'repo' => getenv('GITHUB_REPOSITORY') !== false ? (string) getenv('GITHUB_REPOSITORY') : null,
        'workflow_file' => 'ci.yml',
        'branch' => 'main',
        'event' => 'push',
        'current_run_id' => getenv('GITHUB_RUN_ID') !== false ? (int) getenv('GITHUB_RUN_ID') : null,
        'policy' => $root . '/scripts/ci/config/heavy_job_duration_trend_policy.php',
        'output_json' => $root . '/storage/logs/ci/heavy-job-duration-trends-latest.json',
        'output_summary' => $summaryPath !== false && $summaryPath !== '' ? (string) $summaryPath : null,
        'per_page' => 20,
        'max_runs' => 25,
        'token_env' => 'GITHUB_TOKEN',
        'github_api_url' =>
            getenv('GITHUB_API_URL') !== false ? (string) getenv('GITHUB_API_URL') : 'https://api.github.com',
        'help' => false,
    ];
}

/**
 * @param array<int, string> $argv
 * @param array{
 *     repo:?string,
 *     workflow_file:string,
 *     branch:string,
 *     event:string,
 *     current_run_id:?int,
 *     policy:string,
 *     output_json:string,
 *     output_summary:?string,
 *     per_page:int,
 *     max_runs:int,
 *     token_env:string,
 *     github_api_url:string,
 *     help:bool
 * } $config
 */
function parseHeavyJobDurationTrendsCliOptions(array $argv, array &$config): void
{
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help') {
            $config['help'] = true;
            continue;
        }

        if (str_starts_with($arg, '--repo=')) {
            $config['repo'] = requireHeavyJobDurationTrendsNonEmptyCliValue($arg, '--repo=');
            continue;
        }

        if (str_starts_with($arg, '--workflow-file=')) {
            $config['workflow_file'] = requireHeavyJobDurationTrendsNonEmptyCliValue($arg, '--workflow-file=');
            continue;
        }

        if (str_starts_with($arg, '--branch=')) {
            $config['branch'] = requireHeavyJobDurationTrendsNonEmptyCliValue($arg, '--branch=');
            continue;
        }

        if (str_starts_with($arg, '--event=')) {
            $config['event'] = requireHeavyJobDurationTrendsNonEmptyCliValue($arg, '--event=');
            continue;
        }

        if (str_starts_with($arg, '--current-run-id=')) {
            $value = requireHeavyJobDurationTrendsNonEmptyCliValue($arg, '--current-run-id=');
            $config['current_run_id'] = normalizeHeavyJobDurationTrendsPositiveInt($value, '--current-run-id');
            continue;
        }

        if (str_starts_with($arg, '--policy=')) {
            $config['policy'] = requireHeavyJobDurationTrendsNonEmptyCliValue($arg, '--policy=');
            continue;
        }

        if (str_starts_with($arg, '--output-json=')) {
            $config['output_json'] = requireHeavyJobDurationTrendsNonEmptyCliValue($arg, '--output-json=');
            continue;
        }

        if (str_starts_with($arg, '--output-summary=')) {
            $config['output_summary'] = requireHeavyJobDurationTrendsNonEmptyCliValue($arg, '--output-summary=');
            continue;
        }

        if (str_starts_with($arg, '--per-page=')) {
            $config['per_page'] = normalizeHeavyJobDurationTrendsPositiveInt(
                requireHeavyJobDurationTrendsNonEmptyCliValue($arg, '--per-page='),
                '--per-page',
            );
            continue;
        }

        if (str_starts_with($arg, '--max-runs=')) {
            $config['max_runs'] = normalizeHeavyJobDurationTrendsPositiveInt(
                requireHeavyJobDurationTrendsNonEmptyCliValue($arg, '--max-runs='),
                '--max-runs',
            );
            continue;
        }

        if (str_starts_with($arg, '--token-env=')) {
            $config['token_env'] = requireHeavyJobDurationTrendsNonEmptyCliValue($arg, '--token-env=');
            continue;
        }

        throw new RuntimeException('Unknown CLI option: ' . $arg);
    }

    if ($config['help'] === false && $config['repo'] === null) {
        throw new RuntimeException('GitHub repository is required via --repo=OWNER/REPO or GITHUB_REPOSITORY.');
    }
}

function requireHeavyJobDurationTrendsNonEmptyCliValue(string $arg, string $prefix): string
{
    $value = substr($arg, strlen($prefix));

    if ($value === '') {
        throw new RuntimeException('CLI option ' . rtrim($prefix, '=') . ' requires a non-empty value.');
    }

    return $value;
}

function normalizeHeavyJobDurationTrendsPositiveInt(string $value, string $option): int
{
    if (!preg_match('/^[1-9][0-9]*$/', $value)) {
        throw new RuntimeException($option . ' must be a positive integer.');
    }

    return (int) $value;
}

/**
 * @return array{
 *     recent_window_size:int,
 *     baseline_window_size:int,
 *     min_recent_samples:int,
 *     min_baseline_samples:int,
 *     alert_threshold_ratio:float,
 *     min_absolute_increase_seconds:float,
 *     jobs:array<int, array{job_name:string,min_baseline_median_seconds:float}>
 * }
 */
function loadHeavyJobDurationTrendPolicy(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Missing heavy-job-duration-trend policy file: ' . $path);
    }

    $policy = require $path;

    if (!is_array($policy)) {
        throw new RuntimeException('Heavy-job-duration-trend policy must return an array.');
    }

    $normalized = [
        'recent_window_size' => normalizeHeavyJobDurationTrendsPositivePolicyInt(
            $policy['recent_window_size'] ?? null,
            'recent_window_size',
        ),
        'baseline_window_size' => normalizeHeavyJobDurationTrendsPositivePolicyInt(
            $policy['baseline_window_size'] ?? null,
            'baseline_window_size',
        ),
        'min_recent_samples' => normalizeHeavyJobDurationTrendsPositivePolicyInt(
            $policy['min_recent_samples'] ?? null,
            'min_recent_samples',
        ),
        'min_baseline_samples' => normalizeHeavyJobDurationTrendsPositivePolicyInt(
            $policy['min_baseline_samples'] ?? null,
            'min_baseline_samples',
        ),
        'alert_threshold_ratio' => normalizeHeavyJobDurationTrendsPositivePolicyFloat(
            $policy['alert_threshold_ratio'] ?? null,
            'alert_threshold_ratio',
        ),
        'min_absolute_increase_seconds' => normalizeHeavyJobDurationTrendsPositivePolicyFloat(
            $policy['min_absolute_increase_seconds'] ?? null,
            'min_absolute_increase_seconds',
        ),
        'jobs' => [],
    ];

    if ($normalized['baseline_window_size'] < $normalized['min_baseline_samples']) {
        throw new RuntimeException('baseline_window_size must be >= min_baseline_samples.');
    }

    if ($normalized['recent_window_size'] < $normalized['min_recent_samples']) {
        throw new RuntimeException('recent_window_size must be >= min_recent_samples.');
    }

    if (!isset($policy['jobs']) || !is_array($policy['jobs']) || $policy['jobs'] === []) {
        throw new RuntimeException('Heavy-job-duration-trend policy must define at least one job.');
    }

    foreach ($policy['jobs'] as $index => $job) {
        if (!is_array($job)) {
            throw new RuntimeException(sprintf('jobs[%d] must be an array.', $index));
        }

        $jobName = trim((string) ($job['job_name'] ?? ''));

        if ($jobName === '') {
            throw new RuntimeException(sprintf('jobs[%d].job_name must be non-empty.', $index));
        }

        $normalized['jobs'][] = [
            'job_name' => $jobName,
            'min_baseline_median_seconds' => normalizeHeavyJobDurationTrendsPositivePolicyFloat(
                $job['min_baseline_median_seconds'] ?? 0.0,
                sprintf('jobs[%d].min_baseline_median_seconds', $index),
                true,
            ),
        ];
    }

    return $normalized;
}

function normalizeHeavyJobDurationTrendsPositivePolicyInt(mixed $value, string $field): int
{
    if (!is_int($value) || $value < 1) {
        throw new RuntimeException($field . ' must be a positive integer.');
    }

    return $value;
}

function normalizeHeavyJobDurationTrendsPositivePolicyFloat(mixed $value, string $field, bool $allowZero = false): float
{
    if (!is_int($value) && !is_float($value)) {
        throw new RuntimeException($field . ' must be numeric.');
    }

    $normalized = (float) $value;

    if ($allowZero === true && $normalized === 0.0) {
        return 0.0;
    }

    if ($normalized <= 0.0) {
        throw new RuntimeException($field . ' must be greater than zero.');
    }

    return $normalized;
}

/**
 * @param array{
 *     repo:?string,
 *     token_env:string,
 *     github_api_url:string
 * } $config
 * @return Closure(string): array<string, mixed>
 */
function buildHeavyJobDurationTrendsGitHubApiRequestClosure(array $config): Closure
{
    $token = getenv($config['token_env']);

    if (!is_string($token) || trim($token) === '') {
        throw new RuntimeException('Missing GitHub token in environment variable ' . $config['token_env'] . '.');
    }

    $baseUrl = rtrim($config['github_api_url'], '/');

    return static function (string $path) use ($token, $baseUrl): array {
        $url = $baseUrl . $path;
        $curl = curl_init($url);

        if ($curl === false) {
            throw new RuntimeException('Failed to initialize curl for GitHub API request.');
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github+json',
                'Authorization: Bearer ' . $token,
                'User-Agent: forscherhaus-heavy-job-duration-trends',
                'X-GitHub-Api-Version: 2022-11-28',
            ],
        ]);

        $response = curl_exec($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);

        if ($response === false) {
            throw new RuntimeException('GitHub API request failed: ' . $error);
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Failed to decode GitHub API response for ' . $path . '.');
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $message = (string) ($decoded['message'] ?? 'unknown GitHub API error');
            throw new RuntimeException(
                sprintf('GitHub API request %s failed with HTTP %d: %s', $path, $httpCode, $message),
            );
        }

        return $decoded;
    };
}

/**
 * @param Closure(string): array<string, mixed> $request
 * @param array<int, string> $targetJobs
 * @return array<string, array<string, mixed>>
 */
function fetchWorkflowRunJobSamples(Closure $request, string $repo, int $runId, array $targetJobs): array
{
    $payload = $request(sprintf('/repos/%s/actions/runs/%d/jobs?per_page=100', $repo, $runId));
    $jobs = $payload['jobs'] ?? null;

    if (!is_array($jobs)) {
        throw new RuntimeException('GitHub API response for workflow jobs is missing jobs[].');
    }

    return collectTargetJobSamples($jobs, array_fill_keys($targetJobs, true));
}

/**
 * @param Closure(string): array<string, mixed> $request
 * @param array<int, string> $targetJobs
 * @return array{
 *     runs_scanned:int,
 *     workflow_runs_considered:int,
 *     job_history:array<string, array<int, array<string, mixed>>>
 * }
 */
function fetchWorkflowRunHistory(
    Closure $request,
    string $repo,
    string $workflowFile,
    string $branch,
    string $event,
    array $targetJobs,
    int $perPage,
    int $maxRuns,
    ?int $excludeRunId,
): array {
    $jobHistory = array_fill_keys($targetJobs, []);
    $targetLookup = array_fill_keys($targetJobs, true);
    $page = 1;
    $runsScanned = 0;
    $workflowRunsConsidered = 0;

    while ($runsScanned < $maxRuns) {
        $path = sprintf(
            '/repos/%s/actions/workflows/%s/runs?%s',
            $repo,
            rawurlencode($workflowFile),
            http_build_query([
                'branch' => $branch,
                'event' => $event,
                'status' => 'success',
                'per_page' => $perPage,
                'page' => $page,
            ]),
        );
        $payload = $request($path);
        $runs = $payload['workflow_runs'] ?? null;

        if (!is_array($runs) || $runs === []) {
            break;
        }

        foreach ($runs as $run) {
            if (!is_array($run) || !isset($run['id'])) {
                continue;
            }

            $runId = (int) $run['id'];

            if ($excludeRunId !== null && $runId === $excludeRunId) {
                continue;
            }

            $workflowRunsConsidered++;
            $runsScanned++;

            $jobPayload = $request(sprintf('/repos/%s/actions/runs/%d/jobs?per_page=100', $repo, $runId));
            $jobs = $jobPayload['jobs'] ?? null;

            if (!is_array($jobs)) {
                throw new RuntimeException('GitHub API response for workflow jobs is missing jobs[].');
            }

            $samples = collectTargetJobSamples($jobs, $targetLookup);
            foreach ($samples as $jobName => $sample) {
                $jobHistory[$jobName][] = $sample + ['source_run_id' => $runId];
            }

            if ($runsScanned >= $maxRuns) {
                break;
            }
        }

        if (count($runs) < $perPage) {
            break;
        }

        $page++;
    }

    return [
        'runs_scanned' => $runsScanned,
        'workflow_runs_considered' => $workflowRunsConsidered,
        'job_history' => $jobHistory,
    ];
}

/**
 * @param array<int, mixed> $jobs
 * @param array<string, bool> $targetLookup
 * @return array<string, array<string, mixed>>
 */
function collectTargetJobSamples(array $jobs, array $targetLookup): array
{
    $samples = [];

    foreach ($jobs as $job) {
        if (!is_array($job)) {
            continue;
        }

        $jobName = trim((string) ($job['name'] ?? ''));

        if ($jobName === '' || !isset($targetLookup[$jobName])) {
            continue;
        }

        $startedAt = $job['started_at'] ?? null;
        $completedAt = $job['completed_at'] ?? null;
        $conclusion = (string) ($job['conclusion'] ?? '');

        $sample = [
            'job_name' => $jobName,
            'conclusion' => $conclusion,
            'html_url' => (string) ($job['html_url'] ?? ''),
            'started_at' => is_string($startedAt) ? $startedAt : null,
            'completed_at' => is_string($completedAt) ? $completedAt : null,
        ];

        if ($conclusion === 'success' && is_string($startedAt) && is_string($completedAt)) {
            $sample['duration_seconds'] = calculateHeavyJobDurationTrendsDurationSeconds($startedAt, $completedAt);
        }

        $samples[$jobName] = $sample;
    }

    return $samples;
}

function calculateHeavyJobDurationTrendsDurationSeconds(string $startedAt, string $completedAt): float
{
    $started = strtotime($startedAt);
    $completed = strtotime($completedAt);

    if ($started === false || $completed === false || $completed < $started) {
        throw new RuntimeException('Invalid workflow job timestamps: ' . $startedAt . ' -> ' . $completedAt);
    }

    return (float) ($completed - $started);
}

/**
 * @param array{
 *     recent_window_size:int,
 *     baseline_window_size:int,
 *     min_recent_samples:int,
 *     min_baseline_samples:int,
 *     alert_threshold_ratio:float,
 *     min_absolute_increase_seconds:float,
 *     jobs:array<int, array{job_name:string,min_baseline_median_seconds:float}>
 * } $policy
 * @param array<string, array<string, mixed>> $currentRunJobs
 * @param array<string, array<int, array<string, mixed>>> $jobHistory
 * @return array{
 *     status:string,
 *     jobs:array<int, array<string, mixed>>,
 *     messages:array<int, string>
 * }
 */
function evaluateHeavyJobDurationTrends(array $policy, array $currentRunJobs, array $jobHistory): array
{
    $jobReports = [];
    $messages = [];

    foreach ($policy['jobs'] as $jobPolicy) {
        $jobName = $jobPolicy['job_name'];
        $history = $jobHistory[$jobName] ?? [];
        $currentRunSample = $currentRunJobs[$jobName] ?? null;

        $currentDuration = null;
        $currentRunIncluded = false;
        $recentDurations = [];
        $historyOffset = 0;
        $notes = [];

        if (is_array($currentRunSample)) {
            if (
                ($currentRunSample['conclusion'] ?? null) === 'success' &&
                isset($currentRunSample['duration_seconds'])
            ) {
                $currentDuration = (float) $currentRunSample['duration_seconds'];
                $recentDurations[] = $currentDuration;
                $currentRunIncluded = true;
            } else {
                $notes[] = sprintf(
                    'Current run sample was excluded because the job concluded as %s.',
                    (string) ($currentRunSample['conclusion'] ?? 'unknown'),
                );
            }
        }

        while (count($recentDurations) < $policy['recent_window_size'] && isset($history[$historyOffset])) {
            $sample = $history[$historyOffset];

            if (isset($sample['duration_seconds'])) {
                $recentDurations[] = (float) $sample['duration_seconds'];
            }

            $historyOffset++;
        }

        $baselineDurations = [];
        while (count($baselineDurations) < $policy['baseline_window_size'] && isset($history[$historyOffset])) {
            $sample = $history[$historyOffset];

            if (isset($sample['duration_seconds'])) {
                $baselineDurations[] = (float) $sample['duration_seconds'];
            }

            $historyOffset++;
        }

        $status = 'pass';
        $message = 'No sustained regression detected.';
        $recentMedian = null;
        $baselineMedian = null;
        $absoluteIncreaseSeconds = null;
        $percentIncrease = null;

        if (
            count($recentDurations) < $policy['min_recent_samples'] ||
            count($baselineDurations) < $policy['min_baseline_samples']
        ) {
            $status = 'insufficient_data';
            $message = sprintf(
                'Need at least %d recent and %d baseline successful samples; got %d recent and %d baseline.',
                $policy['min_recent_samples'],
                $policy['min_baseline_samples'],
                count($recentDurations),
                count($baselineDurations),
            );
        } else {
            $recentMedian = calculateHeavyJobDurationTrendsMedian($recentDurations);
            $baselineMedian = calculateHeavyJobDurationTrendsMedian($baselineDurations);
            $absoluteIncreaseSeconds = round($recentMedian - $baselineMedian, 2);
            $percentIncrease =
                $baselineMedian > 0.0 ? round((($recentMedian - $baselineMedian) / $baselineMedian) * 100.0, 2) : 0.0;

            $thresholdBreached =
                $baselineMedian >= $jobPolicy['min_baseline_median_seconds'] &&
                $absoluteIncreaseSeconds >= $policy['min_absolute_increase_seconds'] &&
                $percentIncrease >= $policy['alert_threshold_ratio'] * 100.0;

            if ($thresholdBreached) {
                $status = 'alert';
                $message = sprintf(
                    'Recent median rose by %.2f%% (%ss) versus baseline.',
                    $percentIncrease,
                    formatHeavyJobDurationTrendsDurationSeconds($absoluteIncreaseSeconds),
                );
                $messages[] = $jobName . ': ' . $message;
            } else {
                $message = sprintf(
                    'Recent median is %s versus baseline %s (delta %s / %.2f%%).',
                    formatHeavyJobDurationTrendsDurationSeconds($recentMedian),
                    formatHeavyJobDurationTrendsDurationSeconds($baselineMedian),
                    formatHeavyJobDurationTrendsDurationSeconds($absoluteIncreaseSeconds),
                    $percentIncrease,
                );
            }
        }

        if ($notes !== []) {
            $message .= ' ' . implode(' ', $notes);
        }

        $jobReports[] = [
            'job_name' => $jobName,
            'status' => $status,
            'message' => $message,
            'current_run_included' => $currentRunIncluded,
            'current_run_duration_seconds' => $currentDuration,
            'recent_samples' => count($recentDurations),
            'baseline_samples' => count($baselineDurations),
            'recent_durations_seconds' => $recentDurations,
            'baseline_durations_seconds' => $baselineDurations,
            'recent_median_seconds' => $recentMedian,
            'baseline_median_seconds' => $baselineMedian,
            'absolute_increase_seconds' => $absoluteIncreaseSeconds,
            'percent_increase' => $percentIncrease,
            'min_baseline_median_seconds' => $jobPolicy['min_baseline_median_seconds'],
            'absolute_threshold_seconds' => $policy['min_absolute_increase_seconds'],
            'percent_threshold' => round($policy['alert_threshold_ratio'] * 100.0, 2),
        ];
    }

    $statuses = array_column($jobReports, 'status');
    $overallStatus = 'pass';

    if (in_array('alert', $statuses, true)) {
        $overallStatus = 'alert';
    } elseif ($statuses !== [] && count(array_unique($statuses)) === 1 && $statuses[0] === 'insufficient_data') {
        $overallStatus = 'insufficient_data';
    }

    return [
        'status' => $overallStatus,
        'jobs' => $jobReports,
        'messages' => $messages,
    ];
}

/**
 * @param array{
 *     recent_window_size:int,
 *     baseline_window_size:int,
 *     min_recent_samples:int,
 *     min_baseline_samples:int,
 *     alert_threshold_ratio:float,
 *     min_absolute_increase_seconds:float,
 *     jobs:array<int, array{job_name:string,min_baseline_median_seconds:float}>
 * } $policy
 * @return array<string, mixed>
 */
function buildHeavyJobDurationTrendPolicySummary(array $policy): array
{
    return [
        'recent_window_size' => $policy['recent_window_size'],
        'baseline_window_size' => $policy['baseline_window_size'],
        'min_recent_samples' => $policy['min_recent_samples'],
        'min_baseline_samples' => $policy['min_baseline_samples'],
        'alert_threshold_ratio' => $policy['alert_threshold_ratio'],
        'min_absolute_increase_seconds' => $policy['min_absolute_increase_seconds'],
        'jobs' => $policy['jobs'],
    ];
}

/**
 * @param array<int, float> $values
 */
function calculateHeavyJobDurationTrendsMedian(array $values): float
{
    sort($values);
    $count = count($values);
    $middle = intdiv($count, 2);

    if ($count % 2 === 1) {
        return round($values[$middle], 2);
    }

    return round(($values[$middle - 1] + $values[$middle]) / 2.0, 2);
}

function formatHeavyJobDurationTrendsDurationSeconds(float $seconds): string
{
    $sign = $seconds < 0 ? '-' : '';
    $seconds = abs($seconds);
    $minutes = floor($seconds / 60);
    $remainingSeconds = $seconds - $minutes * 60;

    if ($minutes >= 1) {
        return sprintf('%s%dm %.0fs', $sign, $minutes, $remainingSeconds);
    }

    return sprintf('%s%.0fs', $sign, $remainingSeconds);
}

/**
 * @param array<int, array<string, mixed>> $jobReports
 */
function emitHeavyJobDurationTrendsMessages(array $jobReports): void
{
    foreach ($jobReports as $jobReport) {
        if (($jobReport['status'] ?? null) === 'alert') {
            fwrite(
                STDOUT,
                sprintf(
                    '::warning::%s duration regression detected. %s%s',
                    (string) $jobReport['job_name'],
                    (string) $jobReport['message'],
                    PHP_EOL,
                ),
            );
        }
    }
}

/**
 * @param array<string, mixed> $report
 */
function renderHeavyJobDurationTrendsSummary(array $report): string
{
    $policy = $report['policy'] ?? null;
    $jobs = $report['jobs'] ?? null;

    if (!is_array($policy) || !is_array($jobs)) {
        return '';
    }

    $lines = [
        '## Heavy Job Duration Trends',
        '',
        sprintf(
            'Status: `%s` for `%s` on `%s` (%s successful runs scanned).',
            (string) ($report['status'] ?? 'unknown'),
            (string) ($report['repository'] ?? 'unknown'),
            (string) ($report['branch'] ?? 'unknown'),
            (string) ($report['runs_scanned'] ?? '0'),
        ),
        '',
        sprintf(
            'Policy: recent median over %d samples vs previous %d samples; alert at >= %.0f%% and >= %s absolute increase.',
            (int) $policy['recent_window_size'],
            (int) $policy['baseline_window_size'],
            ((float) $policy['alert_threshold_ratio']) * 100.0,
            formatHeavyJobDurationTrendsDurationSeconds((float) $policy['min_absolute_increase_seconds']),
        ),
        '',
        '| Job | Status | Recent | Baseline | Delta | Notes |',
        '| --- | --- | --- | --- | --- | --- |',
    ];

    foreach ($jobs as $jobReport) {
        if (!is_array($jobReport)) {
            continue;
        }

        $delta = $jobReport['absolute_increase_seconds'];
        $deltaLabel = is_numeric($delta)
            ? sprintf(
                '%s / %.2f%%',
                formatHeavyJobDurationTrendsDurationSeconds((float) $delta),
                (float) ($jobReport['percent_increase'] ?? 0.0),
            )
            : 'n/a';

        $recentLabel = is_numeric($jobReport['recent_median_seconds'] ?? null)
            ? formatHeavyJobDurationTrendsDurationSeconds((float) $jobReport['recent_median_seconds'])
            : 'n/a';
        $baselineLabel = is_numeric($jobReport['baseline_median_seconds'] ?? null)
            ? formatHeavyJobDurationTrendsDurationSeconds((float) $jobReport['baseline_median_seconds'])
            : 'n/a';

        $lines[] = sprintf(
            '| %s | `%s` | %s | %s | %s | %s |',
            (string) $jobReport['job_name'],
            (string) $jobReport['status'],
            $recentLabel,
            $baselineLabel,
            $deltaLabel,
            escapeHeavyJobDurationTrendsMarkdownTableCell((string) ($jobReport['message'] ?? '')),
        );
    }

    $lines[] = '';
    $lines[] =
        'Review the JSON artifact `heavy-job-duration-trends-artifacts` or `storage/logs/ci/heavy-job-duration-trends-latest.json` for full sample details.';
    $lines[] = '';

    return implode(PHP_EOL, $lines);
}

function escapeHeavyJobDurationTrendsMarkdownTableCell(string $value): string
{
    return str_replace("\n", ' ', str_replace('|', '\|', trim($value)));
}

/**
 * @param array<string, mixed> $data
 */
function writeHeavyJobDurationTrendsJsonFile(string $path, array $data): void
{
    ensureHeavyJobDurationTrendsDirectory(dirname($path));
    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($encoded === false) {
        throw new RuntimeException('Failed to encode JSON report for ' . $path . '.');
    }

    if (file_put_contents($path, $encoded . PHP_EOL) === false) {
        throw new RuntimeException('Failed to write JSON report: ' . $path);
    }
}

function writeHeavyJobDurationTrendsTextFile(string $path, string $content): void
{
    ensureHeavyJobDurationTrendsDirectory(dirname($path));

    if (file_put_contents($path, $content) === false) {
        throw new RuntimeException('Failed to write summary file: ' . $path);
    }
}

function ensureHeavyJobDurationTrendsDirectory(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException('Failed to create directory: ' . $path);
    }
}
