<?php

declare(strict_types=1);

namespace CiContract;

use InvalidArgumentException;

final class CheckSelection
{
    /**
     * @param array<int, string> $availableChecks
     * @param array<string, array<int, string>> $dependencies
     * @return array{
     *   requested_checks:array<int, string>,
     *   effective_checks:array<int, string>,
     *   selection_reason_by_check:array<string, string>
     * }
     */
    public static function resolve(mixed $rawChecks, array $availableChecks, array $dependencies = []): array
    {
        self::assertAvailableChecks($availableChecks);
        self::assertDependencies($availableChecks, $dependencies);

        $requestedChecks = self::normalizeRequestedChecks($rawChecks);
        if ($requestedChecks === []) {
            $requestedChecks = $availableChecks;
        }

        self::assertKnownChecks($requestedChecks, $availableChecks);

        $requestedLookup = array_fill_keys($requestedChecks, true);
        $selectedLookup = [];
        $stack = [];

        foreach ($requestedChecks as $checkId) {
            self::selectWithDependencies($checkId, $dependencies, $selectedLookup, $stack);
        }

        $effectiveChecks = [];
        $selectionReasonByCheck = [];

        foreach ($availableChecks as $checkId) {
            if (!isset($selectedLookup[$checkId])) {
                continue;
            }

            $effectiveChecks[] = $checkId;
            $selectionReasonByCheck[$checkId] = isset($requestedLookup[$checkId]) ? 'requested' : 'dependency';
        }

        return [
            'requested_checks' => $requestedChecks,
            'effective_checks' => $effectiveChecks,
            'selection_reason_by_check' => $selectionReasonByCheck,
        ];
    }

    /**
     * @param array<int, string> $availableChecks
     */
    private static function assertAvailableChecks(array $availableChecks): void
    {
        if ($availableChecks === []) {
            throw new InvalidArgumentException('At least one available check must be declared.');
        }

        $seen = [];

        foreach ($availableChecks as $index => $checkId) {
            if (!is_string($checkId) || trim($checkId) === '') {
                throw new InvalidArgumentException('Available check IDs must be non-empty strings.');
            }

            if (isset($seen[$checkId])) {
                throw new InvalidArgumentException('Duplicate available check ID: ' . $checkId);
            }

            $seen[$checkId] = $index;
        }
    }

    /**
     * @param array<int, string> $availableChecks
     * @param array<string, array<int, string>> $dependencies
     */
    private static function assertDependencies(array $availableChecks, array $dependencies): void
    {
        $knownChecks = array_fill_keys($availableChecks, true);

        foreach ($dependencies as $checkId => $dependsOn) {
            if (!isset($knownChecks[$checkId])) {
                throw new InvalidArgumentException('Dependency map references unknown check ID: ' . $checkId);
            }

            if (!is_array($dependsOn)) {
                throw new InvalidArgumentException('Dependencies for check "' . $checkId . '" must be an array.');
            }

            foreach ($dependsOn as $dependencyId) {
                if (!is_string($dependencyId) || trim($dependencyId) === '') {
                    throw new InvalidArgumentException(
                        'Dependency IDs for check "' . $checkId . '" must be non-empty strings.',
                    );
                }

                if (!isset($knownChecks[$dependencyId])) {
                    throw new InvalidArgumentException(
                        'Dependency "' . $dependencyId . '" for check "' . $checkId . '" is not declared.',
                    );
                }
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private static function normalizeRequestedChecks(mixed $rawChecks): array
    {
        if ($rawChecks === null) {
            return [];
        }

        if ($rawChecks === false) {
            throw new InvalidArgumentException('Option --checks requires a value.');
        }

        $rawValues = is_array($rawChecks) ? $rawChecks : [$rawChecks];
        $requestedChecks = [];
        $seen = [];

        foreach ($rawValues as $rawValue) {
            if (!is_scalar($rawValue)) {
                throw new InvalidArgumentException(
                    'Option --checks must be a comma-separated string or repeated string values.',
                );
            }

            foreach (explode(',', trim((string) $rawValue)) as $token) {
                $checkId = trim($token);
                if ($checkId === '') {
                    continue;
                }

                if (isset($seen[$checkId])) {
                    continue;
                }

                $requestedChecks[] = $checkId;
                $seen[$checkId] = true;
            }
        }

        if ($requestedChecks === []) {
            throw new InvalidArgumentException('Option --checks must specify at least one non-empty check ID.');
        }

        return $requestedChecks;
    }

    /**
     * @param array<int, string> $requestedChecks
     * @param array<int, string> $availableChecks
     */
    private static function assertKnownChecks(array $requestedChecks, array $availableChecks): void
    {
        $knownChecks = array_fill_keys($availableChecks, true);
        $unknownChecks = [];

        foreach ($requestedChecks as $checkId) {
            if (!isset($knownChecks[$checkId])) {
                $unknownChecks[] = $checkId;
            }
        }

        if ($unknownChecks !== []) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unknown check ID(s): %s. Allowed values: %s',
                    implode(', ', $unknownChecks),
                    implode(', ', $availableChecks),
                ),
            );
        }
    }

    /**
     * @param array<string, array<int, string>> $dependencies
     * @param array<string, bool> $selectedLookup
     * @param array<int, string> $stack
     */
    private static function selectWithDependencies(
        string $checkId,
        array $dependencies,
        array &$selectedLookup,
        array &$stack,
    ): void {
        if (isset($selectedLookup[$checkId])) {
            return;
        }

        if (in_array($checkId, $stack, true)) {
            $cycle = array_merge($stack, [$checkId]);
            throw new InvalidArgumentException('Dependency cycle detected: ' . implode(' -> ', $cycle));
        }

        $stack[] = $checkId;

        foreach ($dependencies[$checkId] ?? [] as $dependencyId) {
            self::selectWithDependencies($dependencyId, $dependencies, $selectedLookup, $stack);
        }

        array_pop($stack);
        $selectedLookup[$checkId] = true;
    }
}
