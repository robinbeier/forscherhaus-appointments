<?php

declare(strict_types=1);

use CiContract\ExactHeadMergegate;

require_once __DIR__ . '/lib/ExactHeadMergegate.php';

const EXACT_HEAD_MERGEGATE_EXIT_SUCCESS = 0;
const EXACT_HEAD_MERGEGATE_EXIT_NOT_READY = 1;
const EXACT_HEAD_MERGEGATE_EXIT_RUNTIME_ERROR = 2;
const EXACT_HEAD_MERGEGATE_PAGE_SIZE = 100;
const EXACT_HEAD_MERGEGATE_MAX_PAGES = 20;

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(runExactHeadMergegateCli($argv));
}

/**
 * @param array<int, string> $argv
 * @param Closure(string): array<string, mixed>|null $request
 * @param Closure(): string|null $repositoryResolver
 * @param Closure(string, string, string): array<string, mixed>|null $policyLoader
 */
function runExactHeadMergegateCli(
    array $argv,
    ?Closure $request = null,
    ?Closure $repositoryResolver = null,
    ?string $repoRoot = null,
    ?Closure $policyLoader = null,
): int {
    $root = $repoRoot ?? dirname(__DIR__, 2);
    $config = exactHeadMergegateDefaultConfig($root);
    $report = [
        'schema_version' => 1,
        'status' => 'error',
        'generated_at_utc' => gmdate('c'),
    ];

    try {
        parseExactHeadMergegateCliOptions($argv, $config);
        if ($config['help']) {
            fwrite(STDOUT, exactHeadMergegateUsage());

            return EXACT_HEAD_MERGEGATE_EXIT_SUCCESS;
        }

        if ($config['pr'] === null || $config['reviewed_sha'] === null) {
            throw new RuntimeException('Both --pr and --reviewed-sha are required.');
        }

        $repository = $repositoryResolver !== null ? $repositoryResolver() : resolveExactHeadMergegateRepository($root);
        $prNumber = ExactHeadMergegate::parseTarget($config['pr'], $repository);
        $reviewedSha = ExactHeadMergegate::normalizeSha($config['reviewed_sha']);
        $policy =
            $policyLoader !== null
                ? $policyLoader($root, $config['contract'], $reviewedSha)
                : loadExactHeadMergegateVerifiedPolicy($root, $config['contract'], $reviewedSha);
        $githubGet = $request ?? buildExactHeadMergegateGitHubGetClosure();
        $snapshot = fetchExactHeadMergegateSnapshot(
            $githubGet,
            $repository,
            $prNumber,
            $reviewedSha,
            $policy['workflow_file'],
        );
        $evaluation = ExactHeadMergegate::evaluate($policy, $snapshot, $prNumber, $reviewedSha);
        $report = array_merge(
            [
                'schema_version' => 1,
                'generated_at_utc' => gmdate('c'),
                'repository' => $repository,
            ],
            $evaluation,
        );
        $exitCode =
            $evaluation['status'] === 'pass' ? EXACT_HEAD_MERGEGATE_EXIT_SUCCESS : EXACT_HEAD_MERGEGATE_EXIT_NOT_READY;

        fwrite(
            $exitCode === EXACT_HEAD_MERGEGATE_EXIT_SUCCESS ? STDOUT : STDERR,
            $exitCode === EXACT_HEAD_MERGEGATE_EXIT_SUCCESS
                ? '[PASS] Exact-head mergegate is satisfied.' . PHP_EOL
                : '[BLOCK] Exact-head mergegate is not satisfied.' . PHP_EOL,
        );
    } catch (Throwable $exception) {
        $report['error'] = [
            'code' => 'runtime_error',
            'message' => sanitizeExactHeadMergegateError($exception),
        ];
        $exitCode = EXACT_HEAD_MERGEGATE_EXIT_RUNTIME_ERROR;
        fwrite(STDERR, '[ERROR] Exact-head mergegate could not be evaluated.' . PHP_EOL);
    }

    try {
        writeExactHeadMergegateJson($config['output_json'], $report);
        fwrite(STDOUT, '[INFO] Report: ' . $config['output_json'] . PHP_EOL);
    } catch (Throwable) {
        fwrite(STDERR, '[ERROR] Exact-head mergegate report could not be written.' . PHP_EOL);

        return EXACT_HEAD_MERGEGATE_EXIT_RUNTIME_ERROR;
    }

    return $exitCode;
}

