<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Forscherhaus\AgentHarness\ReadonlyReviewOutput;
use Forscherhaus\AgentHarness\ReadonlyReviewerContract;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/agent/lib/ReadonlyReviewerContract.php';

class ReviewerAuthorityContractTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repoRoot = dirname(__DIR__, 3);
    }

    public function testTrustedPhpRuntimeVerifierUnitSuiteRunsInIsolatedSystemPython(): void
    {
        $process = proc_open(
            [
                '/usr/bin/python3',
                '-I',
                '-B',
                $this->repoRoot . '/tests/Unit/Scripts/verify_trusted_php_runtime_test.py',
            ],
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $this->repoRoot,
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), $stdout . $stderr);
    }

    public function testReviewerPolicySnapshotMatchesTheSingleJsonAuthority(): void
    {
        $contract = json_decode(
            (string) file_get_contents($this->repoRoot . '/.codex/contracts/agent-workflow.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $snapshot = require $this->repoRoot . '/scripts/agent/lib/GeneratedReviewerPolicy.php';
        self::assertSame($contract['authority']['reviewer'] ?? null, $snapshot);
        [$status, , $stderr] = $this->runPolicyGenerator(['--check']);
        self::assertSame(0, $status, $stderr);
    }

    public function testReviewerPolicySnapshotGeneratorIsDeterministic(): void
    {
        [$firstStatus, $firstOutput, $firstError] = $this->runPolicyGenerator(['--stdout']);
        [$secondStatus, $secondOutput, $secondError] = $this->runPolicyGenerator(['--stdout']);

        self::assertSame(0, $firstStatus, $firstError);
        self::assertSame(0, $secondStatus, $secondError);
        self::assertSame($firstOutput, $secondOutput);
        self::assertSame(
            (string) file_get_contents($this->repoRoot . '/scripts/agent/lib/GeneratedReviewerPolicy.php'),
            $firstOutput,
        );
    }

    public function testReviewerPolicySnapshotCheckFailsClosedForAnIsolatedStaleSnapshot(): void
    {
        $fixtureRoot = sys_get_temp_dir() . '/reviewer-policy-fixture-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($fixtureRoot . '/scripts/agent', 0700, true));
        self::assertTrue(mkdir($fixtureRoot . '/.codex/contracts', 0700, true));

        $generator = $this->repoRoot . '/scripts/agent/generate_reviewer_policy_snapshot.php';
        $fixtureGenerator = $fixtureRoot . '/scripts/agent/generate_reviewer_policy_snapshot.php';
        self::assertTrue(copy($generator, $fixtureGenerator));
        self::assertTrue(
            file_put_contents(
                $fixtureRoot . '/.codex/contracts/agent-workflow.json',
                json_encode(['authority' => ['reviewer' => ['profiles' => []]]], JSON_THROW_ON_ERROR),
            ) !== false,
        );
        $snapshot = $fixtureRoot . '/scripts/agent/lib/GeneratedReviewerPolicy.php';
        self::assertTrue(mkdir(dirname($snapshot), 0700, true));
        $staleContent = "<?php\nreturn [];\n";
        self::assertSame(strlen($staleContent), file_put_contents($snapshot, $staleContent));

        try {
            $process = proc_open(
                [PHP_BINARY, $fixtureGenerator, '--check'],
                [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                $pipes,
                $fixtureRoot,
            );
            self::assertIsResource($process);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            self::assertSame(1, proc_close($process), $stdout . $stderr);
            self::assertSame('', $stdout);
            self::assertStringContainsString('Generated reviewer policy snapshot is stale.', $stderr);
            self::assertSame($staleContent, file_get_contents($snapshot));
        } finally {
            $this->removeFixtureTree($fixtureRoot);
        }
    }

    public function testReviewerPolicySnapshotRejectsTamperedPolicyBeforeInvocation(): void
    {
        $policy = $this->canonicalReviewerPolicy();
        $policy['profiles']['tests_regression_flake']['model'] = 'untrusted-model';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('generated exact-base snapshot');
        ReadonlyReviewerContract::trustedBasePaths($policy);
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string, string}
     */
    private function runPolicyGenerator(array $arguments): array
    {
        $command = array_merge(
            [PHP_BINARY, $this->repoRoot . '/scripts/agent/generate_reviewer_policy_snapshot.php'],
            $arguments,
        );
        $process = proc_open(
            $command,
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $this->repoRoot,
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $stdout, $stderr];
    }

    private function removeFixtureTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        self::assertIsArray($entries);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $entryPath = $path . '/' . $entry;
            if (is_dir($entryPath) && !is_link($entryPath)) {
                $this->removeFixtureTree($entryPath);
            } else {
                self::assertTrue(unlink($entryPath));
            }
        }
        self::assertTrue(rmdir($path));
    }

    public function testMachineContractDeniesEveryExternalReviewerMutation(): void
    {
        $contract = json_decode(
            (string) file_get_contents($this->repoRoot . '/.codex/contracts/agent-workflow.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($contract);

        self::assertSame(
            [
                'file_write',
                'commit',
                'push',
                'pr_mutation',
                'pr_comment',
                'pr_review',
                'check_rerun',
                'merge',
                'linear_mutation',
                'workpad_update',
            ],
            $contract['authority']['reviewer']['denied_mutations'] ?? null,
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
        self::assertSame('review_base_commit', $contract['authority']['reviewer']['trust_anchor'] ?? null);
        self::assertSame('refs/remotes/origin/main', $contract['authority']['reviewer']['review_base_ref'] ?? null);
        self::assertSame(
            'exact_merge_base_with_live_pinned_public_remote_main_and_matching_tracking_ref',
            $contract['authority']['reviewer']['review_base_policy'] ?? null,
        );
        self::assertSame(
            'https://github.com/robinbeier/forscherhaus-appointments.git',
            $contract['authority']['reviewer']['review_base_remote_url'] ?? null,
        );
        self::assertSame(
            'external_system_git_materialized_exact_base_launcher_then_private_exact_base_payload',
            $contract['authority']['reviewer']['invocation_source'] ?? null,
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
            'absolute_system_git_clean_environment_private_blob_verification_before_any_repository_code_execution',
            $contract['authority']['reviewer']['bootstrap_materialization_policy'] ?? null,
        );
        self::assertSame(
            'required_external_exact_blob_path_and_marker',
            $contract['authority']['reviewer']['launcher_materialization_guard'] ?? null,
        );
        self::assertTrue($contract['authority']['reviewer']['requires_base_runner'] ?? false);
        self::assertSame(
            'forbidden_fail_closed',
            $contract['authority']['reviewer']['direct_checkout_execution'] ?? null,
        );
        self::assertSame(
            'external_bootstrap_review',
            $contract['authority']['reviewer']['runtime_configuration_change_policy'] ?? null,
        );
        self::assertSame(
            'root_owned_system_git_then_clean_bash_environment',
            $contract['authority']['reviewer']['shell_runtime_configuration'] ?? null,
        );
        self::assertSame(
            'fixed_direct_no_ambient_proxy_or_endpoint_override',
            $contract['authority']['reviewer']['transport_environment_policy'] ?? null,
        );
        self::assertSame(
            'private_system_temp_bundle_and_internal_runtime_only',
            $contract['authority']['reviewer']['temporary_directory_policy'] ?? null,
        );
        self::assertSame(
            'exact_base_attested_binary_and_dynamic_closure_ignore_ambient_ini',
            $contract['authority']['reviewer']['php_runtime_configuration'] ?? null,
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
                'Darwin-arm64' => '1da3f4e0e96028b8a771814293c3033dafd1971f943f6c7e79b0897fe705f590',
                'Darwin-x86_64' => '6db9193ce2c9a8cef2b5482612cde24202a4329dfc34f4687a036d5d7da619af',
            ],
            $contract['authority']['reviewer']['codex_binary_sha256_by_platform'] ?? null,
        );
        self::assertSame(
            [
                'Darwin-arm64' => 'cb24bcb9e973a8258c763e4b2777a398799c653996b395b3e2ab4cf1aa806a0a',
                'Darwin-x86_64' => 'a74149a742b113e72e0d59ab1f86786dd52bb2538cdbc42794b718155f06d90b',
            ],
            $contract['authority']['reviewer']['codex_closure_sha256_by_platform'] ?? null,
        );
        self::assertSame(
            'isolated_runtime_home_read_only_link_to_canonical_auth_file',
            $contract['authority']['reviewer']['codex_authentication_home_policy'] ?? null,
        );
        self::assertSame(
            'normalized_exact_diff_paths',
            $contract['authority']['reviewer']['finding_path_policy'] ?? null,
        );
        self::assertSame('disabled', $contract['authority']['reviewer']['web_search'] ?? null);
        self::assertSame(
            'deterministic_exact_commit_bundle',
            $contract['authority']['reviewer']['review_checkout'] ?? null,
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
            'deterministic_sha256_base_head_binding',
            $contract['authority']['reviewer']['review_bundle_manifest_policy'] ?? null,
        );
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
        self::assertSame('read-only', $contract['authority']['reviewer']['codex_sandbox_mode'] ?? null);
        self::assertSame('never', $contract['authority']['reviewer']['codex_approval_policy'] ?? null);
        self::assertSame(
            'outer_seatbelt_default_deny_exact_bundle_read_only_runtime_scratch_only',
            $contract['authority']['reviewer']['filesystem'] ?? null,
        );
        self::assertSame(
            'outer_seatbelt_plus_codex_read_only_no_model_tools',
            $contract['authority']['reviewer']['approval_policy'] ?? null,
        );
        self::assertSame(
            'scripts/agent/readonly-review-output.schema.json',
            $contract['authority']['reviewer']['output_schema_path'] ?? null,
        );
        $trustedBasePaths = ReadonlyReviewerContract::trustedBasePaths($contract['authority']['reviewer']);
        foreach (
            [
                '.codex/contracts/agent-workflow.json',
                'scripts/agent/trusted_base_launcher.sh',
                'scripts/agent/run_readonly_reviewer.sh',
                'scripts/agent/lib/trusted_base_payload_runtime.sh',
                'scripts/agent/readonly-review-output.schema.json',
                'scripts/agent/lib/trusted_runtime_primitives.py',
                'scripts/agent/lib/ReadonlyReviewerModelPolicy.php',
                'scripts/agent/lib/ReadonlyReviewOutput.php',
                'scripts/agent/lib/readonly_reviewer_isolated_runtime.sh',
            ]
            as $trustedBasePath
        ) {
            self::assertContains($trustedBasePath, $trustedBasePaths);
        }
        self::assertSame($trustedBasePaths, array_values(array_unique($trustedBasePaths)));
        self::assertArrayNotHasKey('trusted_base_paths', $contract['authority']['reviewer']);
        $runner = (string) file_get_contents($this->repoRoot . '/scripts/agent/run_readonly_reviewer.sh');
        $sharedRuntime = (string) file_get_contents(
            $this->repoRoot . '/scripts/agent/lib/trusted_base_payload_runtime.sh',
        );
        $bundleRuntime = (string) file_get_contents(
            $this->repoRoot . '/scripts/agent/lib/readonly_reviewer_bundle_runtime.sh',
        );
        $isolatedRuntime = (string) file_get_contents(
            $this->repoRoot . '/scripts/agent/lib/readonly_reviewer_isolated_runtime.sh',
        );
        self::assertStringContainsString(' trusted-paths --lens=', $runner);
        self::assertStringContainsString('output_schema_path', $bundleRuntime);
        self::assertStringContainsString('--output-schema "$control_root/$output_schema_path"', $isolatedRuntime);
        self::assertStringContainsString('GIT_NO_LAZY_FETCH=1', $sharedRuntime);
        self::assertFalse($contract['authority']['reviewer']['inherits_execpolicy_rules'] ?? true);
        self::assertTrue($contract['authority']['reviewer']['output_binds_base_sha'] ?? false);
        $disabledFeatures = $contract['authority']['reviewer']['disabled_features'] ?? null;
        self::assertIsArray($disabledFeatures);
        self::assertSame($disabledFeatures, array_values(array_unique($disabledFeatures)));
        foreach (['shell_tool', 'unified_exec', 'code_mode', 'plugins', 'apps', 'multi_agent', 'hooks'] as $feature) {
            self::assertContains($feature, $disabledFeatures, $feature . ' must remain disabled');
        }
    }

    public function testAllFinalReviewerRolesCarryTheSamePrimaryOnlyBoundary(): void
    {
        foreach (['reviewer-correctness.toml', 'reviewer-design.toml', 'reviewer-tests.toml'] as $filename) {
            $role = (string) file_get_contents($this->repoRoot . '/.codex/agents/' . $filename);
            self::assertStringContainsString('.codex/contracts/agent-workflow.json', $role, $filename);
            self::assertStringContainsString('scripts/agent/trusted_base_launcher.sh', $role, $filename);
            self::assertStringContainsString('Do not delegate or mutate files, Git, GitHub', $role, $filename);
            self::assertStringContainsString('Return findings only to the primary', $role, $filename);
        }
    }

    public function testSeatbeltAndToolFreeInvariantsAreEncodedInRunner(): void
    {
        $runner = file_get_contents($this->repoRoot . '/scripts/agent/run_readonly_reviewer.sh');
        $launcher = file_get_contents($this->repoRoot . '/scripts/agent/trusted_base_launcher.sh');
        $sharedRuntime = file_get_contents($this->repoRoot . '/scripts/agent/lib/trusted_base_payload_runtime.sh');
        $bundleRuntime = file_get_contents($this->repoRoot . '/scripts/agent/lib/readonly_reviewer_bundle_runtime.sh');
        $isolatedRuntime = file_get_contents(
            $this->repoRoot . '/scripts/agent/lib/readonly_reviewer_isolated_runtime.sh',
        );
        $seatbelt = file_get_contents($this->repoRoot . '/scripts/agent/readonly-reviewer.sb');
        self::assertIsString($runner);
        self::assertIsString($launcher);
        self::assertIsString($sharedRuntime);
        self::assertIsString($bundleRuntime);
        self::assertIsString($isolatedRuntime);
        self::assertIsString($seatbelt);

        self::assertStringStartsWith("#!/bin/bash\n", $launcher);
        self::assertStringContainsString('/usr/bin/git', $launcher);
        self::assertStringContainsString('hash-object --no-filters', $launcher);
        self::assertStringContainsString('TRUSTED_BASE_LAUNCHER=1', $launcher);
        self::assertStringContainsString('trusted_base_bootstrap', $launcher);
        self::assertStringNotContainsString(
            'shared_runtime_path="scripts/agent/lib/trusted_base_payload_runtime.sh"',
            $launcher,
        );
        self::assertStringNotContainsString('combined-payload.sh', $launcher);
        self::assertStringContainsString(
            '/bin/bash --noprofile --norc "$materialized_runtime" "$materialized_payload"',
            $launcher,
        );
        self::assertStringContainsString('trusted_base_payload_initialize', $sharedRuntime);
        self::assertStringContainsString('trusted_base_assert_materialized_blob', $sharedRuntime);
        self::assertStringContainsString('trusted_base_assert_bootstrap_manifest', $sharedRuntime);
        self::assertStringContainsString('trusted_base_dispatch_payload', $sharedRuntime);
        self::assertStringContainsString('source "$payload_source" "$payload_source" "$@"', $sharedRuntime);
        self::assertStringStartsWith("#!/bin/bash\n", $runner);
        self::assertStringContainsString('must be launched from the exact-base trusted launcher', $runner);
        self::assertStringContainsString('trusted_base_payload_initialize', $runner);
        self::assertStringContainsString('env -i', $sharedRuntime);
        self::assertStringContainsString('/usr/bin/python3', $sharedRuntime);
        self::assertStringContainsString('-I -B', $sharedRuntime);
        self::assertStringContainsString('scripts/agent/verify_trusted_php_runtime.py', $runner);
        self::assertStringNotContainsString('command -v php', $runner);
        self::assertStringNotContainsString('#!/usr/bin/env -S', $runner);
        self::assertStringContainsString('GIT_NO_REPLACE_OBJECTS=1', $sharedRuntime);
        self::assertStringContainsString('trusted_base_remote_git()', $sharedRuntime);
        $remoteGitStart = strpos($sharedRuntime, 'trusted_base_remote_git() {');
        self::assertNotFalse($remoteGitStart);
        $remoteGitEnd = strpos($sharedRuntime, "\n}\n\ntrusted_base_assert_materialized_blob", $remoteGitStart);
        self::assertNotFalse($remoteGitEnd);
        $remoteGit = substr($sharedRuntime, $remoteGitStart, $remoteGitEnd - $remoteGitStart);
        self::assertStringContainsString('GIT_CONFIG_SYSTEM=/dev/null', $remoteGit);
        self::assertStringContainsString('-c http.proxy=', $remoteGit);
        self::assertStringContainsString('-c https.proxy=', $remoteGit);
        self::assertStringContainsString(
            "canonical_main_remote='https://github.com/robinbeier/forscherhaus-appointments.git'",
            $runner,
        );
        self::assertStringContainsString('materialized_codex="$control_root/codex"', $runner);
        self::assertStringContainsString('validate-codex-copy', $runner);
        self::assertStringContainsString('--runtime=codex', $runner);
        self::assertStringContainsString('--expected-closure-sha256="$expected_codex_closure_sha256"', $runner);
        self::assertTrue(
            strpos($runner, '--runtime=codex') < strpos($runner, 'codex_version="$("$codex_bin" --version'),
            'The Codex dependency closure must be attested before the first Codex execution.',
        );
        self::assertStringContainsString('readonly_reviewer_materialize_bundle', $runner);
        self::assertStringContainsString('readonly_reviewer_execute_isolated', $runner);
        self::assertStringNotContainsString(
            '--dangerously-bypass-approvals-and-sandbox',
            $runner . $bundleRuntime . $isolatedRuntime,
        );
        self::assertStringContainsString('readonly_review_bundle.php', $bundleRuntime);
        self::assertStringContainsString('assert-text-diff', $bundleRuntime);
        self::assertStringContainsString('--unified=0', $bundleRuntime);
        self::assertStringContainsString('sanitize-patch', $bundleRuntime);
        self::assertStringNotContainsString('readonly_reviewer_materialize_changed_blob', $bundleRuntime);
        self::assertStringNotContainsString('cat-file blob', $bundleRuntime);
        self::assertStringNotContainsString('$review_root/base/', $bundleRuntime);
        self::assertStringNotContainsString('$review_root/head/', $bundleRuntime);
        self::assertStringNotContainsString('sandbox_exec', $bundleRuntime);
        self::assertStringNotContainsString('--ask-for-approval', $bundleRuntime);
        self::assertStringContainsString('-f "$seatbelt_profile"', $isolatedRuntime);
        self::assertStringNotContainsString('trusted_git diff --binary', $isolatedRuntime);
        self::assertStringContainsString('Reviewer Seatbelt profile did not deny foreign temp data.', $isolatedRuntime);
        self::assertStringNotContainsString('mktemp "$reviewer_os_home/', $isolatedRuntime);
        self::assertStringNotContainsString('.forscherhaus-readonly-review-denied.', $isolatedRuntime);
        self::assertStringContainsString(
            'Reviewer Seatbelt profile did not deny the original worktree.',
            $isolatedRuntime,
        );
        self::assertStringContainsString('--ignore-user-config', $isolatedRuntime);
        self::assertStringContainsString('--ask-for-approval "$codex_approval_policy"', $isolatedRuntime);
        self::assertStringContainsString('--sandbox "$codex_sandbox_mode" exec', $isolatedRuntime);
        self::assertStringContainsString('mcp_servers={}', $isolatedRuntime);
        self::assertStringContainsString('agents.max_depth=0', $isolatedRuntime);
        self::assertStringContainsString('-c "developer_instructions=$developer_instructions_toml"', $isolatedRuntime);
        self::assertStringContainsString('- < "$review_input"', $isolatedRuntime);
        self::assertStringContainsString('validate-prompt-roles', $isolatedRuntime);
        self::assertStringNotContainsString('review-prompt.txt', $runner . $bundleRuntime . $isolatedRuntime);

        $sealedRootPosition = strpos($isolatedRuntime, 'cd "$sealed_root"');
        $modelCatalogPosition = strpos($isolatedRuntime, 'model_catalog="$control_root/models.json"');
        $promptRoleProbePosition = strpos($isolatedRuntime, "prompt_role_probe='UNTRUSTED-REVIEW-BUNDLE-PROBE'");
        self::assertNotFalse($sealedRootPosition);
        self::assertNotFalse($modelCatalogPosition);
        self::assertNotFalse($promptRoleProbePosition);
        self::assertTrue($sealedRootPosition < $modelCatalogPosition);
        self::assertTrue($sealedRootPosition < $promptRoleProbePosition);

        self::assertStringStartsWith("(version 1)\n(deny default)\n", $seatbelt);
        self::assertStringContainsString(
            '(allow file-read* file-test-existence (subpath (param "SEALED_ROOT")))',
            $seatbelt,
        );
        self::assertStringContainsString(
            '(allow file-write* (subpath (param "ARG0_ROOT")) (subpath (param "RUNTIME_TMP"))',
            $seatbelt,
        );
        self::assertStringContainsString('(literal (param "AUTH_FILE"))', $seatbelt);
        self::assertStringNotContainsString('(subpath (param "AUTH_FILE"))', $seatbelt);
        self::assertStringNotContainsString('/opt/homebrew/lib', $seatbelt);

        self::assertStringNotContainsString('--permission-profile', $runner);
        self::assertStringNotContainsString('default_permissions=', $runner);
        self::assertStringNotContainsString('sandbox_workspace_write', $runner);
    }

    public function testCheckedOutHarnessEntrypointsAreDataOnlyAndFailClosed(): void
    {
        $paths = [
            'scripts/agent/trusted_base_launcher.sh' => 'must be externally materialized from the verified base',
            'scripts/agent/run_readonly_reviewer.sh' => 'must be launched from the exact-base trusted launcher',
            'scripts/agent/check_parallel_work_contract.sh' => 'must be launched from the exact-base trusted launcher',
            'scripts/agent/lib/trusted_base_payload_runtime.sh' => 'must be assembled by the exact-base launcher',
        ];

        foreach ($paths as $path => $expectedError) {
            $absolutePath = $this->repoRoot . '/' . $path;
            self::assertSame(0, fileperms($absolutePath) & 0o111, $path);
            $process = proc_open(
                ['/bin/bash', '--noprofile', '--norc', $absolutePath],
                [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                $pipes,
                $this->repoRoot,
                [],
            );
            self::assertIsResource($process);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            self::assertSame(2, proc_close($process), $path);
            self::assertSame('', $stdout, $path);
            self::assertStringContainsString($expectedError, $stderr, $path);
        }
    }

    public function testSystemBootstrapDropsAmbientPhpAndShellStartupConfigurationBeforePlatformGate(): void
    {
        $fixture = $this->runnerFixture('reviewer-clean-bootstrap', "#!/bin/sh\nexit 0\n", 'reviewer-bin');
        $temporaryDirectory = sys_get_temp_dir() . '/reviewer-bootstrap-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($temporaryDirectory, 0700));
        $bashMarker = $temporaryDirectory . '/bash-startup-ran';
        $phpMarker = $temporaryDirectory . '/php-startup-ran';
        $bashEnvironment = $temporaryDirectory . '/bash-environment';
        $autoPrepend = $temporaryDirectory . '/auto-prepend.php';
        $phpIni = $temporaryDirectory . '/php.ini';

        self::assertNotFalse(file_put_contents($bashEnvironment, ': > ' . escapeshellarg($bashMarker) . "\n"));
        self::assertNotFalse(
            file_put_contents($autoPrepend, '<?php file_put_contents(' . var_export($phpMarker, true) . ", 'ran');\n"),
        );
        self::assertNotFalse(file_put_contents($phpIni, 'auto_prepend_file=' . $autoPrepend . "\n"));

        $environment = $_ENV;
        $environment['BASH_ENV'] = $bashEnvironment;
        $environment['PHPRC'] = $phpIni;
        $environment['PHP_INI_SCAN_DIR'] = $temporaryDirectory;
        $environment['HOME'] = $temporaryDirectory . '/caller-home';
        $environment['CODEX_HOME'] = $temporaryDirectory . '/caller-codex-home';

        try {
            [$exitCode, $stdout, $stderr] = $this->runRunnerFixture($fixture, environment: $environment);

            self::assertSame(2, $exitCode);
            self::assertSame('', $stdout);
            self::assertStringContainsString('does not identify as Codex CLI', $stderr);
            self::assertFileDoesNotExist($bashMarker);
            self::assertFileDoesNotExist($phpMarker);
        } finally {
            $this->removeRunnerFixture($fixture);
            foreach ([$bashMarker, $phpMarker, $bashEnvironment, $autoPrepend, $phpIni] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($temporaryDirectory);
        }
    }

    public function testReviewerBootstrapNeverExecutesPhpResolvedFromAmbientPathBeforePlatformGate(): void
    {
        $fixture = $this->runnerFixture('reviewer-ambient-php', "#!/bin/sh\nexit 0\n", 'reviewer-bin');
        $temporaryDirectory = sys_get_temp_dir() . '/reviewer-path-bootstrap-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($temporaryDirectory, 0700));
        $marker = $temporaryDirectory . '/ambient-php-ran';
        $ambientPhp = $temporaryDirectory . '/php';
        self::assertNotFalse(
            file_put_contents($ambientPhp, "#!/bin/sh\n: > " . escapeshellarg($marker) . "\nexit 99\n"),
        );
        self::assertTrue(chmod($ambientPhp, 0700));

        try {
            [$exitCode, , $stderr] = $this->runRunnerFixture(
                $fixture,
                environment: array_merge($_ENV, ['PATH' => $temporaryDirectory]),
            );

            self::assertNotSame(0, $exitCode);
            self::assertFileDoesNotExist($marker, $stderr);
        } finally {
            $this->removeRunnerFixture($fixture);
            if (is_file($marker)) {
                unlink($marker);
            }
            unlink($ambientPhp);
            rmdir($temporaryDirectory);
        }
    }

    public function testRunnerRejectsAnExecutableWhoseBasenameIsNotCodexBeforePlatformGate(): void
    {
        // This fixture is intentionally invalid before the Darwin-only runtime gate.
        $fixture = $this->runnerFixture('reviewer-not-codex', "#!/bin/sh\nexit 0\n", 'reviewer-bin');

        try {
            [$exitCode, $stdout, $stderr] = $this->runRunnerFixture($fixture);

            self::assertNotSame(0, $exitCode);
            self::assertSame('', $stdout);
            self::assertStringContainsString('does not identify as Codex CLI', $stderr);
        } finally {
            $this->removeRunnerFixture($fixture);
        }
    }

    public function testDiagnosticRunsTheExactLauncherAndSeatbeltWithoutModelExecution(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin' || !is_executable('/usr/bin/sandbox-exec')) {
            self::markTestSkipped('The model-free reviewer isolation diagnostic requires macOS Seatbelt.');
        }

        $executionMarker = sys_get_temp_dir() . '/reviewer-diagnostic-model-' . bin2hex(random_bytes(8));
        $fixture = $this->runnerFixture(
            'reviewer-diagnostic',
            "#!/bin/sh\n: > " . escapeshellarg($executionMarker) . "\nexit 99\n",
            'codex',
            completeBootstrap: true,
        );

        try {
            [$exitCode, $stdout, $stderr] = $this->runRunnerFixture($fixture, diagnostic: true);

            self::assertSame(0, $exitCode, $stderr);
            self::assertSame('', $stderr);
            self::assertFileDoesNotExist($executionMarker);
            $result = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(1, $result['schema_version'] ?? null);
            self::assertSame('diagnostic_pass', $result['status'] ?? null);
            self::assertSame($fixture['base'], $result['base_sha'] ?? null);
            self::assertSame($fixture['base'], $result['head_sha'] ?? null);
            self::assertFalse($result['review_evidence'] ?? true);
        } finally {
            $this->removeRunnerFixture($fixture);
            @unlink($executionMarker);
        }
    }

    public function testRuntimeValidatorRejectsUnpinnedCodexNamedExecutable(): void
    {
        $binary = sys_get_temp_dir() . '/codex-' . bin2hex(random_bytes(8));
        self::assertNotFalse(file_put_contents($binary, "#!/bin/sh\necho 'codex-cli 0.145.0'\n"));
        self::assertTrue(chmod($binary, 0500));
        $canonicalBinary = realpath($binary);
        self::assertIsString($canonicalBinary);

        try {
            try {
                ReadonlyReviewerContract::assertMaterializedCodex(
                    $canonicalBinary,
                    (int) fileowner($binary),
                    str_repeat('a', 64),
                );
                self::fail('An unpinned Codex-named executable was accepted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString(
                    'does not match the pinned official release',
                    $exception->getMessage(),
                );
            }
        } finally {
            unlink($binary);
        }
    }

    public function testRuntimeValidatorsRejectSymlinkedCodexFiles(): void
    {
        $directory = sys_get_temp_dir() . '/reviewer-codex-symlink-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $target = $directory . '/codex-target';
        $link = $directory . '/codex';
        self::assertNotFalse(file_put_contents($target, "#!/bin/sh\nexit 0\n"));
        self::assertTrue(chmod($target, 0500));
        self::assertTrue(symlink($target, $link));

        try {
            foreach (['source', 'materialized'] as $validator) {
                try {
                    if ($validator === 'source') {
                        ReadonlyReviewerContract::assertCodexSource($link, (int) fileowner($target));
                    } else {
                        ReadonlyReviewerContract::assertMaterializedCodex(
                            $link,
                            (int) fileowner($target),
                            (string) hash_file('sha256', $target),
                        );
                    }
                    self::fail('A symlinked Codex file was accepted by the ' . $validator . ' validator.');
                } catch (\InvalidArgumentException $exception) {
                    self::assertStringContainsString('Codex binary target is invalid', $exception->getMessage());
                }
            }
        } finally {
            if (is_link($link)) {
                unlink($link);
            }
            if (is_file($target)) {
                unlink($target);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testRunnerRejectsATrackedSymlinkBeforeAnyModelExecution(): void
    {
        $executionMarker = sys_get_temp_dir() . '/reviewer-symlink-executed-' . bin2hex(random_bytes(8));
        $fixture = $this->runnerFixture(
            'reviewer-tracked-symlink',
            "#!/bin/sh\n: > " . escapeshellarg($executionMarker) . "\nexit 0\n",
            'codex',
        );

        try {
            self::assertNotFalse(file_put_contents($fixture['root'] . '/tracked-target.txt', "target\n"));
            self::assertTrue(symlink('tracked-target.txt', $fixture['root'] . '/tracked-link.txt'));
            $this->runGitCommand(['add', '--all'], $fixture['root']);
            $this->commitRunnerFixture($fixture['root'], 'Add tracked symlink fixture');
            $head = $this->runGitCommand(['rev-parse', 'HEAD'], $fixture['root']);

            // The tree-integrity check is deliberately before the Darwin-only Seatbelt gate,
            // so this remains a deterministic pre-platform rejection on every host OS.
            [$exitCode, $stdout, $stderr] = $this->runRunnerFixture($fixture, $fixture['base'], $head);

            self::assertSame(1, $exitCode);
            self::assertSame('', $stdout);
            self::assertSame(
                "Reviewer exact commit tree contains a tracked symlink, gitlink, or invalid entry.\n",
                $stderr,
            );
            self::assertFileDoesNotExist($executionMarker);
        } finally {
            $this->removeRunnerFixture($fixture);
            if (is_file($executionMarker)) {
                unlink($executionMarker);
            }
        }
    }

    public function testRunnerRejectsATrackedGitlinkBeforeAnyModelExecution(): void
    {
        $executionMarker = sys_get_temp_dir() . '/reviewer-gitlink-executed-' . bin2hex(random_bytes(8));
        $fixture = $this->runnerFixture(
            'reviewer-tracked-gitlink',
            "#!/bin/sh\n: > " . escapeshellarg($executionMarker) . "\nexit 0\n",
            'codex',
        );

        try {
            $submoduleRoot = $fixture['root'] . '/tracked-submodule';
            $this->runGitCommand(['clone', '-q', '--no-hardlinks', $fixture['root'], $submoduleRoot], null);
            $this->runGitCommand(['add', 'tracked-submodule'], $fixture['root']);
            $this->commitRunnerFixture($fixture['root'], 'Add tracked gitlink fixture');
            $head = $this->runGitCommand(['rev-parse', 'HEAD'], $fixture['root']);

            [$exitCode, $stdout, $stderr] = $this->runRunnerFixture($fixture, $fixture['base'], $head);

            self::assertSame(1, $exitCode);
            self::assertSame('', $stdout);
            self::assertSame(
                "Reviewer exact commit tree contains a tracked symlink, gitlink, or invalid entry.\n",
                $stderr,
            );
            self::assertFileDoesNotExist($executionMarker);
        } finally {
            $this->removeRunnerFixture($fixture);
            @unlink($executionMarker);
        }
    }

    public function testRunnerRequiresExternalBootstrapReviewForTrustedReviewerPolicyChanges(): void
    {
        $executionMarker = sys_get_temp_dir() . '/reviewer-policy-executed-' . bin2hex(random_bytes(8));
        $fixture = $this->runnerFixture(
            'reviewer-policy-drift',
            "#!/bin/sh\n: > " . escapeshellarg($executionMarker) . "\nexit 0\n",
            'codex',
        );

        try {
            $role = $fixture['root'] . '/.codex/agents/reviewer-correctness.toml';
            self::assertTrue(mkdir(dirname($role), 0700, true));
            self::assertNotFalse(file_put_contents($role, "developer_instructions = \"weakened\"\n"));
            $this->runGitCommand(['add', '--all'], $fixture['root']);
            $this->commitRunnerFixture($fixture['root'], 'Change trusted reviewer policy');
            $head = $this->runGitCommand(['rev-parse', 'HEAD'], $fixture['root']);

            [$exitCode, $stdout, $stderr] = $this->runRunnerFixture($fixture, $fixture['base'], $head);

            self::assertSame(1, $exitCode);
            self::assertSame('', $stdout);
            self::assertSame(
                "Reviewer runtime configuration changed; external bootstrap review is required.\n",
                $stderr,
            );
            self::assertFileDoesNotExist($executionMarker);
        } finally {
            $this->removeRunnerFixture($fixture);
            @unlink($executionMarker);
        }
    }

    public function testRunnerRequiresExternalBootstrapReviewForWorkflowPolicyChanges(): void
    {
        $executionMarker = sys_get_temp_dir() . '/reviewer-workflow-executed-' . bin2hex(random_bytes(8));
        $fixture = $this->runnerFixture(
            'reviewer-workflow-drift',
            "#!/bin/sh\n: > " . escapeshellarg($executionMarker) . "\nexit 0\n",
            'codex',
        );

        try {
            self::assertNotFalse(file_put_contents($fixture['root'] . '/WORKFLOW.md', "weakened review policy\n"));
            $this->runGitCommand(['add', '--all'], $fixture['root']);
            $this->commitRunnerFixture($fixture['root'], 'Change workflow review policy');
            $head = $this->runGitCommand(['rev-parse', 'HEAD'], $fixture['root']);

            [$exitCode, $stdout, $stderr] = $this->runRunnerFixture($fixture, $fixture['base'], $head);

            self::assertSame(1, $exitCode);
            self::assertSame('', $stdout);
            self::assertSame(
                "Reviewer runtime configuration changed; external bootstrap review is required.\n",
                $stderr,
            );
            self::assertFileDoesNotExist($executionMarker);
        } finally {
            $this->removeRunnerFixture($fixture);
            @unlink($executionMarker);
        }
    }

    public function testExactBaseLauncherNeverExecutesTamperedCheckoutEntrypoints(): void
    {
        $fixture = $this->runnerFixture('reviewer-tampered-entrypoints', "#!/bin/sh\nexit 0\n", 'codex');
        $launcherMarker = sys_get_temp_dir() . '/reviewer-launcher-canary-' . bin2hex(random_bytes(8));
        $payloadMarker = sys_get_temp_dir() . '/reviewer-payload-canary-' . bin2hex(random_bytes(8));

        try {
            self::assertNotFalse(
                file_put_contents(
                    $fixture['root'] . '/scripts/agent/trusted_base_launcher.sh',
                    "#!/bin/bash\n: > " . escapeshellarg($launcherMarker) . "\nexit 99\n",
                ),
            );
            self::assertNotFalse(
                file_put_contents(
                    $fixture['root'] . '/scripts/agent/run_readonly_reviewer.sh',
                    "#!/bin/bash\n: > " . escapeshellarg($payloadMarker) . "\nexit 99\n",
                ),
            );
            $this->runGitCommand(['add', '--all'], $fixture['root']);
            $this->commitRunnerFixture($fixture['root'], 'Tamper checked-out reviewer entrypoints');
            $head = $this->runGitCommand(['rev-parse', 'HEAD'], $fixture['root']);

            [$exitCode, , $stderr] = $this->runRunnerFixture($fixture, $fixture['base'], $head);

            self::assertNotSame(0, $exitCode, $stderr);
            self::assertFileDoesNotExist($launcherMarker);
            self::assertFileDoesNotExist($payloadMarker);
        } finally {
            $this->removeRunnerFixture($fixture);
            @unlink($launcherMarker);
            @unlink($payloadMarker);
        }
    }

    public function testExactBaseLauncherRejectsAnInvalidBootstrapManifestBeforePayloadExecution(): void
    {
        $executionMarker = sys_get_temp_dir() . '/reviewer-invalid-bootstrap-manifest-' . bin2hex(random_bytes(8));
        $fixture = $this->runnerFixture(
            'reviewer-invalid-bootstrap-manifest',
            "#!/bin/sh\n: > " . escapeshellarg($executionMarker) . "\nexit 0\n",
            'codex',
            false,
            static function (array $contract): array {
                $contract['trusted_base_bootstrap']['payloads']['parallel']['mode'] = '0700';
                return $contract;
            },
        );

        try {
            [$exitCode, $stdout, $stderr] = $this->runRunnerFixture($fixture);

            self::assertSame(2, $exitCode);
            self::assertSame('', $stdout);
            self::assertSame("Trusted-base launcher bootstrap manifest is invalid.\n", $stderr);
            self::assertFileDoesNotExist($executionMarker);
        } finally {
            $this->removeRunnerFixture($fixture);
            @unlink($executionMarker);
        }
    }

    public function testRunnerRejectsCallerSelectedNarrowAncestorBase(): void
    {
        $fixture = $this->runnerFixture('reviewer-wrong-base', "#!/bin/sh\nexit 0\n", 'codex');

        try {
            self::assertNotFalse(file_put_contents($fixture['root'] . '/intermediate.txt', "one\n"));
            $this->runGitCommand(['add', '--all'], $fixture['root']);
            $this->commitRunnerFixture($fixture['root'], 'Add intermediate change');
            $narrowBase = $this->runGitCommand(['rev-parse', 'HEAD'], $fixture['root']);
            self::assertNotFalse(file_put_contents($fixture['root'] . '/head.txt', "two\n"));
            $this->runGitCommand(['add', '--all'], $fixture['root']);
            $this->commitRunnerFixture($fixture['root'], 'Add head change');
            $head = $this->runGitCommand(['rev-parse', 'HEAD'], $fixture['root']);

            [$exitCode, $stdout, $stderr] = $this->runRunnerFixture($fixture, $narrowBase, $head);

            self::assertSame(1, $exitCode);
            self::assertSame('', $stdout);
            self::assertStringContainsString('does not match the canonical origin/main merge base', $stderr);
        } finally {
            $this->removeRunnerFixture($fixture);
        }
    }

    public function testRunnerRejectsARewrittenLocalOriginMainTrackingRef(): void
    {
        $fixture = $this->runnerFixture('reviewer-rewritten-origin-main', "#!/bin/sh\nexit 0\n", 'codex');

        try {
            self::assertNotFalse(file_put_contents($fixture['root'] . '/head.txt', "head\n"));
            $this->runGitCommand(['add', '--all'], $fixture['root']);
            $this->commitRunnerFixture($fixture['root'], 'Add untrusted head');
            $head = $this->runGitCommand(['rev-parse', 'HEAD'], $fixture['root']);
            $this->runGitCommand(['update-ref', 'refs/remotes/origin/main', $head], $fixture['root']);

            [$exitCode, $stdout, $stderr] = $this->runRunnerFixture($fixture, $head, $head);

            self::assertSame(1, $exitCode);
            self::assertSame('', $stdout);
            self::assertStringContainsString('does not match live canonical main', $stderr);
        } finally {
            $this->removeRunnerFixture($fixture);
        }
    }

    public function testRuntimeValidatorRejectsUnsupportedCodexVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must match the isolated runtime contract exactly');

        ReadonlyReviewerContract::assertCodexVersion('codex-cli 0.144.0', '0.145.0');
    }

    public function testOutputValidationRejectsCodexEventStreamAndWrongExactHead(): void
    {
        $base = str_repeat('b', 40);
        $head = str_repeat('a', 40);
        $valid = json_encode(
            [
                'lens' => 'correctness_security',
                'base_sha' => $base,
                'head_sha' => $head,
                'verdict' => 'no_findings',
                'findings' => [],
            ],
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            'no_findings',
            ReadonlyReviewerContract::validateOutput($valid, 'correctness_security', $base, $head, [])['verdict'],
        );

        $this->expectException(\InvalidArgumentException::class);
        ReadonlyReviewerContract::validateOutput('{"type":"turn.completed"}', 'correctness_security', $base, $head, []);
    }

    public function testOutputValidationRejectsWrongExactHead(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReadonlyReviewerContract::validateOutput(
            json_encode(
                [
                    'lens' => 'correctness_security',
                    'base_sha' => str_repeat('b', 40),
                    'head_sha' => str_repeat('b', 40),
                    'verdict' => 'no_findings',
                    'findings' => [],
                ],
                JSON_THROW_ON_ERROR,
            ),
            'correctness_security',
            str_repeat('b', 40),
            str_repeat('a', 40),
            [],
        );
    }

    public function testOutputValidationRejectsWrongBaseSha(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReadonlyReviewerContract::validateOutput(
            json_encode(
                [
                    'lens' => 'correctness_security',
                    'base_sha' => str_repeat('c', 40),
                    'head_sha' => str_repeat('a', 40),
                    'verdict' => 'no_findings',
                    'findings' => [],
                ],
                JSON_THROW_ON_ERROR,
            ),
            'correctness_security',
            str_repeat('b', 40),
            str_repeat('a', 40),
            [],
        );
    }

    public function testOutputValidationRejectsWrongLens(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReadonlyReviewerContract::validateOutput(
            json_encode(
                [
                    'lens' => 'design_maintainability',
                    'base_sha' => str_repeat('b', 40),
                    'head_sha' => str_repeat('a', 40),
                    'verdict' => 'no_findings',
                    'findings' => [],
                ],
                JSON_THROW_ON_ERROR,
            ),
            'correctness_security',
            str_repeat('b', 40),
            str_repeat('a', 40),
            [],
        );
    }

    public function testOutputValidationAcceptsValidFindings(): void
    {
        $base = str_repeat('b', 40);
        $head = str_repeat('a', 40);
        $finding = [
            'priority' => 'P2',
            'title' => 'Finding',
            'file' => 'WORKFLOW.md',
            'line' => 1,
            'impact' => 'Impact',
            'trigger' => 'Trigger',
        ];
        $fileFinding = $finding;
        $fileFinding['title'] = 'File-level finding';
        $fileFinding['line'] = null;

        $validated = ReadonlyReviewerContract::validateOutput(
            json_encode(
                [
                    'lens' => 'correctness_security',
                    'base_sha' => $base,
                    'head_sha' => $head,
                    'verdict' => 'findings',
                    'findings' => [$finding, $fileFinding],
                ],
                JSON_THROW_ON_ERROR,
            ),
            'correctness_security',
            $base,
            $head,
            ['WORKFLOW.md'],
        );

        self::assertSame('findings', $validated['verdict']);
        self::assertSame([$finding, $fileFinding], $validated['findings']);
    }

    public function testOutputValidationRejectsZeroAndNegativeFindingLines(): void
    {
        $base = str_repeat('b', 40);
        $head = str_repeat('a', 40);

        foreach ([0, -1] as $line) {
            $output = json_encode(
                [
                    'lens' => 'correctness_security',
                    'base_sha' => $base,
                    'head_sha' => $head,
                    'verdict' => 'findings',
                    'findings' => [
                        [
                            'priority' => 'P2',
                            'title' => 'Invalid line',
                            'file' => 'WORKFLOW.md',
                            'line' => $line,
                            'impact' => 'Impact',
                            'trigger' => 'Trigger',
                        ],
                    ],
                ],
                JSON_THROW_ON_ERROR,
            );

            try {
                ReadonlyReviewerContract::validateOutput($output, 'correctness_security', $base, $head, [
                    'WORKFLOW.md',
                ]);
                self::fail('Invalid reviewer finding line was accepted: ' . $line);
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('finding line is invalid', $exception->getMessage());
            }
        }
    }

    public function testOutputValidationRejectsSensitiveOrUnboundedFindingText(): void
    {
        $base = str_repeat('b', 40);
        $head = str_repeat('a', 40);
        $unsafeValues = [
            'Authorization: Bearer sensitivevalue12345',
            'Contact reviewer@example.invalid',
            'Open https://example.invalid/capability/value',
            'Read /Users/example/.codex/auth.json',
            'Read /root/.codex/auth.json',
            'Read `/Users/example/.codex/auth.json`',
            'Read "/home/example/.codex/auth.json"',
            "Read '/root/.codex/auth.json'",
            'Inspect [auth](/Users/example/.codex/auth.json)',
            'Token=' . str_repeat('a', 40),
            'Opaque ' . str_repeat('Ab9_', 12),
            str_repeat('x', 1201),
        ];

        foreach (['title', 'impact', 'trigger'] as $field) {
            foreach ($unsafeValues as $unsafeValue) {
                $finding = [
                    'priority' => 'P2',
                    'title' => 'Privacy-safe title',
                    'file' => 'WORKFLOW.md',
                    'line' => 1,
                    'impact' => 'A bounded technical impact without sensitive values.',
                    'trigger' => 'A bounded technical trigger without sensitive values.',
                ];
                $finding[$field] = $unsafeValue;
                $output = json_encode(
                    [
                        'lens' => 'correctness_security',
                        'base_sha' => $base,
                        'head_sha' => $head,
                        'verdict' => 'findings',
                        'findings' => [$finding],
                    ],
                    JSON_THROW_ON_ERROR,
                );

                try {
                    ReadonlyReviewerContract::validateOutput($output, 'correctness_security', $base, $head, [
                        'WORKFLOW.md',
                    ]);
                    self::fail('Sensitive or unbounded reviewer finding text was accepted in ' . $field . '.');
                } catch (\InvalidArgumentException $exception) {
                    self::assertStringContainsString('Reviewer finding text', $exception->getMessage());
                }
            }
        }
    }

    public function testOutputValidationDerivesStructuralTextBoundsFromTheExactSchema(): void
    {
        $schemaPath = sys_get_temp_dir() . '/reviewer-output-schema-' . bin2hex(random_bytes(8)) . '.json';
        $schema = json_decode(
            (string) file_get_contents($this->repoRoot . '/scripts/agent/readonly-review-output.schema.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $schema['properties']['findings']['items']['properties']['title']['maxLength'] = 3;
        file_put_contents($schemaPath, json_encode($schema, JSON_THROW_ON_ERROR));
        self::assertTrue(chmod($schemaPath, 0600));

        $base = str_repeat('b', 40);
        $head = str_repeat('a', 40);
        $output = json_encode(
            [
                'lens' => 'correctness_security',
                'base_sha' => $base,
                'head_sha' => $head,
                'verdict' => 'findings',
                'findings' => [
                    [
                        'priority' => 'P2',
                        'title' => 'Four',
                        'file' => 'WORKFLOW.md',
                        'line' => 1,
                        'impact' => 'Impact',
                        'trigger' => 'Trigger',
                    ],
                ],
            ],
            JSON_THROW_ON_ERROR,
        );

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Reviewer finding text is invalid');
            ReadonlyReviewOutput::validate($output, 'correctness_security', $base, $head, ['WORKFLOW.md'], $schemaPath);
        } finally {
            @unlink($schemaPath);
        }
    }

    public function testOutputValidationRejectsNonChangedOrNonNormalizedFindingFiles(): void
    {
        $base = str_repeat('b', 40);
        $head = str_repeat('a', 40);

        foreach (['README.md', '/tmp/reviewer-finding'] as $file) {
            $output = json_encode(
                [
                    'lens' => 'correctness_security',
                    'base_sha' => $base,
                    'head_sha' => $head,
                    'verdict' => 'findings',
                    'findings' => [
                        [
                            'priority' => 'P2',
                            'title' => 'Finding',
                            'file' => $file,
                            'line' => 1,
                            'impact' => 'Impact',
                            'trigger' => 'Trigger',
                        ],
                    ],
                ],
                JSON_THROW_ON_ERROR,
            );

            try {
                ReadonlyReviewerContract::validateOutput($output, 'correctness_security', $base, $head, [
                    'WORKFLOW.md',
                ]);
                self::fail('Non-changed or non-normalized finding file was accepted: ' . $file);
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('not a changed repository path', $exception->getMessage());
            }
        }
    }

    public function testOutputValidationRejectsInvalidChangedPathEvidence(): void
    {
        $base = str_repeat('b', 40);
        $head = str_repeat('a', 40);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('changed-path evidence is invalid');
        ReadonlyReviewerContract::validateOutput(
            json_encode(
                [
                    'lens' => 'correctness_security',
                    'base_sha' => $base,
                    'head_sha' => $head,
                    'verdict' => 'no_findings',
                    'findings' => [],
                ],
                JSON_THROW_ON_ERROR,
            ),
            'correctness_security',
            $base,
            $head,
            ['/tmp/not-repository-relative'],
        );
    }

    public function testOutputValidationRejectsVerdictFindingMismatch(): void
    {
        $base = str_repeat('b', 40);
        $head = str_repeat('a', 40);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('verdict does not match its findings');
        ReadonlyReviewerContract::validateOutput(
            json_encode(
                [
                    'lens' => 'correctness_security',
                    'base_sha' => $base,
                    'head_sha' => $head,
                    'verdict' => 'no_findings',
                    'findings' => [
                        [
                            'priority' => 'P2',
                            'title' => 'Finding',
                            'file' => 'WORKFLOW.md',
                            'line' => 1,
                            'impact' => 'Impact',
                            'trigger' => 'Trigger',
                        ],
                    ],
                ],
                JSON_THROW_ON_ERROR,
            ),
            'correctness_security',
            $base,
            $head,
            ['WORKFLOW.md'],
        );
    }

    public function testOutputValidationRejectsFindingsVerdictWithoutFindings(): void
    {
        $base = str_repeat('b', 40);
        $head = str_repeat('a', 40);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('verdict does not match its findings');
        ReadonlyReviewerContract::validateOutput(
            json_encode(
                [
                    'lens' => 'correctness_security',
                    'base_sha' => $base,
                    'head_sha' => $head,
                    'verdict' => 'findings',
                    'findings' => [],
                ],
                JSON_THROW_ON_ERROR,
            ),
            'correctness_security',
            $base,
            $head,
            [],
        );
    }

    public function testOutputValidationRejectsMalformedFinding(): void
    {
        $base = str_repeat('b', 40);
        $head = str_repeat('a', 40);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('finding has unexpected fields');
        ReadonlyReviewerContract::validateOutput(
            json_encode(
                [
                    'lens' => 'correctness_security',
                    'base_sha' => $base,
                    'head_sha' => $head,
                    'verdict' => 'findings',
                    'findings' => [['priority' => 'P2']],
                ],
                JSON_THROW_ON_ERROR,
            ),
            'correctness_security',
            $base,
            $head,
            ['WORKFLOW.md'],
        );
    }

    public function testProfileResolutionUsesStructuredMachinePolicy(): void
    {
        $temporaryDirectory = sys_get_temp_dir() . '/reviewer-profile-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($temporaryDirectory . '/.codex/agents', 0700, true));
        self::assertNotFalse(
            file_put_contents(
                $temporaryDirectory . '/.codex/agents/reviewer-tests.toml',
                "developer_instructions = \"\"\"\nmodel = 'untrusted-body-value'\n\"\"\"\n",
            ),
        );

        try {
            $resolved = ReadonlyReviewerContract::resolveInvocation(
                $temporaryDirectory,
                'tests_regression_flake',
                $this->canonicalReviewerPolicy(),
            );

            self::assertSame('gpt-5.4-mini', $resolved['model']);
            self::assertSame('medium', $resolved['reasoning']);
            self::assertSame('scripts/agent/readonly-review-output.schema.json', $resolved['output_schema_path']);
            self::assertContains('.codex/agents/reviewer-tests.toml', $resolved['trusted_base_paths']);
            self::assertStringContainsString("model = 'untrusted-body-value'", $resolved['role_instructions']);
        } finally {
            $this->removeDirectory($temporaryDirectory);
        }
    }

    public function testTestsRegressionFlakeLensResolvesTheCanonicalRepositoryRole(): void
    {
        $contract = json_decode(
            (string) file_get_contents($this->repoRoot . '/.codex/contracts/agent-workflow.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($contract);
        self::assertIsArray($contract['authority']['reviewer'] ?? null);

        $resolved = ReadonlyReviewerContract::resolveInvocation(
            $this->repoRoot,
            'tests_regression_flake',
            $contract['authority']['reviewer'],
        );

        self::assertSame('.codex/agents/reviewer-tests.toml', $resolved['role_file']);
        self::assertSame('gpt-5.4-mini', $resolved['model']);
        self::assertSame('medium', $resolved['reasoning']);
        self::assertSame(
            (string) file_get_contents($this->repoRoot . '/.codex/agents/reviewer-tests.toml'),
            $resolved['role_instructions'],
        );
        self::assertStringContainsString('regression coverage', $resolved['role_instructions']);
    }

    public function testRuntimeConfigurationBindsOfficialPlatformDigests(): void
    {
        $runtime = ReadonlyReviewerContract::runtimeConfiguration($this->canonicalReviewerPolicy(), 'Darwin-arm64');

        self::assertSame('0.145.0', $runtime['version']);
        self::assertSame('1da3f4e0e96028b8a771814293c3033dafd1971f943f6c7e79b0897fe705f590', $runtime['binary_sha256']);
        self::assertSame(
            '072a30a65f05666735889ef0f60b56db186adbdde9d5c5cc1a64be0b598530fe',
            $runtime['release_archive_sha256'],
        );
        self::assertSame(
            'cb24bcb9e973a8258c763e4b2777a398799c653996b395b3e2ab4cf1aa806a0a',
            $runtime['closure_sha256'],
        );
    }

    public function testProfileResolutionRejectsAnInvalidBootstrapPathSet(): void
    {
        $policy = $this->canonicalReviewerPolicy();
        $policy['bootstrap_paths'][] = '../escaped-policy';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('trusted-base policy is invalid');
        ReadonlyReviewerContract::trustedBasePaths($policy);
    }

    public function testProfileResolutionRejectsWeakenedDisabledFeatures(): void
    {
        $policy = $this->canonicalReviewerPolicy();
        array_pop($policy['disabled_features']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('runtime boundary is invalid');
        ReadonlyReviewerContract::trustedBasePaths($policy);
    }

    public function testRuntimeBoundaryAttestationRejectsDriftedPolicy(): void
    {
        $policy = $this->canonicalReviewerPolicy();
        $policy['invocation_source'] = 'permissive-checkout-entrypoint';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('runtime boundary attestation is invalid: actual sha256');
        ReadonlyReviewerContract::trustedBasePaths($policy);
    }

    /** @return array<string, mixed> */
    private function canonicalReviewerPolicy(): array
    {
        $contract = json_decode(
            (string) file_get_contents($this->repoRoot . '/.codex/contracts/agent-workflow.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($contract);
        $policy = $contract['authority']['reviewer'] ?? null;
        self::assertIsArray($policy);
        self::assertIsArray($policy['profiles'] ?? null);
        return $policy;
    }

    /**
     * @param (callable(array<string, mixed>): array<string, mixed>)|null $contractMutator
     * @return array{root: string, runner: string, binary: string, base: string, remote: string}
     */
    private function runnerFixture(
        string $label,
        string $binarySource,
        string $binaryName,
        bool $completeBootstrap = false,
        ?callable $contractMutator = null,
    ): array {
        $root = sys_get_temp_dir() . '/' . $label . '-' . bin2hex(random_bytes(8));
        $remote = sys_get_temp_dir() . '/' . $label . '-remote-' . bin2hex(random_bytes(8)) . '.git';
        $this->runGitCommand(['init', '--bare', '-q', $remote], null);
        $this->runGitCommand(['init', '-q', $root], null);
        $runnerPath = 'scripts/agent/run_readonly_reviewer.sh';
        $fixtureRunner = $root . '/' . $runnerPath;
        self::assertTrue(mkdir(dirname($fixtureRunner), 0700, true));
        $launcherPath = 'scripts/agent/trusted_base_launcher.sh';
        $fixtureLauncher = $root . '/' . $launcherPath;
        self::assertNotFalse(
            file_put_contents($fixtureLauncher, (string) file_get_contents($this->repoRoot . '/' . $launcherPath)),
        );
        self::assertTrue(chmod($fixtureLauncher, 0644));
        $sharedRuntimePath = 'scripts/agent/lib/trusted_base_payload_runtime.sh';
        $fixtureSharedRuntime = $root . '/' . $sharedRuntimePath;
        self::assertTrue(mkdir(dirname($fixtureSharedRuntime), 0700, true));
        self::assertNotFalse(
            file_put_contents(
                $fixtureSharedRuntime,
                (string) file_get_contents($this->repoRoot . '/' . $sharedRuntimePath),
            ),
        );
        self::assertTrue(chmod($fixtureSharedRuntime, 0644));
        $contractPath = $root . '/.codex/contracts/agent-workflow.json';
        self::assertTrue(mkdir(dirname($contractPath), 0700, true));
        self::assertTrue(copy($this->repoRoot . '/.codex/contracts/agent-workflow.json', $contractPath));
        if ($contractMutator !== null) {
            $contract = json_decode((string) file_get_contents($contractPath), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($contract);
            self::assertNotFalse(
                file_put_contents(
                    $contractPath,
                    json_encode($contractMutator($contract), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n",
                ),
            );
        }
        $runnerSource = (string) file_get_contents($this->repoRoot . '/' . $runnerPath);
        $fixtureSource = str_replace(
            "canonical_main_remote='https://github.com/robinbeier/forscherhaus-appointments.git'",
            "canonical_main_remote='file://{$remote}'",
            $runnerSource,
        );
        self::assertNotSame($runnerSource, $fixtureSource);
        self::assertNotFalse(file_put_contents($fixtureRunner, $fixtureSource));
        self::assertTrue(chmod($fixtureRunner, 0644));
        if ($completeBootstrap) {
            $contract = json_decode((string) file_get_contents($contractPath), true, 512, JSON_THROW_ON_ERROR);
            $bootstrapPaths = $contract['authority']['reviewer']['bootstrap_paths'] ?? null;
            self::assertIsArray($bootstrapPaths);
            foreach ($bootstrapPaths as $bootstrapPath) {
                self::assertIsString($bootstrapPath);
                $fixturePath = $root . '/' . $bootstrapPath;
                if (is_file($fixturePath)) {
                    continue;
                }
                self::assertFileExists($this->repoRoot . '/' . $bootstrapPath);
                if (!is_dir(dirname($fixturePath))) {
                    self::assertTrue(mkdir(dirname($fixturePath), 0700, true));
                }
                self::assertTrue(copy($this->repoRoot . '/' . $bootstrapPath, $fixturePath));
                self::assertTrue(chmod($fixturePath, 0644));
            }
            self::assertTrue(copy($this->repoRoot . '/AGENTS.md', $root . '/AGENTS.md'));
            self::assertTrue(chmod($root . '/AGENTS.md', 0644));
        }
        $this->runGitCommand(['add', '--all'], $root);
        $this->runGitCommand(
            [
                '-c',
                'user.name=Reviewer Fixture',
                '-c',
                'user.email=reviewer-fixture.invalid',
                '-c',
                'commit.gpgsign=false',
                '-c',
                'core.hooksPath=/dev/null',
                'commit',
                '-q',
                '-m',
                'Build reviewer fixture',
            ],
            $root,
        );
        $this->runGitCommand(['branch', '-M', 'main'], $root);
        $base = $this->runGitCommand(['rev-parse', 'HEAD'], $root);
        $this->runGitCommand(['push', '-q', $remote, 'HEAD:refs/heads/main'], $root);
        $this->runGitCommand(['update-ref', 'refs/remotes/origin/main', $base], $root);
        $runner = sys_get_temp_dir() . '/reviewer-launcher-' . bin2hex(random_bytes(8));
        self::assertNotFalse(
            file_put_contents(
                $runner,
                $this->runTrustedGitBlob($root, $base, 'scripts/agent/trusted_base_launcher.sh'),
            ),
        );
        self::assertTrue(chmod($runner, 0500));
        $binaryDirectory = sys_get_temp_dir() . '/reviewer-binary-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($binaryDirectory, 0700));
        $binary = $binaryDirectory . '/' . $binaryName;
        self::assertNotFalse(file_put_contents($binary, $binarySource));
        self::assertTrue(chmod($binary, 0700));

        return ['root' => $root, 'runner' => $runner, 'binary' => $binary, 'base' => $base, 'remote' => $remote];
    }

    /** @param array{root: string, runner: string, binary: string, base: string, remote: string} $fixture
     *  @return array{int, string, string}
     */
    private function runRunnerFixture(
        array $fixture,
        ?string $base = null,
        ?string $head = null,
        ?array $environment = null,
        bool $diagnostic = false,
    ): array {
        $reviewerArguments = $diagnostic ? ['--diagnostic-bootstrap-only'] : ['--codex-bin=' . $fixture['binary']];
        $reviewerArguments[] = '--lens=correctness_security';
        $reviewerArguments[] = '--head-sha=' . ($head ?? $fixture['base']);
        $process = proc_open(
            array_merge(
                [
                    '/usr/bin/env',
                    '-i',
                    'PATH=/usr/bin:/bin:/usr/sbin:/sbin',
                    'TMPDIR=/tmp',
                    'LANG=C',
                    'LC_ALL=C',
                    'TRUSTED_BASE_MATERIALIZED=1',
                    'TRUSTED_BASE_LAUNCHER_SOURCE_PATH=' . $fixture['runner'],
                    '/bin/bash',
                    '--noprofile',
                    '--norc',
                    $fixture['runner'],
                    '--repo-root=' . $fixture['root'],
                    '--base-sha=' . ($base ?? $fixture['base']),
                    '--payload=reviewer',
                    '--',
                ],
                $reviewerArguments,
            ),
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $fixture['root'],
            $environment,
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    private function runTrustedGitBlob(string $root, string $commit, string $path): string
    {
        $process = proc_open(
            [
                '/usr/bin/env',
                '-i',
                'GIT_CONFIG_GLOBAL=/dev/null',
                'GIT_CONFIG_NOSYSTEM=1',
                'GIT_CONFIG_SYSTEM=/dev/null',
                'GIT_NO_LAZY_FETCH=1',
                'GIT_NO_REPLACE_OBJECTS=1',
                'PATH=/usr/bin:/bin:/usr/sbin:/sbin',
                '/usr/bin/git',
                '-c',
                'core.hooksPath=/dev/null',
                '-C',
                $root,
                'show',
                $commit . ':' . $path,
            ],
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr);

        return $stdout;
    }

    private function commitRunnerFixture(string $root, string $message): void
    {
        $this->runGitCommand(
            [
                '-c',
                'user.name=Reviewer Fixture',
                '-c',
                'user.email=reviewer-fixture.invalid',
                '-c',
                'commit.gpgsign=false',
                '-c',
                'core.hooksPath=/dev/null',
                'commit',
                '-q',
                '-m',
                $message,
            ],
            $root,
        );
    }

    /** @param array{root: string, runner: string, binary: string, base: string, remote: string} $fixture */
    private function removeRunnerFixture(array $fixture): void
    {
        if (is_file($fixture['binary'])) {
            unlink($fixture['binary']);
        }
        $binaryDirectory = dirname($fixture['binary']);
        if (is_dir($binaryDirectory)) {
            rmdir($binaryDirectory);
        }
        if (is_file($fixture['runner'])) {
            unlink($fixture['runner']);
        }
        $this->removeDirectory($fixture['root']);
        $this->removeDirectory($fixture['remote']);
    }

    private function removeDirectory(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }

    /** @param list<string> $arguments */
    private function runGitCommand(array $arguments, ?string $directory): string
    {
        $process = proc_open(
            ['git', ...$arguments],
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $directory,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $stderr);

        return trim((string) $stdout);
    }
}
