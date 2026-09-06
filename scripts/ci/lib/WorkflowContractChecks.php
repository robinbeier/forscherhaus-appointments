<?php

declare(strict_types=1);

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
 * @param string $failureControlPolicy
 * @return array<int, array<string, mixed>>
 */
function agentHarnessReadinessEvaluateBlockingJobs(
    array $ciWorkflow,
    array $blockingJobs,
    string $failureControlPolicy,
): array {
    $failureControls = agentHarnessReadinessFailureControlsForPolicy($failureControlPolicy);
    $jobs = $ciWorkflow['jobs'] ?? [];
    if (!is_array($jobs)) {
        throw new RuntimeException('CI workflow does not contain a valid top-level "jobs" map.');
    }

    $checks = [];
    foreach ($blockingJobs as $jobName) {
        $jobConfig = $jobs[$jobName] ?? null;
        $exists = is_array($jobConfig);
        $failureMasks = $exists
            ? agentHarnessReadinessEvaluateBlockingJobFailureMasks($jobConfig, $jobName, $failureControls)
            : [];
        $isBlocking = $exists && $failureMasks === [];

        $checks[] = [
            'id' => 'job_' . $jobName,
            'label' => $jobName . ' exists and is blocking',
            'status' => $exists && $isBlocking ? 'pass' : 'fail',
            'message' => !$exists
                ? 'Required blocking CI job is missing.'
                : ($isBlocking
                    ? 'Job exists without forbidden failure controls.'
                    : implode('; ', $failureMasks)),
        ];
    }

    return $checks;
}

/**
 * @param array<string, mixed> $ciWorkflow
 * @param string $failureControlPolicy
 * @return array<int, array<string, mixed>>
 */
function agentHarnessReadinessEvaluateWorkflowFailureMasks(array $ciWorkflow, string $failureControlPolicy): array
{
    $failureControls = agentHarnessReadinessFailureControlsForPolicy($failureControlPolicy);

    $failures = [];
    $defaults = $ciWorkflow['defaults'] ?? null;
    $runDefaults = is_array($defaults) ? $defaults['run'] ?? null : null;
    if (is_array($runDefaults)) {
        foreach ($failureControls['forbidden_workflow_run_default_keys'] as $key) {
            if (array_key_exists($key, $runDefaults)) {
                $failures[] = 'workflow declares forbidden defaults.run.' . $key;
            }
        }
    }

    return [
        [
            'id' => 'workflow_failure_controls',
            'label' => 'CI workflow defaults preserve blocking failure semantics',
            'status' => $failures === [] ? 'pass' : 'fail',
            'message' =>
                $failures === []
                    ? 'Workflow defaults contain no forbidden failure controls.'
                    : implode('; ', $failures),
        ],
    ];
}

/**
 * @param array<string, mixed> $ciWorkflow
 * @param array<int, string> $blockingJobs
 * @param array<int, string> $advisoryJobs
 * @return array<int, array<string, mixed>>
 */
function agentHarnessReadinessEvaluateClassifiedJobInventory(
    array $ciWorkflow,
    array $blockingJobs,
    array $advisoryJobs,
): array {
    $jobs = $ciWorkflow['jobs'] ?? [];
    if (!is_array($jobs)) {
        throw new RuntimeException('CI workflow does not contain a valid top-level "jobs" map.');
    }

    $classifiedJobs = array_merge($blockingJobs, $advisoryJobs);
    $workflowJobs = array_keys($jobs);
    $missingJobNames = array_values(array_diff($classifiedJobs, $workflowJobs));
    $unclassifiedJobNames = array_values(array_diff($workflowJobs, $classifiedJobs));
    $overlappingJobNames = array_values(array_intersect($blockingJobs, $advisoryJobs));
    sort($missingJobNames, SORT_STRING);
    sort($unclassifiedJobNames, SORT_STRING);
    sort($overlappingJobNames, SORT_STRING);

    $failures = [];
    if ($missingJobNames !== []) {
        $failures[] = 'Classified CI jobs are missing: ' . implode(', ', $missingJobNames) . '.';
    }
    if ($unclassifiedJobNames !== []) {
        $failures[] =
            'CI jobs require an explicit blocking or advisory classification: ' .
            implode(', ', $unclassifiedJobNames) .
            '.';
    }
    if ($overlappingJobNames !== []) {
        $failures[] = 'CI jobs cannot be both blocking and advisory: ' . implode(', ', $overlappingJobNames) . '.';
    }

    return [
        [
            'id' => 'job_classification_inventory',
            'label' => 'Every CI job has exactly one explicit contract classification',
            'status' => $failures === [] ? 'pass' : 'fail',
            'message' =>
                $failures === []
                    ? 'Every CI job is classified exactly once as blocking or advisory.'
                    : implode(' ', $failures),
        ],
    ];
}

/**
 * @param array<string, mixed> $ciWorkflow
 * @param array<int, string> $blockingJobs
 * @return array<int, array<string, mixed>>
 */
