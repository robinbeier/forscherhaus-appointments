<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/ci/check_agent_harness_readiness.php';

class AgentWorkflowContractTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repoRoot = dirname(__DIR__, 3);
    }

    public function testCanonicalWorkflowSurfacesReferenceMachineContract(): void
    {
        $contract = $this->readRepoJson('.codex/contracts/agent-workflow.json');
        $surfaces = $contract['surfaces'] ?? null;
        self::assertIsArray($surfaces);

        foreach ($surfaces as $path => $requirements) {
            self::assertIsString($path);
            self::assertIsArray($requirements);
            $content = $this->readRepoFile($path);
            self::assertStringContainsString($requirements['contract_reference'], $content, $path);
            self::assertIsArray($requirements['required_sections'] ?? null, $path);
            foreach ($requirements['required_sections'] as $heading => $requiredClauses) {
                self::assertIsString($heading, $path);
                self::assertIsArray($requiredClauses, $path);
                $section = agentHarnessReadinessExtractMarkdownSection($content, $heading);
                self::assertNotNull($section, $path . ': ' . $heading);
                foreach ($requiredClauses as $requiredClause) {
                    self::assertSame(1, substr_count($section, $requiredClause), $path . ': ' . $heading);
                    self::assertSame(1, substr_count($content, $requiredClause), $path . ': ' . $requiredClause);
                }
            }
        }
    }

    public function testStructuredContractDefinesExactHeadAndHighRiskInvariants(): void
    {
        $contract = $this->readRepoJson('.codex/contracts/agent-workflow.json');

        self::assertSame(2, $contract['schema_version'] ?? null);
        self::assertSame('In Review', $contract['publish']['linear_state'] ?? null);
        self::assertFalse($contract['publish']['may_set_ready_to_merge'] ?? null);
        self::assertTrue($contract['publish']['push_invalidates_exact_head_evidence'] ?? null);
        self::assertTrue($contract['land']['requires_exact_head'] ?? null);
        self::assertSame(
            'gh pr merge --merge --match-head-commit <current_head_sha>',
            $contract['land']['merge_command'] ?? null,
        );
        self::assertSame('In Review', $contract['land']['push_after_ready_linear_state'] ?? null);
        self::assertTrue($contract['review']['sensitive_changes_require_independent_final_reviews'] ?? null);
        self::assertSame(
            ['correctness_security', 'design_maintainability', 'tests_regression_flake'],
            $contract['review']['sensitive_change_lenses'] ?? null,
        );
        self::assertFalse($contract['public_write']['caller_supplied_values_create_authority'] ?? null);
        self::assertTrue($contract['public_write']['requires_authority_bound_to_target'] ?? null);
        self::assertTrue($contract['public_write']['requires_null_mutation_on_rejection'] ?? null);
        self::assertTrue($contract['public_write']['requires_race_validation_before_mutation'] ?? null);
        self::assertFalse($contract['evidence_privacy']['allow_secrets'] ?? null);
        self::assertFalse($contract['evidence_privacy']['allow_capability_values'] ?? null);
        self::assertFalse($contract['evidence_privacy']['allow_personal_data'] ?? null);
        self::assertSame(
            [
                'forbidden_workflow_run_default_keys' => ['shell'],
                'forbidden_job_keys' => ['continue-on-error'],
                'forbidden_job_run_default_keys' => ['shell'],
                'forbidden_step_keys' => ['continue-on-error', 'shell'],
            ],
            $contract['ci']['blocking_failure_controls'] ?? null,
        );
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/D',
            (string) ($contract['ci']['blocking_execution_sha256'] ?? ''),
        );
        self::assertSame(
            ['write-contract-booking', 'write-contract-api'],
            $contract['ci']['required_exact_execution_jobs'] ?? null,
        );
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
                'zero_argument_calls' => ['always'],
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
        self::assertTrue($ci['job_inventory_is_exhaustive'] ?? null);
        self::assertSame(
            [
                'heavy-job-duration-trends' => ['kind' => 'advisory_signal'],
                'pdf-renderer-latency' => ['kind' => 'advisory_signal'],
            ],
            $ci['advisory_jobs'] ?? null,
        );
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
                'deep-check-bootstrap',
                'deep-check-seed-snapshot',
                'deep-runtime-suite',
                'integration-smoke',
                'js-lint-changed',
                'phpstan-application',
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
        self::assertCount(17, $fingerprintedJobs);

        foreach (['write-contract-booking', 'write-contract-api'] as $jobName) {
            $job = $ci['blocking_jobs'][$jobName];
            self::assertSame('exact_execution', $job['kind'] ?? null, $jobName);
            self::assertSame(['changes', 'deep-runtime-suite'], $job['needs'] ?? null, $jobName);
            self::assertSame('deep-runtime-suite-artifacts', $job['evidence']['artifact'] ?? null, $jobName);
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
        $classifiedJobs = array_merge(array_keys($ci['blocking_jobs']), array_keys($ci['advisory_jobs']));
        $checks = array_merge(
            agentHarnessReadinessEvaluateJobInventory($ciWorkflow, $classifiedJobs),
            agentHarnessReadinessEvaluateWorkflowFailureMasks($ciWorkflow, $ci['blocking_failure_controls']),
            agentHarnessReadinessEvaluateBlockingJobs(
                $ciWorkflow,
                array_keys($ci['blocking_jobs']),
                $ci['blocking_failure_controls'],
            ),
            agentHarnessReadinessEvaluateBlockingExecutionFingerprint(
                $ciWorkflow,
                array_keys($ci['blocking_jobs']),
                $ci['blocking_execution_sha256'],
            ),
            agentHarnessReadinessEvaluateBlockingJobContracts(
                $ciWorkflow,
                $ci['blocking_jobs'],
                $ci['condition_grammar'],
                $ci['blocking_failure_controls'],
            ),
        );
        foreach ($checks as $check) {
            self::assertSame('pass', $check['status'], $check['id'] . ': ' . ($check['message'] ?? ''));
        }

        $policy = require $this->repoRoot . '/scripts/ci/config/agent_harness_readiness_policy.php';
        self::assertIsArray($policy);
        self::assertArrayNotHasKey('blocking_jobs', $policy);
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
