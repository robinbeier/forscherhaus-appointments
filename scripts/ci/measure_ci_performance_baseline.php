<?php

declare(strict_types=1);

const CI_PERFORMANCE_BASELINE_EXIT_SUCCESS = 0;
const CI_PERFORMANCE_BASELINE_EXIT_RUNTIME_ERROR = 2;

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(runCiPerformanceBaselineCli($argv));
}

/**
 * @param array<int, string> $argv
 */
function runCiPerformanceBaselineCli(array $argv): int
{
    $config = ciPerformanceBaselineDefaultConfig();
    $report = [
        'schema_version' => 1,
        'status' => 'error',
        'generated_at_utc' => gmdate('c'),
    ];
    $exitCode = CI_PERFORMANCE_BASELINE_EXIT_RUNTIME_ERROR;

    try {
        parseCiPerformanceBaselineCliOptions($argv, $config);

        if ($config['help'] === true) {
            fwrite(STDOUT, ciPerformanceBaselineUsage());

            return CI_PERFORMANCE_BASELINE_EXIT_SUCCESS;
        }

        $policy = loadCiPerformanceBaselinePolicy($config['policy']);
        if ($config['api_fixture'] === null) {
            $requestJson = buildCiPerformanceBaselineGitHubApiRequestClosure($config);
            $requestText = buildCiPerformanceBaselineGitHubLogRequestClosure($config);
        } else {
            $fixture = loadCiPerformanceBaselineFixture((string) $config['api_fixture']);
            $requestJson = buildCiPerformanceBaselineFixtureRequestClosure($fixture);
            $requestText = buildCiPerformanceBaselineFixtureLogRequestClosure($fixture);
        }
        $cohort = fetchCiPerformanceBaselineCohort($requestJson, $requestText, $config, $policy);
        $evaluation = evaluateCiPerformanceBaseline($policy, $cohort['samples']);
        $report = [
            'schema_version' => 1,
            'status' => $evaluation['status'],
            'generated_at_utc' => gmdate('c'),
            'repository' => $config['repo'],
            'workflow_file' => $config['workflow_file'],
            'event' => $config['event'],
            'policy_file' => $config['policy'],
            'method' => buildCiPerformanceBaselineMethodSummary($policy),
            'selection' => [
                'runs_scanned' => $cohort['runs_scanned'],
                'eligible_runs' => count($cohort['samples']),
                'excluded_runs' => count($cohort['exclusions']),
                'exclusions' => $cohort['exclusions'],
            ],
            'runs' => $cohort['samples'],
            'metrics' => $evaluation['metrics'],
        ];

        $summary = renderCiPerformanceBaselineSummary($report);
        if ($summary !== '' && $config['output_summary'] !== null) {
            writeCiPerformanceBaselineTextFile($config['output_summary'], $summary);
        }

        if ($evaluation['status'] === 'insufficient_data') {
            fwrite(STDOUT, '[INFO] ci-performance-baseline needs more comparable successful PR runs.' . PHP_EOL);
        } else {
            fwrite(STDOUT, '[PASS] ci-performance-baseline measured the current full-gate PR cohort.' . PHP_EOL);
        }

        $exitCode = CI_PERFORMANCE_BASELINE_EXIT_SUCCESS;
    } catch (Throwable $e) {
        $report['status'] = 'error';
        $report['error'] = [
            'message' => $e->getMessage(),
            'exception' => get_class($e),
        ];
        fwrite(STDERR, '[ERROR] ci-performance-baseline failed: ' . $e->getMessage() . PHP_EOL);
    }

    try {
        writeCiPerformanceBaselineJsonFile($config['output_json'], $report);
        fwrite(STDOUT, '[INFO] Report: ' . $config['output_json'] . PHP_EOL);
    } catch (Throwable $e) {
        fwrite(STDERR, '[WARN] Failed to write ci-performance-baseline report: ' . $e->getMessage() . PHP_EOL);

        if ($exitCode === CI_PERFORMANCE_BASELINE_EXIT_SUCCESS) {
            $exitCode = CI_PERFORMANCE_BASELINE_EXIT_RUNTIME_ERROR;
        }
    }

    return $exitCode;
}

function ciPerformanceBaselineUsage(): string
{
    return implode(PHP_EOL, [
        'Usage: php scripts/ci/measure_ci_performance_baseline.php [options]',
        '',
        'Options:',
        '  --repo=OWNER/REPO      GitHub repository. Defaults to GITHUB_REPOSITORY.',
        '  --workflow-file=FILE   Workflow file name (default: ci.yml).',
        '  --event=EVENT          Workflow event filter (default: pull_request).',
        '  --policy=PATH          Baseline policy config PHP file path.',
        '  --output-json=PATH     JSON report path.',
        '  --output-summary=PATH  Optional markdown summary output path.',
        '  --per-page=N           Workflow runs requested per GitHub API page.',
        '  --max-runs=N           Maximum successful workflow runs to inspect.',
        '  --token-env=NAME       Environment variable holding the GitHub token.',
        '  --api-fixture=PATH     Read deterministic workflow/job responses from JSON instead of GitHub.',
        '  --help                 Show this help text.',
        '',
    ]);
}

/**
 * @return array<string, mixed>
 */
function ciPerformanceBaselineDefaultConfig(): array
{
    $root = dirname(__DIR__, 2);
    $summaryPath = getenv('GITHUB_STEP_SUMMARY');

    return [
        'repo' => getenv('GITHUB_REPOSITORY') !== false ? (string) getenv('GITHUB_REPOSITORY') : null,
        'workflow_file' => 'ci.yml',
        'event' => 'pull_request',
        'policy' => $root . '/scripts/ci/config/ci_performance_baseline_policy.php',
        'output_json' => $root . '/storage/logs/ci/ci-performance-baseline-latest.json',
        'output_summary' => $summaryPath !== false && $summaryPath !== '' ? (string) $summaryPath : null,
        'per_page' => 20,
        'max_runs' => 40,
        'token_env' => 'GITHUB_TOKEN',
        'api_fixture' => null,
        'github_api_url' =>
            getenv('GITHUB_API_URL') !== false ? (string) getenv('GITHUB_API_URL') : 'https://api.github.com',
        'help' => false,
    ];
}

