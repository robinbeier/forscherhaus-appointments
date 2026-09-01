<?php

declare(strict_types=1);

namespace Forscherhaus\AgentHarness;

require_once __DIR__ . '/RepoPath.php';
require_once __DIR__ . '/ParallelWorkOwnershipContract.php';
require_once __DIR__ . '/ParallelWorkPolicyContract.php';

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
        $policyInspection = ParallelWorkPolicyContract::inspect($policy);
        $errors = $policyInspection['errors'];

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

        $approvedComponentIds = ParallelWorkOwnershipContract::readApprovedComponentIds($manifest, $errors);
        $canonicalComponents = ParallelWorkOwnershipContract::readCanonicalComponents($ownershipMap, $errors);
        $requiredComponentIds = [];
        $primaryOwnedPrefixes = $policyInspection['primary_owned_path_prefixes'];

        $lanes = $manifest['lanes'] ?? null;
        if (!is_array($lanes) || !array_is_list($lanes)) {
            return array_values(array_unique([...$errors, 'invalid_lanes']));
        }

        $maximumWriterLanes = $policyInspection['max_local_writer_lanes'];
        if ($maximumWriterLanes !== null && count($lanes) > $maximumWriterLanes) {
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

            if (($lane['role'] ?? null) !== $policyInspection['writer_role']) {
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
                $pathRule = ParallelWorkOwnershipContract::readPathRule(
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
                    if (
                        ParallelWorkOwnershipContract::pathRulesOverlap($path, $match, $primaryOwnedPrefix, 'directory')
                    ) {
                        $errors[] = 'primary_owned_path:' . $index . ':' . $primaryOwnedPrefix;
                    }
                }

                foreach ($canonicalComponents as $componentId => $component) {
                    foreach ($component['path_rules'] as $canonicalRule) {
                        if (
                            ParallelWorkOwnershipContract::pathRulesOverlap(
                                $path,
                                $match,
                                $canonicalRule['path'],
                                $canonicalRule['match'],
                            )
                        ) {
                            $requiredComponentIds[$componentId] = true;
                        }
                    }
                }

                foreach ($ownedPathRules as $ownedPathRule) {
                    if (
                        ParallelWorkOwnershipContract::pathRulesOverlap(
                            $path,
                            $match,
                            $ownedPathRule['path'],
                            $ownedPathRule['match'],
                        )
                    ) {
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
            $pathRule = ParallelWorkOwnershipContract::readPathRule(
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
                if (ParallelWorkOwnershipContract::pathRuleCoversChangedPath($pathRule, $changedPath)) {
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
}
