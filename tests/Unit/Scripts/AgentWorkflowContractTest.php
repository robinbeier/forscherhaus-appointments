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
        self::assertTrue($contract['authority']['primary_external_single_writer'] ?? null);
        self::assertSame(
            ['commit', 'push', 'pr_mutation', 'check_rerun', 'merge', 'linear_mutation', 'workpad_update'],
            $contract['authority']['primary_owned_mutations'] ?? null,
        );
        self::assertSame(
            'scripts/agent/run_readonly_reviewer.sh',
            $contract['authority']['reviewer']['invocation'] ?? null,
        );
        self::assertSame(
            'materialized_base_blob_outside_worktree',
            $contract['authority']['reviewer']['invocation_source'] ?? null,
        );
        self::assertSame('read-only', $contract['authority']['reviewer']['filesystem'] ?? null);
        self::assertSame('denied', $contract['authority']['reviewer']['network'] ?? null);
        self::assertSame('never', $contract['authority']['reviewer']['approval_policy'] ?? null);
        self::assertFalse($contract['authority']['reviewer']['inherits_user_config'] ?? null);
        self::assertFalse($contract['authority']['reviewer']['inherits_execpolicy_rules'] ?? null);
        self::assertTrue($contract['authority']['reviewer']['output_binds_base_sha'] ?? null);
        self::assertFalse($contract['authority']['reviewer']['allows_external_connectors'] ?? null);
        self::assertFalse($contract['authority']['reviewer']['allows_delegation'] ?? null);
        self::assertSame('review_base_commit', $contract['authority']['reviewer']['trust_anchor'] ?? null);
        self::assertSame(
            '.codex/contracts/readonly-reviewer-trust-paths.txt',
            $contract['authority']['reviewer']['trusted_base_paths_file'] ?? null,
        );
        self::assertSame(
            [
                'correctness_security' => [
                    'instructions' => '.codex/agents/reviewer-correctness.toml',
                    'model' => 'gpt-5.4',
                    'reasoning' => 'high',
                ],
                'design_maintainability' => [
                    'instructions' => '.codex/agents/reviewer-design.toml',
                    'model' => 'gpt-5.4-mini',
                    'reasoning' => 'medium',
                ],
                'tests_regression_flake' => [
                    'instructions' => '.codex/agents/reviewer-tests.toml',
                    'model' => 'gpt-5.4-mini',
                    'reasoning' => 'medium',
                ],
            ],
            $contract['authority']['reviewer']['profiles'] ?? null,
        );
        self::assertContains('multi_agent', $contract['authority']['reviewer']['disabled_features'] ?? []);
        self::assertContains('plugins', $contract['authority']['reviewer']['disabled_features'] ?? []);
        self::assertTrue($contract['parallel_work']['local_implementation_only'] ?? null);
        self::assertTrue($contract['parallel_work']['requires_common_base_sha'] ?? null);
        self::assertTrue($contract['parallel_work']['requires_disjoint_ownership'] ?? null);
        self::assertSame(2, $contract['parallel_work']['max_local_writer_lanes'] ?? null);
        self::assertSame('implementation_worker', $contract['parallel_work']['writer_role'] ?? null);
        self::assertTrue($contract['parallel_work']['external_mutations_remain_serial'] ?? null);
        self::assertTrue($contract['parallel_work']['requires_semantic_independence_attestation'] ?? null);
        self::assertSame('_', $contract['parallel_work']['filename_stem_prefix_suffix'] ?? null);
        self::assertSame('docs/maps/component_ownership_map.json', $contract['parallel_work']['ownership_map'] ?? null);
        self::assertContains('scripts/agent', $contract['parallel_work']['primary_owned_path_prefixes'] ?? []);
        self::assertContains('.github/workflows', $contract['parallel_work']['primary_owned_path_prefixes'] ?? []);
        self::assertContains('.codex/config.toml', $contract['parallel_work']['primary_owned_path_prefixes'] ?? []);
        self::assertContains(
            '.codex/agents/implementation-worker.toml',
            $contract['parallel_work']['primary_owned_path_prefixes'] ?? [],
        );
        self::assertContains(
            'docs/maps/component_ownership_map.json',
            $contract['parallel_work']['primary_owned_path_prefixes'] ?? [],
        );
        self::assertTrue($contract['land']['requires_exact_head'] ?? null);
        self::assertSame(
            'gh pr merge --merge --match-head-commit <current_head_sha>',
            $contract['land']['merge_command'] ?? null,
        );
        self::assertSame('In Review', $contract['land']['push_after_ready_linear_state'] ?? null);
        self::assertSame(1, $contract['land']['exact_head_mergegate']['schema_version'] ?? null);
        self::assertSame('main', $contract['land']['exact_head_mergegate']['base_ref'] ?? null);
        self::assertSame('ci.yml', $contract['land']['exact_head_mergegate']['workflow_file'] ?? null);
        self::assertSame('CI', $contract['land']['exact_head_mergegate']['workflow_name'] ?? null);
        self::assertSame(
            'before_between_and_after_bounded_evidence_observations',
            $contract['land']['exact_head_mergegate']['pr_revalidation'] ?? null,
        );
        self::assertSame(
            'two_identical_bounded_observations',
            $contract['land']['exact_head_mergegate']['ci_evidence_revalidation'] ?? null,
        );
        self::assertSame(
            'two_identical_bounded_observations',
            $contract['land']['exact_head_mergegate']['review_evidence_revalidation'] ?? null,
        );
        self::assertSame(
            'review.sensitive_change_lenses',
            $contract['land']['exact_head_mergegate']['review_lens_source'] ?? null,
        );
        self::assertSame(
            'exact-head-review-attestation:v2',
            $contract['land']['exact_head_mergegate']['review_attestation']['marker'] ?? null,
        );
        self::assertSame(
            'no_findings',
            $contract['land']['exact_head_mergegate']['review_attestation']['verdict'] ?? null,
        );
        self::assertSame(
            'owner_accountable_assertion',
            $contract['land']['exact_head_mergegate']['review_attestation']['authority_model'] ?? null,
        );
        self::assertFalse(
            $contract['land']['exact_head_mergegate']['review_attestation']['cryptographic_agent_execution_proof'] ??
                null,
        );
        self::assertFalse(
            $contract['land']['exact_head_mergegate']['review_attestation']['malicious_repository_owner_in_scope'] ??
                null,
        );
        self::assertTrue(
            $contract['land']['exact_head_mergegate']['review_attestation']['requires_unedited_comment'] ?? null,
        );
        self::assertSame(
            'graphql_user_content_edit_count_excluding_creation',
            $contract['land']['exact_head_mergegate']['review_attestation']['comment_edit_evidence'] ?? null,
        );
        self::assertSame(
            ['review_id', 'review_comment_id', 'review_payload_digest'],
            $contract['land']['exact_head_mergegate']['review_attestation']['activity_watermarks'] ?? null,
        );
        self::assertSame(
            ['OWNER'],
            $contract['land']['exact_head_mergegate']['review_attestation']['trusted_author_associations'] ?? null,
        );
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
        self::assertSame('strict-v1', $contract['ci']['blocking_failure_control_policy'] ?? null);
        self::assertSame('explicit-v1', $contract['ci']['job_classification_policy'] ?? null);
        self::assertSame(
            ['heavy-job-duration-trends', 'pdf-renderer-latency'],
            $contract['ci']['advisory_jobs'] ?? null,
        );
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

        $policy = require $this->repoRoot . '/scripts/ci/config/agent_harness_readiness_policy.php';
        self::assertIsArray($policy);
        self::assertArrayNotHasKey('blocking_jobs', $policy);
    }

    public function testHarnessEntryPointsAndGeneratedCachesStayAligned(): void
    {
        $index = $this->readRepoFile('docs/agent-harness-index.md');
        $gitignore = $this->readRepoFile('.gitignore');
        $composer = $this->readRepoJson('composer.json');

        self::assertStringContainsString('docs/ci-write-contracts.md', $index);
        self::assertStringContainsString('docs/exact-head-mergegate.md', $index);
        self::assertSame(
            'php scripts/ci/check_exact_head_mergegate.php',
            $composer['scripts']['check:exact-head-mergegate'] ?? null,
        );
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