/**
 * @param array<int, string> $argv
 * @param array<string, mixed> $config
 */
function parseCiPerformanceBaselineCliOptions(array $argv, array &$config): void
{
    $stringOptions = [
        '--repo=' => 'repo',
        '--workflow-file=' => 'workflow_file',
        '--event=' => 'event',
        '--policy=' => 'policy',
        '--output-json=' => 'output_json',
        '--output-summary=' => 'output_summary',
        '--token-env=' => 'token_env',
        '--api-fixture=' => 'api_fixture',
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help') {
            $config['help'] = true;
            continue;
        }

        $matched = false;
        foreach ($stringOptions as $prefix => $key) {
            if (!str_starts_with($arg, $prefix)) {
                continue;
            }

            $config[$key] = requireCiPerformanceBaselineNonEmptyCliValue($arg, $prefix);
            $matched = true;
            break;
        }

        if ($matched) {
            continue;
        }

        foreach (['--per-page=' => 'per_page', '--max-runs=' => 'max_runs'] as $prefix => $key) {
            if (!str_starts_with($arg, $prefix)) {
                continue;
            }

            $config[$key] = normalizeCiPerformanceBaselinePositiveInt(
                requireCiPerformanceBaselineNonEmptyCliValue($arg, $prefix),
                rtrim($prefix, '='),
            );
            $matched = true;
            break;
        }

        if (!$matched) {
            throw new RuntimeException('Unknown CLI option: ' . $arg);
        }
    }

    if ($config['help'] === false && $config['repo'] === null) {
        throw new RuntimeException('GitHub repository is required via --repo=OWNER/REPO or GITHUB_REPOSITORY.');
    }
}

function requireCiPerformanceBaselineNonEmptyCliValue(string $arg, string $prefix): string
{
    $value = substr($arg, strlen($prefix));

    if ($value === '') {
        throw new RuntimeException('CLI option ' . rtrim($prefix, '=') . ' requires a non-empty value.');
    }

    return $value;
}

function normalizeCiPerformanceBaselinePositiveInt(string $value, string $option): int
{
    if (!preg_match('/^[1-9][0-9]*$/', $value)) {
        throw new RuntimeException($option . ' must be a positive integer.');
    }

    return (int) $value;
}

/**
 * @return array<string, mixed>
 */
function loadCiPerformanceBaselinePolicy(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Missing CI performance baseline policy file: ' . $path);
    }

    $policy = require $path;
    if (!is_array($policy)) {
        throw new RuntimeException('CI performance baseline policy must return an array.');
    }

    $normalized = [
        'cohort_size' => normalizeCiPerformanceBaselinePositivePolicyInt($policy['cohort_size'] ?? null, 'cohort_size'),
        'minimum_samples' => normalizeCiPerformanceBaselinePositivePolicyInt(
            $policy['minimum_samples'] ?? null,
            'minimum_samples',
        ),
        'percentile_method' => (string) ($policy['percentile_method'] ?? ''),
        'required_success_jobs' => normalizeCiPerformanceBaselineJobList(
            $policy['required_success_jobs'] ?? null,
            'required_success_jobs',
        ),
        'tracked_jobs' => normalizeCiPerformanceBaselineJobList($policy['tracked_jobs'] ?? null, 'tracked_jobs'),
        'critical_path_jobs' => normalizeCiPerformanceBaselineJobList(
            $policy['critical_path_jobs'] ?? null,
            'critical_path_jobs',
        ),
        'comparison_profile' => normalizeCiPerformanceBaselineComparisonProfile($policy['comparison_profile'] ?? null),
    ];

    if ($normalized['minimum_samples'] > $normalized['cohort_size']) {
        throw new RuntimeException('minimum_samples must be <= cohort_size.');
    }

    if ($normalized['percentile_method'] !== 'nearest_rank') {
        throw new RuntimeException('percentile_method must be nearest_rank.');
    }

    $requiredLookup = array_fill_keys($normalized['required_success_jobs'], true);
    foreach (array_merge($normalized['tracked_jobs'], $normalized['critical_path_jobs']) as $jobName) {
        if (!isset($requiredLookup[$jobName])) {
            throw new RuntimeException($jobName . ' must also be listed in required_success_jobs.');
        }
    }

    return $normalized;
}

function normalizeCiPerformanceBaselinePositivePolicyInt(mixed $value, string $field): int
{
    if (!is_int($value) || $value < 1) {
        throw new RuntimeException($field . ' must be a positive integer.');
    }

    return $value;
}

/**
 * @return array<int, string>
 */
function normalizeCiPerformanceBaselineJobList(mixed $value, string $field): array
{
    if (!is_array($value) || $value === []) {
        throw new RuntimeException($field . ' must be a non-empty array.');
    }

    $jobs = [];
    foreach ($value as $index => $jobName) {
        if (!is_string($jobName) || trim($jobName) === '') {
            throw new RuntimeException(sprintf('%s[%d] must be a non-empty string.', $field, $index));
        }

        $jobs[] = trim($jobName);
    }

    if (count(array_unique($jobs)) !== count($jobs)) {
        throw new RuntimeException($field . ' must not contain duplicates.');
    }

    return $jobs;
}

/**
 * @return array<string, mixed>
 */
