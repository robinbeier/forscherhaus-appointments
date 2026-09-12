<?php

namespace Tests\Unit\Scripts;

use CiContract\DeterministicFixtureFactory;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/ci/lib/DeterministicFixtureFactory.php';
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
}
