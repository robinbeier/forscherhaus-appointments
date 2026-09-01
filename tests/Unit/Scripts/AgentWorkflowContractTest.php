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
            'scripts/agent/trusted_base_launcher.sh',
            $contract['authority']['reviewer']['invocation'] ?? null,
        );
        self::assertSame(
            'scripts/agent/run_readonly_reviewer.sh',
            $contract['authority']['reviewer']['payload_path'] ?? null,
        );
        self::assertSame(
            'external_system_git_materialized_exact_base_launcher_then_private_exact_base_payload',
            $contract['authority']['reviewer']['invocation_source'] ?? null,
        );
        self::assertSame(
            'absolute_system_git_clean_environment_private_blob_verification_before_any_repository_code_execution',
            $contract['authority']['reviewer']['bootstrap_materialization_policy'] ?? null,
        );
        self::assertSame(
            'required_external_exact_blob_path_and_marker',
            $contract['authority']['reviewer']['launcher_materialization_guard'] ?? null,
        );
        self::assertSame(
            'forbidden_fail_closed',
            $contract['authority']['reviewer']['direct_checkout_execution'] ?? null,
        );
        self::assertSame(
            'outer_seatbelt_default_deny_exact_bundle_read_only_runtime_scratch_only',
            $contract['authority']['reviewer']['filesystem'] ?? null,
        );
        self::assertSame(
            'outer_codex_transport_no_model_network_tool_or_external_credentials',
            $contract['authority']['reviewer']['network'] ?? null,
        );
        self::assertSame(
            'outer_seatbelt_plus_codex_read_only_no_model_tools',
            $contract['authority']['reviewer']['approval_policy'] ?? null,
        );
        self::assertSame('read-only', $contract['authority']['reviewer']['codex_sandbox_mode'] ?? null);
        self::assertSame('never', $contract['authority']['reviewer']['codex_approval_policy'] ?? null);
        self::assertFalse($contract['authority']['reviewer']['inherits_user_config'] ?? null);
        self::assertFalse($contract['authority']['reviewer']['inherits_execpolicy_rules'] ?? null);
        self::assertSame(
            'private_system_temp_bundle_and_internal_runtime_only',
            $contract['authority']['reviewer']['temporary_directory_policy'] ?? null,
        );
        self::assertSame(
            'exact_base_attested_binary_and_dynamic_closure_ignore_ambient_ini',
            $contract['authority']['reviewer']['php_runtime_configuration'] ?? null,
        );
        self::assertSame('/usr/bin/python3', $contract['authority']['interpreter_trust']['system_python'] ?? null);
        self::assertSame(['-I', '-B'], $contract['authority']['interpreter_trust']['system_python_arguments'] ?? null);
        self::assertTrue($contract['authority']['interpreter_trust']['php']['require_exact_closure_sha256'] ?? false);
        self::assertArrayNotHasKey('allow_root_owned_closure', $contract['authority']['interpreter_trust']['php']);
        self::assertArrayNotHasKey('candidates_by_platform', $contract['authority']['interpreter_trust']['php']);
        $fixedPlatforms = array_keys($contract['authority']['interpreter_trust']['php']['candidate_by_platform'] ?? []);
        $archivePlatforms = array_keys(
            $contract['authority']['interpreter_trust']['php']['pinned_archive_by_platform'] ?? [],
        );
        $admittedPlatforms = array_merge($fixedPlatforms, $archivePlatforms);
        sort($admittedPlatforms);
        $pinnedPlatforms = array_keys(
            $contract['authority']['interpreter_trust']['php']['closure_sha256_by_platform'] ?? [],
        );
        sort($pinnedPlatforms);
        self::assertSame(
            $admittedPlatforms,
            $pinnedPlatforms,
            'Every admitted PHP platform must have an exact closure pin.',
        );
        self::assertSame([], array_intersect($fixedPlatforms, $archivePlatforms));
        self::assertSame(
            [
                'url' => 'https://dl.static-php.dev/static-php-cli/bulk/php-8.4.12-cli-macos-aarch64.tar.gz',
                'archive_sha256' => '11b291a5e346d1978f133c523f21691bbbb233d18cfe0499fd2b7cfd8ed1e1a6',
                'member' => 'php',
                'member_sha256' => '918e07c4799de22f50fc5767a99a559d14026d0e37759f3a1265de3e38614c3f',
            ],
            $contract['authority']['interpreter_trust']['php']['pinned_archive_by_platform']['Darwin-arm64'] ?? null,
        );
        self::assertSame(
            'b9d81b2ab2e48895ecfe285c350d468cdcb2d910d7b560cffe0a1399a0f77e75',
            $contract['authority']['interpreter_trust']['php']['closure_sha256_by_platform']['Darwin-arm64'] ?? null,
        );
        self::assertSame(
            '40aab180ea2a8f847304bcb063a931ed43283ae9b714d5fdd74f5da71160f532',
            $contract['authority']['interpreter_trust']['php']['closure_sha256_by_platform']['Linux-aarch64'] ?? null,
        );
        self::assertSame(
            [
                'url' => 'https://dl.static-php.dev/static-php-cli/bulk/php-8.4.12-cli-macos-x86_64.tar.gz',
                'archive_sha256' => 'ba8ebc6784757106124bc4a37d7815ee9e0facb89425d9cfadd453635687bba2',
                'member' => 'php',
                'member_sha256' => '6e596791f60a50bb1cf9ad769555598137b57da592b01a1ecd2e9888be3a61ec',
            ],
            $contract['authority']['interpreter_trust']['php']['pinned_archive_by_platform']['Darwin-x86_64'] ?? null,
        );
        self::assertSame(
            '59f41f2a9e80cf0f359a5ba7e08fff10d62cb6c74ab424b895b9acfb6730216d',
            $contract['authority']['interpreter_trust']['php']['closure_sha256_by_platform']['Darwin-x86_64'] ?? null,
        );
        self::assertSame(
            [
                'url' => 'https://dl.static-php.dev/static-php-cli/bulk/php-8.4.12-cli-linux-x86_64.tar.gz',
                'archive_sha256' => '54558cc7433d3ca526412d8642881c1a1d8a09b964f5c409db92719bce52ce21',
                'member' => 'php',
                'member_sha256' => 'f0cf895506ad98d32e360fd8f29e8cc07bd751964297516d57835ae0b01f2d7a',
            ],
            $contract['authority']['interpreter_trust']['php']['pinned_archive_by_platform']['Linux-x86_64'] ?? null,
        );
        self::assertSame(
            '373ce1d4d9dd2ef439a60bdfff61203688d97025eeda4efae52849f1fba0a0d9',
            $contract['authority']['interpreter_trust']['php']['closure_sha256_by_platform']['Linux-x86_64'] ?? null,
        );
        self::assertSame(
            'ignore_ambient_and_disable_helpers',
            $contract['authority']['reviewer']['git_runtime_configuration'] ?? null,
        );
        self::assertSame('disabled', $contract['authority']['reviewer']['git_lazy_fetch'] ?? null);
        self::assertSame(
            'explicit_primary_codex_with_verified_private_copy',
            $contract['authority']['reviewer']['tool_path_policy'] ?? null,
        );
        self::assertSame(
            'canonical_physical_root',
            $contract['authority']['reviewer']['repository_root_policy'] ?? null,
        );
        self::assertSame(
            'official_release_binary_sha256_platform_version_and_dynamic_closure',
            $contract['authority']['reviewer']['codex_identity_check'] ?? null,
        );
        self::assertSame('0.145.0', $contract['authority']['reviewer']['codex_version'] ?? null);
        self::assertSame(
            'exact_machine_pinned_version_with_bounded_build_metadata',
            $contract['authority']['reviewer']['codex_version_policy'] ?? null,
        );
        self::assertSame(
            'private_copy_rehashed_and_closure_attested_before_first_execution',
            $contract['authority']['reviewer']['codex_binary_materialization_policy'] ?? null,
        );
        self::assertSame(
            'system_sealed_only_non_system_dependency_rejected',
            $contract['authority']['reviewer']['codex_dynamic_dependency_policy'] ?? null,
        );
        self::assertSame(
            [
                'Darwin-arm64' => 'cb24bcb9e973a8258c763e4b2777a398799c653996b395b3e2ab4cf1aa806a0a',
                'Darwin-x86_64' => 'a74149a742b113e72e0d59ab1f86786dd52bb2538cdbc42794b718155f06d90b',
            ],
            $contract['authority']['reviewer']['codex_closure_sha256_by_platform'] ?? null,
        );
        self::assertSame(
            'host_codex_login_without_connector_authority',
            $contract['authority']['reviewer']['codex_authentication_source'] ?? null,
        );
        self::assertSame(
            'isolated_runtime_home_read_only_link_to_canonical_auth_file',
            $contract['authority']['reviewer']['codex_authentication_home_policy'] ?? null,
        );
        self::assertSame(
            'reject_ambient_api_keys',
            $contract['authority']['reviewer']['codex_api_key_override_policy'] ?? null,
        );
        self::assertSame(
            'normalized_exact_diff_paths',
            $contract['authority']['reviewer']['finding_path_policy'] ?? null,
        );
        self::assertSame(
            'bounded_privacy_safe_prose',
            $contract['authority']['reviewer']['finding_text_policy'] ?? null,
        );
        self::assertSame('disabled', $contract['authority']['reviewer']['web_search'] ?? null);
        self::assertSame(
            'deterministic_exact_commit_bundle',
            $contract['authority']['reviewer']['review_checkout'] ?? null,
        );
        self::assertSame(
            'reject_all_tracked_symlinks',
            $contract['authority']['reviewer']['review_checkout_symlink_policy'] ?? null,
        );
        self::assertSame(
            'private_system_temp_random_directory',
            $contract['authority']['reviewer']['review_bundle_parent_policy'] ?? null,
        );
        self::assertSame(
            'zero_context_text_patch_manifest_changed_paths_and_trusted_policy',
            $contract['authority']['reviewer']['review_bundle_contents'] ?? null,
        );
        self::assertSame(
            'deterministic_sha256_base_head_binding',
            $contract['authority']['reviewer']['review_bundle_manifest_policy'] ?? null,
        );
        self::assertSame(
            'zero_context_changed_lines_only_no_full_base_or_head_blobs',
            $contract['authority']['reviewer']['review_bundle_context_policy'] ?? null,
        );
        self::assertSame(
            'strip_unchanged_section_text_before_model_input',
            $contract['authority']['reviewer']['review_bundle_hunk_header_policy'] ?? null,
        );
        self::assertSame(
            'reject_before_model_input',
            $contract['authority']['reviewer']['review_bundle_binary_policy'] ?? null,
        );
        self::assertSame(
            'manifest_patch_changed_paths_and_trusted_policy_only',
            $contract['authority']['reviewer']['review_bundle_file_allowlist'] ?? null,
        );
        self::assertArrayNotHasKey('review_bundle_added_text_deduplication', $contract['authority']['reviewer']);
        self::assertSame(
            'trusted_base_policy_as_developer_instructions_untrusted_bundle_as_user_input',
            $contract['authority']['reviewer']['review_instruction_policy'] ?? null,
        );
        self::assertSame(
            'pinned_cli_requires_developer_policy_and_user_bundle_channels',
            $contract['authority']['reviewer']['prompt_role_preflight'] ?? null,
        );
        self::assertSame(
            'bounded_deterministic_json_serialization_as_untrusted_user_stdin',
            $contract['authority']['reviewer']['review_input_policy'] ?? null,
        );
        self::assertSame(
            'not_model_visible',
            $contract['authority']['reviewer']['review_original_worktree_access'] ?? null,
        );
        self::assertSame(
            'darwin_seatbelt_fail_closed_elsewhere',
            $contract['authority']['reviewer']['isolation_platform'] ?? null,
        );
        self::assertSame(
            'default_deny_runtime_allowlist_exact_bundle_and_auth_read_only',
            $contract['authority']['reviewer']['isolation_profile'] ?? null,
        );
        self::assertSame(
            'bundle_readable_foreign_temp_and_original_worktree_denied_exact_auth_file_only_no_home_write',
            $contract['authority']['reviewer']['isolation_preflight'] ?? null,
        );
        self::assertSame(
            'derived_exact_release_catalog_without_shell_patch_image_search_or_external_tools',
            $contract['authority']['reviewer']['model_tool_surface'] ?? null,
        );
        self::assertSame(
            'scripts/agent/readonly-review-output.schema.json',
            $contract['authority']['reviewer']['output_schema_path'] ?? null,
        );
        self::assertTrue($contract['authority']['reviewer']['output_binds_base_sha'] ?? null);
        self::assertFalse($contract['authority']['reviewer']['allows_external_connectors'] ?? null);
        self::assertFalse($contract['authority']['reviewer']['allows_delegation'] ?? null);
        self::assertSame('review_base_commit', $contract['authority']['reviewer']['trust_anchor'] ?? null);
        self::assertSame('refs/remotes/origin/main', $contract['authority']['reviewer']['review_base_ref'] ?? null);
        self::assertSame(
            'https://github.com/robinbeier/forscherhaus-appointments.git',
            $contract['authority']['reviewer']['review_base_remote_url'] ?? null,
        );
        self::assertSame(
            'exact_merge_base_with_live_pinned_public_remote_main_and_matching_tracking_ref',
            $contract['authority']['reviewer']['review_base_policy'] ?? null,
        );
        self::assertSame(
            'unauthenticated_read_only_ls_remote_clean_environment',
            $contract['authority']['reviewer']['review_base_remote_transport'] ?? null,
        );
        $bootstrapPaths = $contract['authority']['reviewer']['bootstrap_paths'] ?? null;
        $policyContextPaths = $contract['authority']['reviewer']['policy_context_paths'] ?? null;
        self::assertIsArray($bootstrapPaths);
        self::assertIsArray($policyContextPaths);
        foreach (
            [
                '.codex/contracts/agent-workflow.json',
                'scripts/agent/trusted_base_launcher.sh',
                'scripts/agent/run_readonly_reviewer.sh',
                'scripts/agent/lib/trusted_base_payload_runtime.sh',
                'scripts/agent/verify_trusted_php_runtime.py',
                'scripts/agent/lib/trusted_runtime_primitives.py',
                'scripts/agent/lib/ReadonlyReviewerModelPolicy.php',
                'scripts/agent/lib/ReadonlyReviewOutput.php',
                'scripts/agent/lib/readonly_reviewer_isolated_runtime.sh',
            ]
            as $bootstrapPath
        ) {
            self::assertContains($bootstrapPath, $bootstrapPaths);
        }
        self::assertSame($bootstrapPaths, array_values(array_unique($bootstrapPaths)));
        self::assertSame(['AGENTS.md', 'code_review.md'], $policyContextPaths);
        self::assertArrayNotHasKey('trusted_base_paths', $contract['authority']['reviewer']);
        $profiles = $contract['authority']['reviewer']['profiles'] ?? null;
        self::assertIsArray($profiles);
        self::assertSame(
            ['correctness_security', 'design_maintainability', 'tests_regression_flake'],
            array_keys($profiles),
        );
        foreach ($profiles as $profile) {
            self::assertIsArray($profile);
            foreach (['instructions', 'model', 'reasoning'] as $field) {
                self::assertIsString($profile[$field] ?? null);
                self::assertNotSame('', $profile[$field]);
            }
        }
        $disabledFeatures = $contract['authority']['reviewer']['disabled_features'] ?? null;
        self::assertIsArray($disabledFeatures);
        self::assertSame($disabledFeatures, array_values(array_unique($disabledFeatures)));
        foreach (
            [
                'shell_tool',
                'unified_exec',
                'code_mode',
                'code_mode_only',
                'plugins',
                'apps',
                'browser_use',
                'image_generation',
                'multi_agent',
                'hooks',
            ]
            as $feature
        ) {
            self::assertContains($feature, $disabledFeatures, $feature);
        }
        self::assertSame(
            'bounded_deterministic_json_serialization_as_untrusted_user_stdin',
            $contract['authority']['reviewer']['review_input_policy'] ?? null,
        );
        self::assertSame(
            'private_system_temp_bundle_and_internal_runtime_only',
            $contract['authority']['reviewer']['temporary_directory_policy'] ?? null,
        );
        self::assertTrue($contract['parallel_work']['local_implementation_only'] ?? null);
        self::assertTrue($contract['parallel_work']['requires_common_base_sha'] ?? null);
        self::assertTrue($contract['parallel_work']['requires_disjoint_ownership'] ?? null);
        self::assertSame(2, $contract['parallel_work']['max_local_writer_lanes'] ?? null);
        self::assertSame('implementation_worker', $contract['parallel_work']['writer_role'] ?? null);
        self::assertTrue($contract['parallel_work']['external_mutations_remain_serial'] ?? null);
        self::assertTrue($contract['parallel_work']['requires_semantic_independence_attestation'] ?? null);
        self::assertSame(
            'scripts/agent/trusted_base_launcher.sh',
            $contract['parallel_work']['validator_invocation'] ?? null,
        );
        self::assertSame(
            'scripts/agent/check_parallel_work_contract.sh',
            $contract['parallel_work']['validator_payload_path'] ?? null,
        );
        self::assertSame(
            'external_system_git_materialized_declared_base_launcher_then_private_declared_base_payload',
            $contract['parallel_work']['validator_trust_anchor'] ?? null,
        );
        self::assertSame(
            'required_external_exact_blob_path_and_marker',
            $contract['parallel_work']['validator_launcher_materialization_guard'] ?? null,
        );
        $validatorBootstrapPaths = $contract['parallel_work']['validator_bootstrap_paths'] ?? null;
        self::assertIsArray($validatorBootstrapPaths);
        foreach (
            [
                '.codex/contracts/agent-workflow.json',
                'scripts/agent/check_parallel_work_contract.sh',
                'scripts/agent/lib/trusted_base_payload_runtime.sh',
                'scripts/agent/verify_trusted_php_runtime.py',
                'scripts/agent/lib/trusted_runtime_primitives.py',
            ]
            as $validatorBootstrapPath
        ) {
            self::assertContains($validatorBootstrapPath, $validatorBootstrapPaths);
        }
        self::assertSame($validatorBootstrapPaths, array_values(array_unique($validatorBootstrapPaths)));
        self::assertSame('refs/remotes/origin/main', $contract['parallel_work']['canonical_base_ref'] ?? null);
        self::assertSame(
            'https://github.com/robinbeier/forscherhaus-appointments.git',
            $contract['parallel_work']['canonical_base_remote_url'] ?? null,
        );
        self::assertSame(
            'live_pinned_public_remote_main_and_matching_tracking_ref',
            $contract['parallel_work']['canonical_base_policy'] ?? null,
        );
        self::assertSame(
            'unauthenticated_read_only_ls_remote_clean_environment',
            $contract['parallel_work']['canonical_base_remote_transport'] ?? null,
        );
        self::assertTrue($contract['parallel_work']['admission_requires_clean_exact_base_checkout'] ?? null);
        self::assertTrue($contract['parallel_work']['admission_executes_only_declared_base_blobs'] ?? null);
        self::assertTrue($contract['parallel_work']['admission_binds_base_before_source_execution'] ?? null);
        self::assertTrue($contract['parallel_work']['requires_post_implementation_verification'] ?? null);
        self::assertTrue($contract['parallel_work']['requires_clean_post_commit_verification'] ?? null);
        self::assertSame('provisional_pass', $contract['parallel_work']['dirty_precommit_status'] ?? null);
        self::assertSame('pass', $contract['parallel_work']['clean_integration_status'] ?? null);
        self::assertSame(
            'exact_base_attested_binary_and_dynamic_closure_ignore_ambient_ini',
            $contract['parallel_work']['php_runtime_configuration'] ?? null,
        );
        self::assertSame(
            'root_owned_system_git_before_clean_bash_and_attested_php',
            $contract['parallel_work']['shell_bootstrap'] ?? null,
        );
        self::assertSame(
            ['PATH', 'TMPDIR', 'LANG', 'LC_ALL'],
            $contract['parallel_work']['shell_environment_allowlist'] ?? null,
        );
        self::assertSame('docs/maps/component_ownership_map.json', $contract['parallel_work']['ownership_map'] ?? null);
        self::assertSame(3, $contract['parallel_work']['ownership_map_schema_version'] ?? null);
        self::assertSame(
            'explicit_path_and_match_objects',
            $contract['parallel_work']['ownership_rule_format'] ?? null,
        );
        self::assertSame(
            [
                '.codex/agents/reviewer-correctness.toml',
                '.codex/agents/reviewer-design.toml',
                '.codex/agents/reviewer-tests.toml',
                '.codex/agents/implementation-worker.toml',
                '.codex/config.toml',
                '.codex/contracts',
                '.codex/skills/land',
                '.codex/skills/push',
                '.github/workflows',
                'AGENTS.md',
                'WORKFLOW.md',
                'code_review.md',
                'docs/maps/component_ownership_map.json',
                'scripts/agent',
                'scripts/ci/ownership_path_rules.py',
                'scripts/ci/exact_head_mergegate.php',
                'scripts/ci/lib/ExactHeadMergegate.php',
            ],
            $contract['parallel_work']['primary_owned_path_prefixes'] ?? null,
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
