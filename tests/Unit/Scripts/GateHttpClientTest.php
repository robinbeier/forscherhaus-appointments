<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReleaseGate\GateHttpClient;
use RuntimeException;

require_once __DIR__ . '/../../../scripts/release-gate/lib/GateHttpClient.php';

class GateHttpClientTest extends TestCase
{
    public function testRequestAppNormalizesAndAllowsTheRob552Methods(): void
    {
        $client = new GateHttpClient('https://example.test/app', 'index.php');
        $method = new ReflectionMethod(GateHttpClient::class, 'normalizeAppRequestMethod');
        $method->setAccessible(true);

        foreach (
            [
                'get' => 'GET',
                'HEAD' => 'HEAD',
                ' post ' => 'POST',
                'PUT' => 'PUT',
                'PATCH' => 'PATCH',
                'delete' => 'DELETE',
                'OPTIONS' => 'OPTIONS',
            ]
            as $input => $expected
        ) {
            self::assertSame($expected, $method->invoke($client, $input));
        }
    }

    public function testRequestAppRejectsUnsupportedMethodsBeforeNetworkAccess(): void
    {
        $client = new GateHttpClient('https://example.test/app', 'index.php');

        foreach (['TRACE', 'connect', 'BREW', ''] as $method) {
            try {
                $client->requestApp($method, 'health');
                self::fail('Unsupported method was accepted: ' . $method);
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('Unsupported app request method', $exception->getMessage());
            }
        }
    }

    public function testRequestAppCsrfInjectionIsExplicitAndPostOnly(): void
    {
        $client = new GateHttpClient('https://example.test/app', 'index.php');
        $prepare = new ReflectionMethod(GateHttpClient::class, 'prepareAppRequestForm');
        $prepare->setAccessible(true);
        $consume = new ReflectionMethod(GateHttpClient::class, 'consumeSetCookies');
        $consume->setAccessible(true);
        $consume->invoke($client, ['csrf_cookie=token-123; Path=/app/'], 'https://example.test/app/index.php/login');

        self::assertSame(
            ['name' => 'alice', 'csrf_token' => 'token-123'],
            $prepare->invoke($client, 'POST', ['name' => 'alice'], true, 'users/update'),
        );
        self::assertSame(
            ['name' => 'alice'],
            $prepare->invoke($client, 'POST', ['name' => 'alice'], false, 'users/update'),
        );

        $missingCookieClient = new GateHttpClient('https://example.test/app', 'index.php');
        $this->expectException(RuntimeException::class);
        $missingCookieClient->requestApp('POST', 'users/update', [], null, true);
    }

    public function testRequestAppRejectsCsrfInjectionForNonPostMethods(): void
    {
        $client = new GateHttpClient('https://example.test/app', 'index.php');
        $prepare = new ReflectionMethod(GateHttpClient::class, 'prepareAppRequestForm');
        $prepare->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $prepare->invoke($client, 'PUT', [], true, 'users/update');
    }

