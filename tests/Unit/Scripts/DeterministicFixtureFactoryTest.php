<?php

namespace Tests\Unit\Scripts;

use CiContract\DeterministicFixtureFactory;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/ci/lib/DeterministicFixtureFactory.php';
require_once __DIR__ . '/../../../scripts/ci/lib/CanarySlotSearchPolicy.php';
require_once __DIR__ . '/../../../scripts/release-gate/lib/GateAssertions.php';

final class DeterministicFixtureFactoryTest extends TestCase
{
    public function testBookingCustomerPayloadUsesValidatorSafeReservedEmailDomain(): void
    {
        $factory = new DeterministicFixtureFactory('ci-write-20260314T000000Z-aaaa', 30, 'Europe/Berlin');

        $payload = $factory->createBookingCustomerPayload();

        $this->assertArrayHasKey('email', $payload);
        $this->assertStringEndsWith('@example.org', (string) $payload['email']);
        $this->assertNotFalse(filter_var((string) $payload['email'], FILTER_VALIDATE_EMAIL));
        $this->assertLessThanOrEqual(254, strlen((string) $payload['email']));
    }

    public function testAvailableHoursRetryDecisionOnlyRetries429BeforeFinalAttempt(): void
    {
        $reflection = new \ReflectionMethod(DeterministicFixtureFactory::class, 'shouldRetryAvailableHoursStatus');
        $reflection->setAccessible(true);

        $this->assertTrue($reflection->invoke(null, 429, 1, 3));
        $this->assertTrue($reflection->invoke(null, 429, 2, 3));
        $this->assertFalse($reflection->invoke(null, 429, 3, 3));
        $this->assertFalse($reflection->invoke(null, 500, 1, 3));
    }

    public function testBookingWindowUsesConfiguredTimezoneAtTheUtcDayBoundary(): void
    {
        $factory = new DeterministicFixtureFactory('ci-write-test', 35, 'Europe/Berlin');
        $windowStart = new \ReflectionMethod(DeterministicFixtureFactory::class, 'bookingWindowStart');

        $beforeBerlinMidnight = new \DateTimeImmutable('2026-09-25T21:30:00+00:00');
        $afterBerlinMidnight = new \DateTimeImmutable('2026-09-25T22:30:00+00:00');

        $this->assertSame(
            '2026-09-26 00:00:00+02:00',
            $windowStart->invoke($factory, $beforeBerlinMidnight)->format('Y-m-d H:i:sP'),
        );
        $this->assertSame(
            '2026-09-27 00:00:00+02:00',
            $windowStart->invoke($factory, $afterBerlinMidnight)->format('Y-m-d H:i:sP'),
        );
    }

    public function testExplicitBookingStartDateWinsAcrossMidnightBoundary(): void
    {
        $factory = new DeterministicFixtureFactory('ci-write-test', 35, 'Europe/Berlin', false, '2026-10-25');
        $windowStart = new \ReflectionMethod(DeterministicFixtureFactory::class, 'resolveBookingWindowStart');

        self::assertSame(
            '2026-10-25 00:00:00+02:00',
            $windowStart->invoke($factory, new \DateTimeImmutable('2026-10-24T22:30:00+00:00'))->format('Y-m-d H:i:sP'),
        );
    }

    public function testExplicitBookingStartDateRejectsInvalidCalendarDate(): void
    {
        $this->expectException(\ReleaseGate\GateAssertionException::class);
        new DeterministicFixtureFactory('ci-write-test', 35, 'Europe/Berlin', false, '2026-02-30');
    }

    public function testCanaryPairRequiresExactOwnedProviderAndServicePair(): void
    {
        $factory = new DeterministicFixtureFactory('ci-write-test', 2, 'UTC', true);
        $this->assertSame(
            ['provider_id' => 7, 'service_id' => 8],
            $factory->resolveCanaryPair(
                [['provider_id' => 1, 'service_id' => 2], ['provider_id' => 7, 'service_id' => 8]],
                7,
                8,
            ),
        );
        $this->expectException(\ReleaseGate\GateAssertionException::class);
        $factory->resolveCanaryPair([['provider_id' => 7, 'service_id' => 9]], 7, 8);
    }

    public function testCanaryCustomerUsesSyntheticDomain(): void
    {
        $factory = new DeterministicFixtureFactory('ci-write-test', 2, 'UTC', true);
        $this->assertStringEndsWith('@synthetic.invalid', $factory->createBookingCustomerPayload()['email']);
    }

    public function testProductHorizonIsOnlyUsedForDiscoveryNotReplay(): void
    {
        self::assertSame(90, \CiContract\CanarySlotSearchPolicy::productFutureBookingLimit(true, 90));
        self::assertNull(\CiContract\CanarySlotSearchPolicy::productFutureBookingLimit(false, 90));
    }
}
