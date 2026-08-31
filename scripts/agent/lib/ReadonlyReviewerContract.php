<?php

declare(strict_types=1);

namespace Forscherhaus\AgentHarness;

use JsonException;

final class ReadonlyReviewerContract
{
    /**
     * @param array<string, mixed> $reviewerPolicy
     * @return array{role_file: string, model: string, reasoning: string, disabled_features: list<string>}
     */
    public static function resolveInvocation(string $repoRoot, string $lens, array $reviewerPolicy): array
    {
        self::assertRuntimeBoundary($reviewerPolicy);

        $profiles = $reviewerPolicy['profiles'] ?? null;
        $roleFile = is_array($profiles) ? $profiles[$lens] ?? null : null;
        if (!is_string($roleFile) || !self::isNormalizedRepoPath($roleFile)) {
            throw new \InvalidArgumentException('Unsupported reviewer lens.');
        }

        $rolePath = $repoRoot . '/' . $roleFile;
        $role = file_get_contents($rolePath);
        if (!is_string($role)) {
            throw new \RuntimeException('Reviewer profile is unavailable.');
        }

        $model = self::readTomlString($role, 'model');
        $reasoning = self::readTomlString($role, 'model_reasoning_effort');
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
            'model' => $model,
            'reasoning' => $reasoning,
            'disabled_features' => array_values($disabledFeatures),
        ];
    }

    /** @param array<string, mixed> $reviewerPolicy */
    private static function assertRuntimeBoundary(array $reviewerPolicy): void
    {
        $expected = [
            'filesystem' => 'read-only',
            'network' => 'denied',
            'approval_policy' => 'never',
            'inherits_user_config' => false,
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
    public static function validateOutput(string $output, string $expectedLens, string $expectedHeadSha): array
    {
        try {
            $review = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new \InvalidArgumentException('Reviewer output is not valid JSON.');
        }

        if (!is_array($review) || array_is_list($review)) {
            throw new \InvalidArgumentException('Reviewer output has an invalid shape.');
        }

        $requiredKeys = ['findings', 'head_sha', 'lens', 'verdict'];
        $actualKeys = array_keys($review);
        sort($requiredKeys, SORT_STRING);
        sort($actualKeys, SORT_STRING);
        if (
            $actualKeys !== $requiredKeys ||
            $review['lens'] !== $expectedLens ||
            $review['head_sha'] !== $expectedHeadSha
        ) {
            throw new \InvalidArgumentException('Reviewer output is not bound to the requested exact head and lens.');
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

    private static function readTomlString(string $toml, string $key): string
    {
        $pattern = '/^' . preg_quote($key, '/') . '\s*=\s*"([a-zA-Z0-9._-]+)"\s*$/m';
        if (preg_match($pattern, $toml, $matches) !== 1) {
            throw new \RuntimeException('Reviewer profile is missing ' . $key . '.');
        }

        return $matches[1];
    }

    private static function isNormalizedRepoPath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_ends_with($path, '/') || str_contains($path, '\\')) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
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
