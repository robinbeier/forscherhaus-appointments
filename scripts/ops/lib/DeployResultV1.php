<?php

declare(strict_types=1);

namespace Ops;

use JsonException;
use RuntimeException;

/**
 * Validates candidate bytes only. A Host Runner must additionally bind them to
 * an independently observed terminal child result and persist their exact byte
 * hash durably; decode success alone is never an authoritative child verdict.
 */
final class DeployResultV1
{
    public const SCHEMA = 'deploy_result.v1';

    public const OUTCOME_EXIT_CODES = [
        'succeeded' => 0,
        'failed_pre_switch' => 30,
        'internal_rollback_succeeded' => 30,
        'rollback_failed_or_unverifiable' => 31,
        'switch_recovery_required' => 32,
        'interrupted_pre_switch' => 143,
    ];

    /** @return array{schema:string,outcome:string,exit_code:int} */
    public static function create(string $outcome, int $exitCode): array
    {
        $receipt = [
            'schema' => self::SCHEMA,
            'outcome' => $outcome,
            'exit_code' => $exitCode,
        ];
        self::validate($receipt);

        return $receipt;
    }

    /** @param array<string,mixed> $receipt */
    public static function validate(array $receipt): void
    {
        if (array_is_list($receipt)) {
            throw new RuntimeException('deploy result must be an object');
        }

        $actualKeys = array_keys($receipt);
        $expectedKeys = ['schema', 'outcome', 'exit_code'];
        sort($actualKeys);
        sort($expectedKeys);
        if ($actualKeys !== $expectedKeys) {
            throw new RuntimeException('deploy result contains missing or unexpected fields');
        }
        if ($receipt['schema'] !== self::SCHEMA) {
            throw new RuntimeException('deploy result schema is invalid');
        }
        if (!is_string($receipt['outcome']) || !array_key_exists($receipt['outcome'], self::OUTCOME_EXIT_CODES)) {
            throw new RuntimeException('deploy result outcome is invalid');
        }
        if (!is_int($receipt['exit_code']) || self::OUTCOME_EXIT_CODES[$receipt['outcome']] !== $receipt['exit_code']) {
            throw new RuntimeException('deploy result outcome and exit_code are inconsistent');
        }
    }

    /** @return array{schema:string,outcome:string,exit_code:int} */
    public static function decode(string $encoded): array
    {
        if ($encoded === '' || strlen($encoded) > 512 || str_contains($encoded, "\0")) {
            throw new RuntimeException('deploy result encoding is invalid');
        }

        try {
            $receipt = json_decode($encoded, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('deploy result JSON is invalid', 0, $exception);
        }
        if (!is_array($receipt)) {
            throw new RuntimeException('deploy result must be an object');
        }
        self::validate($receipt);
        if (!hash_equals(self::canonicalJson($receipt), $encoded)) {
            throw new RuntimeException('deploy result is not canonical');
        }

        /** @var array{schema:string,outcome:string,exit_code:int} $receipt */
        return $receipt;
    }

    /** @param array<string,mixed> $receipt */
    public static function canonicalJson(array $receipt): string
    {
        self::validate($receipt);
        $canonical = [
            'schema' => $receipt['schema'],
            'outcome' => $receipt['outcome'],
            'exit_code' => $receipt['exit_code'],
        ];

        try {
            return json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('deploy result cannot be encoded', 0, $exception);
        }
    }

    /** @return array{status:string,invocation_count:int,exit_code:int,rollback_outcome:string} */
    public static function deployEvidence(string $outcome): array
    {
        $exitCode = self::OUTCOME_EXIT_CODES[$outcome] ?? null;
        if ($exitCode === null) {
            throw new RuntimeException('deploy result outcome is invalid');
        }

        [$status, $rollbackOutcome] = match ($outcome) {
            'succeeded' => ['succeeded', 'not_run'],
            'failed_pre_switch', 'interrupted_pre_switch' => ['failed', 'not_run'],
            'internal_rollback_succeeded' => ['failed', 'succeeded'],
            'rollback_failed_or_unverifiable' => ['failed', 'failed'],
            'switch_recovery_required' => ['failed', 'recovery_required'],
        };

        return [
            'status' => $status,
            'invocation_count' => 1,
            'exit_code' => $exitCode,
            'rollback_outcome' => $rollbackOutcome,
        ];
    }
}
