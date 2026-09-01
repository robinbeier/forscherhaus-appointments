<?php

declare(strict_types=1);

namespace Forscherhaus\AgentHarness;

require_once __DIR__ . '/RepoPath.php';
require_once __DIR__ . '/ReadonlyReviewOutput.php';
require_once __DIR__ . '/GeneratedReviewerRuntimeAttestation.php';

final class ReadonlyReviewerContract
{
    /** @var list<string> */
    private const MINIMUM_DISABLED_FEATURES = [
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
     *     disabled_features: list<string>,
     *     codex_sandbox_mode: string,
     *     codex_approval_policy: string,
     *     output_schema_path: string,
     *     trusted_base_paths: list<string>
     * }
     */
    public static function resolveInvocation(string $repoRoot, string $lens, array $reviewerPolicy): array
    {
        $trustedBasePaths = self::trustedBasePaths($reviewerPolicy);

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
        $disabledFeatures = self::disabledFeatures($reviewerPolicy);

        return [
            'role_file' => $roleFile,
            'role_instructions' => $role,
            'model' => $model,
            'reasoning' => $reasoning,
            'disabled_features' => array_values($disabledFeatures),
            'codex_sandbox_mode' => $reviewerPolicy['codex_sandbox_mode'],
            'codex_approval_policy' => $reviewerPolicy['codex_approval_policy'],
            'output_schema_path' => $reviewerPolicy['output_schema_path'],
            'trusted_base_paths' => $trustedBasePaths,
        ];
    }

    /**
     * @param array<string, mixed> $reviewerPolicy
     * @return list<string>
     */
    public static function trustedBasePaths(array $reviewerPolicy): array
    {
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

        $bootstrapPaths = self::normalizedPolicyPaths($reviewerPolicy, 'bootstrap_paths');
        $policyContextPaths = self::normalizedPolicyPaths($reviewerPolicy, 'policy_context_paths');
        $outputSchemaPath = $reviewerPolicy['output_schema_path'] ?? null;
        if (
            !in_array('.codex/contracts/agent-workflow.json', $bootstrapPaths, true) ||
            !is_string($outputSchemaPath) ||
            !in_array($outputSchemaPath, $bootstrapPaths, true)
        ) {
            throw new \RuntimeException('Reviewer trusted-base policy is invalid.');
        }

        $trustedBasePaths = array_values(
            array_unique([...$bootstrapPaths, ...$instructionPaths, ...$policyContextPaths]),
        );
        self::assertRuntimeBoundary($reviewerPolicy);
        self::assertGeneratedPolicy($reviewerPolicy);

        return $trustedBasePaths;
    }

    /**
     * @param array<string, mixed> $reviewerPolicy
     * @return array{
     *     version: string,
     *     binary_sha256: string,
     *     release_archive_sha256: string,
     *     closure_sha256: string
     * }
     */
    public static function runtimeConfiguration(array $reviewerPolicy, string $platform): array
    {
        self::assertRuntimeBoundary($reviewerPolicy);

        $version = $reviewerPolicy['codex_version'];
        assert(is_string($version));
        $binaryDigests = $reviewerPolicy['codex_binary_sha256_by_platform'];
        $archiveDigests = $reviewerPolicy['codex_release_archive_sha256_by_platform'];
        $closureDigests = $reviewerPolicy['codex_closure_sha256_by_platform'];
        $binarySha256 = is_array($binaryDigests) ? $binaryDigests[$platform] ?? null : null;
        $archiveSha256 = is_array($archiveDigests) ? $archiveDigests[$platform] ?? null : null;
        $closureSha256 = is_array($closureDigests) ? $closureDigests[$platform] ?? null : null;
        if (
            !is_string($binarySha256) ||
            preg_match('/^[a-f0-9]{64}$/D', $binarySha256) !== 1 ||
            !is_string($archiveSha256) ||
            preg_match('/^[a-f0-9]{64}$/D', $archiveSha256) !== 1 ||
            !is_string($closureSha256) ||
            preg_match('/^[a-f0-9]{64}$/D', $closureSha256) !== 1
        ) {
            throw new \InvalidArgumentException('Reviewer Codex binary platform is not pinned.');
        }

        self::assertGeneratedPolicy($reviewerPolicy);

        return [
            'version' => $version,
            'binary_sha256' => $binarySha256,
            'release_archive_sha256' => $archiveSha256,
            'closure_sha256' => $closureSha256,
        ];
    }

