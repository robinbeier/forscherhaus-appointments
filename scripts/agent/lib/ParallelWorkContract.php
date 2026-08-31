<?php

declare(strict_types=1);

namespace Forscherhaus\AgentHarness;

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

        $filenameStemPrefixSuffix = $policy['filename_stem_prefix_suffix'] ?? null;
        if ($filenameStemPrefixSuffix !== '_') {
            $errors[] = 'invalid_policy_filename_stem_prefix_suffix';
            $filenameStemPrefixSuffix = '';
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
        $ownedPaths = [];
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

            foreach ($ownership as $path) {
                if (!is_string($path) || !self::isNormalizedRepoPath($path)) {
                    $errors[] = 'invalid_ownership_path:' . $index;
                    continue;
                }

                foreach ($primaryOwnedPrefixes as $primaryOwnedPrefix) {
                    if (!is_string($primaryOwnedPrefix) || !self::isNormalizedRepoPath($primaryOwnedPrefix)) {
                        $errors[] = 'invalid_policy_primary_owned_path_prefix';
                        continue;
                    }
                    if (self::pathsOverlap($path, $primaryOwnedPrefix, $filenameStemPrefixSuffix)) {
                        $errors[] = 'primary_owned_path:' . $index . ':' . $primaryOwnedPrefix;
                    }
                }

                foreach ($canonicalComponents as $componentId => $component) {
                    foreach ($component['folder_prefixes'] as $folderPrefix) {
                        if (self::pathMatchesCanonicalPrefix($path, $folderPrefix, $filenameStemPrefixSuffix)) {
                            $requiredComponentIds[$componentId] = true;
                        }
                    }
                }

                foreach ($ownedPaths as $ownedPath => $owner) {
                    if (self::pathsOverlap($path, $ownedPath, $filenameStemPrefixSuffix)) {
                        $errors[] = 'ownership_overlap:' . $owner . ':' . $index;
                    }
                }
                $ownedPaths[$path] = (string) $index;
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
     * @return array<string, array{folder_prefixes: list<string>}>
     */
    private static function readCanonicalComponents(array $ownershipMap, array &$errors): array
    {
        $components = $ownershipMap['components'] ?? null;
        if (!is_array($components) || !array_is_list($components)) {
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
            $normalizedPrefixes = [];
            foreach ($folderPrefixes as $folderPrefix) {
                $normalizedPrefix = is_string($folderPrefix) ? rtrim($folderPrefix, '/') : '';
                if (!self::isNormalizedRepoPath($normalizedPrefix)) {
                    $errors[] = 'invalid_canonical_ownership_prefix:' . $componentId;
                    continue 2;
                }
                $normalizedPrefixes[] = str_ends_with((string) $folderPrefix, '/')
                    ? $normalizedPrefix . '/'
                    : $normalizedPrefix;
            }
            $canonical[$componentId] = ['folder_prefixes' => $normalizedPrefixes];
        }

        return $canonical;
    }

    private static function isNormalizedRepoPath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_ends_with($path, '/')) {
            return false;
        }

        if (str_contains($path, '\\') || preg_match('/[*?\[\]]/', $path) === 1) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private static function pathsOverlap(string $left, string $right, string $filenameStemPrefixSuffix): bool
    {
        return self::pathCovers($left, $right, $filenameStemPrefixSuffix) ||
            self::pathCovers($right, $left, $filenameStemPrefixSuffix);
    }

    private static function pathMatchesCanonicalPrefix(
        string $lanePath,
        string $canonicalPrefix,
        string $filenameStemPrefixSuffix,
    ): bool {
        $normalizedPrefix = rtrim($canonicalPrefix, '/');
        return self::pathsOverlap($lanePath, $normalizedPrefix, $filenameStemPrefixSuffix);
    }

    private static function pathCovers(string $prefix, string $path, string $filenameStemPrefixSuffix): bool
    {
        if ($prefix === $path || str_starts_with($path, $prefix . '/')) {
            return true;
        }

        return $filenameStemPrefixSuffix !== '' &&
            str_ends_with(basename($prefix), $filenameStemPrefixSuffix) &&
            str_starts_with($path, $prefix);
    }
}
