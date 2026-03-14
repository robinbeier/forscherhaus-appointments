<?php

namespace Tests\Unit\Scripts;

use CiContract\DeterministicFixtureFactory;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/ci/lib/DeterministicFixtureFactory.php';

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
}
