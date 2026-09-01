<?php

declare(strict_types=1);

namespace Forscherhaus\AgentHarness;

require_once __DIR__ . '/RepoPath.php';
require_once __DIR__ . '/OwnershipPathRuleEngineClient.php';

/**
 * Domain-facing ownership policy contract backed by the canonical Python
 * engine. This class intentionally implements no path normalization, matching,
 * overlap, process, or wire semantics.
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
        $output = OwnershipPathRuleEngineClient::execute([
            'operation' => 'validate_contract',
            'contract' => $contract,
        ]);
        $result = $output['result'];
        return is_array($result['errors'] ?? null)
            ? array_values(array_unique($result['errors']))
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
            $output = OwnershipPathRuleEngineClient::execute(['operation' => 'parse', 'rule' => $value]);
        } catch (\RuntimeException) {
            $errors[] = $error;
            return null;
        }
        if (
            ($output['result']['valid'] ?? null) !== true ||
            !is_array($output['result']['rule'] ?? null) ||
            array_is_list($output['result']['rule'])
        ) {
            $errors[] = $error;
            return null;
        }
        $rule = $output['result']['rule'];
        if (!is_string($rule['path'] ?? null) || !is_string($rule['match'] ?? null)) {
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
        $output = OwnershipPathRuleEngineClient::execute([
            'operation' => 'overlap',
            'left' => ['path' => $leftPath, 'match' => $leftMatch],
            'right' => ['path' => $rightPath, 'match' => $rightMatch],
        ]);
        if (!is_bool($output['result']['overlaps'] ?? null)) {
            throw new \RuntimeException('Ownership path-rule engine output invalid');
        }
        return $output['result']['overlaps'];
    }

    /** @param array{path: string, match: string} $pathRule */
    public static function pathRuleCoversChangedPath(array $pathRule, string $changedPath): bool
    {
        $output = OwnershipPathRuleEngineClient::execute([
            'operation' => 'covers',
            'rule' => $pathRule,
            'candidate' => $changedPath,
        ]);
        if (!is_bool($output['result']['matches'] ?? null)) {
            throw new \RuntimeException('Ownership path-rule engine output invalid');
        }
        return $output['result']['matches'];
    }
}