function exactHeadMergegateUsage(): string
{
    return implode(PHP_EOL, [
        'Usage: php scripts/ci/check_exact_head_mergegate.php --pr=NUMBER_OR_URL --reviewed-sha=SHA [options]',
        '',
        'Required:',
        '  --pr=NUMBER_OR_URL  Pull request number or canonical GitHub pull request URL.',
        '  --reviewed-sha=SHA  Full 40-character commit SHA reviewed for landing.',
        '',
        'Options:',
        '  --output-json=PATH  Sanitized JSON report path.',
        '  --help              Show this help text.',
        '',
        'The command performs GitHub GET requests only and never merges or mutates the pull request.',
        '',
    ]);
}

/**
 * @return array{
 *     pr:?string,
 *     reviewed_sha:?string,
 *     contract:string,
 *     output_json:string,
 *     help:bool
 * }
 */
function exactHeadMergegateDefaultConfig(string $root): array
{
    return [
        'pr' => null,
        'reviewed_sha' => null,
        'contract' => $root . '/.codex/contracts/agent-workflow.json',
        'output_json' => $root . '/storage/logs/ci/exact-head-mergegate-latest.json',
        'help' => false,
    ];
}

/**
 * @param array<int, string> $argv
 * @param array{
 *     pr:?string,
 *     reviewed_sha:?string,
 *     contract:string,
 *     output_json:string,
 *     help:bool
 * } $config
 */
function parseExactHeadMergegateCliOptions(array $argv, array &$config): void
{
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--') {
            continue;
        }

        if ($arg === '--help') {
            $config['help'] = true;
            continue;
        }

        if (str_starts_with($arg, '--pr=')) {
            if ($config['pr'] !== null) {
                throw new InvalidArgumentException('--pr may be supplied only once.');
            }

            $config['pr'] = requireExactHeadMergegateCliValue($arg, '--pr=');
            continue;
        }

        if (str_starts_with($arg, '--reviewed-sha=')) {
            if ($config['reviewed_sha'] !== null) {
                throw new InvalidArgumentException('--reviewed-sha may be supplied only once.');
            }

            $config['reviewed_sha'] = requireExactHeadMergegateCliValue($arg, '--reviewed-sha=');
            continue;
        }

        if (str_starts_with($arg, '--output-json=')) {
            $config['output_json'] = requireExactHeadMergegateCliValue($arg, '--output-json=');
            continue;
        }

        throw new InvalidArgumentException('Unknown exact-head mergegate option.');
    }
}

function requireExactHeadMergegateCliValue(string $argument, string $prefix): string
{
    $value = substr($argument, strlen($prefix));
    if ($value === '') {
        throw new InvalidArgumentException('Exact-head mergegate option value must not be empty.');
    }

    return $value;
}

/**
 * @return array{
 *     base_ref:string,
 *     workflow_file:string,
 *     workflow_name:string,
 *     required_checks:array<int, string>,
 *     conditional_checks:array<int, string>,
 *     required_review_lenses:array<int, string>,
 *     trusted_associations:array<int, string>,
 *     blocking_feedback_associations:array<int, string>,
 *     attestation_marker:string,
 *     attestation_verdict:string
 * }
 */
function loadExactHeadMergegatePolicy(string $contractPath): array
{
    return decodeExactHeadMergegatePolicy(readExactHeadMergegatePolicyContents($contractPath));
}

/**
 * @param Closure(array<int, string>, ?string): string $processRunner
 */