function normalizeCiPerformanceBaselineComparisonProfile(mixed $value): array
{
    if (!is_array($value)) {
        throw new RuntimeException('comparison_profile must be an array.');
    }

    $profileVersion = $value['profile_version'] ?? null;
    if ($profileVersion !== 1) {
        throw new RuntimeException('comparison_profile.profile_version must be 1.');
    }

    $consumerConclusions = normalizeCiPerformanceBaselineStringMap(
        $value['consumer_conclusions'] ?? null,
        'comparison_profile.consumer_conclusions',
    );
    foreach ($consumerConclusions as $jobName => $conclusion) {
        if (!in_array($conclusion, ['success', 'skipped'], true)) {
            throw new RuntimeException(
                'comparison_profile.consumer_conclusions.' . $jobName . ' must be success or skipped.',
            );
        }
    }

    return [
        'profile_version' => $profileVersion,
        'consumer_conclusions' => $consumerConclusions,
        'requested_suites' => normalizeCiPerformanceBaselineJobList(
            $value['requested_suites'] ?? null,
            'comparison_profile.requested_suites',
        ),
        'change_flags' => normalizeCiPerformanceBaselineScalarMap(
            $value['change_flags'] ?? null,
            'comparison_profile.change_flags',
        ),
        'mode_flags' => normalizeCiPerformanceBaselineScalarMap(
            $value['mode_flags'] ?? null,
            'comparison_profile.mode_flags',
        ),
    ];
}

/**
 * @return array<string, string>
 */
function normalizeCiPerformanceBaselineStringMap(mixed $value, string $field): array
{
    if (!is_array($value) || $value === [] || array_is_list($value)) {
        throw new RuntimeException($field . ' must be a non-empty map.');
    }

    $normalized = [];
    foreach ($value as $key => $item) {
        if (!is_string($key) || trim($key) === '' || !is_string($item) || trim($item) === '') {
            throw new RuntimeException($field . ' must contain non-empty string keys and values.');
        }
        $normalized[trim($key)] = trim($item);
    }

    return $normalized;
}

/**
 * @return array<string, bool|int|float|string>
 */
function normalizeCiPerformanceBaselineScalarMap(mixed $value, string $field): array
{
    if (!is_array($value) || $value === [] || array_is_list($value)) {
        throw new RuntimeException($field . ' must be a non-empty map.');
    }

    $normalized = [];
    foreach ($value as $key => $item) {
        if (
            !is_string($key) ||
            trim($key) === '' ||
            (!is_bool($item) && !is_int($item) && !is_float($item) && !is_string($item))
        ) {
            throw new RuntimeException($field . ' must contain scalar values with non-empty string keys.');
        }
        $normalized[trim($key)] = $item;
    }

    return $normalized;
}

/**
 * @param array<string, mixed> $config
 * @return Closure(string): array<string, mixed>
 */
function buildCiPerformanceBaselineGitHubApiRequestClosure(array $config): Closure
{
    $token = getenv((string) $config['token_env']);
    if (!is_string($token) || trim($token) === '') {
        throw new RuntimeException('Missing GitHub token in environment variable ' . $config['token_env'] . '.');
    }

    $baseUrl = rtrim((string) $config['github_api_url'], '/');

    return static function (string $path) use ($token, $baseUrl): array {
        $curl = curl_init($baseUrl . $path);
        if ($curl === false) {
            throw new RuntimeException('Failed to initialize curl for GitHub API request.');
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github+json',
                'Authorization: Bearer ' . $token,
                'User-Agent: forscherhaus-ci-performance-baseline',
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
            throw new RuntimeException(
                sprintf(
                    'GitHub API request %s failed with HTTP %d: %s',
                    $path,
                    $httpCode,
                    (string) ($decoded['message'] ?? 'unknown GitHub API error'),
                ),
            );
        }

        return $decoded;
    };
}

/**
 * @param array<string, mixed> $config
 * @return Closure(string): string
 */
function buildCiPerformanceBaselineGitHubLogRequestClosure(array $config): Closure
{
    $token = getenv((string) $config['token_env']);
    if (!is_string($token) || trim($token) === '') {
        throw new RuntimeException('Missing GitHub token in environment variable ' . $config['token_env'] . '.');
    }

    $baseUrl = rtrim((string) $config['github_api_url'], '/');

    return static function (string $path) use ($token, $baseUrl): string {
        $curl = curl_init($baseUrl . $path);
        if ($curl === false) {
            throw new RuntimeException('Failed to initialize curl for GitHub log request.');
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github+json',
                'Authorization: Bearer ' . $token,
                'User-Agent: forscherhaus-ci-performance-baseline',
                'X-GitHub-Api-Version: 2022-11-28',
            ],
        ]);

        $response = curl_exec($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);

        if ($response === false) {
            throw new RuntimeException('GitHub log request failed: ' . $error);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException(sprintf('GitHub log request %s failed with HTTP %d.', $path, $httpCode));
        }

        return $response;
    };
}

/**
 * @return array<string, mixed>
 */
function loadCiPerformanceBaselineFixture(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Missing CI performance baseline API fixture: ' . $path);
    }

    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('Failed to read CI performance baseline API fixture: ' . $path);
    }

    $fixture = json_decode($content, true);
    if (!is_array($fixture)) {
        throw new RuntimeException('CI performance baseline API fixture must contain a JSON object.');
    }

    return $fixture;
}

/**
 * @param array<string, mixed> $fixture
 * @return Closure(string): array<string, mixed>
 */
