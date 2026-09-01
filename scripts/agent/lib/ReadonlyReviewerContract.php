<?php

declare(strict_types=1);

namespace Forscherhaus\AgentHarness;

use JsonException;

require_once __DIR__ . '/RepoPath.php';

final class ReadonlyReviewerContract
{
    private const CODEX_VERSION = '0.145.0';

    /** @var array<string, int> */
    private const FINDING_TEXT_MAX_BYTES = [
        'title' => 160,
        'impact' => 1200,
        'trigger' => 1200,
    ];

    /** @var list<string> */
    private const SENSITIVE_FINDING_TEXT_PATTERNS = [
        '/[\x00-\x1F\x7F]/',
        '/\b(?:Bearer|Basic)\s+[A-Za-z0-9+\/_=.-]{8,}\b/i',
        '/\b(?:sk|rk|pk|gh[pousr]|xox[baprs])[-_][A-Za-z0-9._-]{12,}\b/i',
        '/\bAKIA[0-9A-Z]{16}\b/',
        '/\b(?:password|passwd|secret|api[_ -]?key|access[_ -]?token|refresh[_ -]?token|session[_ -]?id|cookie)\s*[:=]\s*\S+/i',
        '/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i',
        '#\b(?:https?|ssh)://\S+#i',
        '#(?:^|[\s(])/(?:root(?:/|\b)|(?:Users|home)/[^/\s]+)#',
        '/\b[0-9a-f]{32,}\b/i',
        '#(?<![A-Za-z0-9+/_-])[A-Za-z0-9+/_-]{48,}={0,2}(?![A-Za-z0-9+/_-])#',
    ];

    /** @var list<string> */
    private const REQUIRED_DISABLED_FEATURES = [
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
    ];

    /**
     * @param array<string, mixed> $reviewerPolicy
     * @return array{
     *     role_file: string,
     *     role_instructions: string,
     *     model: string,
     *     reasoning: string,
     *     disabled_features: list<string>
     * }
     */
    public static function resolveInvocation(string $repoRoot, string $lens, array $reviewerPolicy): array
    {
        self::trustedBasePaths($reviewerPolicy);

        $profiles = $reviewerPolicy['profiles'] ?? null;
        $profile = is_array($profiles) ? $profiles[$lens] ?? null : null;
        if (!is_array($profile) || array_is_list($profile)) {
            throw new \InvalidArgumentException('Unsupported reviewer lens.');
        }

        $expectedProfileKeys = ['instructions', 'model', 'reasoning'];
        $actualProfileKeys = array_keys($profile);
        sort($actualProfileKeys, SORT_STRING);
        sort($expectedProfileKeys, SORT_STRING);
        if ($actualProfileKeys !== $expectedProfileKeys) {
            throw new \RuntimeException('Reviewer profile policy is invalid.');
        }

        $roleFile = $profile['instructions'];
        $model = $profile['model'];
        $reasoning = $profile['reasoning'];
        if (!is_string($roleFile) || !RepoPath::isNormalized($roleFile)) {
            throw new \RuntimeException('Reviewer profile instructions are invalid.');
        }

        $rolePath = $repoRoot . '/' . $roleFile;
        $role = file_get_contents($rolePath);
        if (!is_string($role) || trim($role) === '' || str_contains($role, "\0")) {
            throw new \RuntimeException('Reviewer profile is unavailable.');
        }

        if (!is_string($model) || preg_match('/[\x00-\x20\x7f]/', $model) === 1 || $model === '') {
            throw new \RuntimeException('Reviewer profile model is invalid.');
        }
        if (!is_string($reasoning) || !in_array($reasoning, ['low', 'medium', 'high', 'xhigh', 'max', 'ultra'], true)) {
            throw new \RuntimeException('Reviewer profile reasoning effort is invalid.');
        }
        $disabledFeatures = $reviewerPolicy['disabled_features'] ?? null;
        if ($disabledFeatures !== self::REQUIRED_DISABLED_FEATURES) {
            throw new \RuntimeException('Reviewer disabled-feature policy is invalid.');
        }

        return [
            'role_file' => $roleFile,
            'role_instructions' => $role,
            'model' => $model,
            'reasoning' => $reasoning,
            'disabled_features' => array_values($disabledFeatures),
        ];
    }