function loadExactHeadMergegateVerifiedPolicy(
    string $root,
    string $contractPath,
    string $reviewedSha,
    ?Closure $processRunner = null,
): array {
    $runProcess =
        $processRunner ??
        static fn(array $command, ?string $workingDirectory): string => runExactHeadMergegateProcess(
            $command,
            $workingDirectory,
        );
    $normalizedReviewedSha = ExactHeadMergegate::normalizeSha($reviewedSha);
    $currentHead = ExactHeadMergegate::normalizeSha(trim($runProcess(['git', 'rev-parse', 'HEAD'], $root)));
    if ($currentHead !== $normalizedReviewedSha) {
        throw new RuntimeException('Exact-head mergegate must run from the exact reviewed HEAD.');
    }

    $resolvedRoot = realpath($root);
    $resolvedContract = realpath($contractPath);
    if ($resolvedRoot === false || $resolvedContract === false) {
        throw new RuntimeException('Exact-head mergegate contract path could not be resolved.');
    }

    $rootPrefix = rtrim(str_replace('\\', '/', $resolvedRoot), '/') . '/';
    $contractNormalized = str_replace('\\', '/', $resolvedContract);
    if (!str_starts_with($contractNormalized, $rootPrefix)) {
        throw new RuntimeException('Exact-head mergegate contract must live inside the repository root.');
    }

    $contractRelativePath = substr($contractNormalized, strlen($rootPrefix));
    if (!is_string($contractRelativePath) || $contractRelativePath === '') {
        throw new RuntimeException('Exact-head mergegate contract path is invalid.');
    }

    $criticalPaths = [
        $contractRelativePath,
        'scripts/ci/check_exact_head_mergegate.php',
        'scripts/ci/lib/ExactHeadMergegate.php',
    ];
    try {
        $runProcess(array_merge(['git', 'diff', '--quiet', 'HEAD', '--'], $criticalPaths), $root);
    } catch (Throwable) {
        throw new RuntimeException('Exact-head mergegate security-critical files must be clean.');
    }

    return decodeExactHeadMergegatePolicy(
        $runProcess(['git', 'show', $normalizedReviewedSha . ':' . $contractRelativePath], $root),
    );
}

function readExactHeadMergegatePolicyContents(string $contractPath): string
{
    $contents = file_get_contents($contractPath);
    if ($contents === false) {
        throw new RuntimeException('Exact-head mergegate contract could not be read.');
    }

    return $contents;
}

/**
 * @return array{
 *     base_ref:string,
 *     workflow_file:string,
 *     workflow_name:string,
 *     required_checks:array<int, string>,
 *     conditional_checks:array<int, string>,
 *     required_review_lenses:array<int, string>,
 *     trusted_associations:array<int, string>,
 *     blocking_feedback_associations:array<int, string>,
 *     attestation_marker:string,
 *     attestation_verdict:string
 * }
 */
