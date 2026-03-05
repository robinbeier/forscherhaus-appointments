<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use CiContract\CheckSelection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/ci/lib/CheckSelection.php';

final class CheckSelectionTest extends TestCase
{
    public function testResolveDefaultsToAllChecksWhenSelectionIsMissing(): void
    {
        $selection = CheckSelection::resolve(null, ['alpha', 'beta', 'gamma']);

        self::assertSame(['alpha', 'beta', 'gamma'], $selection['requested_checks']);
        self::assertSame(['alpha', 'beta', 'gamma'], $selection['effective_checks']);
        self::assertSame(
            [
                'alpha' => 'requested',
                'beta' => 'requested',
                'gamma' => 'requested',
            ],
            $selection['selection_reason_by_check'],
        );
    }

    public function testResolveParsesRepeatedAndCommaSeparatedChecksWithDependenciesInRegistryOrder(): void
    {
        $selection = CheckSelection::resolve(
            ['delta,gamma', 'delta'],
            ['alpha', 'beta', 'gamma', 'delta'],
            [
                'gamma' => ['alpha'],
                'delta' => ['beta'],
            ],
        );

        self::assertSame(['delta', 'gamma'], $selection['requested_checks']);
        self::assertSame(['alpha', 'beta', 'gamma', 'delta'], $selection['effective_checks']);
        self::assertSame(
            [
                'alpha' => 'dependency',
                'beta' => 'dependency',
                'gamma' => 'requested',
                'delta' => 'requested',
            ],
            $selection['selection_reason_by_check'],
        );
    }

    public function testResolveRejectsUnknownChecks(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown check ID(s): omega.');

        CheckSelection::resolve('omega', ['alpha', 'beta']);
    }

    public function testResolveRejectsMissingChecksValueWhenOptionWasProvided(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Option --checks requires a value.');

        CheckSelection::resolve(false, ['alpha']);
    }

    public function testResolveRejectsDependencyCycles(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Dependency cycle detected: alpha -> beta -> alpha');

        CheckSelection::resolve(
            'alpha',
            ['alpha', 'beta'],
            [
                'alpha' => ['beta'],
                'beta' => ['alpha'],
            ],
        );
    }
}