    /**
     * @param array<string, mixed> $reviewerPolicy
     * @return list<string>
     */
    public static function trustedBasePaths(array $reviewerPolicy): array
    {
        self::assertRuntimeBoundary($reviewerPolicy);

        $profiles = $reviewerPolicy['profiles'] ?? null;
        if (!is_array($profiles) || array_is_list($profiles)) {
            throw new \RuntimeException('Reviewer profile policy is invalid.');
        }
        $expectedLenses = ['correctness_security', 'design_maintainability', 'tests_regression_flake'];
        $actualLenses = array_keys($profiles);
        sort($expectedLenses, SORT_STRING);
        sort($actualLenses, SORT_STRING);
        if ($actualLenses !== $expectedLenses) {
            throw new \RuntimeException('Reviewer profile policy is invalid.');
        }

        $instructionPaths = [];
        foreach (['correctness_security', 'design_maintainability', 'tests_regression_flake'] as $lens) {
            $profile = $profiles[$lens] ?? null;
            $instructions = is_array($profile) ? $profile['instructions'] ?? null : null;
            if (!is_string($instructions) || !RepoPath::isNormalized($instructions)) {
                throw new \RuntimeException('Reviewer profile instructions are invalid.');
            }
            $instructionPaths[] = $instructions;
        }

        $expectedPaths = array_values(
            array_unique([
                '.codex/contracts/agent-workflow.json',
                ...$instructionPaths,
                'scripts/agent/readonly-review-output.schema.json',
                'scripts/agent/readonly-reviewer.sb',
                'scripts/agent/readonly_review_bundle.php',
                'scripts/agent/readonly_reviewer_contract.php',
                'scripts/agent/lib/RepoPath.php',
                'scripts/agent/lib/ReadonlyReviewBundle.php',
                'scripts/agent/lib/ReadonlyReviewerContract.php',
                'AGENTS.md',
                'code_review.md',
            ]),
        );
        $trustedPaths = $reviewerPolicy['trusted_base_paths'] ?? null;
        if ($trustedPaths !== $expectedPaths) {
            throw new \RuntimeException('Reviewer trusted-base policy is invalid.');
        }

        return $expectedPaths;
    }

    /**
     * @param array<string, mixed> $reviewerPolicy
     * @return array{version: string, binary_sha256: string, release_archive_sha256: string}
     */
    public static function runtimeConfiguration(array $reviewerPolicy, string $platform): array
    {
        self::assertRuntimeBoundary($reviewerPolicy);

        $binaryDigests = $reviewerPolicy['codex_binary_sha256_by_platform'];
        $archiveDigests = $reviewerPolicy['codex_release_archive_sha256_by_platform'];
        $binarySha256 = is_array($binaryDigests) ? $binaryDigests[$platform] ?? null : null;
        $archiveSha256 = is_array($archiveDigests) ? $archiveDigests[$platform] ?? null : null;
        if (
            !is_string($binarySha256) ||
            preg_match('/^[a-f0-9]{64}$/D', $binarySha256) !== 1 ||
            !is_string($archiveSha256) ||
            preg_match('/^[a-f0-9]{64}$/D', $archiveSha256) !== 1
        ) {
            throw new \InvalidArgumentException('Reviewer Codex binary platform is not pinned.');
        }

        return [
            'version' => self::CODEX_VERSION,
            'binary_sha256' => $binarySha256,
            'release_archive_sha256' => $archiveSha256,
        ];
    }

    public static function assertCodexVersion(string $versionOutput): void
    {
        if (
            preg_match(
                '/^codex-cli[[:space:]]+([0-9]+)\.([0-9]+)\.([0-9]+)([+-][A-Za-z0-9._-]+)?(?:[[:space:]]+\([A-Za-z0-9._\/+\:\ -]{1,80}\))?$/D',
                trim($versionOutput),
                $matches,
            ) !== 1
        ) {
            throw new \InvalidArgumentException('Reviewer Codex binary does not identify as Codex CLI.');
        }
        $actualVersion = sprintf('%d.%d.%d', (int) $matches[1], (int) $matches[2], (int) $matches[3]);
        if ($actualVersion !== self::CODEX_VERSION) {
            throw new \InvalidArgumentException(
                'Reviewer Codex CLI must match the isolated runtime contract exactly (' . self::CODEX_VERSION . ').',
            );
        }
    }

    public static function assertCodexSource(string $path, int $expectedOwner): void
    {
        self::assertExecutableRegularFile($path);
        $owner = fileowner($path);
        $mode = fileperms($path);
        if (!in_array($owner, [0, $expectedOwner], true) || !is_int($mode) || ($mode & 0o022) !== 0) {
            throw new \InvalidArgumentException('Reviewer Codex binary target ownership is unsafe.');
        }
    }