    public static function assertCodexVersion(string $versionOutput, string $expectedVersion): void
    {
        if (preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$/D', $expectedVersion) !== 1) {
            throw new \InvalidArgumentException('Reviewer Codex version policy is invalid.');
        }
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
        if ($actualVersion !== $expectedVersion) {
            throw new \InvalidArgumentException(
                'Reviewer Codex CLI must match the isolated runtime contract exactly (' . $expectedVersion . ').',
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
        self::disabledFeatures($reviewerPolicy);

        $attestedBoundary = [];
        foreach (GeneratedReviewerRuntimeAttestation::KEYS as $key) {
            if (!array_key_exists($key, $reviewerPolicy)) {
                throw new \RuntimeException('Reviewer runtime boundary is missing: ' . $key . '.');
            }
            $attestedBoundary[$key] = $reviewerPolicy[$key];
        }
        ksort($attestedBoundary, SORT_STRING);
        $encodedBoundary = json_encode($attestedBoundary, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $actualBoundarySha256 = hash('sha256', $encodedBoundary);
        if (!hash_equals(GeneratedReviewerRuntimeAttestation::SHA256, $actualBoundarySha256)) {
            throw new \RuntimeException(
                'Reviewer runtime boundary attestation is invalid: actual sha256 ' . $actualBoundarySha256 . '.',
            );
        }
        $version = $reviewerPolicy['codex_version'] ?? null;
        if (
            !is_string($version) ||
            preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$/D', $version) !== 1
        ) {
            throw new \RuntimeException('Reviewer runtime version boundary is invalid.');
        }
        $platforms = ['Darwin-arm64', 'Darwin-x86_64'];
        foreach (
            [
                'codex_binary_sha256_by_platform',
                'codex_release_archive_sha256_by_platform',
                'codex_closure_sha256_by_platform',
            ]
            as $digestMapKey
        ) {
            $digestMap = $reviewerPolicy[$digestMapKey] ?? null;
            if (!is_array($digestMap) || array_keys($digestMap) !== $platforms) {
                throw new \RuntimeException('Reviewer runtime digest boundary is invalid.');
            }
            foreach ($digestMap as $digest) {
                if (!is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                    throw new \RuntimeException('Reviewer runtime digest boundary is invalid.');
                }
            }
        }
    }

    /** @param array<string, mixed> $reviewerPolicy */
    private static function assertGeneratedPolicy(array $reviewerPolicy): void
    {
        $snapshotPath = __DIR__ . '/GeneratedReviewerPolicy.php';
        if (!is_file($snapshotPath) || is_link($snapshotPath) || realpath($snapshotPath) !== $snapshotPath) {
            throw new \RuntimeException('Reviewer policy snapshot is unavailable.');
        }
        /** @var mixed $generatedPolicy */
        $generatedPolicy = require $snapshotPath;
        if (!is_array($generatedPolicy) || $reviewerPolicy !== $generatedPolicy) {
            throw new \RuntimeException('Reviewer policy must match the generated exact-base snapshot.');
        }
    }

    /** @param array<string, mixed> $reviewerPolicy
     *  @return list<string>
     */
    private static function disabledFeatures(array $reviewerPolicy): array
    {
        $features = $reviewerPolicy['disabled_features'] ?? null;
        if (!is_array($features) || !array_is_list($features)) {
            throw new \RuntimeException('Reviewer disabled-feature policy is invalid.');
        }
        foreach ($features as $feature) {
            if (!is_string($feature) || preg_match('/^[a-z0-9_]+$/D', $feature) !== 1) {
                throw new \RuntimeException('Reviewer disabled-feature policy is invalid.');
            }
        }
        if (count($features) !== count(array_unique($features))) {
            throw new \RuntimeException('Reviewer disabled-feature policy is invalid.');
        }
        if (array_diff(self::MINIMUM_DISABLED_FEATURES, $features) !== []) {
            throw new \RuntimeException('Reviewer runtime boundary is invalid.');
        }

        return $features;
    }

    /** @param array<string, mixed> $reviewerPolicy
     *  @return list<string>
     */
    private static function normalizedPolicyPaths(array $reviewerPolicy, string $key): array
    {
        $paths = $reviewerPolicy[$key] ?? null;
        if (!is_array($paths) || !array_is_list($paths) || $paths === []) {
            throw new \RuntimeException('Reviewer trusted-base policy is invalid.');
        }
        foreach ($paths as $path) {
            if (!is_string($path) || !RepoPath::isNormalized($path)) {
                throw new \RuntimeException('Reviewer trusted-base policy is invalid.');
            }
        }
        if (count($paths) !== count(array_unique($paths))) {
            throw new \RuntimeException('Reviewer trusted-base policy is invalid.');
        }

        return $paths;
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
        return ReadonlyReviewOutput::validate(
            $output,
            $expectedLens,
            $expectedBaseSha,
            $expectedHeadSha,
            $changedPaths,
        );
    }
}
