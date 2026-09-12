<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

defined('BASEPATH') || define('BASEPATH', __DIR__ . '/../../../system/');
require_once __DIR__ . '/../../../application/libraries/Zero_surprise_canary_fixture.php';

final class ZeroSurpriseCanaryFixtureContractTest extends TestCase
{
    public function testOwnershipMarkersAreNonSecretAndRunSpecific(): void
    {
        $fixture = (new ReflectionClass(Zero_surprise_canary_fixture::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(Zero_surprise_canary_fixture::class, 'marker');
        $method->setAccessible(true);
        $tokenA = str_repeat('b', 64);
        $tokenB = str_repeat('c', 64);
        $markerA = json_decode(
            $method->invoke($fixture, 'zs-canary-' . str_repeat('a', 32), 1, 2, 3, $tokenA, 123),
            true,
        );
        $markerB = json_decode(
            $method->invoke($fixture, 'zs-canary-' . str_repeat('d', 32), 1, 2, 3, $tokenB, 456),
            true,
        );

        self::assertSame('zero_surprise_canary.v1', $markerA['schema']);
        self::assertSame(hash('sha256', $tokenA), $markerA['token_hash']);
        self::assertSame(123, $markerA['expires_at']);
        self::assertArrayNotHasKey('token', $markerA);
        self::assertNotSame($markerA['run_id'], $markerB['run_id']);
        self::assertNotSame($markerA['token_hash'], $markerB['token_hash']);
        self::assertStringNotContainsString($tokenA, json_encode($markerA, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($tokenB, json_encode($markerB, JSON_THROW_ON_ERROR));
    }
}