function decodeExactHeadMergegatePolicy(string $contents): array
{
    try {
        $contract = json_decode($contents, true, 128, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('Exact-head mergegate contract is not valid JSON.');
    }

    $mergegate = $contract['land']['exact_head_mergegate'] ?? null;
    $review = $contract['review'] ?? null;
    $ci = $contract['ci'] ?? null;
    if (
        !is_array($mergegate) ||
        ($mergegate['schema_version'] ?? null) !== 1 ||
        ($mergegate['pr_revalidation'] ?? null) !== 'before_between_and_after_bounded_evidence_observations' ||
        ($mergegate['ci_evidence_revalidation'] ?? null) !== 'two_identical_bounded_observations' ||
        ($mergegate['review_evidence_revalidation'] ?? null) !== 'two_identical_bounded_observations' ||
        ($mergegate['review_lens_source'] ?? null) !== 'review.sensitive_change_lenses' ||
        !is_array($review) ||
        !is_array($ci) ||
        !is_array($mergegate['review_attestation'] ?? null) ||
        !is_array($ci['blocking_jobs'] ?? null)
    ) {
        throw new RuntimeException('Exact-head mergegate contract is malformed.');
    }

    $requiredChecks = normalizeExactHeadMergegateStringList($mergegate['required_checks'] ?? null);
    $conditionalChecks = normalizeExactHeadMergegateStringList($mergegate['conditional_checks'] ?? null);
    $blockingJobs = array_keys($ci['blocking_jobs']);
    $classifiedChecks = array_merge($requiredChecks, $conditionalChecks);
    sort($blockingJobs, SORT_STRING);
    sort($classifiedChecks, SORT_STRING);
    if ($blockingJobs !== $classifiedChecks) {
        throw new RuntimeException('Exact-head mergegate check classification does not own every blocking job.');
    }

    $advisoryJobs = normalizeExactHeadMergegateStringList($ci['advisory_jobs'] ?? null);
    if (array_intersect($classifiedChecks, $advisoryJobs) !== []) {
        throw new RuntimeException('Exact-head mergegate classifies an advisory job as blocking.');
    }

    $attestation = $mergegate['review_attestation'];
    if (
        ($attestation['authority_model'] ?? null) !== 'owner_accountable_assertion' ||
        ($attestation['cryptographic_agent_execution_proof'] ?? null) !== false ||
        ($attestation['malicious_repository_owner_in_scope'] ?? null) !== false ||
        ($attestation['requires_unedited_comment'] ?? null) !== true ||
        ($attestation['activity_watermarks'] ?? null) !== ['review_id', 'review_comment_id', 'review_payload_digest']
    ) {
        throw new RuntimeException('Exact-head mergegate review authority model is malformed.');
    }

    return [
        'base_ref' => requireExactHeadMergegatePolicyString($mergegate, 'base_ref'),
        'workflow_file' => requireExactHeadMergegatePolicyString($mergegate, 'workflow_file'),
        'workflow_name' => requireExactHeadMergegatePolicyString($mergegate, 'workflow_name'),
        'required_checks' => $requiredChecks,
        'conditional_checks' => $conditionalChecks,
        'required_review_lenses' => normalizeExactHeadMergegateStringList($review['sensitive_change_lenses'] ?? null),
        'trusted_associations' => normalizeExactHeadMergegateStringList(
            $attestation['trusted_author_associations'] ?? null,
        ),
        'blocking_feedback_associations' => normalizeExactHeadMergegateStringList(
            $attestation['blocking_feedback_author_associations'] ?? null,
        ),
        'attestation_marker' => requireExactHeadMergegatePolicyString($attestation, 'marker'),
        'attestation_verdict' => requireExactHeadMergegatePolicyString($attestation, 'verdict'),
    ];
}

/**
 * @param mixed $value
 * @return array<int, string>
 */
function normalizeExactHeadMergegateStringList(mixed $value): array
{
    if (!is_array($value) || $value === []) {
        throw new RuntimeException('Exact-head mergegate contract contains an invalid list.');
    }

    $normalized = [];
    foreach ($value as $item) {
        if (!is_string($item) || $item === '') {
            throw new RuntimeException('Exact-head mergegate contract contains an invalid list item.');
        }
        $normalized[] = $item;
    }

    if (count($normalized) !== count(array_unique($normalized))) {
        throw new RuntimeException('Exact-head mergegate contract contains a duplicate list item.');
    }

    return $normalized;
}

/**
 * @param array<string, mixed> $source
 */
function requireExactHeadMergegatePolicyString(array $source, string $key): string
{
    $value = $source[$key] ?? null;
    if (!is_string($value) || $value === '' || str_contains($value, "\n")) {
        throw new RuntimeException('Exact-head mergegate contract contains an invalid string.');
    }

    return $value;
}

function resolveExactHeadMergegateRepository(string $root, ?string $originRemote = null): string
{
    $remote = $originRemote ?? runExactHeadMergegateProcess(['git', 'remote', 'get-url', 'origin'], $root);
    $patterns = [
        '~^https://github\.com/([^/]+/[^/]+?)(?:\.git)?$~D',
        '~^git@github\.com:([^/]+/[^/]+?)(?:\.git)?$~D',
        '~^ssh://git@github\.com/([^/]+/[^/]+?)(?:\.git)?$~D',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, trim($remote), $matches) === 1) {
            $originRepository = $matches[1];
            ExactHeadMergegate::parseTarget(1, $originRepository);
            $environmentRepository = getenv('GITHUB_REPOSITORY');
            if (
                is_string($environmentRepository) &&
                $environmentRepository !== '' &&
                $environmentRepository !== $originRepository
            ) {
                throw new RuntimeException('GITHUB_REPOSITORY does not match the canonical origin repository.');
            }

            return $originRepository;
        }
    }

    throw new RuntimeException('Origin is not a canonical GitHub repository remote.');
}

/**
 * @param Closure(array<int, string>, ?string): string|null $processRunner
 * @return Closure(string): array<string, mixed>
 */