function agentHarnessReadinessEvaluateBlockingExecutionFingerprints(
    array $ciWorkflow,
    array $blockingJobs,
    array $conditionGrammar,
    array $expectedFingerprints,
): array {
    $actualFingerprints = agentHarnessReadinessCalculateBlockingExecutionFingerprints(
        $ciWorkflow,
        $blockingJobs,
        $conditionGrammar,
    );

    $checks = [];
    foreach ($actualFingerprints as $component => $actualSha256) {
        $expectedSha256 = $expectedFingerprints[$component] ?? null;
        $matches =
            is_string($expectedSha256) &&
            preg_match('/^[a-f0-9]{64}$/D', $expectedSha256) === 1 &&
            hash_equals($expectedSha256, $actualSha256);
        $checks[] = [
            'id' => 'blocking_execution_fingerprint_' . $component,
            'label' => 'Blocking CI execution component ' . $component . ' matches its canonical fingerprint',
            'status' => $matches ? 'pass' : 'fail',
            'message' => $matches
                ? 'Execution component matches the reviewed contract.'
                : sprintf(
                    'Execution component %s changed without a matching reviewed contract fingerprint (expected %s, actual %s).',
                    $component,
                    is_string($expectedSha256) ? $expectedSha256 : 'missing',
                    $actualSha256,
                ),
        ];
    }
    foreach (array_diff(array_keys($expectedFingerprints), array_keys($actualFingerprints)) as $component) {
        $checks[] = [
            'id' => 'blocking_execution_fingerprint_' . $component,
            'label' => 'Blocking CI execution component ' . $component . ' matches its canonical fingerprint',
            'status' => 'fail',
            'message' => 'Contract fingerprint names a component that is not present in the workflow.',
        ];
    }
    return $checks;
}

