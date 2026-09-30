<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\CalendarMethodProbe;
use ReleaseGate\GateHttpResponse;

require_once dirname(__DIR__, 3) . '/scripts/release-gate/lib/GateHttpClient.php';
require_once dirname(__DIR__, 3) . '/scripts/release-gate/lib/CalendarMethodProbe.php';

final class CalendarMethodProbeTest extends TestCase
{
    public function testAllSixMethodsAndAliasesAreBoundedAndReadOnly(): void
    {
        $client = new FakeCalendarMethodClient();
        $snapshot = ['owned' => ['appointment' => ['id' => 41]], 'totals' => ['appointments' => 7]];
        $phases = [];
        $lifecycle = [];
        $probe = new CalendarMethodProbe(
            $client->request(...),
            static function () use (&$lifecycle): void {
                $lifecycle[] = 'login';
            },
            static function () use (&$lifecycle): void {
                $lifecycle[] = 'logout';
            },
            static fn(): array => [
                'appointment_id' => 41,
                'provider_id' => 17,
                'customer_id' => 23,
                'marker' => 'defense-verification:synthetic',
            ],
            static function () use (&$snapshot): array {
                return $snapshot;
            },
        );

        $result = $probe->run(static function (string $phase, string $outcome) use (&$phases): void {
            $phases[] = [$phase, $outcome];
        });

        self::assertSame('verified', $result['status']);
        self::assertSame(['login', 'logout'], $lifecycle);
        self::assertCount(30, $client->requests);
        self::assertCount(12, array_filter($phases, static fn(array $entry): bool => $entry[1] === 'passed'));
        self::assertSame(12, count($result['method_statuses'], COUNT_RECURSIVE) - count($result['method_statuses']));
        self::assertSame(18, count($result['alias_statuses'], COUNT_RECURSIVE) - count($result['alias_statuses']));
        self::assertCount(
            6,
            array_filter(
                $client->requests,
                static fn(array $request): bool => $request['method'] === 'POST' &&
                    str_starts_with($request['path'], 'backend_api/'),
            ),
        );
        foreach ($client->requests as $request) {
            self::assertContains($request['method'], ['GET', 'HEAD', 'POST']);
            self::assertTrue(
                str_contains($request['path'], 'calendar') || str_contains($request['path'], 'backend_api'),
            );
        }
    }

    public function testUnexpectedMethodResponseFailsAndMarksPhase(): void
    {
        $client = new FakeCalendarMethodClient(status: 200);
        $phases = [];
        $lifecycle = [];
        $probe = new CalendarMethodProbe(
            $client->request(...),
            static function () use (&$lifecycle): void {
                $lifecycle[] = 'login';
            },
            static function () use (&$lifecycle): void {
                $lifecycle[] = 'logout';
            },
            static fn(): array => [
                'appointment_id' => 41,
                'provider_id' => 17,
                'customer_id' => 23,
                'marker' => 'owned',
            ],
            static fn(): array => ['owned' => ['id' => 41], 'totals' => ['appointments' => 1]],
        );

        try {
            $probe->run(static function (string $phase, string $outcome) use (&$phases): void {
                $phases[] = [$phase, $outcome];
            });
            self::fail('Expected method failure.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('unexpected status', $error->getMessage());
        }
        self::assertSame(['save_appointment_methods', 'started'], $phases[0]);
        self::assertSame(['save_appointment_methods', 'failed'], $phases[1]);
        self::assertSame(['login', 'logout'], $lifecycle);
    }

    public function testAuthenticationFailureDoesNotAttemptLogout(): void
    {
        $client = new FakeCalendarMethodClient();
        $logoutCalls = 0;
        $probe = new CalendarMethodProbe(
            $client->request(...),
            static function (): void {
                throw new RuntimeException('login failed');
            },
            static function () use (&$logoutCalls): void {
                $logoutCalls++;
            },
            static fn(): array => [],
            static fn(): array => [],
        );

        $this->expectException(RuntimeException::class);
        try {
            $probe->run();
        } finally {
            self::assertSame(0, $logoutCalls);
        }
    }

    public function testAliasMustReturnAnActionableRedirectStatus(): void
    {
        $client = new FakeCalendarMethodClient(aliasStatus: 305);
        $probe = new CalendarMethodProbe(
            $client->request(...),
            static function (): void {},
            static function (): void {},
            static fn(): array => [
                'appointment_id' => 41,
                'provider_id' => 17,
                'customer_id' => 23,
                'marker' => 'owned',
            ],
            static fn(): array => ['owned' => ['id' => 41], 'totals' => ['appointments' => 1]],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('was not redirect-only');
        $probe->run();
    }

    public function testAliasRejectsOffOriginAndMisleadingLocations(): void
    {
        foreach (
            [
                'https://unrelated.invalid/prefix/calendar/save_appointment',
                '/prefix/calendar/save_appointment',
                '/index.php/calendar/save_appointment?appointment_id=41',
            ]
            as $location
        ) {
            $client = new FakeCalendarMethodClient(aliasLocationOverride: $location);
            $probe = new CalendarMethodProbe(
                $client->request(...),
                static function (): void {},
                static function (): void {},
                static fn(): array => [
                    'appointment_id' => 41,
                    'provider_id' => 17,
                    'customer_id' => 23,
                    'marker' => 'owned',
                ],
                static fn(): array => ['owned' => ['id' => 41], 'totals' => ['appointments' => 1]],
            );
            try {
                $probe->run();
                self::fail('An unexpected alias redirect was accepted: ' . $location);
            } catch (RuntimeException $error) {
                self::assertStringContainsString('redirect', $error->getMessage());
            }
        }
    }

    public function testAbsoluteSameOriginAliasRedirectsPass(): void
    {
        $client = new FakeCalendarMethodClient(absoluteAliasLocation: true);
        $probe = new CalendarMethodProbe(
            $client->request(...),
            static function (): void {},
            static function (): void {},
            static fn(): array => [
                'appointment_id' => 41,
                'provider_id' => 17,
                'customer_id' => 23,
                'marker' => 'owned',
            ],
            static fn(): array => ['owned' => ['id' => 41], 'totals' => ['appointments' => 1]],
        );
        self::assertSame('verified', $probe->run()['status']);
    }
}

final class FakeCalendarMethodClient
{
    /** @var array<int,array{method:string,path:string}> */
    public array $requests = [];

    public function __construct(
        private readonly int $status = 405,
        private readonly int $aliasStatus = 302,
        private readonly ?string $aliasLocationOverride = null,
        private readonly bool $absoluteAliasLocation = false,
    ) {}

    public function request(
        string $method,
        string $path,
        array $form = [],
        ?int $timeoutSeconds = null,
        bool $withCsrfToken = false,
    ): GateHttpResponse {
        $this->requests[] = ['method' => strtoupper($method), 'path' => $path];
        $alias = str_starts_with($path, 'backend_api/');
        $redirectPath = '/index.php/' . str_replace('backend_api/ajax_', 'calendar/', explode('?', $path, 2)[0]);
        $location =
            $this->aliasLocationOverride ??
            ($this->absoluteAliasLocation ? 'http://localhost' . $redirectPath : $redirectPath);
        return new GateHttpResponse(
            $alias ? $this->aliasStatus : $this->status,
            $alias ? ['location' => [$location]] : ['allow' => ['POST']],
            '',
            0.0,
            'http://localhost/index.php/' . $path,
        );
    }
}
