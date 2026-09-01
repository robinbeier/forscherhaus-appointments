<?php

declare(strict_types=1);

namespace Forscherhaus\AgentHarness;

final class ParallelWorkPolicyContract
{
    /**
     * @param array<string, mixed> $policy
     * @return array{
     *     errors: list<string>,
     *     primary_owned_path_rules: list<mixed>,
     *     max_local_writer_lanes: int|null,
     *     writer_role: string|null
     * }
     */
    public static function inspect(array $policy): array
    {
        $errors = [];

        if (($policy['ownership_map_schema_version'] ?? null) !== 3) {
            $errors[] = 'invalid_policy_ownership_map_schema_version';
        }
        if (($policy['ownership_rule_format'] ?? null) !== 'explicit_path_and_match_objects') {
            $errors[] = 'invalid_policy_ownership_rule_format';
        }
        if (($policy['ownership_rule_contract'] ?? null) !== '.codex/contracts/ownership-path-rules.json') {
            $errors[] = 'invalid_policy_ownership_rule_contract';
        }

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

        $primaryOwnedPathRules = $policy['primary_owned_path_rules'] ?? null;
        if (
            !is_array($primaryOwnedPathRules) ||
            !array_is_list($primaryOwnedPathRules) ||
            $primaryOwnedPathRules === []
        ) {
            $errors[] = 'invalid_policy_primary_owned_path_rules';
            $primaryOwnedPathRules = [];
        }

        $maximumWriterLanes = $policy['max_local_writer_lanes'] ?? null;
        if (!is_int($maximumWriterLanes) || $maximumWriterLanes < 1) {
            $errors[] = 'invalid_policy_max_local_writer_lanes';
            $maximumWriterLanes = null;
        }

        $writerRole = $policy['writer_role'] ?? null;
        if (!is_string($writerRole) || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $writerRole) !== 1) {
            $errors[] = 'invalid_policy_writer_role';
            $writerRole = null;
        }

        return [
            'errors' => array_values(array_unique($errors)),
            'primary_owned_path_rules' => $primaryOwnedPathRules,
            'max_local_writer_lanes' => $maximumWriterLanes,
            'writer_role' => $writerRole,
        ];
    }
}