function buildExactHeadMergegateGitHubGetClosure(?Closure $processRunner = null): Closure
{
    $runProcess =
        $processRunner ??
        static fn(array $command, ?string $workingDirectory): string => runExactHeadMergegateProcess(
            $command,
            $workingDirectory,
        );

    return static function (string $path) use ($runProcess): array {
        if (!str_starts_with($path, '/repos/')) {
            throw new RuntimeException('GitHub GET path is outside the repository API.');
        }

        $output = $runProcess(
            [
                'gh',
                'api',
                '--method',
                'GET',
                '--hostname',
                'github.com',
                '-H',
                'Accept: application/vnd.github+json',
                '-H',
                'X-GitHub-Api-Version: 2022-11-28',
                $path,
            ],
            null,
        );

        try {
            $decoded = json_decode($output, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('GitHub GET response was not valid JSON.');
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('GitHub GET response had an invalid shape.');
        }

        return $decoded;
    };
}

/**
 * @param array<int, string> $command
 */
function runExactHeadMergegateProcess(array $command, ?string $workingDirectory): string
{
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $workingDirectory,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Required read-only command could not be started.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0 || !is_string($stdout)) {
        unset($stderr);
        throw new RuntimeException('Required read-only command failed.');
    }
    if (strlen($stdout) > 32 * 1024 * 1024) {
        throw new RuntimeException('Required read-only command returned too much data.');
    }

    return $stdout;
}

/**
 * @param Closure(string): array<string, mixed> $request
 * @return array<string, mixed>
 */
function fetchExactHeadMergegateSnapshot(
    Closure $request,
    string $repository,
    int $prNumber,
    string $reviewedSha,
    string $workflowFile,
): array {
    $prefix = '/repos/' . $repository;
    $initialPullRequest = normalizeExactHeadMergegatePullRequest($request($prefix . '/pulls/' . $prNumber));
    $initialEvidence = fetchExactHeadMergegateEvidenceObservation(
        $request,
        $prefix,
        $prNumber,
        $reviewedSha,
        $workflowFile,
    );
    $middlePullRequest = normalizeExactHeadMergegatePullRequest($request($prefix . '/pulls/' . $prNumber));
    $finalEvidence = fetchExactHeadMergegateEvidenceObservation(
        $request,
        $prefix,
        $prNumber,
        $reviewedSha,
        $workflowFile,
    );
    $finalPullRequest = normalizeExactHeadMergegatePullRequest($request($prefix . '/pulls/' . $prNumber));

    $identityFields = ['number', 'state', 'draft', 'base_ref', 'head_sha', 'head_ref', 'head_repository'];
    $prHeadRevalidated = true;
    foreach ($identityFields as $field) {
        if (
            ($initialPullRequest[$field] ?? null) !== ($middlePullRequest[$field] ?? null) ||
            ($middlePullRequest[$field] ?? null) !== ($finalPullRequest[$field] ?? null)
        ) {
            $prHeadRevalidated = false;
            break;
        }
    }

    return [
        'pr' => $finalPullRequest,
        'pr_head_revalidated' => $prHeadRevalidated,
        'ci_evidence_revalidated' =>
            $initialEvidence['workflow_runs'] === $finalEvidence['workflow_runs'] &&
            $initialEvidence['check_runs'] === $finalEvidence['check_runs'] &&
            $initialEvidence['associated_pr_numbers'] === $finalEvidence['associated_pr_numbers'],
        'ci_evidence_observation_count' => 2,
        'review_evidence_revalidated' =>
            $initialEvidence['comments'] === $finalEvidence['comments'] &&
            $initialEvidence['review_activity'] === $finalEvidence['review_activity'],
        'workflow_runs' => $finalEvidence['workflow_runs'],
        'check_runs' => $finalEvidence['check_runs'],
        'associated_pr_numbers' => $finalEvidence['associated_pr_numbers'],
        'comments' => $finalEvidence['comments'],
        'review_activity' => $finalEvidence['review_activity'],
    ];
}

/**
 * @param Closure(string): array<string, mixed> $request
 * @return array{
 *     workflow_runs:array<int, array<string, mixed>>,
 *     check_runs:array<int, array<string, mixed>>,
 *     associated_pr_numbers:array<int, int>,
 *     comments:array<int, array<string, mixed>>,
 *     review_activity:array<int, array<string, mixed>>
 * }
 */