function buildCiPerformanceBaselineFixtureRequestClosure(array $fixture): Closure
{
    $workflowRuns = $fixture['workflow_runs'] ?? null;
    $jobsByRunId = $fixture['jobs_by_run_id'] ?? null;
    if (!is_array($workflowRuns) || !is_array($jobsByRunId)) {
        throw new RuntimeException('CI performance baseline API fixture needs workflow_runs and jobs_by_run_id.');
    }

    return static function (string $requestPath) use ($workflowRuns, $jobsByRunId): array {
        if (str_contains($requestPath, '/actions/workflows/') && str_contains($requestPath, '/runs?')) {
            $query = [];
            parse_str((string) parse_url($requestPath, PHP_URL_QUERY), $query);
            $page = max(1, (int) ($query['page'] ?? 1));
            $perPage = max(1, (int) ($query['per_page'] ?? 20));

            return ['workflow_runs' => array_slice($workflowRuns, ($page - 1) * $perPage, $perPage)];
        }

        if (preg_match('~/actions/runs/(?<run_id>[0-9]+)/jobs(?:\\?|$)~', $requestPath, $matches) === 1) {
            $runId = $matches['run_id'];
            if (!array_key_exists($runId, $jobsByRunId) || !is_array($jobsByRunId[$runId])) {
                throw new RuntimeException('API fixture is missing jobs for run ' . $runId . '.');
            }

            $query = [];
            parse_str((string) parse_url($requestPath, PHP_URL_QUERY), $query);
            $page = max(1, (int) ($query['page'] ?? 1));
            $perPage = max(1, (int) ($query['per_page'] ?? 100));

            return [
                'total_count' => count($jobsByRunId[$runId]),
                'jobs' => array_slice($jobsByRunId[$runId], ($page - 1) * $perPage, $perPage),
            ];
        }

        throw new RuntimeException('API fixture does not support request path ' . $requestPath . '.');
    };
}

/**
 * @param array<string, mixed> $fixture
 * @return Closure(string): string
 */
function buildCiPerformanceBaselineFixtureLogRequestClosure(array $fixture): Closure
{
    $logsByJobId = $fixture['job_logs_by_job_id'] ?? null;
    if (!is_array($logsByJobId)) {
        throw new RuntimeException('CI performance baseline API fixture needs job_logs_by_job_id.');
    }

    return static function (string $requestPath) use ($logsByJobId): string {
        if (preg_match('~/actions/jobs/(?<job_id>[0-9]+)/logs$~', $requestPath, $matches) !== 1) {
            throw new RuntimeException('API fixture does not support log request path ' . $requestPath . '.');
        }

        $jobId = $matches['job_id'];
        if (!array_key_exists($jobId, $logsByJobId) || !is_string($logsByJobId[$jobId])) {
            throw new RuntimeException('API fixture is missing logs for job ' . $jobId . '.');
        }

        return $logsByJobId[$jobId];
    };
}

/**
 * @param Closure(string): array<string, mixed> $requestJson
 * @param Closure(string): string $requestText
 * @param array<string, mixed> $config
 * @param array<string, mixed> $policy
 * @return array{runs_scanned:int,samples:array<int, array<string, mixed>>,exclusions:array<int, array<string, mixed>>}
 */
function fetchCiPerformanceBaselineCohort(
    Closure $requestJson,
    Closure $requestText,
    array $config,
    array $policy,
): array {
    $samples = [];
    $exclusions = [];
    $runsScanned = 0;
    $page = 1;

    while ($runsScanned < $config['max_runs'] && count($samples) < $policy['cohort_size']) {
        $payload = $requestJson(
            sprintf(
                '/repos/%s/actions/workflows/%s/runs?%s',
                $config['repo'],
                rawurlencode((string) $config['workflow_file']),
                http_build_query([
                    'event' => $config['event'],
                    'status' => 'success',
                    'per_page' => $config['per_page'],
                    'page' => $page,
                ]),
            ),
        );
        $runs = $payload['workflow_runs'] ?? null;

        if (!is_array($runs) || $runs === []) {
            break;
        }

        foreach ($runs as $run) {
            if (!is_array($run) || !isset($run['id'])) {
                continue;
            }

            $runsScanned++;
            $runId = (int) $run['id'];
            $jobs = fetchCiPerformanceBaselineJobs($requestJson, (string) $config['repo'], $runId);
            $deepRuntimeLog = '';
            $logError = null;
            foreach ($jobs as $job) {
                if (!is_array($job) || ($job['name'] ?? null) !== 'deep-runtime-suite') {
                    continue;
                }

                $jobId = (int) ($job['id'] ?? 0);
                if ($jobId < 1) {
                    $logError = 'deep-runtime-suite job did not expose a job id';
                    break;
                }

                try {
                    $deepRuntimeLog = $requestText(sprintf('/repos/%s/actions/jobs/%d/logs', $config['repo'], $jobId));
                } catch (Throwable $e) {
                    $logError = 'failed to read deep-runtime-suite log: ' . $e->getMessage();
                }
                break;
            }

            $candidate = buildCiPerformanceBaselineRunSample($run, $jobs, $policy, $deepRuntimeLog);
            if ($logError !== null) {
                $candidate['eligible'] = false;
                $candidate['sample'] = [];
                $candidate['reasons'][] = $logError;
                $candidate['reasons'] = array_values(array_unique($candidate['reasons']));
            }
            if ($candidate['eligible'] === true) {
                $samples[] = $candidate['sample'];
            } else {
                $exclusions[] = [
                    'run_id' => $runId,
                    'url' => (string) ($run['html_url'] ?? ''),
                    'reasons' => $candidate['reasons'],
                ];
            }

            if ($runsScanned >= $config['max_runs'] || count($samples) >= $policy['cohort_size']) {
                break;
            }
        }

        if (count($runs) < $config['per_page']) {
            break;
        }

        $page++;
    }

    return [
        'runs_scanned' => $runsScanned,
        'samples' => $samples,
        'exclusions' => $exclusions,
    ];
}

/**
 * @param Closure(string): array<string, mixed> $request
 * @return array<int, mixed>
 */
