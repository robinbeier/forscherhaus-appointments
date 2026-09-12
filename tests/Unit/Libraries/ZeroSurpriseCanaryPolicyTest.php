<?php

namespace Tests\Unit\Libraries;

use Tests\TestCase;
use Zero_surprise_canary;

require_once APPPATH . 'core/Zero_surprise_canary.php';

final class ZeroSurpriseCanaryPolicyTest extends TestCase
{
    public function testLeaseRequiresAnUnexpiredMatchingCapabilityAndCompleteIdentity(): void
    {
        $token = str_repeat('a', 64);
        $lease = [
            'schema' => Zero_surprise_canary::SCHEMA,
            'run_id' => 'zs-canary-' . str_repeat('b', 32),
            'token_hash' => hash('sha256', $token),
            'expires_at' => 1600,
            'actor_id' => 1,
            'provider_id' => 2,
            'service_id' => 3,
        ];
        $encode = static fn(array $value): string => json_encode($value, JSON_THROW_ON_ERROR);
        self::assertSame($lease, Zero_surprise_canary::decodeLease($encode($lease), $token, 1000));
        self::assertNull(Zero_surprise_canary::decodeLease($encode($lease), $token, 1600));
        self::assertNull(Zero_surprise_canary::decodeLease($encode($lease), $token, 999));
        self::assertNull(Zero_surprise_canary::decodeLease($encode($lease), str_repeat('c', 64), 1000));
        foreach (['actor_id', 'provider_id', 'service_id'] as $field) {
            $invalid = $lease;
            unset($invalid[$field]);
            self::assertNull(Zero_surprise_canary::decodeLease($encode($invalid), $token, 1000));
            $invalid[$field] = '1';
            self::assertNull(Zero_surprise_canary::decodeLease($encode($invalid), $token, 1000));
        }
    }

    public function testCleanupOwnershipUsesADelimitedRunIdentity(): void
    {
        $run = 'zs-canary-' . str_repeat('d', 32);
        self::assertTrue(Zero_surprise_canary::ownsNotes('run:' . $run, $run));
        self::assertTrue(Zero_surprise_canary::ownsNotes('run:' . $run . ':cancel-protect', $run));
        self::assertFalse(Zero_surprise_canary::ownsNotes('run:' . $run . '0:cancel-protect', $run));
        self::assertFalse(Zero_surprise_canary::ownsNotes('ordinary appointment', $run));
        self::assertFalse(Zero_surprise_canary::ownsNotes(null, $run));
    }

    public function testOrdinaryNotificationsAreNotSuppressedWithoutAVerifiedContext(): void
    {
        $policy = (new \ReflectionClass(Zero_surprise_canary::class))->newInstanceWithoutConstructor();
        self::assertFalse($policy->active());
        self::assertFalse($policy->suppressNotifications([]));
    }
}
