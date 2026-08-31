<?php

declare(strict_types=1);

namespace Forscherhaus\AgentHarness;

require_once __DIR__ . '/RepoPath.php';

final class ParallelWorkContract
{
    /**
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $policy
     * @param array<string, mixed> $ownershipMap
     * @return list<string>
     */
    public static function validate(array $manifest, array $policy, array $ownershipMap = []): array
    {
        $errors = [];

        foreach (
            [
                'local_implementation_only',
                'requires_common_base_sha',
                'requires_disjoint_ownership',
                'external_mutations_remain_serial',
                'requires_semantic_independence_attestation',
            ]
            as $requiredPolicy
        ) {
            if (($policy[$requiredPolicy] ?? null) !== true) {
                $errors[] = 'invalid_policy_requirement:' . $requiredPolicy;
            }
        }

        if (($manifest['schema_version'] ?? null) !== 1) {
            $errors[] = 'unsupported_schema_version';
        }

        $baseSha = $manifest['base_sha'] ?? null;
        if (!is_string($baseSha) || preg_match('/^[a-f0-9]{40}$/D', $baseSha) !== 1) {
            $errors[] = 'invalid_base_sha';
        }

        $primaryId = $manifest['primary_id'] ?? null;
        if (!is_string($primaryId) || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $primaryId) !== 1) {
            $errors[] = 'invalid_primary_id';
        }

        $semanticIndependence = $manifest['semantic_independence'] ?? null;
        if (!is_array($semanticIndependence) || array_is_list($semanticIndependence)) {
            $errors[] = 'invalid_semantic_independence';
        } else {
            $expectedSemanticKeys = ['coordination_required', 'cross_lane_dependencies', 'shared_contracts'];
            $actualSemanticKeys = array_keys($semanticIndependence);
            sort($expectedSemanticKeys, SORT_STRING);
            sort($actualSemanticKeys, SORT_STRING);
            if ($actualSemanticKeys !== $expectedSemanticKeys) {
                $errors[] = 'invalid_semantic_independence';
            }
            if (($semanticIndependence['shared_contracts'] ?? null) !== []) {
                $errors[] = 'shared_contract_requires_serial_work';
            }
            if (($semanticIndependence['cross_lane_dependencies'] ?? null) !== []) {
                $errors[] = 'cross_lane_dependency_requires_serial_work';
            }
            if (($semanticIndependence['coordination_required'] ?? null) !== false) {
                $errors[] = 'semantic_coordination_requires_serial_work';
            }
        }

        $approvedComponentIds = self::readApprovedComponentIds($manifest, $errors);
        $canonicalComponents = self::readCanonicalComponents($ownershipMap, $errors);
        $requiredComponentIds = [];

        $primaryOwnedPrefixes = $policy['primary_owned_path_prefixes'] ?? null;
        if (!is_array($primaryOwnedPrefixes) || !array_is_list($primaryOwnedPrefixes)) {
            $errors[] = 'invalid_policy_primary_owned_path_prefixes';
            $primaryOwnedPrefixes = [];
        }

        $lanes = $manifest['lanes'] ?? null;
        if (!is_array($lanes) || !array_is_list($lanes)) {
            return array_values(array_unique([...$errors, 'invalid_lanes']));
        }

        $maximumWriterLanes = $policy['max_local_writer_lanes'] ?? null;
        if (!is_int($maximumWriterLanes) || $maximumWriterLanes < 1) {
            $errors[] = 'invalid_policy_max_local_writer_lanes';
        } elseif (count($lanes) > $maximumWriterLanes) {
            $errors[] = 'too_many_writer_lanes';
        }

        $laneIds = [];
        $ownedPathRules = [];
        foreach ($lanes as $index => $lane) {
            if (!is_array($lane)) {
                $errors[] = 'invalid_lane:' . $index;
                continue;
            }

            $laneId = $lane['id'] ?? null;
            if (!is_string($laneId) || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $laneId) !== 1) {
                $errors[] = 'invalid_lane_id:' . $index;
            } elseif (isset($laneIds[$laneId]) || $laneId === $primaryId) {
                $errors[] = 'duplicate_lane_id:' . $laneId;
            } else {
                $laneIds[$laneId] = true;
            }

            if (($lane['role'] ?? null) !== ($policy['writer_role'] ?? null)) {
                $errors[] = 'invalid_writer_role:' . $index;
            }