function fetchCiPerformanceBaselineJobs(Closure $request, string $repo, int $runId): array
{
    $jobs = [];
    $page = 1;
    $perPage = 100;

    while (true) {
        $payload = $request(
            sprintf(
                '/repos/%s/actions/runs/%d/jobs?%s',
                $repo,
                $runId,
                http_build_query(['per_page' => $perPage, 'page' => $page]),
            ),
        );
        $pageJobs = $payload['jobs'] ?? null;
        if (!is_array($pageJobs)) {
            throw new RuntimeException('GitHub API response for workflow jobs is missing jobs[].');
        }

        array_push($jobs, ...$pageJobs);
        if (count($pageJobs) < $perPage) {
            break;
        }
        $page++;
    }

    return $jobs;
}

/**
 * @param array<string, mixed> $run
 * @param array<int, mixed> $jobs
 * @param array<string, mixed> $policy
 * @return array{eligible:bool,reasons:array<int, string>,sample:array<string, mixed>}
 */
function buildCiPerformanceBaselineRunSample(array $run, array $jobs, array $policy, string $deepRuntimeLog = ''): array
{
    $reasons = [];
    $runId = (int) ($run['id'] ?? 0);
    $createdAt = ciPerformanceBaselineTimestamp($run['created_at'] ?? null, 'run.created_at');
    $jobsByName = [];

    if (($run['conclusion'] ?? null) !== 'success') {
        $reasons[] = 'workflow conclusion was not success';
    }

    $runAttempt = $run['run_attempt'] ?? null;
    if (!is_int($runAttempt)) {
        $reasons[] = 'workflow run_attempt was missing or invalid';
    } elseif ($runAttempt !== 1) {
        $reasons[] = 'workflow run attempt was not 1';
    }

    foreach ($jobs as $job) {
        if (!is_array($job)) {
            continue;
        }

        $name = trim((string) ($job['name'] ?? ''));
        if ($name !== '') {
            $jobsByName[$name] = $job;
        }
    }

    foreach ($policy['required_success_jobs'] as $requiredJob) {
        $job = $jobsByName[$requiredJob] ?? null;
        if (!is_array($job) || ($job['conclusion'] ?? null) !== 'success') {
            $reasons[] = 'required job not successful: ' . $requiredJob;
        }
    }

    $comparisonProfile = buildCiPerformanceBaselineComparisonProfile(
        $run,
        $jobsByName,
        $deepRuntimeLog,
        $policy['comparison_profile'],
    );
    $expectedFingerprint = fingerprintCiPerformanceBaselineComparisonProfile($policy['comparison_profile']);
    $observedFingerprint = fingerprintCiPerformanceBaselineComparisonProfile($comparisonProfile);
    if ($observedFingerprint !== $expectedFingerprint) {
        $reasons[] = sprintf(
            'comparison profile fingerprint mismatch: expected %s, observed %s',
            $expectedFingerprint,
            $observedFingerprint,
        );
    }

    $activeJobs = [];
    foreach ($jobsByName as $jobName => $job) {
        if (($job['conclusion'] ?? null) === 'skipped') {
            continue;
        }

        try {
            $startedAt = ciPerformanceBaselineTimestamp($job['started_at'] ?? null, $jobName . '.started_at');
            $completedAt = ciPerformanceBaselineTimestamp($job['completed_at'] ?? null, $jobName . '.completed_at');
            $jobCreatedAt = ciPerformanceBaselineTimestamp($job['created_at'] ?? null, $jobName . '.created_at');
            ciPerformanceBaselineRequireOrderedTimestamps($jobCreatedAt, $startedAt, $jobName . ' queue');
            ciPerformanceBaselineRequireOrderedTimestamps($startedAt, $completedAt, $jobName . ' duration');
            $activeJobs[$jobName] = [
                'started_at_epoch' => $startedAt,
                'completed_at_epoch' => $completedAt,
                'duration_seconds' => (float) ($completedAt - $startedAt),
                'queue_seconds' => (float) ($startedAt - $jobCreatedAt),
                'steps' => is_array($job['steps'] ?? null) ? $job['steps'] : [],
            ];
        } catch (RuntimeException $e) {
            $reasons[] = $e->getMessage();
        }
    }

    if ($activeJobs === []) {
        $reasons[] = 'run has no active jobs with complete timestamps';
    }

    if ($reasons !== []) {
        return ['eligible' => false, 'reasons' => array_values(array_unique($reasons)), 'sample' => []];
    }

    $firstStartedAt = min(array_column($activeJobs, 'started_at_epoch'));
    $lastCompletedAt = max(array_column($activeJobs, 'completed_at_epoch'));
    ciPerformanceBaselineRequireOrderedTimestamps($createdAt, $firstStartedAt, 'initial workflow queue');
    ciPerformanceBaselineRequireOrderedTimestamps($createdAt, $lastCompletedAt, 'workflow elapsed');

    $latestJobs = [];
    foreach ($activeJobs as $jobName => $job) {
        if ($job['completed_at_epoch'] === $lastCompletedAt) {
            $latestJobs[] = $jobName;
        }
    }

    $trackedJobs = [];
    $stepDurations = [];
    foreach ($policy['tracked_jobs'] as $jobName) {
        $job = $activeJobs[$jobName];
        $trackedJobs[$jobName] = $job['duration_seconds'];

        foreach ($job['steps'] as $step) {
            if (!is_array($step) || ($step['conclusion'] ?? null) !== 'success') {
                continue;
            }

            try {
                $stepStarted = ciPerformanceBaselineTimestamp(
                    $step['started_at'] ?? null,
                    $jobName . '.' . (string) ($step['name'] ?? 'unknown') . '.started_at',
                );
                $stepCompleted = ciPerformanceBaselineTimestamp(
                    $step['completed_at'] ?? null,
                    $jobName . '.' . (string) ($step['name'] ?? 'unknown') . '.completed_at',
                );
                ciPerformanceBaselineRequireOrderedTimestamps($stepStarted, $stepCompleted, $jobName . ' step');
            } catch (RuntimeException) {
                continue;
            }

            $stepName = trim((string) ($step['name'] ?? ''));
            if ($stepName !== '') {
                $stepDurations[$jobName . ' :: ' . $stepName] = (float) ($stepCompleted - $stepStarted);
            }
        }
    }

    return [
        'eligible' => true,
        'reasons' => [],
        'sample' => [
            'run_id' => $runId,
            'url' => (string) ($run['html_url'] ?? ''),
            'created_at' => (string) ($run['created_at'] ?? ''),
            'head_branch' => (string) ($run['head_branch'] ?? ''),
            'head_sha' => (string) ($run['head_sha'] ?? ''),
            'run_attempt' => $runAttempt,
            'comparison_profile' => [
                'fingerprint' => $observedFingerprint,
                'components' => $comparisonProfile,
            ],
            'workflow_elapsed_seconds' => (float) ($lastCompletedAt - $createdAt),
            'initial_queue_seconds' => (float) ($firstStartedAt - $createdAt),
            'max_job_queue_seconds' => max(array_column($activeJobs, 'queue_seconds')),
            'latest_jobs' => $latestJobs,
            'tracked_job_durations_seconds' => $trackedJobs,
            'tracked_step_durations_seconds' => $stepDurations,
        ],
    ];
}

