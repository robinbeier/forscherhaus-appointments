<?php

declare(strict_types=1);

namespace Forscherhaus\AgentHarness;

require_once __DIR__ . '/RepoPath.php';

/**
 * Fail-closed JSON transport adapter for the one canonical Python ownership
 * engine. This class intentionally implements no path normalization, matching,
 * or overlap semantics; every such decision is returned by the exact-base
 * `scripts/ci/ownership_path_rules.py` process and schema-checked here.
 */
final class ParallelWorkOwnershipContract
{
    /**
     * Execute the language-neutral semantics fixture through the trusted PHP
     * matcher. Python CI consumers execute the same fixture at import time.
     *
     * @param array<string, mixed> $contract
     * @return list<string>
     */
    public static function validateSemanticsContract(array $contract): array
    {
        $output = self::runCanonicalEngine(['operation' => 'validate_contract', 'contract' => $contract]);
        return is_array($output['errors'] ?? null)
            ? array_values(array_unique($output['errors']))
            : ['ownership_path_rule_engine_output_invalid'];
    }

    /**
     * @param array<string, mixed> $manifest
     * @param list<string> $errors
     * @return array<string, true>
     */
    public static function readApprovedComponentIds(array $manifest, array &$errors): array
    {
        $approved = $manifest['primary_approved_component_ids'] ?? null;
        if (!is_array($approved) || !array_is_list($approved)) {
            $errors[] = 'invalid_primary_approved_component_ids';
            return [];
        }

        $ids = [];
        foreach ($approved as $componentId) {
            if (!is_string($componentId) || preg_match('/^[a-z0-9][a-z0-9-]*$/D', $componentId) !== 1) {
                $errors[] = 'invalid_primary_component_approval';
                continue;
            }
            if (isset($ids[$componentId])) {
                $errors[] = 'duplicate_primary_component_approval:' . $componentId;
                continue;
            }
            $ids[$componentId] = true;
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $ownershipMap
     * @param list<string> $errors
     * @return array<string, array{path_rules: list<array{path: string, match: string}>}>
     */
    public static function readCanonicalComponents(array $ownershipMap, array &$errors): array
    {
        if (($ownershipMap['schema_version'] ?? null) !== 3) {
            $errors[] = 'invalid_canonical_ownership_schema_version';
            return [];
        }
        $components = $ownershipMap['components'] ?? null;
        if (!is_array($components) || !array_is_list($components) || $components === []) {
            $errors[] = 'invalid_canonical_ownership_map';
            return [];
        }

        $canonical = [];
        foreach ($components as $component) {
            if (!is_array($component)) {
                $errors[] = 'invalid_canonical_ownership_component';
                continue;
            }
            $componentId = $component['component_id'] ?? null;
            $canonicalPathRules = $component['path_rules'] ?? null;
            $ownershipMode = $component['ownership_mode'] ?? null;
            $manualApprovalRequired = $component['manual_approval_required'] ?? null;
            if (!is_string($componentId) || !is_array($canonicalPathRules) || !array_is_list($canonicalPathRules)) {
                $errors[] = 'invalid_canonical_ownership_component';
                continue;
            }
            if ($canonicalPathRules === []) {
                $errors[] = 'invalid_canonical_path_rules:' . $componentId;
                continue;
            }
            if (!is_string($ownershipMode) || !in_array($ownershipMode, ['single-owner', 'multi-owner'], true)) {
                $errors[] = 'invalid_canonical_ownership_mode:' . $componentId;
                continue;
            }
            if (!is_bool($manualApprovalRequired)) {
                $errors[] = 'invalid_canonical_manual_approval:' . $componentId;
                continue;
            }
            if ($ownershipMode === 'single-owner' && $manualApprovalRequired !== true) {
                $errors[] = 'invalid_canonical_single_owner_approval:' . $componentId;
                continue;
            }
            if ($ownershipMode !== 'single-owner' && !$manualApprovalRequired) {
                continue;
            }
            if (isset($canonical[$componentId])) {
                $errors[] = 'duplicate_canonical_component_id:' . $componentId;
                continue;
            }
            $pathRules = [];
            foreach ($canonicalPathRules as $canonicalPathRule) {
                $pathRule = self::readPathRule(
                    $canonicalPathRule,
                    $errors,
                    'invalid_canonical_ownership_path_rule:' . $componentId,
                );
                if ($pathRule === null) {
                    continue 2;
                }
                $pathRules[] = $pathRule;
            }
            $canonical[$componentId] = ['path_rules' => $pathRules];
        }

        return $canonical;
    }

    /**
     * @param list<string> $errors
     * @return array{path: string, match: string}|null
     */
    public static function readPathRule(mixed $value, array &$errors, string $error): ?array
    {
        try {
            $output = self::runCanonicalEngine(['operation' => 'parse', 'rule' => $value]);
        } catch (\RuntimeException) {
            $errors[] = $error;
            return null;
        }
        if (
            ($output['valid'] ?? null) !== true ||
            !is_array($output['rule'] ?? null) ||
            array_is_list($output['rule'])
        ) {
            $errors[] = $error;
            return null;
        }
        $rule = $output['rule'];
        if (
            array_keys($rule) !== ['match', 'path'] ||
            !is_string($rule['path'] ?? null) ||
            !is_string($rule['match'] ?? null)
        ) {
            $errors[] = $error;
            return null;
        }
        return ['path' => $rule['path'], 'match' => $rule['match']];
    }

    public static function pathRulesOverlap(
        string $leftPath,
        string $leftMatch,
        string $rightPath,
        string $rightMatch,
    ): bool {
        $output = self::runCanonicalEngine([
            'operation' => 'overlap',
            'left' => ['path' => $leftPath, 'match' => $leftMatch],
            'right' => ['path' => $rightPath, 'match' => $rightMatch],
        ]);
        if (!is_bool($output['overlaps'] ?? null)) {
            throw new \RuntimeException('Ownership path-rule engine output invalid');
        }
        return $output['overlaps'];
    }

    /** @param array{path: string, match: string} $pathRule */
    public static function pathRuleCoversChangedPath(array $pathRule, string $changedPath): bool
    {
        $output = self::runCanonicalEngine([
            'operation' => 'covers',
            'rule' => $pathRule,
            'candidate' => $changedPath,
        ]);
        if (!is_bool($output['matches'] ?? null)) {
            throw new \RuntimeException('Ownership path-rule engine output invalid');
        }
        return $output['matches'];
    }

    /** @param array<string, mixed> $request @return array<string, mixed> */
    private static function runCanonicalEngine(array $request): array
    {
        $engine = getenv('PARALLEL_WORK_OWNERSHIP_ENGINE');
        if (
            !is_string($engine) ||
            !str_starts_with($engine, '/') ||
            is_link($engine) ||
            !is_file($engine) ||
            realpath($engine) !== $engine
        ) {
            throw new \RuntimeException('Ownership path-rule engine unavailable');
        }
        $root = dirname(__DIR__, 3);
        $process = proc_open(
            ['/usr/bin/python3', '-I', '-B', $engine],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $root,
            [
                'PATH' => '/usr/bin:/bin:/usr/sbin:/sbin',
                'LANG' => 'C',
                'LC_ALL' => 'C',
                'TMPDIR' => '/tmp',
                'PYTHONPATH' => '',
            ],
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Ownership path-rule engine unavailable');
        }
        $encodedRequest = json_encode($request, JSON_THROW_ON_ERROR);
        if (fwrite($pipes[0], $encodedRequest) !== strlen($encodedRequest)) {
            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            throw new \RuntimeException('Ownership path-rule engine input incomplete');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0 || !is_string($stdout) || $stderr !== '') {
            throw new \RuntimeException('Ownership path-rule engine failed');
        }
        $output = json_decode($stdout, true);
        $expectedKeys = match ($request['operation'] ?? null) {
            'parse' => ['rule', 'valid'],
            'covers' => ['matches'],
            'overlap' => ['overlaps'],
            'validate_contract' => ['errors'],
            default => [],
        };
        if (!is_array($output) || array_keys($output) !== $expectedKeys) {
            throw new \RuntimeException('Ownership path-rule engine output invalid');
        }
        if (
            ($request['operation'] ?? null) === 'parse' &&
            (!is_bool($output['valid']) ||
                ($output['valid'] && (!is_array($output['rule']) || array_is_list($output['rule']))))
        ) {
            throw new \RuntimeException('Ownership path-rule engine output invalid');
        }
        if (
            ($request['operation'] ?? null) === 'validate_contract' &&
            (!is_array($output['errors']) ||
                !array_is_list($output['errors']) ||
                array_filter($output['errors'], 'is_string') !== $output['errors'])
        ) {
            throw new \RuntimeException('Ownership path-rule engine output invalid');
        }
        return $output;
    }
}
