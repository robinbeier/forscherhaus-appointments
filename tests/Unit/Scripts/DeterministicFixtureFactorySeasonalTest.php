<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use CiContract\DeterministicFixtureFactory;
use PHPUnit\Framework\TestCase;
use ReleaseGate\GateAssertionException;
use ReleaseGate\GateHttpClient;

require_once __DIR__ . '/../../../scripts/ci/lib/DeterministicFixtureFactory.php';
require_once __DIR__ . '/../../../scripts/release-gate/lib/GateAssertions.php';
require_once __DIR__ . '/../../../scripts/release-gate/lib/GateHttpClient.php';

final class DeterministicFixtureFactorySeasonalTest extends TestCase
{
    /** @var resource|null */
    private $server;

    private string $router = '';

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        if ($this->router !== '') {
            @unlink($this->router);
        }
    }

    public function testSearchReachesBookableDateAfterOldSeventyDayWindow(): void
    {
        $factory = new DeterministicFixtureFactory('ci-write-seasonal', 70, 'Europe/Berlin', true);
        $start = new \DateTimeImmutable('tomorrow', new \DateTimeZone('Europe/Berlin'));
        $openDate = $start->modify('+80 days')->format('Y-m-d');
        $client = $this->startServer([$openDate => ['09:00']]);
        $client->get('booking');

        $slot = $factory->resolveBookableSlot($client, 2, [['provider_id' => 1, 'service_id' => 2]], 90);

        self::assertSame($openDate, $slot['date']);
        self::assertSame('product_future_booking_limit', $slot['mode']);
    }

    public function testSearchFindsOpenPairAfterPartiallyBlockedPair(): void
    {
        $factory = new DeterministicFixtureFactory('ci-write-partial', 14, 'Europe/Berlin', true);
        $start = new \DateTimeImmutable('tomorrow', new \DateTimeZone('Europe/Berlin'));
        $openDate = $start->format('Y-m-d');
        $client = $this->startServer([
            $openDate . '|1' => [],
            $openDate . '|2' => ['10:00'],
        ]);
        $client->get('booking');

        $slot = $factory->resolveBookableSlot(
            $client,
            2,
            [['provider_id' => 1, 'service_id' => 2], ['provider_id' => 2, 'service_id' => 2]],
            14,
        );

        self::assertSame(2, $slot['provider_id']);
        self::assertSame($openDate, $slot['date']);
    }

    public function testNoSafeDateFailsAtProductHorizon(): void
    {
        $factory = new DeterministicFixtureFactory('ci-write-empty', 70, 'Europe/Berlin', true);
        $client = $this->startServer([]);
        $client->get('booking');

        $this->expectException(GateAssertionException::class);
        $this->expectExceptionMessage('3-day window');
        $factory->resolveBookableSlot($client, 2, [['provider_id' => 1, 'service_id' => 2]], 3);
    }

    /** @param array<string,array<int,string>> $openSlots */
    private function startServer(array $openSlots): GateHttpClient
    {
        $port = random_int(20000, 35000);
        $temporary = tempnam(sys_get_temp_dir(), 'rob630-router-');
        self::assertNotFalse($temporary);
        $this->router = $temporary . '.php';
        rename($temporary, $this->router);
        $encoded = var_export($openSlots, true);
        file_put_contents(
            $this->router,
            "<?php\n\$open = {$encoded};\nif (\$_SERVER['REQUEST_METHOD'] === 'GET') { header('Set-Cookie: csrf_cookie=test'); header('Content-Type: text/html'); echo '<html></html>'; exit; }\nparse_str((string) file_get_contents('php://input'), \$body);\n\$key = (string)(\$body['selected_date'] ?? '') . '|' . (string)(\$body['provider_id'] ?? '');\nheader('Content-Type: application/json');\necho json_encode(\$open[\$key] ?? \$open[(string)(\$body['selected_date'] ?? '')] ?? []);\n",
        );
        $stderr = tempnam(sys_get_temp_dir(), 'rob630-server-');
        self::assertNotFalse($stderr);
        $this->server = proc_open(
            'php -S 127.0.0.1:' . $port . ' ' . escapeshellarg(basename($this->router)),
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $stderr, 'a']],
            $pipes,
            dirname($this->router),
        );
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.1);
            if (is_resource($socket)) {
                fclose($socket);
                @unlink($stderr);
                return new GateHttpClient('http://127.0.0.1:' . $port, '', 2, 'rob630-test');
            }
            usleep(50000);
        }
        $diagnostic = is_file($stderr) ? (string) file_get_contents($stderr) : 'server did not start';
        @unlink($stderr);
        self::fail('Could not start local HTTP test server: ' . $diagnostic);
    }
}