/**
 * @param array<string, mixed> $run
 * @param array<string, array<string, mixed>> $jobsByName
 * @param array<string, mixed> $expectedProfile
 * @return array<string, mixed>
 */
function buildCiPerformanceBaselineComparisonProfile(
    array $run,
    array $jobsByName,
    string $deepRuntimeLog,
    array $expectedProfile,
): array {
    $consumerConclusions = [];
    foreach (array_keys($expectedProfile['consumer_conclusions']) as $jobName) {
        $conclusion = $jobsByName[$jobName]['conclusion'] ?? null;
        $consumerConclusions[$jobName] = is_string($conclusion) && $conclusion !== '' ? $conclusion : 'missing';
    }

    $requestedSuites = extractCiPerformanceBaselineRequestedSuites($deepRuntimeLog);
    $isActive = static fn(string $jobName): bool => isset($consumerConclusions[$jobName]) &&
        !in_array($consumerConclusions[$jobName], ['missing', 'skipped'], true);

    $deepRuntimeAssetBuildRequired = false;
    foreach ($jobsByName['deep-runtime-suite']['steps'] ?? [] as $step) {
        if (
            is_array($step) &&
            ($step['name'] ?? null) === 'Build runtime JS assets' &&
            ($step['conclusion'] ?? null) === 'success'
        ) {
            $deepRuntimeAssetBuildRequired = true;
            break;
        }
    }
    $includeLdap = extractCiPerformanceBaselineBooleanFlag($deepRuntimeLog, 'integration-smoke-include-ldap');

    return [
        'profile_version' => 1,
        'consumer_conclusions' => $consumerConclusions,
        'requested_suites' => $requestedSuites,
        'change_flags' => [
            'request_contracts_required' => $isActive('typed-request-contracts'),
            'deep_bootstrap_required' => $isActive('deep-check-bootstrap'),
            'deep_runtime_asset_build_required' => $deepRuntimeAssetBuildRequired,
            'coverage_required' =>
                $isActive('coverage-shard-unit') &&
                $isActive('coverage-shard-integration') &&
                $isActive('coverage-delta'),
            'heavy_job_trends_required' => $isActive('heavy-job-duration-trends'),
            'api_contract' => $isActive('api-contract-openapi'),
            'write_contract_booking' => $isActive('write-contract-booking'),
            'write_contract_api' => $isActive('write-contract-api'),
            'booking_flows' => $isActive('booking-controller-flows'),
            'integration_smoke' => $isActive('integration-smoke'),
            'ldap_guardrail_required' => $includeLdap === true && ($run['event'] ?? null) === 'pull_request',
            'pdf_renderer_latency_required' => $isActive('pdf-renderer-latency'),
        ],
        'mode_flags' => [
            'event' => (string) ($run['event'] ?? 'missing'),
            'run_attempt' => is_int($run['run_attempt'] ?? null) ? $run['run_attempt'] : 'missing',
            'booking_search_days' => extractCiPerformanceBaselineIntegerFlag($deepRuntimeLog, 'booking-search-days'),
            'retry_count' => extractCiPerformanceBaselineIntegerFlag($deepRuntimeLog, 'retry-count'),
            'start_date' => extractCiPerformanceBaselineStringFlag($deepRuntimeLog, 'start-date'),
            'end_date' => extractCiPerformanceBaselineStringFlag($deepRuntimeLog, 'end-date'),
            'integration_smoke_include_ldap' => $includeLdap,
            'integration_smoke_browser_bootstrap_timeout' => extractCiPerformanceBaselineIntegerFlag(
                $deepRuntimeLog,
                'integration-smoke-browser-bootstrap-timeout',
            ),
            'integration_smoke_browser_evidence' => extractCiPerformanceBaselineStringFlag(
                $deepRuntimeLog,
                'integration-smoke-browser-evidence',
            ),
            'playwright_runtime_package' => extractCiPerformanceBaselineEnvironmentValue(
                $deepRuntimeLog,
                'PLAYWRIGHT_RUNTIME_PACKAGE',
            ),
        ],
    ];
}

/**
 * @return array<int, string>
 */
function extractCiPerformanceBaselineRequestedSuites(string $log): array
{
    if (preg_match('/--suites=([a-z0-9,-]+)/', $log, $matches) !== 1) {
        return [];
    }

    return array_values(array_filter(explode(',', $matches[1]), static fn(string $suite): bool => $suite !== ''));
}

function extractCiPerformanceBaselineBooleanFlag(string $log, string $flag): ?bool
{
    if (preg_match('/--' . preg_quote($flag, '/') . '=(true|false)/', $log, $matches) !== 1) {
        return null;
    }

    return $matches[1] === 'true';
}

