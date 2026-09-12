<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use ReleaseGate\ZeroSurpriseCanaryContext;
use RuntimeException;

require_once __DIR__ . '/../../../scripts/release-gate/lib/ZeroSurpriseCanaryContext.php';

final class ZeroSurpriseCanaryContextTest extends TestCase
{
    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'schema' => 'zero_surprise_canary.v1',
            'run_id' => 'zs-canary-' . str_repeat('a', 32),
            'actor_id' => 1,
            'actor_username' => '__ea_zero_surprise_canary_v1',
            'actor_password' => str_repeat('a', 64),
            'provider_id' => 2,
            'service_id' => 3,
            'token' => str_repeat('b', 64),
            'expires_at' => 1500,
            'created_at' => 1000,
        ];
    }

    public function testValidPayloadPasses(): void
    {
        ZeroSurpriseCanaryContext::validatePayload($this->payload(), 1100);
        $this->addToAssertionCount(1);
    }

    public function testExpiredLeaseFails(): void
    {
        $payload = array_merge($this->payload(), ['expires_at' => 1100]);
        $this->expectException(RuntimeException::class);
        ZeroSurpriseCanaryContext::validatePayload($payload, 1100);
    }

    public function testOverlongLeaseFails(): void
    {
        $this->expectException(RuntimeException::class);
        ZeroSurpriseCanaryContext::validatePayload(array_merge($this->payload(), ['expires_at' => 1701]), 1100);
    }

    public function testFutureIssuedLeaseFails(): void
    {
        $this->expectException(RuntimeException::class);
        ZeroSurpriseCanaryContext::validatePayload(array_merge($this->payload(), ['created_at' => 1101]), 1100);
    }

    public function testMalformedShapeFails(): void
    {
        $payload = $this->payload();
        foreach (
            [
                ['token' => 'short'],
                ['actor_password' => 'short'],
                ['run_id' => 'zs-canary-short'],
                ['provider_id' => 0],
                ['unknown' => true],
            ]
            as $changes
        ) {
            $candidate = array_merge($payload, $changes);
            try {
                ZeroSurpriseCanaryContext::validatePayload($candidate, 1100);
                $this->fail('Malformed payload accepted.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        unset($payload['token']);
        $this->expectException(RuntimeException::class);
        ZeroSurpriseCanaryContext::validatePayload($payload, 1100);
    }
}
