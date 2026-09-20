<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** The bounded probe routes stay stateless on the real loopback application path. */
final class ReadOnlyProbeHttpTest extends TestCase
{
    private ?DefenseCycleHttpServer $server = null;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || !is_file('/.dockerenv')) {
            self::markTestSkipped('Requires the explicitly owned isolated Docker runner.');
        }

        $this->server = new DefenseCycleHttpServer();
    }

    protected function tearDown(): void
    {
        $this->server?->close();
    }

    public function testModernAndLegacyProbeRoutesRemainStatelessOverLoopback(): void
    {
        $server = $this->server;
        self::assertNotNull($server);
        $client = new GateHttpClient($server->baseUrl, additionalHeaders: ['Accept' => 'text/html']);
        $root = dirname(__DIR__, 3);
        $rootStat = stat($root);
        self::assertIsArray($rootStat);
        $expectedRootBinding = 'dev:' . $rootStat['dev'] . ':' . $rootStat['ino'];
        $before = $this->stateSnapshot($root, $server);

        foreach ([str_repeat('a', 64), str_repeat('b', 12)] as $capability) {
            $confirmation = $client->get('booking_confirmation/of/' . $capability);
            self::assertSame(307, $confirmation->statusCode);
            self::assertStringContainsString('/appointments', (string) $confirmation->header('location'));
            self::assertSame($expectedRootBinding, $confirmation->header('x-fh-read-only-probe-root'));
            self::assertSame('unreleased', $confirmation->header('x-fh-read-only-probe-release'));

            $ics = $client->get('appointments/ics/' . $capability);
            self::assertSame(404, $ics->statusCode);
            self::assertStringNotContainsString('text/calendar', strtolower((string) $ics->header('content-type')));
            self::assertNull($ics->header('content-disposition'));
            self::assertSame($expectedRootBinding, $ics->header('x-fh-read-only-probe-root'));
            self::assertSame('unreleased', $ics->header('x-fh-read-only-probe-release'));
        }

        $after = $this->stateSnapshot($root, $server);
        self::assertSame($before['session'], $after['session'], 'Probe must not create a session file.');
        self::assertSame($before['rate_limit'], $after['rate_limit'], 'Loopback rate-limit state must stay unchanged.');
        self::assertSame($before['logs'], $after['logs'], 'Probe must not append an application log entry.');
    }

    /** @return array{session:array<int,string>,rate_limit:array<int,string>,logs:array<int,string>} */
    private function stateSnapshot(string $root, DefenseCycleHttpServer $server): array
    {
        $files = static function (array $patterns): array {
            $paths = [];
            foreach ($patterns as $pattern) {
                $paths = array_merge($paths, glob($pattern) ?: []);
            }
            $paths = array_values(array_unique($paths));
            sort($paths);

            return array_map(static fn(string $path): string => $path . ':' . hash_file('sha256', $path), $paths);
        };

        return [
            'session' => $files([$server->directory . '/sessions/*']),
            'rate_limit' => $files([
                $root . '/storage/cache/rate_limit_key_127.0.0.1',
                $root . '/storage/cache/rate_limit_tmp_127.0.0.1',
            ]),
            'logs' => $files([$root . '/storage/logs/log-*.php']),
        ];
    }
}