function fetchExactHeadMergegateEvidenceObservation(
    Closure $request,
    string $prefix,
    int $prNumber,
    string $reviewedSha,
    string $workflowFile,
): array {
    $workflowRuns = fetchExactHeadMergegateCollection(
        $request,
        $prefix .
            '/actions/workflows/' .
            rawurlencode($workflowFile) .
            '/runs?' .
            http_build_query([
                'event' => 'pull_request',
                'head_sha' => $reviewedSha,
            ]),
        'workflow_runs',
    );
    $checkRuns = fetchExactHeadMergegateCollection(
        $request,
        $prefix . '/commits/' . $reviewedSha . '/check-runs?filter=latest',
        'check_runs',
    );
    $associatedPullRequests = fetchExactHeadMergegateCollection(
        $request,
        $prefix . '/commits/' . $reviewedSha . '/pulls',
        null,
    );
    $comments = fetchExactHeadMergegateCollection($request, $prefix . '/issues/' . $prNumber . '/comments', null);
    $reviews = fetchExactHeadMergegateCollection($request, $prefix . '/pulls/' . $prNumber . '/reviews', null);
    $reviewComments = fetchExactHeadMergegateCollection($request, $prefix . '/pulls/' . $prNumber . '/comments', null);

    $normalizedWorkflowRuns = array_map(
        static fn(mixed $run): array => normalizeExactHeadMergegateWorkflowRun($run),
        $workflowRuns,
    );
    usort(
        $normalizedWorkflowRuns,
        static fn(array $left, array $right): int => ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0)),
    );
    $normalizedCheckRuns = array_map(
        static fn(mixed $run): array => normalizeExactHeadMergegateCheckRun($run),
        $checkRuns,
    );
    usort(
        $normalizedCheckRuns,
        static fn(array $left, array $right): int => [
            $left['id'] ?? 0,
            $left['name'] ?? null,
            $left['check_suite_id'] ?? 0,
            $left['head_sha'] ?? null,
            $left['status'] ?? null,
            $left['conclusion'] ?? null,
        ] <=> [
            $right['id'] ?? 0,
            $right['name'] ?? null,
            $right['check_suite_id'] ?? 0,
            $right['head_sha'] ?? null,
            $right['status'] ?? null,
            $right['conclusion'] ?? null,
        ],
    );
    $normalizedAssociatedPrNumbers = normalizeExactHeadMergegateAssociatedPullRequests($associatedPullRequests);
    sort($normalizedAssociatedPrNumbers, SORT_NUMERIC);
    $normalizedComments = array_map(
        static fn(mixed $comment): array => normalizeExactHeadMergegateComment($comment),
        $comments,
    );
    usort(
        $normalizedComments,
        static fn(array $left, array $right): int => ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0)),
    );
    $normalizedReviews = array_map(
        static fn(mixed $review): array => normalizeExactHeadMergegateReview($review),
        $reviews,
    );
    usort(
        $normalizedReviews,
        static fn(array $left, array $right): int => ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0)),
    );
    $normalizedReviewComments = array_map(
        static fn(mixed $comment): array => normalizeExactHeadMergegateReviewComment($comment),
        $reviewComments,
    );
    usort(
        $normalizedReviewComments,
        static fn(array $left, array $right): int => ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0)),
    );

    return [
        'workflow_runs' => $normalizedWorkflowRuns,
        'check_runs' => $normalizedCheckRuns,
        'associated_pr_numbers' => $normalizedAssociatedPrNumbers,
        'comments' => $normalizedComments,
        'review_activity' => array_merge($normalizedReviews, $normalizedReviewComments),
    ];
}

/**
 * @param Closure(string): array<string, mixed> $request
 * @return array<int, mixed>
 */
