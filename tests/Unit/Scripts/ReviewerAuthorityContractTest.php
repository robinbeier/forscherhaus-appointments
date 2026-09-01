<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

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
            'hardened_system_git_materialized_base_blob_outside_worktree',
            $contract['authority']['reviewer']['invocation_source'] ?? null,
        );
        self::assertSame(
            'absolute_system_git_clean_environment_no_replace_objects',
            $contract['authority']['reviewer']['bootstrap_materialization_policy'] ?? null,
        );
        self::assertTrue($contract['authority']['reviewer']['requires_base_runner'] ?? false);
        self::assertSame(
            'external_bootstrap_review',
            $contract['authority']['reviewer']['runtime_configuration_change_policy'] ?? null,
        );
        self::assertSame(
            'root_owned_posix_bootstrap_and_clean_bash_environment',
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
            'bundle_readable_temp_home_and_original_worktree_denied',
            $contract['authority']['reviewer']['isolation_preflight'] ?? null,
        );
        self::assertSame(
            'derived_exact_release_catalog_without_shell_patch_image_search_or_external_tools',
            $contract['authority']['reviewer']['model_tool_surface'] ?? null,
        );
        self::assertSame(
            'outer_seatbelt_default_deny_exact_bundle_read_only_runtime_scratch_only',
            $contract['authority']['reviewer']['filesystem'] ?? null,
        );
        self::assertSame(
            'scripts/agent/readonly-review-output.schema.json',
            $contract['authority']['reviewer']['output_schema_path'] ?? null,
        );
        self::assertSame(
            [
                '.codex/contracts/agent-workflow.json',
                '.codex/agents/reviewer-correctness.toml',
                '.codex/agents/reviewer-design.toml',
                '.codex/agents/reviewer-tests.toml',
                'scripts/agent/readonly-review-output.schema.json',
                'scripts/agent/readonly-reviewer.sb',
                'scripts/agent/verify_trusted_php_runtime.py',
                'scripts/agent/readonly_review_bundle.php',
                'scripts/agent/readonly_reviewer_contract.php',
                'scripts/agent/lib/RepoPath.php',
                'scripts/agent/lib/ReadonlyReviewBundle.php',
                'scripts/agent/lib/ReadonlyReviewerContract.php',
                'scripts/agent/lib/readonly_reviewer_bundle_runtime.sh',
                'scripts/agent/lib/readonly_reviewer_isolated_runtime.sh',
                'AGENTS.md',
                'code_review.md',
            ],
            $contract['authority']['reviewer']['trusted_base_paths'] ?? null,
        );
        $runner = (string) file_get_contents($this->repoRoot . '/scripts/agent/run_readonly_reviewer.sh');
        $bundleRuntime = (string) file_get_contents(
            $this->repoRoot . '/scripts/agent/lib/readonly_reviewer_bundle_runtime.sh',
        );
        $isolatedRuntime = (string) file_get_contents(
            $this->repoRoot . '/scripts/agent/lib/readonly_reviewer_isolated_runtime.sh',
        );
        self::assertStringContainsString(' trusted-paths --lens=', $runner);
        self::assertStringContainsString('output_schema_path', $bundleRuntime);
        self::assertStringContainsString('--output-schema "$control_root/$output_schema_path"', $isolatedRuntime);
        self::assertStringContainsString('GIT_NO_LAZY_FETCH=1', $runner);
        self::assertFalse($contract['authority']['reviewer']['inherits_execpolicy_rules'] ?? true);
        self::assertTrue($contract['authority']['reviewer']['output_binds_base_sha'] ?? false);
        self::assertSame(
            [
                'apps',
                'plugins',
                'browser_use',
                'browser_use_external',
                'browser_use_full_cdp_access',
                'computer_use',
                'image_generation',
                'in_app_browser',
                'memories',
                'skill_search',
                'skill_mcp_dependency_install',
                'auth_elicitation',
                'tool_call_mcp_elicitation',
                'multi_agent',
                'multi_agent_v2',
                'hooks',
                'shell_tool',
                'unified_exec',
                'code_mode',
                'code_mode_only',
                'code_mode_host',
                'shell_snapshot',
                'workspace_dependencies',
                'goals',
                'chronicle',
                'tool_suggest',
                'remote_plugin',
                'plugin_sharing',
                'deferred_executor',
                'executor_capability_discovery',
                'request_permissions_tool',
                'default_mode_request_user_input',
            ],
            $contract['authority']['reviewer']['disabled_features'] ?? null,
        );
    }

    public function testAllFinalReviewerRolesCarryTheSamePrimaryOnlyBoundary(): void
    {
        foreach (['reviewer-correctness.toml', 'reviewer-design.toml', 'reviewer-tests.toml'] as $filename) {
            $role = (string) file_get_contents($this->repoRoot . '/.codex/agents/' . $filename);
            self::assertStringContainsString('.codex/contracts/agent-workflow.json', $role, $filename);
            self::assertStringContainsString('scripts/agent/run_readonly_reviewer.sh', $role, $filename);
            self::assertStringContainsString('Do not delegate or mutate files, Git, GitHub', $role, $filename);
            self::assertStringContainsString('Return findings only to the primary', $role, $filename);
        }
    }

    public function testSeatbeltAndToolFreeInvariantsAreEncodedInRunner(): void
    {
        $runner = file_get_contents($this->repoRoot . '/scripts/agent/run_readonly_reviewer.sh');
        $bundleRuntime = file_get_contents($this->repoRoot . '/scripts/agent/lib/readonly_reviewer_bundle_runtime.sh');
        $isolatedRuntime = file_get_contents(
            $this->repoRoot . '/scripts/agent/lib/readonly_reviewer_isolated_runtime.sh',
        );
        $seatbelt = file_get_contents($this->repoRoot . '/scripts/agent/readonly-reviewer.sb');
        self::assertIsString($runner);
        self::assertIsString($bundleRuntime);
        self::assertIsString($isolatedRuntime);
        self::assertIsString($seatbelt);

        self::assertStringStartsWith("#!/bin/sh\n", $runner);
        self::assertStringContainsString('env -i', $runner);
        self::assertStringContainsString('/usr/bin/python3', $runner);
        self::assertStringContainsString('-I -B', $runner);
        self::assertStringContainsString('scripts/agent/verify_trusted_php_runtime.py', $runner);
        self::assertStringNotContainsString('command -v php', $runner);
        self::assertStringNotContainsString('#!/usr/bin/env -S', $runner);
        self::assertStringContainsString('GIT_NO_REPLACE_OBJECTS=1', $runner);
        self::assertStringContainsString('trusted_remote_git()', $runner);
        $remoteGitStart = strpos($runner, 'trusted_remote_git() {');
        self::assertNotFalse($remoteGitStart);
        $remoteGitEnd = strpos($runner, "\n}\n\nsha_pattern=", $remoteGitStart);
        self::assertNotFalse($remoteGitEnd);
        $remoteGit = substr($runner, $remoteGitStart, $remoteGitEnd - $remoteGitStart);
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
        self::assertStringNotContainsString('--dangerously-bypass-approvals-and-sandbox', $runner);
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
        self::assertStringContainsString('Reviewer Seatbelt profile did not deny host-home data.', $isolatedRuntime);
        self::assertStringContainsString(
            'Reviewer Seatbelt profile did not deny the original worktree.',
            $isolatedRuntime,
        );
        self::assertStringContainsString('--ignore-user-config', $isolatedRuntime);
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
        self::assertStringNotContainsString('/opt/homebrew/lib', $seatbelt);

        self::assertStringNotContainsString('--permission-profile', $runner);
        self::assertStringNotContainsString('default_permissions=', $runner);
        self::assertStringNotContainsString('--sandbox read-only', $runner);
        self::assertStringNotContainsString('sandbox_workspace_write', $runner);
    }

    public function testSystemBootstrapDropsAmbientPhpAndShellStartupConfiguration(): void
    {
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
            $process = proc_open(
                [$this->repoRoot . '/scripts/agent/run_readonly_reviewer.sh'],
                [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                $pipes,
                $this->repoRoot,
                $environment,
            );
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            self::assertSame(2, proc_close($process));
            self::assertSame('', (string) $stdout);
            self::assertStringContainsString('Reviewer SHAs must be full lowercase commit IDs.', (string) $stderr);
            self::assertFileDoesNotExist($bashMarker);
            self::assertFileDoesNotExist($phpMarker);
        } finally {
            foreach ([$bashMarker, $phpMarker, $bashEnvironment, $autoPrepend, $phpIni] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($temporaryDirectory);
        }
    }

    public function testReviewerBootstrapNeverExecutesPhpResolvedFromAmbientPath(): void
    {
        $temporaryDirectory = sys_get_temp_dir() . '/reviewer-path-bootstrap-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($temporaryDirectory, 0700));
        $marker = $temporaryDirectory . '/ambient-php-ran';
        $ambientPhp = $temporaryDirectory . '/php';
        self::assertNotFalse(
            file_put_contents($ambientPhp, "#!/bin/sh\n: > " . escapeshellarg($marker) . "\nexit 99\n"),
        );
        self::assertTrue(chmod($ambientPhp, 0700));

        try {
            $process = proc_open(
                [$this->repoRoot . '/scripts/agent/run_readonly_reviewer.sh'],
                [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                $pipes,
                $this->repoRoot,
                array_merge($_ENV, ['PATH' => $temporaryDirectory]),
            );
            self::assertIsResource($process);
            stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            self::assertNotSame(0, proc_close($process));
            self::assertFileDoesNotExist($marker, $stderr);
        } finally {
            if (is_file($marker)) {
                unlink($marker);
            }
            unlink($ambientPhp);
            rmdir($temporaryDirectory);
        }
    }

    public function testRunnerRejectsAnExecutableWhoseBasenameIsNotCodex(): void
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
            self::assertSame("Reviewer exact commit tree contains a tracked symlink or invalid entry.\n", $stderr);
            self::assertFileDoesNotExist($executionMarker);
        } finally {
            $this->removeRunnerFixture($fixture);
            if (is_file($executionMarker)) {
                unlink($executionMarker);
            }
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
        file_put_contents(
            $temporaryDirectory . '/.codex/agents/reviewer.toml',
            "developer_instructions = \"\"\"\nmodel = 'untrusted-body-value'\n\"\"\"\n",
        );

        $resolved = ReadonlyReviewerContract::resolveInvocation(
            $temporaryDirectory,
            'tests_regression_flake',
            $this->reviewerPolicyForProfile('.codex/agents/reviewer.toml'),
        );

        self::assertSame('gpt-5.4-mini', $resolved['model']);
        self::assertSame('medium', $resolved['reasoning']);
        self::assertSame('scripts/agent/readonly-review-output.schema.json', $resolved['output_schema_path']);
        self::assertContains('.codex/agents/reviewer.toml', $resolved['trusted_base_paths']);
        self::assertStringContainsString("model = 'untrusted-body-value'", $resolved['role_instructions']);
    }

    public function testRuntimeConfigurationBindsOfficialPlatformDigests(): void
    {
        $runtime = ReadonlyReviewerContract::runtimeConfiguration(
            $this->reviewerPolicyForProfile('.codex/agents/reviewer.toml'),
            'Darwin-arm64',
        );

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

    public function testProfileResolutionRejectsAStaleTrustedPathSet(): void
    {
        $policy = $this->reviewerPolicyForProfile('.codex/agents/reviewer.toml');
        array_pop($policy['trusted_base_paths']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('trusted-base policy is invalid');
        ReadonlyReviewerContract::trustedBasePaths($policy);
    }

    public function testProfileResolutionRejectsWeakenedDisabledFeatures(): void
    {
        $policy = $this->reviewerPolicyForProfile('.codex/agents/reviewer.toml');
        array_pop($policy['disabled_features']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('runtime boundary is invalid');
        ReadonlyReviewerContract::trustedBasePaths($policy);
    }

    /** @return array<string, mixed> */
    private function reviewerPolicyForProfile(string $profile): array
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
        foreach (array_keys($policy['profiles']) as $lens) {
            $policy['profiles'][$lens]['instructions'] = $profile;
        }
        $policy['trusted_base_paths'] = [
            '.codex/contracts/agent-workflow.json',
            $profile,
            'scripts/agent/readonly-review-output.schema.json',
            'scripts/agent/readonly-reviewer.sb',
            'scripts/agent/verify_trusted_php_runtime.py',
            'scripts/agent/readonly_review_bundle.php',
            'scripts/agent/readonly_reviewer_contract.php',
            'scripts/agent/lib/RepoPath.php',
            'scripts/agent/lib/ReadonlyReviewBundle.php',
            'scripts/agent/lib/ReadonlyReviewerContract.php',
            'scripts/agent/lib/readonly_reviewer_bundle_runtime.sh',
            'scripts/agent/lib/readonly_reviewer_isolated_runtime.sh',
            'AGENTS.md',
            'code_review.md',
        ];

        return $policy;
    }

    /** @return array{root: string, runner: string, binary: string, base: string, remote: string} */
    private function runnerFixture(string $label, string $binarySource, string $binaryName): array
    {
        $root = sys_get_temp_dir() . '/' . $label . '-' . bin2hex(random_bytes(8));
        $remote = sys_get_temp_dir() . '/' . $label . '-remote-' . bin2hex(random_bytes(8)) . '.git';
        $this->runGitCommand(['init', '--bare', '-q', $remote], null);
        $this->runGitCommand(['init', '-q', $root], null);
        $runnerPath = 'scripts/agent/run_readonly_reviewer.sh';
        $fixtureRunner = $root . '/' . $runnerPath;
        self::assertTrue(mkdir(dirname($fixtureRunner), 0700, true));
        $runnerSource = (string) file_get_contents($this->repoRoot . '/' . $runnerPath);
        $fixtureSource = str_replace(
            "canonical_main_remote='https://github.com/robinbeier/forscherhaus-appointments.git'",
            "canonical_main_remote='file://{$remote}'",
            $runnerSource,
        );
        self::assertNotSame($runnerSource, $fixtureSource);
        self::assertNotFalse(file_put_contents($fixtureRunner, $fixtureSource));
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
        $runner = sys_get_temp_dir() . '/reviewer-runner-' . bin2hex(random_bytes(8));
        self::assertNotFalse(
            file_put_contents($runner, (string) file_get_contents($root . '/scripts/agent/run_readonly_reviewer.sh')),
        );
        self::assertTrue(chmod($runner, 0700));
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
    private function runRunnerFixture(array $fixture, ?string $base = null, ?string $head = null): array
    {
        $process = proc_open(
            [
                $fixture['runner'],
                '--repo-root=' . $fixture['root'],
                '--codex-bin=' . $fixture['binary'],
                '--lens=correctness_security',
                '--base-sha=' . ($base ?? $fixture['base']),
                '--head-sha=' . ($head ?? $fixture['base']),
            ],
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $fixture['root'],
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
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
