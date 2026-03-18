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
        self::assertSame('/app/index.php/', $records[0]['path']);
        self::assertSame('http://example.test/app/index.php/', $records[0]['url']);
        self::assertArrayNotHasKey('domain', $records[0]);
        self::assertTrue($records[0]['httpOnly']);
    }

    public function testConsumeSetCookiesPreservesDistinctCookieScopesWithSameName(): void
    {
        $client = new GateHttpClient('https://example.test/app', 'index.php');

        $method = new ReflectionMethod(GateHttpClient::class, 'consumeSetCookies');
        $method->setAccessible(true);
        $method->invoke(
            $client,
            [
                'ea_session=abc123; Path=/app/; Domain=example.test',
                'ea_session=xyz789; Path=/app/admin/; Domain=example.test',
            ],
            'https://example.test/app/index.php/login/validate',
        );

        $records = $client->cookieRecords();
        self::assertCount(2, $records);
        self::assertSame('/app/', $records[0]['path']);
        self::assertSame('/app/admin/', $records[1]['path']);
    }

    public function testBuildCookieHeaderPrefersMoreSpecificScopedCookieMatches(): void
    {
        $client = new GateHttpClient('https://example.test/app', 'index.php');

        $consumeMethod = new ReflectionMethod(GateHttpClient::class, 'consumeSetCookies');
        $consumeMethod->setAccessible(true);
        $consumeMethod->invoke(
            $client,
            [
                'ea_session=abc123; Path=/app/; Domain=example.test',
                'ea_session=xyz789; Path=/app/admin/; Domain=example.test',
            ],
            'https://example.test/app/index.php/login/validate',
        );

        $buildMethod = new ReflectionMethod(GateHttpClient::class, 'buildCookieHeader');
        $buildMethod->setAccessible(true);

        self::assertSame(
            'ea_session=xyz789; ea_session=abc123',
            $buildMethod->invoke($client, 'https://example.test/app/admin/dashboard'),
        );
        self::assertSame('ea_session=abc123', $buildMethod->invoke($client, 'https://example.test/app/appointments'));
    }

    public function testBuildCookieHeaderMatchesUrlScopedHostOnlyCookies(): void
    {
        $client = new GateHttpClient('http://example.test/app', 'index.php');

        $consumeMethod = new ReflectionMethod(GateHttpClient::class, 'consumeSetCookies');
        $consumeMethod->setAccessible(true);
        $consumeMethod->invoke(
            $client,
            ['csrf_cookie=token-123; HttpOnly'],
            'http://example.test/app/index.php/login/validate',
        );

        $buildMethod = new ReflectionMethod(GateHttpClient::class, 'buildCookieHeader');
        $buildMethod->setAccessible(true);

        self::assertSame(
            'csrf_cookie=token-123',
            $buildMethod->invoke($client, 'http://example.test/app/index.php/dashboard'),
        );
        self::assertNull($buildMethod->invoke($client, 'http://example.test/app/public/home'));
    }
}