function fetchExactHeadMergegateCollection(Closure $request, string $path, ?string $collectionKey): array
{
    $items = [];
    for ($page = 1; $page <= EXACT_HEAD_MERGEGATE_MAX_PAGES; $page++) {
        $separator = str_contains($path, '?') ? '&' : '?';
        $payload = $request(
            $path .
                $separator .
                http_build_query([
                    'per_page' => EXACT_HEAD_MERGEGATE_PAGE_SIZE,
                    'page' => $page,
                ]),
        );
        $pageItems = $collectionKey === null ? $payload : $payload[$collectionKey] ?? null;
        if (!is_array($pageItems) || !array_is_list($pageItems)) {
            throw new RuntimeException('GitHub GET collection had an invalid shape.');
        }

        array_push($items, ...$pageItems);
        if (count($pageItems) < EXACT_HEAD_MERGEGATE_PAGE_SIZE) {
            return $items;
        }
    }

    throw new RuntimeException('GitHub GET collection exceeded the bounded pagination window.');
}

/**
 * @param array<string, mixed> $pullRequest
 * @return array<string, mixed>
 */
function normalizeExactHeadMergegatePullRequest(array $pullRequest): array
{
    return [
        'number' => $pullRequest['number'] ?? null,
        'state' => $pullRequest['state'] ?? null,
        'draft' => $pullRequest['draft'] ?? null,
        'base_ref' => is_array($pullRequest['base'] ?? null) ? $pullRequest['base']['ref'] ?? null : null,
        'head_sha' => is_array($pullRequest['head'] ?? null) ? $pullRequest['head']['sha'] ?? null : null,
        'mergeable' => $pullRequest['mergeable'] ?? null,
        'mergeable_state' => $pullRequest['mergeable_state'] ?? null,
        'head_ref' => is_array($pullRequest['head'] ?? null) ? $pullRequest['head']['ref'] ?? null : null,
        'head_repository' => is_array($pullRequest['head']['repo'] ?? null)
            ? $pullRequest['head']['repo']['full_name'] ?? null
            : null,
    ];
}

/**
 * @return array<string, mixed>
 */
function normalizeExactHeadMergegateWorkflowRun(mixed $run): array
{
    if (!is_array($run)) {
        return [];
    }

    $prNumbers = [];
    $pullRequests = $run['pull_requests'] ?? null;
    if (is_array($pullRequests)) {
        foreach ($pullRequests as $pullRequest) {
            if (is_array($pullRequest) && is_int($pullRequest['number'] ?? null)) {
                $prNumbers[] = $pullRequest['number'];
            }
        }
    }

    return [
        'id' => $run['id'] ?? null,
        'name' => $run['name'] ?? null,
        'event' => $run['event'] ?? null,
        'status' => $run['status'] ?? null,
        'conclusion' => $run['conclusion'] ?? null,
        'head_sha' => $run['head_sha'] ?? null,
        'head_branch' => $run['head_branch'] ?? null,
        'head_repository' => is_array($run['head_repository'] ?? null)
            ? $run['head_repository']['full_name'] ?? null
            : null,
        'pr_numbers' => array_values(array_unique($prNumbers)),
        'check_suite_id' => $run['check_suite_id'] ?? null,
    ];
}

/**
 * @param array<int, mixed> $pullRequests
 * @return array<int, int>
 */
function normalizeExactHeadMergegateAssociatedPullRequests(array $pullRequests): array
{
    $numbers = [];
    foreach ($pullRequests as $pullRequest) {
        if (is_array($pullRequest) && is_int($pullRequest['number'] ?? null)) {
            $numbers[] = $pullRequest['number'];
        }
    }

    return array_values(array_unique($numbers));
}

/**
 * @return array<string, mixed>
 */
function normalizeExactHeadMergegateCheckRun(mixed $run): array
{
    if (
        !is_array($run) ||
        !is_int($run['id'] ?? null) ||
        !is_string($run['name'] ?? null) ||
        !is_array($run['check_suite'] ?? null) ||
        !is_int($run['check_suite']['id'] ?? null) ||
        !is_string($run['head_sha'] ?? null) ||
        !is_string($run['status'] ?? null) ||
        (!is_string($run['conclusion'] ?? null) && ($run['conclusion'] ?? null) !== null)
    ) {
        throw new RuntimeException('GitHub check run had an invalid shape.');
    }

    return [
        'id' => $run['id'],
        'name' => $run['name'],
        'check_suite_id' => $run['check_suite']['id'],
        'head_sha' => $run['head_sha'],
        'status' => $run['status'],
        'conclusion' => $run['conclusion'] ?? null,
    ];
}

