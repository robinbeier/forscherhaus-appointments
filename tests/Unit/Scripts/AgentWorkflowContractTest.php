<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/ci/lib/WorkflowContractChecks.php';

class AgentWorkflowContractTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repoRoot = dirname(__DIR__, 3);
    }

    public function testCanonicalSteeringSourcesKeepRequiredReferences(): void
    {
        $steeringChecks = agentHarnessReadinessEvaluateSteeringSources($this->repoRoot, [
            'README.md' => ['docs/agent-harness-index.md', 'WORKFLOW.md', 'AGENTS.md'],
            'AGENTS.md' => ['docs/agent-harness-index.md'],
            'WORKFLOW.md' => ['docs/agent-harness-index.md'],
            'docs/agent-harness-index.md' => [
                '.github/workflows/ci.yml',
                'docs/architecture-map.md',
                'docs/ownership-map.md',
            ],
        ]);
        foreach ($steeringChecks as $check) {
            self::assertSame('pass', $check['status'], $check['id'] . ': ' . ($check['message'] ?? ''));
        }
    }

    public function testStructuredContractDefinesExactHeadAndHighRiskInvariants(): void
    {
        $contract = $this->readRepoJson('.codex/contracts/agent-workflow.json');

        self::assertSame(2, $contract['schema_version'] ?? null);
        self::assertSame('In Review', $contract['publish']['linear_state'] ?? null);
        self::assertFalse($contract['publish']['may_set_ready_to_merge'] ?? null);
        self::assertTrue($contract['publish']['push_invalidates_exact_head_evidence'] ?? null);
        self::assertTrue($contract['authority']['primary_external_single_writer'] ?? null);
        self::assertSame(
            ['commit', 'push', 'pr_mutation', 'check_rerun', 'merge', 'linear_mutation', 'workpad_update'],
            $contract['authority']['primary_owned_mutations'] ?? null,
        );
        self::assertTrue($contract['land']['requires_exact_head'] ?? null);
        self::assertSame(
            'gh pr merge --merge --match-head-commit <current_head_sha>',
            $contract['land']['merge_command'] ?? null,
        );
        self::assertSame('In Review', $contract['land']['push_after_ready_linear_state'] ?? null);
        self::assertTrue($contract['land']['requires_independent_review'] ?? null);
        self::assertTrue($contract['land']['requires_current_blocking_ci'] ?? null);
        self::assertSame('standard', $contract['review']['mode'] ?? null);
        self::assertSame(1, $contract['review']['minimum_independent_reviewers'] ?? null);
        self::assertSame('risk_based', $contract['review']['specialist_review'] ?? null);
        self::assertSame('available_read_only_reviewer_or_human', $contract['review']['execution'] ?? null);
        self::assertFalse($contract['review']['requires_sealed_runner'] ?? null);
        self::assertFalse($contract['review']['requires_external_bootstrap_review'] ?? null);
        self::assertTrue($contract['review']['summary_binds_reviewed_head'] ?? null);
        self::assertSame(
            [
                'protocol' => 'docs/reviewer-runtime-preflight.md',
                'capability_source' => 'current_runtime',
                'before_diff_dispatch' => true,
                'startup_probe_is_review' => false,
                'requires_enforced_read_only_boundary' => true,
                'fallback_preserves' => [
                    'independence',
                    'correctness_security',
                    'design_maintainability',
                    'tests_regressions',
                    'repository_base_head',
                    'tool_and_credential_boundaries',
                ],
                'runtime_failure_classification' => 'harness_failure',
                'no_equivalent_reviewer' => 'block_merge',
                'requires_fresh_probe_context' => true,
                'startup_probe_input' => 'self_contained_readiness_request_only',
            ],
            $contract['review']['runtime_preflight'] ?? null,
        );
        self::assertFileExists(__DIR__ . '/../../../' . $contract['review']['runtime_preflight']['protocol']);
        self::assertArrayNotHasKey('sensitive_changes_require_independent_final_reviews', $contract['review']);
        self::assertArrayNotHasKey('sensitive_change_lenses', $contract['review']);
        self::assertFalse($contract['public_write']['caller_supplied_values_create_authority'] ?? null);
        self::assertTrue($contract['public_write']['requires_authority_bound_to_target'] ?? null);
        self::assertTrue($contract['public_write']['requires_null_mutation_on_rejection'] ?? null);
        self::assertTrue($contract['public_write']['requires_race_validation_before_mutation'] ?? null);
        self::assertFalse($contract['evidence_privacy']['allow_secrets'] ?? null);
        self::assertFalse($contract['evidence_privacy']['allow_capability_values'] ?? null);
        self::assertFalse($contract['evidence_privacy']['allow_personal_data'] ?? null);
        self::assertSame('strict-v1', $contract['ci']['blocking_failure_control_policy'] ?? null);
        self::assertSame('explicit-v1', $contract['ci']['job_classification_policy'] ?? null);
        self::assertSame([], $contract['ci']['advisory_jobs'] ?? null);
        self::assertArrayNotHasKey('unclassified_job_policy', $contract['ci']);
        self::assertArrayNotHasKey('blocking_failure_controls', $contract['ci']);
        self::assertArrayNotHasKey('blocking_execution_sha256', $contract['ci']);
        self::assertArrayNotHasKey('required_exact_execution_jobs', $contract['ci']);
        self::assertArrayNotHasKey('job_inventory_is_exhaustive', $contract['ci']);
        self::assertIsArray($contract['ci']['blocking_execution_fingerprints'] ?? null);
        foreach ($contract['ci']['blocking_execution_fingerprints'] as $fingerprint) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', (string) $fingerprint);
        }
        self::assertSame(
            [
                'version' => 1,
                'operators' => [
                    'and' => '&&',
                    'or' => '||',
                    'equals' => '==',
                ],
                'grouping' => [
                    'open' => '(',
                    'close' => ')',
                ],
                'identifier_pattern' => '[A-Za-z_][A-Za-z0-9_.-]*',
                'literals' => [
                    'string_delimiter' => "'",
                    'booleans' => [
                        'true' => true,
                        'false' => false,
                    ],
                ],
                'zero_argument_calls' => ['always', 'failure'],
                'unsupported_syntax_fails_closed' => true,
            ],
            $contract['ci']['condition_grammar'] ?? null,
        );
    }

    public function testStructuredContractOwnsEveryBlockingJob(): void
    {
        $contract = $this->readRepoJson('.codex/contracts/agent-workflow.json');
        $ci = $contract['ci'] ?? null;

        self::assertIsArray($ci);
        self::assertSame('.github/workflows/ci.yml', $ci['workflow'] ?? null);
        self::assertIsArray($ci['blocking_jobs'] ?? null);
        $jobNames = array_keys($ci['blocking_jobs']);
        sort($jobNames, SORT_STRING);
        self::assertSame(
            [
                'api-contract-openapi',
                'architecture-boundaries',
                'architecture-ownership-map',
                'booking-controller-flows',
                'build-test',
                'changes',
                'coverage-delta',
                'coverage-shard-integration',
                'coverage-shard-unit',
                'deep-runtime-suite',
                'defense-cycle-ordinary-flows',
                'integration-smoke',
                'js-lint-changed',
                'pdf-renderer-tests',
                'phpstan-application',
                'root-deployment-tests',
                'typed-request-contracts',
                'typed-request-dto',
                'write-contract-api',
                'write-contract-booking',
            ],
            $jobNames,
        );

        $fingerprintedJobs = array_filter(
            $ci['blocking_jobs'],
            static fn(array $job): bool => ($job['kind'] ?? null) === 'fingerprinted_execution',
        );
        self::assertCount(18, $fingerprintedJobs);
        $expectedFingerprintComponents = array_merge(['workflow_execution_envelope'], array_keys($fingerprintedJobs));
        $actualFingerprintComponents = array_keys($ci['blocking_execution_fingerprints']);
        sort($expectedFingerprintComponents, SORT_STRING);
        sort($actualFingerprintComponents, SORT_STRING);
        self::assertSame($expectedFingerprintComponents, $actualFingerprintComponents);

        foreach (['write-contract-booking', 'write-contract-api'] as $jobName) {
            $job = $ci['blocking_jobs'][$jobName];
            self::assertSame('exact_execution', $job['kind'] ?? null, $jobName);
            self::assertSame(['changes', 'deep-runtime-suite'], $job['needs'] ?? null, $jobName);
            self::assertSame('ubuntu-latest', $job['runs_on'] ?? null, $jobName);
            self::assertSame(35, $job['timeout_minutes'] ?? null, $jobName);
            self::assertSame(
                '${{ needs.deep-runtime-suite.outputs.artifact-name || \'missing-deep-runtime-artifact\' }}',
                $job['evidence']['artifact'] ?? null,
                $jobName,
            );
            self::assertSame('storage/logs/ci/deep-runtime-suite', $job['evidence']['path'] ?? null, $jobName);
            self::assertSame(
                'php scripts/ci/assert_deep_runtime_suite.php ' .
                    '--manifest=storage/logs/ci/deep-runtime-suite/manifest.json --suite=' .
                    $jobName,
                $job['assertion']['run'] ?? null,
                $jobName,
            );
            self::assertSame(
                [
                    [
                        'if' => 'failure()',
                        'run' =>
                            "cat storage/logs/ci/deep-runtime-suite/manifest.json || true\n" .
                            'cat storage/logs/ci/deep-runtime-suite/' .
                            $jobName .
                            ".log || true\n" .
                            'cat storage/logs/ci/deep-runtime-suite/' .
                            $jobName .
                            ".json || true\n",
                    ],
                ],
                $job['post_assertion_steps'] ?? null,
                $jobName,
            );
        }

        $ciWorkflow = agentHarnessReadinessLoadWorkflowYaml(
            $this->repoRoot . '/' . ltrim((string) $ci['workflow'], '/'),
        );
        $checks = array_merge(
            agentHarnessReadinessEvaluateClassifiedJobInventory(
                $ciWorkflow,
                array_keys($ci['blocking_jobs']),
                $ci['advisory_jobs'],
            ),
            agentHarnessReadinessEvaluateWorkflowFailureMasks($ciWorkflow, $ci['blocking_failure_control_policy']),
            agentHarnessReadinessEvaluateBlockingJobs(
                $ciWorkflow,
                array_keys($ci['blocking_jobs']),
                $ci['blocking_failure_control_policy'],
            ),
            agentHarnessReadinessEvaluateBlockingExecutionFingerprints(
                $ciWorkflow,
                array_keys($fingerprintedJobs),
                $ci['condition_grammar'],
                $ci['blocking_execution_fingerprints'],
            ),
            agentHarnessReadinessEvaluateBlockingJobContracts(
                $ciWorkflow,
                $ci['blocking_jobs'],
                $ci['condition_grammar'],
                $ci['blocking_failure_control_policy'],
            ),
        );
        foreach ($checks as $check) {
            self::assertSame('pass', $check['status'], $check['id'] . ': ' . ($check['message'] ?? ''));
        }
    }

    public function testHarnessEntryPointsAndGeneratedCachesStayAligned(): void
    {
        $index = $this->readRepoFile('docs/agent-harness-index.md');
        $gitignore = $this->readRepoFile('.gitignore');

        self::assertStringContainsString('docs/ci-write-contracts.md', $index);
        self::assertStringContainsString('/.deptrac.cache', $gitignore);
        self::assertStringContainsString('/.playwright-cli/', $gitignore);
        self::assertStringNotContainsString('/.playwright-mcp/', $gitignore);
        self::assertTrue(is_executable($this->repoRoot . '/scripts/setup-worktree.sh'));
    }

    private function readRepoFile(string $relativePath): string
    {
        $contents = file_get_contents($this->repoRoot . '/' . $relativePath);
        self::assertNotFalse($contents, 'Failed to read ' . $relativePath);

        return $contents;
    }

    /**
     * @return array<string, mixed>
     */
    private function readRepoJson(string $relativePath): array
    {
        $decoded = json_decode($this->readRepoFile($relativePath), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