            if (($lane['base_sha'] ?? null) !== $baseSha) {
                $errors[] = 'base_sha_mismatch:' . $index;
            }

            if (($lane['external_mutations'] ?? null) !== []) {
                $errors[] = 'external_mutation_not_primary:' . $index;
            }

            $ownership = $lane['ownership'] ?? null;
            if (!is_array($ownership) || !array_is_list($ownership) || $ownership === []) {
                $errors[] = 'invalid_ownership:' . $index;
                continue;
            }

            foreach ($ownership as $ruleIndex => $ownershipRule) {
                $pathRule = self::readPathRule(
                    $ownershipRule,
                    $errors,
                    'invalid_ownership_path:' . $index . ':' . $ruleIndex,
                );
                if ($pathRule === null) {
                    continue;
                }
                $path = $pathRule['path'];
                $match = $pathRule['match'];

                foreach ($primaryOwnedPrefixes as $primaryOwnedPrefix) {
                    if (!is_string($primaryOwnedPrefix) || !RepoPath::isNormalized($primaryOwnedPrefix)) {
                        $errors[] = 'invalid_policy_primary_owned_path_prefix';
                        continue;
                    }
                    if (self::pathRulesOverlap($path, $match, $primaryOwnedPrefix, 'exact_or_descendants')) {
                        $errors[] = 'primary_owned_path:' . $index . ':' . $primaryOwnedPrefix;
                    }
                }

                foreach ($canonicalComponents as $componentId => $component) {
                    foreach ($component['path_rules'] as $canonicalRule) {
                        if (self::pathRulesOverlap($path, $match, $canonicalRule['path'], $canonicalRule['match'])) {
                            $requiredComponentIds[$componentId] = true;
                        }
                    }
                }

                foreach ($ownedPathRules as $ownedPathRule) {
                    if (self::pathRulesOverlap($path, $match, $ownedPathRule['path'], $ownedPathRule['match'])) {
                        $errors[] = 'ownership_overlap:' . $ownedPathRule['owner'] . ':' . $index;
                    }
                }
                $ownedPathRules[] = ['path' => $path, 'match' => $match, 'owner' => (string) $index];
            }
        }

        foreach (array_keys($requiredComponentIds) as $componentId) {
            if (!isset($approvedComponentIds[$componentId])) {
                $errors[] = 'missing_primary_component_approval:' . $componentId;
            }
        }
        foreach (array_keys($approvedComponentIds) as $componentId) {
            if (!isset($canonicalComponents[$componentId])) {
                $errors[] = 'unknown_primary_component_approval:' . $componentId;
            } elseif (!isset($requiredComponentIds[$componentId])) {
                $errors[] = 'unused_primary_component_approval:' . $componentId;
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param array<string, mixed> $manifest
     * @param list<string> $changedPaths
     * @return list<string>
     */
    public static function validateLaneChanges(array $manifest, string $laneId, array $changedPaths): array
    {
        $lanes = $manifest['lanes'] ?? null;
        if (!is_array($lanes) || !array_is_list($lanes)) {
            return ['invalid_lanes_for_verification'];
        }

        $matchingLanes = array_values(
            array_filter($lanes, static fn(mixed $lane): bool => is_array($lane) && ($lane['id'] ?? null) === $laneId),
        );
        if (count($matchingLanes) !== 1) {
            return ['unknown_lane_for_verification:' . $laneId];
        }

        $errors = [];
        $ownership = $matchingLanes[0]['ownership'] ?? null;
        if (!is_array($ownership) || !array_is_list($ownership) || $ownership === []) {
            return ['invalid_lane_ownership_for_verification:' . $laneId];
        }

        $pathRules = [];
        foreach ($ownership as $ruleIndex => $ownershipRule) {
            $pathRule = self::readPathRule(
                $ownershipRule,
                $errors,
                'invalid_lane_ownership_for_verification:' . $laneId . ':' . $ruleIndex,
            );
            if ($pathRule !== null) {
                $pathRules[] = $pathRule;
            }
        }

        foreach (array_values(array_unique($changedPaths)) as $changedPath) {
            if (!is_string($changedPath) || !RepoPath::isNormalized($changedPath)) {
                $errors[] = 'invalid_changed_path:' . $laneId;
                continue;
            }

            $covered = false;
            foreach ($pathRules as $pathRule) {
                if (self::pathRuleCovers($pathRule['path'], $pathRule['match'], $changedPath)) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                $errors[] = 'ownership_violation:' . $laneId . ':' . $changedPath;
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param array<string, mixed> $manifest
     * @param list<string> $errors
     * @return array<string, true>
     */
    private static function readApprovedComponentIds(array $manifest, array &$errors): array
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
    private static function readCanonicalComponents(array $ownershipMap, array &$errors): array
    {
        $prefixMatchOverrides = $ownershipMap['prefix_match_overrides'] ?? [];
        if (!is_array($prefixMatchOverrides) || array_is_list($prefixMatchOverrides)) {
            $errors[] = 'invalid_canonical_prefix_match_overrides';
            $prefixMatchOverrides = [];
        }
        foreach ($prefixMatchOverrides as $path => $match) {
            if (!is_string($path) || !RepoPath::isNormalized($path) || $match !== 'filename_stem') {
                $errors[] = 'invalid_canonical_prefix_match_override';
                unset($prefixMatchOverrides[$path]);
            }
        }

        $components = $ownershipMap['components'] ?? null;
        if (!is_array($components) || !array_is_list($components)) {
            $errors[] = 'invalid_canonical_ownership_map';
            return [];
        }

        $canonical = [];
        $usedPrefixMatchOverrides = [];
        foreach ($components as $component) {
            if (!is_array($component)) {
                $errors[] = 'invalid_canonical_ownership_component';
                continue;
            }
            $componentId = $component['component_id'] ?? null;
            $folderPrefixes = $component['folder_prefixes'] ?? null;
            $ownershipMode = $component['ownership_mode'] ?? null;
            $manualApprovalRequired = $component['manual_approval_required'] ?? null;
            if (!is_string($componentId) || !is_array($folderPrefixes) || !array_is_list($folderPrefixes)) {
                $errors[] = 'invalid_canonical_ownership_component';
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
            $requiresApproval = $ownershipMode === 'single-owner' || $manualApprovalRequired;
            if (!$requiresApproval) {
                continue;
            }
            if (isset($canonical[$componentId])) {
                $errors[] = 'duplicate_canonical_component_id:' . $componentId;
                continue;
            }
            $pathRules = [];
            foreach ($folderPrefixes as $folderPrefix) {
                $normalizedPrefix = is_string($folderPrefix) ? rtrim($folderPrefix, '/') : '';
                if (!RepoPath::isNormalized($normalizedPrefix)) {
                    $errors[] = 'invalid_canonical_ownership_prefix:' . $componentId;
                    continue 2;
                }
                $match = $prefixMatchOverrides[$normalizedPrefix] ?? 'exact_or_descendants';
                if ($match === 'filename_stem') {
                    $usedPrefixMatchOverrides[$normalizedPrefix] = true;
                }
                $pathRules[] = ['path' => $normalizedPrefix, 'match' => $match];
            }
            $canonical[$componentId] = ['path_rules' => $pathRules];
        }

        foreach (array_keys($prefixMatchOverrides) as $prefixMatchOverride) {
            if (!isset($usedPrefixMatchOverrides[$prefixMatchOverride])) {
                $errors[] = 'unused_canonical_prefix_match_override:' . $prefixMatchOverride;
            }
        }

        return $canonical;
    }

    /**
     * @param list<string> $errors
     * @return array{path: string, match: string}|null
     */
    private static function readPathRule(mixed $value, array &$errors, string $error): ?array
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
            !in_array($match, ['exact_or_descendants', 'filename_stem'], true)
        ) {
            $errors[] = $error;
            return null;
        }

        return ['path' => $path, 'match' => $match];
    }

    private static function pathRulesOverlap(
        string $leftPath,
        string $leftMatch,
        string $rightPath,
        string $rightMatch,
    ): bool {
        return self::pathRuleCovers($leftPath, $leftMatch, $rightPath) ||
            self::pathRuleCovers($rightPath, $rightMatch, $leftPath);
    }

    private static function pathRuleCovers(string $rulePath, string $match, string $candidatePath): bool
    {
        if ($match === 'filename_stem') {
            return str_starts_with($candidatePath, $rulePath);
        }

        return $rulePath === $candidatePath || str_starts_with($candidatePath, $rulePath . '/');
    }
}