    public function testHeadRequestSuppressesResponseBody(): void
    {
        $client = new GateHttpClient('https://example.test/app', 'index.php');
        $method = new ReflectionMethod(GateHttpClient::class, 'shouldSuppressResponseBody');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($client, 'HEAD'));
        self::assertFalse($method->invoke($client, 'GET'));
    }

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

    public function testConsumeSetCookiesDoesNotInferSecureFromHttpsTransport(): void
    {
        $client = new GateHttpClient('https://example.test/app', 'index.php');

        $method = new ReflectionMethod(GateHttpClient::class, 'consumeSetCookies');
        $method->setAccessible(true);
        $method->invoke(
            $client,
            ['csrf_cookie=token-123; HttpOnly'],
            'https://example.test/app/index.php/login/validate',
        );

        $records = $client->cookieRecords();
        self::assertCount(1, $records);
        self::assertArrayNotHasKey('secure', $records[0]);
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

    public function testBuildCookieHeaderSkipsSecureCookieOnHttpRequest(): void
    {
        $client = new GateHttpClient('http://example.test/app', 'index.php');

        $consumeMethod = new ReflectionMethod(GateHttpClient::class, 'consumeSetCookies');
        $consumeMethod->setAccessible(true);
        $consumeMethod->invoke(
            $client,
            ['ea_session=abc123; Path=/app/; Domain=example.test; Secure; HttpOnly'],
            'https://example.test/app/index.php/login/validate',
        );

        $buildMethod = new ReflectionMethod(GateHttpClient::class, 'buildCookieHeader');
        $buildMethod->setAccessible(true);

        self::assertNull($buildMethod->invoke($client, 'http://example.test/app/index.php/dashboard'));
        self::assertSame(
            'ea_session=abc123',
            $buildMethod->invoke($client, 'https://example.test/app/index.php/dashboard'),
        );
    }

    public function testBuildCookieHeaderHonorsPathSegmentBoundaries(): void
    {
        $client = new GateHttpClient('https://example.test/app', 'index.php');

        $consumeMethod = new ReflectionMethod(GateHttpClient::class, 'consumeSetCookies');
        $consumeMethod->setAccessible(true);
        $consumeMethod->invoke(
            $client,
            ['ea_session=abc123; Path=/app; Domain=example.test; HttpOnly'],
            'https://example.test/app/index.php/login/validate',
        );

        $buildMethod = new ReflectionMethod(GateHttpClient::class, 'buildCookieHeader');
        $buildMethod->setAccessible(true);

        self::assertSame('ea_session=abc123', $buildMethod->invoke($client, 'https://example.test/app/dashboard'));
        self::assertNull($buildMethod->invoke($client, 'https://example.test/application/dashboard'));
    }

    public function testMaxAgeTakesPrecedenceAndExpiredCookiesArePurgedFromAllViews(): void
    {
        $now = 1_700_000_000;
        $client = new GateHttpClient(
            'https://example.test/app',
            'index.php',
            clock: function () use (&$now): int {
                return $now;
            },
        );

        $consumeMethod = new ReflectionMethod(GateHttpClient::class, 'consumeSetCookies');
        $consumeMethod->setAccessible(true);
        $consumeMethod->invoke(
            $client,
            ['ea_session=abc123; Path=/app/; Expires=Wed, 01 Jan 2030 00:00:00 GMT; Max-Age=10'],
            'https://example.test/app/index.php/login/validate',
        );

        self::assertSame('abc123', $client->getCookie('ea_session'));
        self::assertCount(1, $client->cookieRecords());
        self::assertArrayNotHasKey('expiresAt', $client->cookieRecords()[0]);

        $now += 11;
        self::assertNull($client->getCookie('ea_session'));
        self::assertSame([], $client->cookieRecords());

        $buildMethod = new ReflectionMethod(GateHttpClient::class, 'buildCookieHeader');
        $buildMethod->setAccessible(true);
        self::assertNull($buildMethod->invoke($client, 'https://example.test/app/index.php/dashboard'));
    }

    public function testEmptyValueWithoutExpiryRemainsAValidCookie(): void
    {
        $client = new GateHttpClient('https://example.test', '');

        $consumeMethod = new ReflectionMethod(GateHttpClient::class, 'consumeSetCookies');
        $consumeMethod->setAccessible(true);
        $consumeMethod->invoke($client, ['feature_flag=; Path=/app/'], 'https://example.test/app/login');

        self::assertSame('', $client->getCookie('feature_flag'));
        self::assertCount(1, $client->cookieRecords());
    }

    public function testVeryLargePositiveMaxAgeDoesNotOverflowIntoImmediateExpiry(): void
    {
        $client = new GateHttpClient('https://example.test', '', clock: static fn(): int => 1_700_000_000);

        $consumeMethod = new ReflectionMethod(GateHttpClient::class, 'consumeSetCookies');
        $consumeMethod->setAccessible(true);
        $consumeMethod->invoke(
            $client,
            ['long_lived=value; Path=/; Max-Age=999999999999999999999999'],
            'https://example.test/login',
        );

        self::assertSame('value', $client->getCookie('long_lived'));
        self::assertCount(1, $client->cookieRecords());
    }

    public function testDeletionRemovesOnlyTheExactCookieScope(): void
    {
        $client = new GateHttpClient('https://example.test/app', 'index.php');

        $consumeMethod = new ReflectionMethod(GateHttpClient::class, 'consumeSetCookies');
        $consumeMethod->setAccessible(true);
        $consumeMethod->invoke(
            $client,
            [
                'ea_session=app; Path=/app/; Domain=example.test',
                'ea_session=admin; Path=/app/admin/; Domain=example.test',
            ],
            'https://example.test/app/index.php/login/validate',
        );
        $consumeMethod->invoke(
            $client,
            ['ea_session=; Path=/app/admin/; Domain=example.test; Max-Age=0'],
            'https://example.test/app/admin/logout',
        );

        self::assertSame('app', $client->getCookie('ea_session'));
        self::assertCount(1, $client->cookieRecords());
        self::assertSame('/app/', $client->cookieRecords()[0]['path']);

        $buildMethod = new ReflectionMethod(GateHttpClient::class, 'buildCookieHeader');
        $buildMethod->setAccessible(true);
        self::assertSame('ea_session=app', $buildMethod->invoke($client, 'https://example.test/app/admin/dashboard'));
    }

    public function testHostOnlyDeletionUsesTheSameRedirectResolvedScope(): void
    {
        $client = new GateHttpClient('https://example.test', '');

        $consumeMethod = new ReflectionMethod(GateHttpClient::class, 'consumeSetCookies');
        $consumeMethod->setAccessible(true);
        $consumeMethod->invoke($client, ['login_step=one; HttpOnly'], 'https://example.test/login/start');
        $consumeMethod->invoke(
            $client,
            ['login_step=; HttpOnly; Expires=Thu, 01 Jan 1970 00:00:00 GMT'],
            'https://example.test/login/final',
        );

        self::assertNull($client->getCookie('login_step'));
        self::assertSame([], $client->cookieRecords());
    }

    public function testHostOnlyDeletionIgnoresSchemeAndPortButPreservesHostAndPathScopes(): void
    {
        $client = new GateHttpClient('https://example.test/app', '');

        $consumeMethod = new ReflectionMethod(GateHttpClient::class, 'consumeSetCookies');
        $consumeMethod->setAccessible(true);
        $consumeMethod->invoke(
            $client,
            ['session=secure-port; Path=/app/', 'session=other-path; Path=/app/admin/'],
            'https://example.test:8443/app/login',
        );
        $consumeMethod->invoke($client, ['session=other-host; Path=/app/'], 'https://other.test/app/login');
        $consumeMethod->invoke($client, ['session=; Path=/app/; Max-Age=0'], 'http://example.test/app/logout');

        $records = $client->cookieRecords();
        self::assertCount(2, $records);
        self::assertSame('other-path', $records[0]['value']);
        self::assertSame('/app/admin/', $records[0]['path']);
        self::assertSame('other-host', $records[1]['value']);
        self::assertSame('/app/', $records[1]['path']);
    }

    public function testConsumeResponseCookieBlocksScopesHostOnlyCookiesPerRedirectHop(): void
    {
        $client = new GateHttpClient('https://example.test', '');

        $method = new ReflectionMethod(GateHttpClient::class, 'consumeResponseCookieBlocks');
        $method->setAccessible(true);
        $method->invoke(
            $client,
            [
                [
                    'headers' => [
                        'location' => ['/auth/final'],
                    ],
                    'set_cookies' => ['login_step=one; HttpOnly'],
                ],
                [
                    'headers' => [],
                    'set_cookies' => ['login_final=two; HttpOnly'],
                ],
            ],
            'https://example.test/login/start',
            'https://example.test/auth/final',
        );

        $records = $client->cookieRecords();
        self::assertCount(2, $records);
        self::assertSame('https://example.test/login/', $records[0]['url']);
        self::assertSame('https://example.test/auth/', $records[1]['url']);
    }

    public function testConsumeResponseCookieBlocksNormalizesRelativeRedirectTargets(): void
    {
        $client = new GateHttpClient('https://example.test', '');

        $method = new ReflectionMethod(GateHttpClient::class, 'consumeResponseCookieBlocks');
        $method->setAccessible(true);
        $method->invoke(
            $client,
            [
                [
                    'headers' => [
                        'location' => ['../auth/final?step=2#details'],
                    ],
                    'set_cookies' => ['login_step=one; HttpOnly'],
                ],
                [
                    'headers' => [],
                    'set_cookies' => ['login_final=two; HttpOnly'],
                ],
            ],
            'https://example.test/app/login/start',
            'https://example.test/app/auth/final?step=2#details',
        );

        $records = $client->cookieRecords();
        self::assertCount(2, $records);
        self::assertSame('https://example.test/app/login/', $records[0]['url']);
        self::assertSame('https://example.test/app/auth/', $records[1]['url']);
    }
}
