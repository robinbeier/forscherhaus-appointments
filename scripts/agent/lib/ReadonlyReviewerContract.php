<?php

declare(strict_types=1);

namespace Forscherhaus\AgentHarness;

use JsonException;

require_once __DIR__ . '/RepoPath.php';

final class ReadonlyReviewerContract
{
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
        if (!is_array($disabledFeatures) || !array_is_list($disabledFeatures) || $disabledFeatures === []) {
            throw new \RuntimeException('Reviewer disabled-feature policy is invalid.');
        }

        foreach ($disabledFeatures as $feature) {
            if (!is_string($feature) || preg_match('/^[a-z][a-z0-9_]*$/D', $feature) !== 1) {
                throw new \RuntimeException('Reviewer disabled-feature policy is invalid.');
            }
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
                'scripts/agent/readonly_reviewer_contract.php',
                'scripts/agent/lib/RepoPath.php',
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

    /** @param array<string, mixed> $reviewerPolicy */
    private static function assertRuntimeBoundary(array $reviewerPolicy): void
    {
        $expected = [
            'invocation_source' => 'materialized_base_blob_outside_worktree',
            'trust_anchor' => 'review_base_commit',
            'requires_base_runner' => true,
            'runtime_configuration_change_policy' => 'external_bootstrap_review',
            'php_runtime_configuration' => 'ignore_ambient_ini',
            'git_runtime_configuration' => 'ignore_ambient_and_disable_helpers',
            'git_lazy_fetch' => 'disabled',
            'tool_path_policy' => 'fixed_system_path_or_explicit_primary_codex',
            'codex_identity_check' => 'basename_and_version',
            'web_search' => 'disabled',
            'review_checkout' => 'private_exact_commit_clone',
            'filesystem' => 'read-only',
            'network' => 'denied',
            'approval_policy' => 'never',
            'inherits_user_config' => false,
            'inherits_execpolicy_rules' => false,
            'output_binds_base_sha' => true,
            'allows_external_connectors' => false,
            'allows_delegation' => false,
        ];
        foreach ($expected as $key => $value) {
            if (($reviewerPolicy[$key] ?? null) !== $value) {
                throw new \RuntimeException('Reviewer runtime boundary is invalid.');
            }
        }
    }

    /** @return array<string, mixed> */
    public static function validateOutput(
        string $output,
        string $expectedLens,
        string $expectedBaseSha,
        string $expectedHeadSha,
    ): array {
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
            self::validateFinding($finding);
        }

        return $review;
    }

    private static function validateFinding(mixed $finding): void
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
        foreach (['title', 'file', 'impact', 'trigger'] as $key) {
            if (!is_string($finding[$key] ?? null) || trim($finding[$key]) === '') {
                throw new \InvalidArgumentException('Reviewer finding text is invalid.');
            }
        }
        if (($finding['line'] ?? null) !== null && (!is_int($finding['line']) || $finding['line'] < 1)) {
            throw new \InvalidArgumentException('Reviewer finding line is invalid.');
        }
    }
}
