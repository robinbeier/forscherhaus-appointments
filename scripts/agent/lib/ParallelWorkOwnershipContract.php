<?php

declare(strict_types=1);

namespace Forscherhaus\AgentHarness;

require_once __DIR__ . '/RepoPath.php';

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
        $errors = [];
        $expectedKeys = ['candidate_path_policy', 'invalid_rule_cases', 'match_cases', 'match_modes', 'schema_version'];
        $actualKeys = array_keys($contract);
        sort($expectedKeys, SORT_STRING);
        sort($actualKeys, SORT_STRING);
        if (
            $actualKeys !== $expectedKeys ||
            ($contract['schema_version'] ?? null) !== 1 ||
            ($contract['candidate_path_policy'] ?? null) !== 'strict_normalized_repository_relative' ||
            ($contract['match_modes'] ?? null) !== [
                'directory' => 'descendants_only',
                'exact_file' => 'exact_path_only',
                'filename_prefix' => 'same_directory_filename_prefix',
            ]
        ) {
            return ['invalid_ownership_path_rule_contract'];
        }

        $matchCases = $contract['match_cases'] ?? null;
        if (!is_array($matchCases) || !array_is_list($matchCases) || $matchCases === []) {
            $errors[] = 'invalid_ownership_path_rule_match_cases';
        } else {
            $seen = [];
            foreach ($matchCases as $index => $case) {
                if (!is_array($case) || array_is_list($case)) {
                    $errors[] = 'invalid_ownership_path_rule_match_case:' . $index;
                    continue;
                }
                $expectedCaseKeys = ['candidate', 'matches', 'name', 'rule'];
                $actualCaseKeys = array_keys($case);
                sort($expectedCaseKeys, SORT_STRING);
                sort($actualCaseKeys, SORT_STRING);
                $name = $case['name'] ?? null;
                $candidate = $case['candidate'] ?? null;
                $matches = $case['matches'] ?? null;
                if (
                    $actualCaseKeys !== $expectedCaseKeys ||
                    !is_string($name) ||
                    $name === '' ||
                    isset($seen[$name]) ||
                    !is_string($candidate) ||
                    !RepoPath::isNormalized($candidate) ||
                    !is_bool($matches)
                ) {
                    $errors[] = 'invalid_ownership_path_rule_match_case:' . $index;
                    continue;
                }
                $seen[$name] = true;
                $ruleErrors = [];
                $rule = self::readPathRule($case['rule'] ?? null, $ruleErrors, 'invalid');
                if ($rule === null || $ruleErrors !== []) {
                    $errors[] = 'invalid_ownership_path_rule_match_case:' . $name;
                    continue;
                }
                if (self::pathRuleCoversChangedPath($rule, $candidate) !== $matches) {
                    $errors[] = 'ownership_path_rule_match_case_failed:' . $name;
                }
            }
        }

        $invalidCases = $contract['invalid_rule_cases'] ?? null;
        if (!is_array($invalidCases) || !array_is_list($invalidCases) || $invalidCases === []) {
            $errors[] = 'invalid_ownership_path_rule_invalid_cases';
        } else {
            $seen = [];
            foreach ($invalidCases as $index => $case) {
                if (!is_array($case) || array_is_list($case)) {
                    $errors[] = 'invalid_ownership_path_rule_invalid_case:' . $index;
                    continue;
                }
                $expectedCaseKeys = ['name', 'rule'];
                $actualCaseKeys = array_keys($case);
                sort($expectedCaseKeys, SORT_STRING);
                sort($actualCaseKeys, SORT_STRING);
                $name = $case['name'] ?? null;
                if ($actualCaseKeys !== $expectedCaseKeys || !is_string($name) || $name === '' || isset($seen[$name])) {
                    $errors[] = 'invalid_ownership_path_rule_invalid_case:' . $index;
                    continue;
                }
                $seen[$name] = true;
                $ruleErrors = [];
                if (self::readPathRule($case['rule'] ?? null, $ruleErrors, 'invalid') !== null) {
                    $errors[] = 'ownership_path_rule_invalid_case_accepted:' . $name;
                }
            }
        }

        return array_values(array_unique($errors));
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
        if (!is_array($value) || array_is_list($value)) {
            $errors[] = $error;
            return null;
        }
        $expectedKeys = ['match', 'path'];
        $actualKeys = array_keys($value);
        sort($expectedKeys, SORT_STRING);
        sort($actualKeys, SORT_STRING);
        $path = $value['path'] ?? null;
        $match = $value['match'] ?? null;
        if (
            $actualKeys !== $expectedKeys ||
            !is_string($path) ||
            !RepoPath::isNormalized($path) ||
            !in_array($match, ['directory', 'exact_file', 'filename_prefix'], true)
        ) {
            $errors[] = $error;
            return null;
        }

        return ['path' => $path, 'match' => $match];
    }

    private static function pathRuleCovers(string $rulePath, string $match, string $candidatePath): bool
    {
        if ($match === 'directory') {
            return str_starts_with($candidatePath, $rulePath . '/');
        }

        if ($match === 'filename_prefix') {
            $ruleSeparator = strrpos($rulePath, '/');
            $candidateSeparator = strrpos($candidatePath, '/');
            $ruleDirectory = $ruleSeparator === false ? '' : substr($rulePath, 0, $ruleSeparator);
            $candidateDirectory = $candidateSeparator === false ? '' : substr($candidatePath, 0, $candidateSeparator);
            $filenamePrefix = $ruleSeparator === false ? $rulePath : substr($rulePath, $ruleSeparator + 1);
            $candidateName =
                $candidateSeparator === false ? $candidatePath : substr($candidatePath, $candidateSeparator + 1);

            return $ruleDirectory === $candidateDirectory && str_starts_with($candidateName, $filenamePrefix);
        }

        return $rulePath === $candidatePath;
    }

    public static function pathRulesOverlap(
        string $leftPath,
        string $leftMatch,
        string $rightPath,
        string $rightMatch,
    ): bool {
        if ($leftMatch === 'directory' && $rightMatch === 'directory' && $leftPath === $rightPath) {
            return true;
        }

        return self::pathRuleCovers($leftPath, $leftMatch, $rightPath) ||
            self::pathRuleCovers($rightPath, $rightMatch, $leftPath);
    }

    /** @param array{path: string, match: string} $pathRule */
    public static function pathRuleCoversChangedPath(array $pathRule, string $changedPath): bool
    {
        return self::pathRuleCovers($pathRule['path'], $pathRule['match'], $changedPath);
    }
}