/** @return array<string, string> */
function agentHarnessReadinessCalculateBlockingExecutionFingerprints(
    array $ciWorkflow,
    array $blockingJobs,
    array $conditionGrammar,
): array {
    agentHarnessReadinessValidateConditionGrammar($conditionGrammar);
    $jobs = $ciWorkflow['jobs'] ?? null;
    if (!is_array($jobs)) {
        throw new RuntimeException('CI workflow does not contain a valid top-level "jobs" map.');
    }
    $envelope = [];
    foreach (['on', 'permissions', 'env', 'defaults', 'concurrency'] as $key) {
        if (array_key_exists($key, $ciWorkflow)) {
            $envelope[$key] =
                $key === 'on' ? agentHarnessReadinessNormalizeWorkflowTriggers($ciWorkflow[$key]) : $ciWorkflow[$key];
        }
    }
    $fingerprints = [
        'workflow_execution_envelope' => hash(
            'sha256',
            json_encode(agentHarnessReadinessCanonicalizeMap($envelope), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ),
    ];
    sort($blockingJobs, SORT_STRING);
    foreach ($blockingJobs as $jobName) {
        $job = $jobs[$jobName] ?? null;
        if (!is_array($job)) {
            continue;
        }
        $fingerprints[$jobName] = hash(
            'sha256',
            json_encode(
                agentHarnessReadinessNormalizeBlockingJobExecution($job, $conditionGrammar),
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        );
    }
    return $fingerprints;
}

/**
 * Normalize trigger lists whose order cannot affect execution. Glob filters
 * deliberately retain their order because negated patterns are order-sensitive.
 */
function agentHarnessReadinessNormalizeWorkflowTriggers(mixed $triggers): mixed
{
    if (!is_array($triggers)) {
        return $triggers;
    }

    if (array_is_list($triggers)) {
        if (array_all($triggers, static fn(mixed $event): bool => is_string($event))) {
            sort($triggers, SORT_STRING);
        }

        return $triggers;
    }

    foreach ($triggers as $event => $configuration) {
        if (!is_array($configuration)) {
            continue;
        }
        foreach (['types', 'workflows'] as $orderInsensitiveKey) {
            $values = $configuration[$orderInsensitiveKey] ?? null;
            if (!is_array($values) || !array_is_list($values)) {
                continue;
            }
            if (!array_all($values, static fn(mixed $value): bool => is_string($value))) {
                continue;
            }
            sort($values, SORT_STRING);
            $configuration[$orderInsensitiveKey] = $values;
        }
        $triggers[$event] = $configuration;
    }

    return agentHarnessReadinessCanonicalizeMap($triggers);
}

/**
 * Remove display-only metadata while retaining every value that can change
 * whether or how a blocking job executes. Only `needs` is order-insensitive;
 * step order and all other lists remain untouched.
 *
 * @param array<string, mixed> $job
 * @param array<string, mixed> $conditionGrammar
 * @return array<string, mixed>
 */
function agentHarnessReadinessNormalizeBlockingJobExecution(array $job, array $conditionGrammar): array
{
    unset($job['name']);

    if (array_key_exists('if', $job)) {
        if (!is_string($job['if'])) {
            throw new RuntimeException('Blocking job conditions must be strings.');
        }
        $job['if'] = agentHarnessReadinessParseCondition($job['if'], $conditionGrammar);
    }

    if (array_key_exists('needs', $job)) {
        $normalizedNeeds = agentHarnessReadinessNormalizeNeeds($job['needs']);
        if ($normalizedNeeds !== null) {
            $job['needs'] = $normalizedNeeds;
        }
    }

    if (isset($job['steps']) && is_array($job['steps'])) {
        $job['steps'] = array_map(
            static fn(mixed $step): mixed => is_array($step)
                ? agentHarnessReadinessNormalizeBlockingExecutionStep($step, $conditionGrammar)
                : $step,
            $job['steps'],
        );
    }

    return agentHarnessReadinessCanonicalizeMap($job);
}

/**
 * @param array<string, mixed> $step
 * @param array<string, mixed> $conditionGrammar
 * @return array<string, mixed>
 */
function agentHarnessReadinessNormalizeBlockingExecutionStep(array $step, array $conditionGrammar): array
{
    unset($step['name']);

    if (array_key_exists('if', $step)) {
        if (!is_string($step['if'])) {
            throw new RuntimeException('Blocking step conditions must be strings.');
        }
        $step['if'] = agentHarnessReadinessParseCondition($step['if'], $conditionGrammar);
    }

    return agentHarnessReadinessCanonicalizeMap($step);
}

/**
 * @param array<string, mixed> $ciWorkflow
 * @param array<string, mixed> $contracts
 * @param array<string, mixed> $conditionGrammar
 * @param string $failureControlPolicy
 * @return array<int, array<string, mixed>>
 */
function agentHarnessReadinessEvaluateBlockingJobContracts(
    array $ciWorkflow,
    array $contracts,
    array $conditionGrammar,
    string $failureControlPolicy,
): array {
    $failureControls = agentHarnessReadinessFailureControlsForPolicy($failureControlPolicy);
    $jobs = $ciWorkflow['jobs'] ?? [];
    if (!is_array($jobs)) {
        throw new RuntimeException('CI workflow does not contain a valid top-level "jobs" map.');
    }

    $checks = [];
    foreach ($contracts as $jobName => $contract) {
        if (!is_string($jobName) || !is_array($contract)) {
            throw new RuntimeException('Blocking job contracts must be a map of job names to contract objects.');
        }

        $kind = $contract['kind'] ?? null;
        if ($kind === 'fingerprinted_execution') {
            continue;
        }
        if ($kind !== 'exact_execution') {
            throw new RuntimeException('Blocking job contract "' . $jobName . '" has an unsupported kind.');
        }

        $expectedNeeds = agentHarnessReadinessNormalizeNeeds($contract['needs'] ?? null);
        $expectedRunsOn = $contract['runs_on'] ?? null;
        $expectedTimeoutMinutes = $contract['timeout_minutes'] ?? null;
        $expectedCondition = $contract['condition'] ?? null;
        $evidence = $contract['evidence'] ?? null;
        $assertion = $contract['assertion'] ?? null;
        $postAssertionSteps = $contract['post_assertion_steps'] ?? null;
        if (
            $expectedNeeds === null ||
            !is_string($expectedRunsOn) ||
            trim($expectedRunsOn) === '' ||
            !is_int($expectedTimeoutMinutes) ||
            $expectedTimeoutMinutes < 1 ||
            !is_array($expectedCondition) ||
            !is_array($evidence) ||
            !is_array($assertion) ||
            !is_array($postAssertionSteps)
        ) {
            throw new RuntimeException('Blocking job contract "' . $jobName . '" is malformed.');
        }

        $expectedCondition = agentHarnessReadinessNormalizeConditionAst($expectedCondition);
        $checkoutAction = agentHarnessReadinessRequireContractString(
            $evidence['checkout_action'] ?? null,
            $jobName . '.evidence.checkout_action',
        );
        $downloadAction = agentHarnessReadinessRequireContractString(
            $evidence['download_action'] ?? null,
            $jobName . '.evidence.download_action',
        );
        $artifactName = agentHarnessReadinessRequireContractString(
            $evidence['artifact'] ?? null,
            $jobName . '.evidence.artifact',
        );
        $artifactPath = agentHarnessReadinessRequireContractString(
            $evidence['path'] ?? null,
            $jobName . '.evidence.path',
        );
        $assertionRun = agentHarnessReadinessRequireContractString(
            $assertion['run'] ?? null,
            $jobName . '.assertion.run',
        );
        $expectedPostAssertionSteps = array_map(static function (mixed $step) use ($jobName): array {
            if (!is_array($step)) {
                throw new RuntimeException(
                    'Blocking job contract "' . $jobName . '" post-assertion steps must be objects.',
                );
            }

            return agentHarnessReadinessNormalizeWorkflowStep($step);
        }, $postAssertionSteps);

        $failures = [];
        $job = $jobs[$jobName] ?? null;
        if (!is_array($job)) {
            $failures[] = 'job is missing';
        } else {
            $failures = array_merge(
                $failures,
                agentHarnessReadinessEvaluateBlockingJobFailureMasks($job, $jobName, $failureControls),
            );

            $unexpectedJobKeys = array_diff(array_keys($job), [
                'name',
                'needs',
                'if',
                'runs-on',
                'timeout-minutes',
                'steps',
            ]);
            if ($unexpectedJobKeys !== []) {
                $failures[] = 'unexpected job keys: ' . implode(', ', $unexpectedJobKeys);
            }

            $actualNeeds = agentHarnessReadinessNormalizeNeeds($job['needs'] ?? null);
            if ($actualNeeds === null || $actualNeeds !== $expectedNeeds) {
                $failures[] = 'needs do not match contract';
            }
            if (($job['runs-on'] ?? null) !== $expectedRunsOn) {
                $failures[] = 'runs-on does not match contract';
            }
            if (($job['timeout-minutes'] ?? null) !== $expectedTimeoutMinutes) {
                $failures[] = 'timeout-minutes does not match contract';
            }

            $condition = $job['if'] ?? null;
            if (!is_string($condition)) {
                $failures[] = 'condition is missing';
            } else {
                try {
                    $actualCondition = agentHarnessReadinessParseCondition($condition, $conditionGrammar);
                    if ($actualCondition !== $expectedCondition) {
                        $failures[] = 'condition does not match contract';
                    }
                } catch (InvalidArgumentException $e) {
                    $failures[] = 'condition is invalid: ' . $e->getMessage();
                }
            }

            $steps = $job['steps'] ?? [];
            if (!is_array($steps)) {
                $failures[] = 'steps are invalid';
                $steps = [];
            }

            $matchingStepIndexes = [];
            foreach ($steps as $stepIndex => $step) {
                if (is_array($step) && ($step['run'] ?? null) === $assertionRun) {
                    $matchingStepIndexes[] = $stepIndex;
                }
            }

            if (count($matchingStepIndexes) !== 1) {
                $failures[] = 'expected exactly one assertion step';
            } else {
                $assertionIndex = $matchingStepIndexes[0];
                $expectedPrefix = [
                    ['uses' => $checkoutAction],
                    [
                        'uses' => $downloadAction,
                        'with' => [
                            'name' => $artifactName,
                            'path' => $artifactPath,
                        ],
                    ],
                ];
                $actualPrefix = array_map(
                    static fn(mixed $step): array => is_array($step)
                        ? agentHarnessReadinessNormalizeWorkflowStep($step)
                        : ['invalid_step' => get_debug_type($step)],
                    array_slice($steps, 0, $assertionIndex),
                );
                if ($actualPrefix !== $expectedPrefix) {
                    $failures[] = 'pre-assertion evidence steps do not match contract';
                }

                $gateStep = $steps[$assertionIndex];
                if (
                    !is_array($gateStep) ||
                    agentHarnessReadinessNormalizeWorkflowStep($gateStep) !== ['run' => $assertionRun]
                ) {
                    $failures[] = 'assertion step does not match contract';
                }

                $actualPostAssertionSteps = array_map(
                    static fn(mixed $step): array => is_array($step)
                        ? agentHarnessReadinessNormalizeWorkflowStep($step)
                        : ['invalid_step' => get_debug_type($step)],
                    array_slice($steps, $assertionIndex + 1),
                );
                if ($actualPostAssertionSteps !== $expectedPostAssertionSteps) {
                    $failures[] = 'post-assertion steps do not match contract';
                }
            }
        }

        $checks[] = [
            'id' => 'job_contract_' . $jobName,
            'label' => $jobName . ' preserves its fail-closed execution contract',
            'status' => $failures === [] ? 'pass' : 'fail',
            'message' =>
                $failures === [] ? 'Required job scope and assertion step are intact.' : implode('; ', $failures),
        ];
    }

    return $checks;
}

/**
 * @param array<string, mixed> $job
 * @param array<string, array<int, string>> $failureControls
 * @return array<int, string>
 */
function agentHarnessReadinessEvaluateBlockingJobFailureMasks(
    array $job,
    string $jobName,
    array $failureControls,
): array {
    $failures = [];
    foreach ($failureControls['forbidden_job_keys'] as $key) {
        if (array_key_exists($key, $job)) {
            $failures[] = 'job declares forbidden ' . $key;
        }
    }

    $defaults = $job['defaults'] ?? null;
    $runDefaults = is_array($defaults) ? $defaults['run'] ?? null : null;
    if (is_array($runDefaults)) {
        foreach ($failureControls['forbidden_job_run_default_keys'] as $key) {
            if (array_key_exists($key, $runDefaults)) {
                $failures[] = 'job declares forbidden defaults.run.' . $key;
            }
        }
    }

    $steps = $job['steps'] ?? null;
    if (is_array($steps)) {
        foreach ($steps as $stepIndex => $step) {
            if (!is_array($step)) {
                continue;
            }
            foreach ($failureControls['forbidden_step_keys'] as $key) {
                if (array_key_exists($key, $step)) {
                    $failures[] = sprintf('step %s declares forbidden %s', (string) $stepIndex, $key);
                }
            }
        }
    }

    return array_map(static fn(string $failure): string => $jobName . ': ' . $failure, $failures);
}

/** @return array<string, array<int, string>> */
function agentHarnessReadinessFailureControlsForPolicy(string $policy): array
{
    if ($policy !== 'strict-v1') {
        throw new RuntimeException('Unknown workflow contract blocking failure-control policy.');
    }
    return [
        'forbidden_workflow_run_default_keys' => ['shell'],
        'forbidden_job_keys' => ['continue-on-error'],
        'forbidden_job_run_default_keys' => ['shell'],
        'forbidden_step_keys' => ['continue-on-error', 'shell'],
    ];
}

/**
 * @return array<int, string>|null
 */
function agentHarnessReadinessNormalizeNeeds(mixed $needs): ?array
{
    if (is_string($needs)) {
        $needs = [$needs];
    }
    if (!is_array($needs)) {
        return null;
    }

    foreach ($needs as $need) {
        if (!is_string($need) || $need === '') {
            return null;
        }
    }

    $normalized = array_values(array_unique($needs));
    if (count($normalized) !== count($needs)) {
        return null;
    }
    sort($normalized, SORT_STRING);

    return $normalized;
}

/**
 * @param array<string, mixed> $step
 * @return array<string, mixed>
 */
function agentHarnessReadinessNormalizeWorkflowStep(array $step): array
{
    unset($step['name']);

    return agentHarnessReadinessCanonicalizeMap($step);
}

/**
 * @param array<string, mixed> $value
 * @return array<string, mixed>
 */
function agentHarnessReadinessCanonicalizeMap(array $value): array
{
    foreach ($value as $key => $item) {
        if (is_array($item)) {
            $value[$key] = array_is_list($item)
                ? array_map(
                    static fn(mixed $entry): mixed => is_array($entry)
                        ? agentHarnessReadinessCanonicalizeMap($entry)
                        : $entry,
                    $item,
                )
                : agentHarnessReadinessCanonicalizeMap($item);
        }
    }
    ksort($value, SORT_STRING);

    return $value;
}

function agentHarnessReadinessRequireContractString(mixed $value, string $path): string
{
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException('Workflow contract value "' . $path . '" must be a non-empty string.');
    }

    return $value;
}

function agentHarnessReadinessRequireRepoRelativePath(mixed $value, string $path): string
{
    $value = agentHarnessReadinessRequireContractString($value, $path);
    if (str_starts_with($value, '/') || preg_match('#(?:^|/)\.\.(?:/|$)#', $value) === 1) {
        throw new RuntimeException('Workflow contract path "' . $path . '" must stay inside the repository.');
    }

    return $value;
}

/**
 * @param array<string, mixed> $node
 * @return array<string, mixed>
 */
function agentHarnessReadinessNormalizeConditionAst(array $node): array
{
    if (count($node) !== 1) {
        throw new RuntimeException('Workflow condition nodes must contain exactly one operator.');
    }

    if (array_key_exists('call', $node)) {
        return [
            'call' => agentHarnessReadinessRequireContractString($node['call'], 'condition.call'),
        ];
    }

    if (array_key_exists('equals', $node)) {
        $equals = $node['equals'];
        if (
            !is_array($equals) ||
            count($equals) !== 2 ||
            !is_string($equals[0] ?? null) ||
            (!is_string($equals[1] ?? null) && !is_bool($equals[1] ?? null))
        ) {
            throw new RuntimeException('Workflow condition equals nodes must contain an identifier and scalar.');
        }

        return ['equals' => [$equals[0], $equals[1]]];
    }

    foreach (['all', 'any'] as $operator) {
        if (!array_key_exists($operator, $node)) {
            continue;
        }

        $children = $node[$operator];
        if (!is_array($children) || $children === []) {
            throw new RuntimeException('Workflow condition "' . $operator . '" nodes must be non-empty lists.');
        }

        $normalized = [];
        foreach ($children as $child) {
            if (!is_array($child)) {
                throw new RuntimeException('Workflow condition child nodes must be objects.');
            }
            $child = agentHarnessReadinessNormalizeConditionAst($child);
            if (array_key_exists($operator, $child)) {
                array_push($normalized, ...$child[$operator]);
            } else {
                $normalized[] = $child;
            }
        }
        usort(
            $normalized,
            static fn(array $left, array $right): int => strcmp(
                json_encode($left, JSON_THROW_ON_ERROR),
                json_encode($right, JSON_THROW_ON_ERROR),
            ),
        );

        return [$operator => $normalized];
    }

    throw new RuntimeException('Unknown workflow condition operator.');
}

/**
 * This fail-closed grammar is intentionally narrower than the full GitHub
 * Actions expression language. Its tokens come from the canonical contract;
 * this validator only enforces the parser's structural schema.
 *
 * @param array<string, mixed> $grammar
 */
function agentHarnessReadinessValidateConditionGrammar(array $grammar): void
{
    $version = $grammar['version'] ?? null;
    $operators = $grammar['operators'] ?? null;
    $grouping = $grammar['grouping'] ?? null;
    $identifierPattern = $grammar['identifier_pattern'] ?? null;
    $literals = $grammar['literals'] ?? null;
    $zeroArgumentCalls = $grammar['zero_argument_calls'] ?? null;

    if (!is_int($version) || $version < 1 || ($grammar['unsupported_syntax_fails_closed'] ?? null) !== true) {
        throw new RuntimeException('Workflow condition grammar is invalid.');
    }
    if (!is_array($operators) || array_keys($operators) !== ['and', 'or', 'equals']) {
        throw new RuntimeException('Workflow condition grammar is invalid.');
    }
    if (!is_array($grouping) || array_keys($grouping) !== ['open', 'close']) {
        throw new RuntimeException('Workflow condition grammar is invalid.');
    }
    foreach (array_merge(array_values($operators), array_values($grouping)) as $token) {
        if (!is_string($token) || $token === '' || preg_match('/\s/', $token) === 1) {
            throw new RuntimeException('Workflow condition grammar is invalid.');
        }
    }
    if (count(array_unique(array_merge(array_values($operators), array_values($grouping)))) !== 5) {
        throw new RuntimeException('Workflow condition grammar is invalid.');
    }
    if (
        !is_string($identifierPattern) ||
        $identifierPattern === '' ||
        str_contains($identifierPattern, '~') ||
        @preg_match('~^(?:' . $identifierPattern . ')$~D', 'identifier') === false
    ) {
        throw new RuntimeException('Workflow condition grammar is invalid.');
    }
    if (
        !is_array($literals) ||
        array_keys($literals) !== ['string_delimiter', 'booleans'] ||
        !is_string($literals['string_delimiter']) ||
        strlen($literals['string_delimiter']) !== 1 ||
        !is_array($literals['booleans']) ||
        $literals['booleans'] === []
    ) {
        throw new RuntimeException('Workflow condition grammar is invalid.');
    }
    foreach ($literals['booleans'] as $token => $value) {
        if (!is_string($token) || preg_match('~^(?:' . $identifierPattern . ')$~D', $token) !== 1 || !is_bool($value)) {
            throw new RuntimeException('Workflow condition grammar is invalid.');
        }
    }
    if (!is_array($zeroArgumentCalls) || count(array_unique($zeroArgumentCalls)) !== count($zeroArgumentCalls)) {
        throw new RuntimeException('Workflow condition grammar is invalid.');
    }
    foreach ($zeroArgumentCalls as $call) {
        if (!is_string($call) || preg_match('~^(?:' . $identifierPattern . ')$~D', $call) !== 1) {
            throw new RuntimeException('Workflow condition grammar is invalid.');
        }
    }
}

/**
 * @param array<string, mixed> $grammar
 * @return array<string, mixed>
 */
function agentHarnessReadinessParseCondition(string $expression, array $grammar): array
{
    agentHarnessReadinessValidateConditionGrammar($grammar);
    $tokens = agentHarnessReadinessTokenizeCondition($expression, $grammar);
    $cursor = 0;
    $condition = agentHarnessReadinessParseConditionOr($tokens, $cursor, $grammar);
    if ($cursor !== count($tokens)) {
        throw new InvalidArgumentException('unexpected token "' . $tokens[$cursor]['value'] . '"');
    }

    return agentHarnessReadinessNormalizeConditionAst($condition);
}

/**
 * @param array<string, mixed> $grammar
 * @return array<int, array{type:string,value:mixed}>
 */
function agentHarnessReadinessTokenizeCondition(string $expression, array $grammar): array
{
    $operators = array_values($grammar['operators']);
    usort($operators, static fn(string $left, string $right): int => strlen($right) <=> strlen($left));
    $grouping = array_values($grammar['grouping']);
    usort($grouping, static fn(string $left, string $right): int => strlen($right) <=> strlen($left));
    $stringDelimiter = $grammar['literals']['string_delimiter'];
    $booleans = $grammar['literals']['booleans'];
    $identifierRegex = '~\G(?:' . $grammar['identifier_pattern'] . ')~A';

    $tokens = [];
    $offset = 0;
    $length = strlen($expression);
    while ($offset < $length) {
        if (preg_match('/\G\s+/A', $expression, $match, 0, $offset) === 1) {
            $offset += strlen($match[0]);
            continue;
        }

        $matchedToken = false;
        foreach ($operators as $operator) {
            if (substr($expression, $offset, strlen($operator)) === $operator) {
                $tokens[] = ['type' => 'operator', 'value' => $operator];
                $offset += strlen($operator);
                $matchedToken = true;
                break;
            }
        }
        if ($matchedToken) {
            continue;
        }

        foreach ($grouping as $groupingToken) {
            if (substr($expression, $offset, strlen($groupingToken)) === $groupingToken) {
                $tokens[] = ['type' => 'parenthesis', 'value' => $groupingToken];
                $offset += strlen($groupingToken);
                $matchedToken = true;
                break;
            }
        }
        if ($matchedToken) {
            continue;
        }

        if ($expression[$offset] === $stringDelimiter) {
            $end = strpos($expression, $stringDelimiter, $offset + 1);
            if ($end === false) {
                throw new InvalidArgumentException('unterminated string literal');
            }
            $tokens[] = [
                'type' => 'literal',
                'value' => substr($expression, $offset + 1, $end - $offset - 1),
            ];
            $offset = $end + 1;
            continue;
        }

        if (preg_match($identifierRegex, $expression, $match, 0, $offset) === 1) {
            $value = $match[0];
            $tokens[] = array_key_exists($value, $booleans)
                ? ['type' => 'literal', 'value' => $booleans[$value]]
                : ['type' => 'identifier', 'value' => $value];
            $offset += strlen($value);
            continue;
        }

        throw new InvalidArgumentException('unsupported character at offset ' . $offset);
    }

    if ($tokens === []) {
        throw new InvalidArgumentException('condition is empty');
    }

    return $tokens;
}

/**
 * @param array<int, array{type:string,value:mixed}> $tokens
 * @param array<string, mixed> $grammar
 * @return array<string, mixed>
 */
function agentHarnessReadinessParseConditionOr(array $tokens, int &$cursor, array $grammar): array
{
    $left = agentHarnessReadinessParseConditionAnd($tokens, $cursor, $grammar);
    while (agentHarnessReadinessConsumeConditionToken($tokens, $cursor, 'operator', $grammar['operators']['or'])) {
        $right = agentHarnessReadinessParseConditionAnd($tokens, $cursor, $grammar);
        $left = ['any' => [$left, $right]];
    }

    return $left;
}

/**
 * @param array<int, array{type:string,value:mixed}> $tokens
 * @param array<string, mixed> $grammar
 * @return array<string, mixed>
 */
function agentHarnessReadinessParseConditionAnd(array $tokens, int &$cursor, array $grammar): array
{
    $left = agentHarnessReadinessParseConditionPrimary($tokens, $cursor, $grammar);
    while (agentHarnessReadinessConsumeConditionToken($tokens, $cursor, 'operator', $grammar['operators']['and'])) {
        $right = agentHarnessReadinessParseConditionPrimary($tokens, $cursor, $grammar);
        $left = ['all' => [$left, $right]];
    }

    return $left;
}

/**
 * @param array<int, array{type:string,value:mixed}> $tokens
 * @param array<string, mixed> $grammar
 * @return array<string, mixed>
 */
function agentHarnessReadinessParseConditionPrimary(array $tokens, int &$cursor, array $grammar): array
{
    if (agentHarnessReadinessConsumeConditionToken($tokens, $cursor, 'parenthesis', $grammar['grouping']['open'])) {
        $condition = agentHarnessReadinessParseConditionOr($tokens, $cursor, $grammar);
        if (
            !agentHarnessReadinessConsumeConditionToken($tokens, $cursor, 'parenthesis', $grammar['grouping']['close'])
        ) {
            throw new InvalidArgumentException('missing closing parenthesis');
        }

        return $condition;
    }

    $identifier = $tokens[$cursor] ?? null;
    if (!is_array($identifier) || $identifier['type'] !== 'identifier') {
        throw new InvalidArgumentException('expected identifier');
    }
    ++$cursor;

    if (agentHarnessReadinessConsumeConditionToken($tokens, $cursor, 'parenthesis', $grammar['grouping']['open'])) {
        if (
            !agentHarnessReadinessConsumeConditionToken($tokens, $cursor, 'parenthesis', $grammar['grouping']['close'])
        ) {
            throw new InvalidArgumentException('condition calls must not contain arguments');
        }
        if (!in_array($identifier['value'], $grammar['zero_argument_calls'], true)) {
            throw new InvalidArgumentException('unsupported zero-argument call');
        }

        return ['call' => $identifier['value']];
    }

    if (!agentHarnessReadinessConsumeConditionToken($tokens, $cursor, 'operator', $grammar['operators']['equals'])) {
        throw new InvalidArgumentException('expected equality operator');
    }
    $literal = $tokens[$cursor] ?? null;
    if (!is_array($literal) || $literal['type'] !== 'literal') {
        throw new InvalidArgumentException('expected string or boolean literal');
    }
    ++$cursor;

    return ['equals' => [$identifier['value'], $literal['value']]];
}

/**
 * @param array<int, array{type:string,value:mixed}> $tokens
 */
function agentHarnessReadinessConsumeConditionToken(array $tokens, int &$cursor, string $type, mixed $value): bool
{
    $token = $tokens[$cursor] ?? null;
    if (!is_array($token) || $token['type'] !== $type || $token['value'] !== $value) {
        return false;
    }

    ++$cursor;

    return true;
}

function agentHarnessReadinessLoadWorkflowContract(string $path): array
{
    if (!is_file($path)) {
        throw new InvalidArgumentException('Workflow contract does not exist: ' . $path);
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Failed to read workflow contract: ' . $path);
    }

    $contract = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($contract) || ($contract['schema_version'] ?? null) !== 2) {
        throw new RuntimeException('Workflow contract must use schema version 2.');
    }

    $ci = $contract['ci'] ?? null;
    if (
        !is_array($ci) ||
        !is_string($ci['workflow'] ?? null) ||
        trim($ci['workflow']) === '' ||
        !is_string($ci['blocking_failure_control_policy'] ?? null) ||
        ($ci['job_classification_policy'] ?? null) !== 'explicit-v1' ||
        !is_array($ci['advisory_jobs'] ?? null) ||
        !is_array($ci['blocking_execution_fingerprints'] ?? null) ||
        $ci['blocking_execution_fingerprints'] === [] ||
        !is_array($ci['condition_grammar'] ?? null) ||
        !is_array($ci['blocking_jobs'] ?? null) ||
        $ci['blocking_jobs'] === []
    ) {
        throw new RuntimeException(
            'Workflow contract must define a CI workflow, a failure-control policy, component execution fingerprints, grammar, an explicit job-classification policy, advisory jobs, and blocking jobs.',
        );
    }

    agentHarnessReadinessFailureControlsForPolicy($ci['blocking_failure_control_policy']);
    $fingerprints = $ci['blocking_execution_fingerprints'];
    foreach ($fingerprints as $component => $fingerprint) {
        if (
            !is_string($component) ||
            trim($component) === '' ||
            !is_string($fingerprint) ||
            preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1
        ) {
            throw new RuntimeException('Workflow contract execution fingerprints are invalid.');
        }
    }
    agentHarnessReadinessValidateConditionGrammar($ci['condition_grammar']);
    agentHarnessReadinessRequireRepoRelativePath($ci['workflow'], 'ci.workflow');

    $advisoryJobs = $ci['advisory_jobs'];
    if ($advisoryJobs !== [] && array_keys($advisoryJobs) !== range(0, count($advisoryJobs) - 1)) {
        throw new RuntimeException('Workflow contract advisory jobs must be a list.');
    }
    foreach ($advisoryJobs as $jobName) {
        if (!is_string($jobName) || trim($jobName) === '') {
            throw new RuntimeException('Workflow contract advisory jobs must be non-empty strings.');
        }
    }
    if (count(array_unique($advisoryJobs)) !== count($advisoryJobs)) {
        throw new RuntimeException('Workflow contract advisory jobs must be unique.');
    }

    $fingerprintedExecutionJobs = [];
    foreach ($ci['blocking_jobs'] as $jobName => $job) {
        if (!is_string($jobName) || !is_array($job)) {
            throw new RuntimeException('Workflow contract blocking jobs must be a map of job objects.');
        }
        if (($job['kind'] ?? null) === 'exact_execution') {
            if (
                agentHarnessReadinessNormalizeNeeds($job['needs'] ?? null) === null ||
                !is_string($job['runs_on'] ?? null) ||
                trim($job['runs_on']) === '' ||
                !is_int($job['timeout_minutes'] ?? null) ||
                $job['timeout_minutes'] < 1 ||
                !is_array($job['condition'] ?? null) ||
                !is_array($job['evidence'] ?? null) ||
                !is_array($job['assertion'] ?? null) ||
                !is_array($job['post_assertion_steps'] ?? null)
            ) {
                throw new RuntimeException('Workflow contract exact-execution job "' . $jobName . '" is malformed.');
            }
            continue;
        }
        if (($job['kind'] ?? null) !== 'fingerprinted_execution') {
            throw new RuntimeException('Workflow contract blocking jobs must declare a supported kind.');
        }
        if (array_keys($job) !== ['kind']) {
            throw new RuntimeException('Workflow contract fingerprinted-execution jobs may only declare their kind.');
        }
        $fingerprintedExecutionJobs[] = $jobName;
    }

    $overlappingJobs = array_intersect(array_keys($ci['blocking_jobs']), $advisoryJobs);
    if ($overlappingJobs !== []) {
        throw new RuntimeException('Workflow contract jobs cannot be both blocking and advisory.');
    }

    $expectedFingerprintComponents = array_merge(['workflow_execution_envelope'], $fingerprintedExecutionJobs);
    $actualFingerprintComponents = array_keys($fingerprints);
    sort($expectedFingerprintComponents, SORT_STRING);
    sort($actualFingerprintComponents, SORT_STRING);
    if ($expectedFingerprintComponents !== $actualFingerprintComponents) {
        throw new RuntimeException(
            'Workflow contract execution fingerprints must match every fingerprinted-execution component exactly.',
        );
    }

    return $contract;
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

    $autoloadPath = dirname(__DIR__, 3) . '/vendor/autoload.php';
    if (!is_file($autoloadPath)) {
        throw new RuntimeException('Symfony YAML support requires vendor/autoload.php. Run composer install first.');
    }

    require_once $autoloadPath;

    if (!class_exists(Symfony\Component\Yaml\Yaml::class)) {
        throw new RuntimeException('Failed to load Symfony YAML support from vendor/autoload.php.');
    }
}