    public static function assertMaterializedCodex(string $path, int $expectedOwner, string $expectedSha256): void
    {
        self::assertExecutableRegularFile($path);
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedSha256) !== 1) {
            throw new \InvalidArgumentException('Reviewer Codex binary digest policy is invalid.');
        }
        $owner = fileowner($path);
        $mode = fileperms($path);
        $sha256 = hash_file('sha256', $path);
        if (
            $owner !== $expectedOwner ||
            !is_int($mode) ||
            ($mode & 0o277) !== 0 ||
            !is_string($sha256) ||
            !hash_equals($expectedSha256, $sha256)
        ) {
            throw new \InvalidArgumentException('Reviewer Codex binary does not match the pinned official release.');
        }
    }

    private static function assertExecutableRegularFile(string $path): void
    {
        if (!is_file($path) || is_link($path) || realpath($path) !== $path || !is_executable($path)) {
            throw new \InvalidArgumentException('Reviewer Codex binary target is invalid.');
        }
    }

    /** @param array<string, mixed> $reviewerPolicy */
    private static function assertRuntimeBoundary(array $reviewerPolicy): void
    {
        $expected = [
            'invocation_source' => 'hardened_system_git_materialized_base_blob_outside_worktree',
            'bootstrap_materialization_policy' => 'absolute_system_git_clean_environment_no_replace_objects',
            'trust_anchor' => 'review_base_commit',
            'requires_base_runner' => true,
            'runtime_configuration_change_policy' => 'external_bootstrap_review',
            'shell_runtime_configuration' => 'clean_bootstrap_environment',
            'transport_environment_policy' => 'fixed_direct_no_ambient_proxy_or_endpoint_override',
            'temporary_directory_policy' => 'private_system_temp_bundle_and_internal_runtime_only',
            'php_runtime_configuration' => 'ignore_ambient_ini',
            'git_runtime_configuration' => 'ignore_ambient_and_disable_helpers',
            'git_lazy_fetch' => 'disabled',
            'tool_path_policy' => 'explicit_primary_codex_with_verified_private_copy',
            'repository_root_policy' => 'canonical_physical_root',
            'codex_identity_check' => 'official_release_binary_sha256_platform_and_version',
            'codex_version_policy' => 'exact_0_145_0_with_bounded_build_metadata',
            'codex_binary_materialization_policy' => 'private_copy_rehashed_before_first_execution',
            'codex_binary_sha256_by_platform' => [
                'Darwin-arm64' => '1da3f4e0e96028b8a771814293c3033dafd1971f943f6c7e79b0897fe705f590',
                'Darwin-x86_64' => '6db9193ce2c9a8cef2b5482612cde24202a4329dfc34f4687a036d5d7da619af',
            ],
            'codex_release_archive_sha256_by_platform' => [
                'Darwin-arm64' => '072a30a65f05666735889ef0f60b56db186adbdde9d5c5cc1a64be0b598530fe',
                'Darwin-x86_64' => '4216d7a40aa49d74b65fab93d2a86d2e25a902482b827dbdb3f357777b09fadf',
            ],
            'codex_authentication_source' => 'host_codex_login_without_connector_authority',
            'codex_authentication_home_policy' => 'isolated_runtime_home_read_only_link_to_canonical_auth_file',
            'codex_api_key_override_policy' => 'reject_ambient_api_keys',
            'finding_path_policy' => 'normalized_exact_diff_paths',
            'finding_text_policy' => 'bounded_privacy_safe_prose',
            'web_search' => 'disabled',
            'review_checkout' => 'deterministic_exact_commit_bundle',
            'review_checkout_symlink_policy' => 'reject_all_tracked_symlinks',
            'review_bundle_parent_policy' => 'private_system_temp_random_directory',
            'review_bundle_contents' => 'committed_patch_manifest_changed_base_head_and_trusted_policy',
            'review_bundle_manifest_policy' => 'deterministic_sha256_base_head_binding',
            'review_input_policy' => 'bounded_deterministic_json_serialization_over_stdin',
            'review_original_worktree_access' => 'not_model_visible',
            'isolation_platform' => 'darwin_seatbelt_fail_closed_elsewhere',
            'isolation_profile' => 'default_deny_runtime_allowlist_exact_bundle_and_auth_read_only',
            'isolation_preflight' => 'bundle_readable_temp_home_and_original_worktree_denied',
            'model_tool_surface' => 'derived_exact_release_catalog_without_shell_patch_image_search_or_external_tools',
            'filesystem' => 'outer_seatbelt_default_deny_exact_bundle_read_only_runtime_scratch_only',
            'network' => 'outer_codex_transport_no_model_network_tool_or_external_credentials',
            'approval_policy' => 'outer_sandbox_no_model_tools',
            'inherits_user_config' => false,
            'inherits_execpolicy_rules' => false,
            'output_binds_base_sha' => true,
            'allows_external_connectors' => false,
            'allows_delegation' => false,
            'disabled_features' => self::REQUIRED_DISABLED_FEATURES,
        ];
        foreach ($expected as $key => $value) {
            if (($reviewerPolicy[$key] ?? null) !== $value) {
                throw new \RuntimeException('Reviewer runtime boundary is invalid.');
            }
        }
    }

    /**
     * @param list<string> $changedPaths
     * @return array<string, mixed>
     */
    public static function validateOutput(
        string $output,
        string $expectedLens,
        string $expectedBaseSha,
        string $expectedHeadSha,
        array $changedPaths,
    ): array {
        $changedPathSet = self::changedPathSet($changedPaths);

        try {
            $review = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new \InvalidArgumentException('Reviewer output is not valid JSON.');
        }

        if (!is_array($review) || array_is_list($review)) {
            throw new \InvalidArgumentException('Reviewer output has an invalid shape.');
        }

        $requiredKeys = ['base_sha', 'findings', 'head_sha', 'lens', 'verdict'];
        $actualKeys = array_keys($review);
        sort($requiredKeys, SORT_STRING);
        sort($actualKeys, SORT_STRING);
        if (
            $actualKeys !== $requiredKeys ||
            $review['lens'] !== $expectedLens ||
            $review['base_sha'] !== $expectedBaseSha ||
            $review['head_sha'] !== $expectedHeadSha
        ) {
            throw new \InvalidArgumentException(
                'Reviewer output is not bound to the requested base, exact head, and lens.',
            );
        }

        $findings = $review['findings'] ?? null;
        $verdict = $review['verdict'] ?? null;
        if (
            !is_array($findings) ||
            !array_is_list($findings) ||
            !in_array($verdict, ['no_findings', 'findings'], true)
        ) {
            throw new \InvalidArgumentException('Reviewer output verdict or findings are invalid.');
        }
        if (($verdict === 'no_findings') !== ($findings === [])) {
            throw new \InvalidArgumentException('Reviewer output verdict does not match its findings.');
        }

        foreach ($findings as $finding) {
            self::validateFinding($finding, $changedPathSet);
        }

        return $review;
    }

    /** @param array<string, true> $changedPathSet */
    private static function validateFinding(mixed $finding, array $changedPathSet): void
    {
        if (!is_array($finding) || array_is_list($finding)) {
            throw new \InvalidArgumentException('Reviewer finding has an invalid shape.');
        }

        $requiredKeys = ['file', 'impact', 'line', 'priority', 'title', 'trigger'];
        $actualKeys = array_keys($finding);
        sort($requiredKeys, SORT_STRING);
        sort($actualKeys, SORT_STRING);
        if ($actualKeys !== $requiredKeys) {
            throw new \InvalidArgumentException('Reviewer finding has unexpected fields.');
        }

        if (!in_array($finding['priority'] ?? null, ['P0', 'P1', 'P2', 'P3'], true)) {
            throw new \InvalidArgumentException('Reviewer finding priority is invalid.');
        }
        foreach (['title', 'impact', 'trigger'] as $key) {
            self::validateFindingText($key, $finding[$key] ?? null);
        }
        $file = $finding['file'] ?? null;
        if (!is_string($file) || !RepoPath::isNormalized($file) || !isset($changedPathSet[$file])) {
            throw new \InvalidArgumentException('Reviewer finding file is not a changed repository path.');
        }
        if (($finding['line'] ?? null) !== null && (!is_int($finding['line']) || $finding['line'] < 1)) {
            throw new \InvalidArgumentException('Reviewer finding line is invalid.');
        }
    }

    private static function validateFindingText(string $field, mixed $value): void
    {
        $maxBytes = self::FINDING_TEXT_MAX_BYTES[$field] ?? null;
        if (!is_int($maxBytes) || !is_string($value) || trim($value) === '' || strlen($value) > $maxBytes) {
            throw new \InvalidArgumentException('Reviewer finding text is invalid.');
        }

        foreach (self::SENSITIVE_FINDING_TEXT_PATTERNS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                throw new \InvalidArgumentException('Reviewer finding text is not privacy-safe.');
            }
        }
    }

    /**
     * @param list<string> $changedPaths
     * @return array<string, true>
     */
    private static function changedPathSet(array $changedPaths): array
    {
        if (!array_is_list($changedPaths)) {
            throw new \InvalidArgumentException('Reviewer changed-path evidence is invalid.');
        }

        $set = [];
        foreach ($changedPaths as $path) {
            if (!is_string($path) || !RepoPath::isNormalized($path)) {
                throw new \InvalidArgumentException('Reviewer changed-path evidence is invalid.');
            }
            $set[$path] = true;
        }

        return $set;
    }
}
