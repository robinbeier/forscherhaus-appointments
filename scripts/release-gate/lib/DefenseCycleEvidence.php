<?php

declare(strict_types=1);

namespace ReleaseGate;

use InvalidArgumentException;

/**
 * Append-only evidence report for the six ROB-547 security invariants.
 *
 * An observation is retained exactly as recorded.  The cycle is verified only
 * when every invariant has a complete, clean production observation and no
 * earlier observation recorded a failure or an unsafe result.
 */
final class DefenseCycleEvidence
{
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_FAILED = 'failed';
    public const STATUS_NOT_SAFELY_TESTABLE = 'not_safely_testable';

    public const COVERAGE_COMPLETE = 'complete';
    public const COVERAGE_PARTIAL = 'partial';

    /** @var list<string> */
    public const INVARIANTS = ['ROB-538', 'ROB-548', 'ROB-549', 'ROB-550', 'ROB-551', 'ROB-552'];

    /** @var list<array<string, mixed>> */
    private array $observations = [];

    public function __construct(
        private readonly string $expectedCommit,
        private readonly string $targetEnvironment = 'production',
    ) {
        self::assertCommit($expectedCommit);
        self::assertEnvironment($targetEnvironment);
    }

    /**
     * @param array<string, mixed> $observation
     */
    public function append(array $observation): void
    {
        $record = [
            'invariant' => $observation['invariant'] ?? null,
            'commit' => $observation['commit'] ?? null,
            'environment' => $observation['environment'] ?? null,
            'method' => $observation['method'] ?? null,
            'expected' => $observation['expected'] ?? null,
            'observed' => $observation['observed'] ?? null,
            'status' => $observation['status'] ?? null,
            'coverage' => $observation['coverage'] ?? self::COVERAGE_PARTIAL,
            'gap' => $observation['gap'] ?? null,
            'cleanup' => $observation['cleanup'] ?? false,
        ];

        // Copying the normalized record into the list makes each append a
        // historical fact; subsequent caller mutations cannot rewrite it.
        $this->observations[] = $record;
    }

    /** @param array<string, mixed> $observation */
    public function addObservation(array $observation): void
    {
        $this->append($observation);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $invalid = [];
        $hasHistoricalFailure = false;
        $completeByInvariant = [];

        foreach ($this->observations as $index => $observation) {
            $valid = $this->isValid($observation);
            if (!$valid) {
                $invalid[] = $index;
            }
            if (in_array($observation['status'], [self::STATUS_FAILED, self::STATUS_NOT_SAFELY_TESTABLE], true)) {
                $hasHistoricalFailure = true;
            }
            if ($valid && $this->isCompleteVerifiedObservation($observation)) {
                $completeByInvariant[$observation['invariant']] = true;
            }
        }

        $missing = array_values(array_diff(self::INVARIANTS, array_keys($completeByInvariant)));
        $verified = $this->observations !== [] && $invalid === [] && !$hasHistoricalFailure && $missing === [];

        return [
            'expected_commit' => $this->expectedCommit,
            'target_environment' => $this->targetEnvironment,
            'observations' => $this->observations,
            'coverage' => $missing === [] ? self::COVERAGE_COMPLETE : self::COVERAGE_PARTIAL,
            'overall_status' => $verified ? self::STATUS_VERIFIED : self::STATUS_NOT_SAFELY_TESTABLE,
            'missing_invariants' => $missing,
            'invalid_observations' => $invalid,
        ];
    }

    public function isVerified(): bool
    {
        return $this->toArray()['overall_status'] === self::STATUS_VERIFIED;
    }

    /** @param array<string, mixed> $observation */
    private function isValid(array $observation): bool
    {
        return in_array($observation['invariant'], self::INVARIANTS, true) &&
            is_string($observation['commit']) &&
            $observation['commit'] === $this->expectedCommit &&
            in_array($observation['environment'], ['source', 'isolated', 'production'], true) &&
            is_string($observation['method']) &&
            trim($observation['method']) !== '' &&
            is_string($observation['expected']) &&
            is_string($observation['observed']) &&
            in_array(
                $observation['status'],
                [self::STATUS_VERIFIED, self::STATUS_FAILED, self::STATUS_NOT_SAFELY_TESTABLE],
                true,
            ) &&
            in_array($observation['coverage'], [self::COVERAGE_COMPLETE, self::COVERAGE_PARTIAL], true) &&
            is_string($observation['gap']) &&
            is_bool($observation['cleanup']) &&
            $observation['cleanup'];
    }

    /** @param array<string, mixed> $observation */
    private function isCompleteVerifiedObservation(array $observation): bool
    {
        return $observation['environment'] === $this->targetEnvironment &&
            $observation['status'] === self::STATUS_VERIFIED &&
            $observation['coverage'] === self::COVERAGE_COMPLETE &&
            trim($observation['expected']) !== '' &&
            trim($observation['observed']) !== '' &&
            trim($observation['gap']) === '' &&
            $observation['cleanup'] === true;
    }

    private static function assertCommit(string $commit): void
    {
        if (preg_match('/\A[a-f0-9]{40}\z/', $commit) !== 1) {
            throw new InvalidArgumentException('expectedCommit must be exactly 40 lowercase hexadecimal characters.');
        }
    }

    private static function assertEnvironment(string $environment): void
    {
        if (!in_array($environment, ['source', 'isolated', 'production'], true)) {
            throw new InvalidArgumentException('Environment must be source, isolated, or production.');
        }
    }
}
