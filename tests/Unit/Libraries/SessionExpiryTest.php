<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries;

use EA_Session;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SessionExpiryTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $sessionBackup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionBackup = $_SESSION ?? [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->sessionBackup;
        parent::tearDown();
    }

    public function testFrameworkBootstrapLoadsApplicationSessionSubclass(): void
    {
        self::assertInstanceOf(EA_Session::class, get_instance()->session);
    }

    public function testExpirationDisabledLeavesSessionUntouched(): void
    {
        $_SESSION = [
            '__ea_last_activity' => 100,
            'user_id' => 42,
            'role_slug' => 'provider',
        ];

        $session = $this->session();
        $session->enforce(200, 0);

        self::assertSame(
            [
                '__ea_last_activity' => 100,
                'user_id' => 42,
                'role_slug' => 'provider',
            ],
            $_SESSION,
        );
        self::assertSame([], $session->regenerations);
    }

    #[DataProvider('invalidMarkerProvider')]
    public function testInvalidOrStaleMarkersDestroyTheSessionAndRegenerateWithDestroy(mixed $marker, int $now): void
    {
        $_SESSION = [
            '__ea_last_activity' => $marker,
            'user_id' => 42,
            'csrf_token' => 'secret',
        ];

        $session = $this->session();
        $session->enforce($now, 60);

        self::assertSame([true], $session->regenerations);
        self::assertSame(['__ci_last_regenerate' => 1234, '__ea_last_activity' => $now], $_SESSION);
        self::assertArrayNotHasKey('user_id', $_SESSION);
        self::assertArrayNotHasKey('csrf_token', $_SESSION);
    }

    /** @return iterable<string, array{0:mixed, 1:int}> */
    public static function invalidMarkerProvider(): iterable
    {
        yield 'stale' => [100, 161];
        yield 'exact boundary' => [100, 160];
        yield 'malformed string' => ['100', 120];
        yield 'malformed array' => [[], 120];
        yield 'null' => [null, 120];
        yield 'zero' => [0, 120];
        yield 'negative' => [-1, 120];
        yield 'future marker' => [121, 120];
    }

    public function testFreshMarkerIsRefreshedWithoutRegeneration(): void
    {
        $_SESSION = [
            '__ea_last_activity' => 100,
            'user_id' => 42,
        ];

        $session = $this->session();
        $session->enforce(159, 60);

        self::assertSame(159, $_SESSION['__ea_last_activity']);
        self::assertSame(42, $_SESSION['user_id']);
        self::assertSame([], $session->regenerations);
    }

    public function testValidMarkerAdvancesOnEveryActiveRequest(): void
    {
        $_SESSION = ['__ea_last_activity' => 100, 'user_id' => 42];
        $session = $this->session();

        $session->enforce(150, 300);
        self::assertSame(150, $_SESSION['__ea_last_activity']);
        $session->enforce(151, 300);

        self::assertSame(151, $_SESSION['__ea_last_activity']);
        self::assertSame([], $session->regenerations);
    }

    public function testMarkerlessAuthenticatedSessionIsExpired(): void
    {
        $_SESSION = ['user_id' => 42, 'role_slug' => 'provider'];
        $session = $this->session();

        $session->enforce(200, 60);

        self::assertSame([true], $session->regenerations);
        self::assertSame(['__ci_last_regenerate' => 1234, '__ea_last_activity' => 200], $_SESSION);
        self::assertArrayNotHasKey('user_id', $_SESSION);
        self::assertArrayNotHasKey('role_slug', $_SESSION);
    }

    public function testMarkerlessAnonymousSessionIsPreservedAndGetsActivityMarker(): void
    {
        $_SESSION = ['flash_notice' => 'welcome'];
        $session = $this->session();

        $session->enforce(200, 60);

        self::assertSame(200, $_SESSION['__ea_last_activity']);
        self::assertSame('welcome', $_SESSION['flash_notice']);
        self::assertSame([], $session->regenerations);
    }

    private function session(): TestableSession
    {
        return new TestableSession();
    }
}

final class TestableSession extends EA_Session
{
    /** @var list<bool> */
    public array $regenerations = [];

    public function __construct() {}

    public function enforce(int $now, int $expiration): void
    {
        $this->enforceInactivityTimeout($now, $expiration);
    }

    public function sess_regenerate($destroy = null)
    {
        $this->regenerations[] = (bool) $destroy;
        $_SESSION['__ci_last_regenerate'] = 1234;
    }
}