/**
 * @return array<string, mixed>
 */
function normalizeExactHeadMergegateComment(mixed $comment): array
{
    if (
        !is_array($comment) ||
        !is_int($comment['id'] ?? null) ||
        !is_string($comment['author_association'] ?? null) ||
        !is_string($comment['created_at'] ?? null) ||
        !is_string($comment['updated_at'] ?? null) ||
        !is_string($comment['body'] ?? null)
    ) {
        throw new RuntimeException('GitHub issue comment had an invalid shape.');
    }

    return [
        'id' => $comment['id'] ?? null,
        'author_association' => $comment['author_association'] ?? null,
        'created_at' => $comment['created_at'] ?? null,
        'updated_at' => $comment['updated_at'] ?? null,
        'body' => $comment['body'] ?? null,
    ];
}

/**
 * @return array<string, mixed>
 */
function normalizeExactHeadMergegateReview(mixed $review): array
{
    if (
        !is_array($review) ||
        !is_int($review['id'] ?? null) ||
        !is_string($review['author_association'] ?? null) ||
        !is_string($review['state'] ?? null) ||
        !is_string($review['commit_id'] ?? null) ||
        !is_string($review['submitted_at'] ?? null) ||
        !is_string($review['body'] ?? null)
    ) {
        throw new RuntimeException('GitHub review had an invalid shape.');
    }

    return [
        'kind' => 'review',
        'id' => $review['id'] ?? null,
        'author_association' => $review['author_association'] ?? null,
        'actor_ref' => exactHeadMergegateOpaqueActorRef($review['user'] ?? null),
        'state' => $review['state'] ?? null,
        'commit_sha' => $review['commit_id'] ?? null,
        'occurred_at' => $review['submitted_at'] ?? null,
        'content_digest' => hash('sha256', $review['body']),
    ];
}

/**
 * @return array<string, mixed>
 */
function normalizeExactHeadMergegateReviewComment(mixed $comment): array
{
    if (
        !is_array($comment) ||
        !is_int($comment['id'] ?? null) ||
        !is_string($comment['author_association'] ?? null) ||
        !is_string($comment['commit_id'] ?? null) ||
        (!is_string($comment['updated_at'] ?? null) && !is_string($comment['created_at'] ?? null))
    ) {
        throw new RuntimeException('GitHub review comment had an invalid shape.');
    }

    return [
        'kind' => 'review_comment',
        'id' => $comment['id'] ?? null,
        'author_association' => $comment['author_association'] ?? null,
        'actor_ref' => exactHeadMergegateOpaqueActorRef($comment['user'] ?? null),
        'state' => null,
        'commit_sha' => $comment['commit_id'] ?? null,
        'occurred_at' => $comment['updated_at'] ?? ($comment['created_at'] ?? null),
    ];
}

function exactHeadMergegateOpaqueActorRef(mixed $user): string
{
    if (!is_array($user) || (!is_int($user['id'] ?? null) && !is_string($user['id'] ?? null))) {
        throw new RuntimeException('GitHub review actor had an invalid shape.');
    }

    return hash('sha256', 'github-actor:' . (string) $user['id']);
}

function sanitizeExactHeadMergegateError(Throwable $exception): string
{
    $safeMessages = [InvalidArgumentException::class, RuntimeException::class];
    if (in_array($exception::class, $safeMessages, true)) {
        return preg_replace('/[\r\n]+/', ' ', $exception->getMessage()) ?: 'Exact-head mergegate runtime error.';
    }

    return 'Exact-head mergegate runtime error.';
}

/**
 * @param array<string, mixed> $report
 */
function writeExactHeadMergegateJson(string $path, array $report): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Exact-head mergegate report directory could not be created.');
    }

    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    $temporaryPath = tempnam($directory, '.exact-head-mergegate-');
    if ($temporaryPath === false) {
        throw new RuntimeException('Exact-head mergegate temporary report could not be created.');
    }

    try {
        if (file_put_contents($temporaryPath, $json, LOCK_EX) === false || !rename($temporaryPath, $path)) {
            throw new RuntimeException('Exact-head mergegate report could not be published.');
        }
    } finally {
        if (is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
    }
}
