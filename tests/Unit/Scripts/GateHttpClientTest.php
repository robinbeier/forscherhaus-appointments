<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReleaseGate\GateHttpClient;

require_once __DIR__ . '/../../../scripts/release-gate/lib/GateHttpClient.php';

class GateHttpClientTest extends TestCase
{
    public function testConsumeSetCookiesPreservesPathAndDomainForBrowserReuse(): void
    {
        $client = new GateHttpClient('https://example.test/app', 'index.php');

        $method = new ReflectionMethod(GateHttpClient::class, 'consumeSetCookies');
        $method->setAccessible(true);
        $method->invoke(
            $client,
            ['ea_session=abc123; Path=/app/; Domain=example.test; HttpOnly; Secure; SameSite=Lax'],
            'https://example.test/app/index.php/login/validate',
        );

        self::assertSame('abc123', $client->getCookie('ea_session'));

        $records = $client->cookieRecords();
        self::assertCount(1, $records);
        self::assertSame('ea_session', $records[0]['name']);
        self::assertSame('abc123', $records[0]['value']);
        self::assertSame('example.test', $records[0]['domain']);
        self::assertSame('/app/', $records[0]['path']);
        self::assertTrue($records[0]['httpOnly']);
        self::assertTrue($records[0]['secure']);
        self::assertSame('Lax', $records[0]['sameSite']);
    }

    public function testConsumeSetCookiesFallsBackToResponseDomainAndPath(): void
    {
        $client = new GateHttpClient('http://example.test/app', 'index.php');

        $method = new ReflectionMethod(GateHttpClient::class, 'consumeSetCookies');
        $method->setAccessible(true);
        $method->invoke(
            $client,
            ['csrf_cookie=token-123; HttpOnly'],
            'http://example.test/app/index.php/login/validate',
        );

        $records = $client->cookieRecords();
        self::assertCount(1, $records);
        self::assertSame('csrf_cookie', $records[0]['name']);
        self::assertSame('token-123', $records[0]['value']);
        self::assertSame('example.test', $records[0]['domain']);
        self::assertSame('/app/index.php/login/', $records[0]['path']);
        self::assertTrue($records[0]['httpOnly']);
    }
}
