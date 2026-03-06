<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReleaseGate\ZeroSurpriseProfile;

require_once __DIR__ . '/../../../scripts/release-gate/lib/ZeroSurpriseProfile.php';

class ZeroSurpriseProfileTest extends TestCase
{
    public function testResolveLoadsNamedProfileAndResolvesTrailingDaysWindow(): void
    {
        $resolved = ZeroSurpriseProfile::resolve('school-day-default', new DateTimeImmutable('2026-03-20T08:00:00Z'));

        $this->assertSame('school-day-default', $resolved['name']);
        $this->assertSame('Europe/Berlin', $resolved['timezone']);
        $this->assertSame('2026-02-18', $resolved['start_date']);
        $this->assertSame('2026-03-20', $resolved['end_date']);
        $this->assertSame(14, $resolved['booking_search_days']);
        $this->assertSame(1, $resolved['retry_count']);
        $this->assertSame(30000, $resolved['max_pdf_duration_ms']);
    }

    public function testResolveHonorsTimezoneOverrideWhenDerivingWindow(): void
    {
        $resolved = ZeroSurpriseProfile::resolve(
            'school-day-default',
            new DateTimeImmutable('2026-03-20T23:30:00Z'),
            'Pacific/Auckland',
        );

        $this->assertSame('Pacific/Auckland', $resolved['timezone']);
        $this->assertSame('2026-02-19', $resolved['start_date']);
        $this->assertSame('2026-03-21', $resolved['end_date']);
    }

    public function testLoadDefinitionFailsForUnknownProfile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown zero-surprise profile');

        ZeroSurpriseProfile::loadDefinition('missing-profile');
    }
}