function extractCiPerformanceBaselineIntegerFlag(string $log, string $flag): ?int
{
    if (preg_match('/--' . preg_quote($flag, '/') . '=([0-9]+)/', $log, $matches) !== 1) {
        return null;
    }

    return (int) $matches[1];
}

function extractCiPerformanceBaselineStringFlag(string $log, string $flag): ?string
{
    if (preg_match('/--' . preg_quote($flag, '/') . '=([a-z0-9_-]+)/', $log, $matches) !== 1) {
        return null;
    }

    return $matches[1];
}

function extractCiPerformanceBaselineEnvironmentValue(string $log, string $name): ?string
{
    if (preg_match('/(?:^|\\s)(?:-e\\s+)?' . preg_quote($name, '/') . '=([^\\s]+)/', $log, $matches) !== 1) {
        return null;
    }

    return $matches[1];
}

/**
 * @param array<string, mixed> $profile
 */
function fingerprintCiPerformanceBaselineComparisonProfile(array $profile): string
{
    $encoded = json_encode(
        canonicalizeCiPerformanceBaselineValue($profile),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );

    return 'sha256:' . hash('sha256', $encoded);
}

function canonicalizeCiPerformanceBaselineValue(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }

    if (array_is_list($value)) {
        return array_map('canonicalizeCiPerformanceBaselineValue', $value);
    }

    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = canonicalizeCiPerformanceBaselineValue($item);
    }

    return $value;
}

function ciPerformanceBaselineTimestamp(mixed $value, string $field): int
{
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException('Missing timestamp: ' . $field);
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        throw new RuntimeException('Invalid timestamp: ' . $field);
    }

    return $timestamp;
}

function ciPerformanceBaselineRequireOrderedTimestamps(int $start, int $end, string $field): void
{
    if ($end < $start) {
        throw new RuntimeException('Invalid timestamp order: ' . $field);
    }
}

/**
 * @param array<string, mixed> $policy
 * @param array<int, array<string, mixed>> $samples
 * @return array{status:string,metrics:array<string, mixed>}
 */
function evaluateCiPerformanceBaseline(array $policy, array $samples): array
{
    $status = count($samples) >= $policy['minimum_samples'] ? 'pass' : 'insufficient_data';
    $jobSamples = array_fill_keys($policy['tracked_jobs'], []);
    $stepSamples = [];
    $observedTerminalJobCounts = [];

    foreach ($samples as $sample) {
        foreach ($policy['tracked_jobs'] as $jobName) {
            if (isset($sample['tracked_job_durations_seconds'][$jobName])) {
                $jobSamples[$jobName][] = (float) $sample['tracked_job_durations_seconds'][$jobName];
            }
        }

        foreach ($sample['tracked_step_durations_seconds'] as $stepName => $duration) {
            $stepSamples[$stepName][] = (float) $duration;
        }

        foreach ($sample['latest_jobs'] ?? [] as $jobName) {
            if (!is_string($jobName) || $jobName === '') {
                continue;
            }
            $observedTerminalJobCounts[$jobName] = ($observedTerminalJobCounts[$jobName] ?? 0) + 1;
        }
    }
    arsort($observedTerminalJobCounts, SORT_NUMERIC);

    $jobs = [];
    foreach ($jobSamples as $jobName => $durations) {
        $jobs[] = ['job_name' => $jobName] + summarizeCiPerformanceBaselineValues($durations);
    }

    $steps = [];
    foreach ($stepSamples as $stepName => $durations) {
        if (count($durations) !== count($samples)) {
            continue;
        }

        $steps[] = ['phase' => $stepName] + summarizeCiPerformanceBaselineValues($durations);
    }
    usort($steps, static fn(array $left, array $right): int => $right['median_seconds'] <=> $left['median_seconds']);

    return [
        'status' => $status,
        'metrics' => [
            'workflow_elapsed' => summarizeCiPerformanceBaselineValues(
                array_map(static fn(array $sample): float => (float) $sample['workflow_elapsed_seconds'], $samples),
            ),
            'initial_queue' => summarizeCiPerformanceBaselineValues(
                array_map(static fn(array $sample): float => (float) $sample['initial_queue_seconds'], $samples),
            ),
            'max_job_queue' => summarizeCiPerformanceBaselineValues(
                array_map(static fn(array $sample): float => (float) $sample['max_job_queue_seconds'], $samples),
            ),
            'critical_path' => [
                'reference_jobs' => $policy['critical_path_jobs'],
                'observed_terminal_job_counts' => $observedTerminalJobCounts,
            ],
            'jobs' => $jobs,
            'phases' => $steps,
        ],
    ];
}

/**
 * @param array<int, float> $values
 * @return array<string, int|float|null>
 */
function summarizeCiPerformanceBaselineValues(array $values): array
{
    if ($values === []) {
        return [
            'sample_count' => 0,
            'median_seconds' => null,
            'p75_seconds' => null,
            'min_seconds' => null,
            'max_seconds' => null,
        ];
    }

    sort($values, SORT_NUMERIC);

    return [
        'sample_count' => count($values),
        'median_seconds' => calculateCiPerformanceBaselineMedian($values),
        'p75_seconds' => calculateCiPerformanceBaselineNearestRankPercentile($values, 0.75),
        'min_seconds' => round((float) min($values), 2),
        'max_seconds' => round((float) max($values), 2),
    ];
}

/**
 * @param array<int, float> $sortedValues
 */
function calculateCiPerformanceBaselineMedian(array $sortedValues): float
{
    $count = count($sortedValues);
    if ($count === 0) {
        throw new RuntimeException('Cannot calculate median for an empty sample.');
    }

    $middle = intdiv($count, 2);
    if ($count % 2 === 1) {
        return round((float) $sortedValues[$middle], 2);
    }

    return round(((float) $sortedValues[$middle - 1] + (float) $sortedValues[$middle]) / 2.0, 2);
}

/**
 * @param array<int, float> $sortedValues
 */
function calculateCiPerformanceBaselineNearestRankPercentile(array $sortedValues, float $percentile): float
{
    if ($sortedValues === [] || $percentile <= 0.0 || $percentile > 1.0) {
        throw new RuntimeException('Nearest-rank percentile needs samples and a percentile in (0, 1].');
    }

    $rank = (int) ceil($percentile * count($sortedValues));

    return round((float) $sortedValues[$rank - 1], 2);
}

/**
 * @param array<string, mixed> $policy
 * @return array<string, mixed>
 */
function buildCiPerformanceBaselineMethodSummary(array $policy): array
{
    return [
        'cohort_size' => $policy['cohort_size'],
        'minimum_samples' => $policy['minimum_samples'],
        'percentile_method' => $policy['percentile_method'],
        'required_success_jobs' => $policy['required_success_jobs'],
        'tracked_jobs' => $policy['tracked_jobs'],
        'critical_path_jobs' => $policy['critical_path_jobs'],
        'comparison_profile' => [
            'fingerprint' => fingerprintCiPerformanceBaselineComparisonProfile($policy['comparison_profile']),
            'components' => $policy['comparison_profile'],
        ],
    ];
}

/**
 * @param array<string, mixed> $report
 */
function renderCiPerformanceBaselineSummary(array $report): string
{
    $metrics = $report['metrics'] ?? null;
    if (!is_array($metrics)) {
        return '';
    }

    $workflow = $metrics['workflow_elapsed'];
    $queue = $metrics['initial_queue'];
    $criticalPath = $metrics['critical_path'];
    $lines = [
        '## CI Performance Baseline',
        '',
        sprintf(
            'Status: `%s`; %d comparable successful full-gate PR runs.',
            (string) ($report['status'] ?? 'unknown'),
            (int) ($workflow['sample_count'] ?? 0),
        ),
        '',
        sprintf(
            'Comparison profile: `%s`.',
            (string) ($report['method']['comparison_profile']['fingerprint'] ?? 'missing'),
        ),
        '',
        sprintf(
            'End-to-end: median %s, p75 %s. Initial queue: median %s, p75 %s.',
            formatCiPerformanceBaselineOptionalSeconds($workflow['median_seconds'] ?? null),
            formatCiPerformanceBaselineOptionalSeconds($workflow['p75_seconds'] ?? null),
            formatCiPerformanceBaselineOptionalSeconds($queue['median_seconds'] ?? null),
            formatCiPerformanceBaselineOptionalSeconds($queue['p75_seconds'] ?? null),
        ),
        '',
        'Reference dependency path: `' . implode(' -> ', $criticalPath['reference_jobs']) . '`.',
        sprintf(
            'Observed terminal jobs: %s.',
            formatCiPerformanceBaselineTerminalJobCounts($criticalPath['observed_terminal_job_counts']),
        ),
        '',
        '| Job | Samples | Median | p75 |',
        '| --- | ---: | ---: | ---: |',
    ];

    foreach ($metrics['jobs'] as $job) {
        $lines[] = sprintf(
            '| %s | %d | %s | %s |',
            (string) $job['job_name'],
            (int) $job['sample_count'],
            formatCiPerformanceBaselineOptionalSeconds($job['median_seconds'] ?? null),
            formatCiPerformanceBaselineOptionalSeconds($job['p75_seconds'] ?? null),
        );
    }

    $lines[] = '';
    $lines[] = 'Largest fully observed phases:';
    foreach (array_slice($metrics['phases'], 0, 3) as $phase) {
        $lines[] = sprintf(
            '- %s: median %s, p75 %s',
            (string) $phase['phase'],
            formatCiPerformanceBaselineSeconds((float) $phase['median_seconds']),
            formatCiPerformanceBaselineSeconds((float) $phase['p75_seconds']),
        );
    }
    $lines[] = '';
    $lines[] = 'The report is measurement-only; it does not define or enforce a performance goal.';
    $lines[] = '';

    return implode(PHP_EOL, $lines);
}

function formatCiPerformanceBaselineSeconds(float $seconds): string
{
    $minutes = floor($seconds / 60.0);
    $remainder = $seconds - $minutes * 60.0;

    return $minutes >= 1 ? sprintf('%dm %.0fs', $minutes, $remainder) : sprintf('%.0fs', $remainder);
}

function formatCiPerformanceBaselineOptionalSeconds(mixed $seconds): string
{
    if (!is_int($seconds) && !is_float($seconds)) {
        return 'n/a';
    }

    return formatCiPerformanceBaselineSeconds((float) $seconds);
}

/**
 * @param array<string, int> $counts
 */
function formatCiPerformanceBaselineTerminalJobCounts(array $counts): string
{
    if ($counts === []) {
        return 'n/a';
    }

    $parts = [];
    foreach ($counts as $jobName => $count) {
        $parts[] = sprintf('`%s` (%d)', $jobName, $count);
    }

    return implode(', ', $parts);
}

/**
 * @param array<string, mixed> $data
 */
function writeCiPerformanceBaselineJsonFile(string $path, array $data): void
{
    ensureCiPerformanceBaselineDirectory(dirname($path));
    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false || file_put_contents($path, $encoded . PHP_EOL) === false) {
        throw new RuntimeException('Failed to write JSON report: ' . $path);
    }
}

function writeCiPerformanceBaselineTextFile(string $path, string $content): void
{
    ensureCiPerformanceBaselineDirectory(dirname($path));
    if (file_put_contents($path, $content) === false) {
        throw new RuntimeException('Failed to write summary file: ' . $path);
    }
}

function ensureCiPerformanceBaselineDirectory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException('Failed to create directory: ' . $path);
    }
}
