<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Ops\TrafficGateV1;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../../scripts/ops/lib/TrafficGateV1.php';
require_once __DIR__ . '/../../../scripts/ops/traffic_gate_v1.php';

final class TrafficGateV1Test extends TestCase
{
    private const EPOCH = 1786298400;
    private const PRODUCER_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private string $workspace;
    /** @var array<string, mixed> */
    private array $catalog;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/traffic-gate-v1-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->workspace, 0700));
        $canonicalWorkspace = realpath($this->workspace);
        self::assertIsString($canonicalWorkspace);
        $this->workspace = $canonicalWorkspace;
        $this->catalog = TrafficGateV1::loadCatalog(
            dirname(__DIR__, 3) . '/scripts/ops/config/traffic_gate_catalog.v1.json',
        );
    }

    protected function tearDown(): void
    {
        if (!isset($this->workspace) || !is_dir($this->workspace)) {
            return;
        }
        foreach (scandir($this->workspace) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($this->workspace . '/' . $entry);
            }
        }
        @rmdir($this->workspace);
    }

    public function testEveryClassIsMutuallyExclusiveAndSumsToWindowTotal(): void
    {
        $lines = [
            $this->line('127.0.0.1', '-', 'GET', '/health', 200),
            $this->line('127.0.0.1', '-', 'GET', '/', 200),
            $this->line('203.0.113.10', '-', 'GET', '/.env', 404),
            $this->line('203.0.113.11', '-', 'GET', '/public', 200),
            $this->line('203.0.113.12', '-', 'GET', '/?lookup=1', 200),
            $this->line('UNKNOWN_SOURCE_SENTINEL', '-', 'GET', '/public', 200),
        ];

        $report = $this->evaluate($lines, 'no-business-traffic');

        foreach (TrafficGateV1::CLASSES as $class) {
            self::assertSame(1, $report['counts'][$class], $class);
        }
        self::assertSame(6, $report['counts']['lines_in_window']);
        self::assertSame(6, array_sum(array_intersect_key($report['counts'], array_flip(TrafficGateV1::CLASSES))));
        self::assertSame('hard_stop', $report['decision']);
        self::assertSame(20, $report['exit_code']);
    }

    public function testNormalModeStopsPublicReadButNoBusinessModeMakesOnlyThatClassAdvisory(): void
    {
        $line = $this->line('203.0.113.10', '-', 'HEAD', '/public', 200);

        $normal = $this->evaluate([$line], 'normal');
        $noBusiness = $this->evaluate([$line], 'no-business-traffic');

        self::assertSame(['hard_stop', 20], [$normal['decision'], $normal['exit_code']]);
        self::assertSame(['advisory', 0], [$noBusiness['decision'], $noBusiness['exit_code']]);
        self::assertSame(1, $normal['counts']['public_read']);
        self::assertSame(1, $noBusiness['counts']['public_read']);
    }

    public function testDocumentedAndDeniedTrafficAllowOrRemainAdvisory(): void
    {
        $report = $this->evaluate(
            [
                $this->line('::1', '-', 'HEAD', '/health', 204),
                $this->line('127.0.0.2', '-', 'GET', '/', 302),
                $this->line('203.0.113.10', '-', 'GET', '/wp-login.php', 403),
            ],
            'normal',
        );

        self::assertSame('advisory', $report['decision']);
        self::assertSame(0, $report['exit_code']);
        self::assertSame(1, $report['counts']['documented_health']);
        self::assertSame(1, $report['counts']['documented_periodic_ops']);
        self::assertSame(1, $report['counts']['denied_external']);
    }

    public function testUnexpectedLoopbackScannerPathIsNotTrustedAsPeriodicOps(): void
    {
        $report = $this->evaluate([$this->line('127.0.0.1', '-', 'GET', '/server-status', 404)], 'no-business-traffic');

        self::assertSame(1, $report['counts']['business_or_authenticated']);
        self::assertSame(0, $report['counts']['documented_periodic_ops']);
        self::assertSame(['hard_stop', 20], [$report['decision'], $report['exit_code']]);
    }

    public function testCanonicalVhostCombinedPrefixUsesTheSourceColumnFailClosed(): void
    {
        $line =
            'booking.example:443 127.0.0.1 - - [09/Aug/2026:20:00:00 +0200] ' .
            '"GET /health HTTP/1.1" 200 123 "-" "spoofed-external-monitor"';

        $report = $this->evaluate([$line], 'normal');

        self::assertSame(1, $report['counts']['documented_health']);
        self::assertSame(0, $report['exit_code']);
    }

    #[DataProvider('hardStopProvider')]
    public function testHardStopClassesRemainHardInBothModes(string $line, string $overlay): void
    {
        foreach (TrafficGateV1::MODES as $mode) {
            $report = $this->evaluate([$line], $mode);
            self::assertSame('hard_stop', $report['decision'], $mode);
            self::assertSame(20, $report['exit_code'], $mode);
            self::assertSame(1, $report['counts'][$overlay], $mode . ':' . $overlay);
        }
    }

    /** @return iterable<string, array{string,string}> */
    public static function hardStopProvider(): iterable
    {
        $timestamp = '[09/Aug/2026:20:00:00 +0200]';
        $line = static fn(string $source, string $user, string $method, string $target, int $status): string => sprintf(
            '%s - %s %s "%s %s HTTP/1.1" %d 123 "-" "Kuma/SPOOFED_MONITOR_UA"',
            $source,
            $user,
            $timestamp,
            $method,
            $target,
            $status,
        );

        yield 'write' => [$line('203.0.113.10', '-', 'POST', '/health', 200), 'write'];
        yield 'authenticated' => [$line('203.0.113.10', 'operator', 'GET', '/public', 200), 'authenticated'];
        yield 'customers' => [$line('203.0.113.10', '-', 'GET', '/index.php/customers', 403), 'customers_or_sensitive'];
        yield 'scanner success' => [$line('203.0.113.10', '-', 'GET', '/.env', 200), 'scanner_success'];
        yield 'five hundred' => [$line('127.0.0.1', '-', 'GET', '/health', 503), 'status_5xx'];
        yield 'unknown source' => [$line('SOURCE_UNKNOWN', '-', 'GET', '/public', 200), 'source_unknown'];
        yield 'unknown method' => [$line('203.0.113.10', '-', 'BREW', '/public', 200), 'method_unknown'];
    }

    public function testScannerRedirectAndNonDeniedFourHundredRemainHard(): void
    {
        foreach ([302, 401, 429] as $status) {
            $report = $this->evaluate(
                [$this->line('203.0.113.10', '-', 'GET', '/.env', $status)],
                'no-business-traffic',
            );
            self::assertSame('hard_stop', $report['decision'], (string) $status);
            self::assertSame(1, $report['counts']['business_or_authenticated']);
        }
    }

    public function testMalformedRecordInvalidatesEvidenceWithoutLeakingRawTraffic(): void
    {
        $sentinel = 'CUSTOMER_SECRET_EMAIL@example.invalid';
        $report = $this->evaluate(
            [$this->line('203.0.113.10', '-', 'GET', '/', 200), 'MALFORMED ' . $sentinel],
            'no-business-traffic',
        );
        $json = json_encode($report, JSON_THROW_ON_ERROR);

        self::assertSame('invalid', $report['decision']);
        self::assertSame(21, $report['exit_code']);
        self::assertFalse($report['parse_complete']);
        self::assertStringNotContainsString($sentinel, $json);
        self::assertStringNotContainsString('203.0.113.10', $json);
        self::assertStringNotContainsString('/public', $json);
    }

    public function testCurrentDotOneAndGzipAreReadCompletelyPastTwoThousandLines(): void
    {
        $oldLines = array_fill(0, 2001, $this->line('127.0.0.1', '-', 'GET', '/health', 200));
        $oldLines[] = $this->line('203.0.113.10', '-', 'GET', '/index.php/customers', 403);
        $this->write('app-access.log', [$this->line('127.0.0.1', '-', 'GET', '/', 200)]);
        $this->write('app-access.log.1', $oldLines);
        $this->writeGzip('app-access.log.2.gz', [$this->line('203.0.113.10', '-', 'GET', '/.env', 404)]);
        $before = TrafficGateV1::captureLogSet($this->workspace);
        $after = TrafficGateV1::captureLogSet($this->workspace);

        $report = TrafficGateV1::evaluate(
            $before,
            $after,
            $this->catalog,
            'deploy',
            'no-business-traffic',
            self::EPOCH,
            self::EPOCH + 1,
            self::PRODUCER_SHA,
        );

        self::assertSame(2001, $report['counts']['documented_health']);
        self::assertSame(1, $report['counts']['documented_periodic_ops']);
        self::assertSame(1, $report['counts']['denied_external']);
        self::assertSame(1, $report['counts']['customers_or_sensitive']);
        self::assertSame(2004, $report['counts']['lines_seen']);
        self::assertSame('hard_stop', $report['decision']);
    }

    public function testRenameRotationIsCompleteWhenOriginalIdentitySurvivesAsDotOne(): void
    {
        $this->write('app-access.log', [$this->line('127.0.0.1', '-', 'GET', '/health', 200)]);
        $before = TrafficGateV1::captureLogSet($this->workspace);
        self::assertTrue(rename($this->workspace . '/app-access.log', $this->workspace . '/app-access.log.1'));
        $this->write('app-access.log', [$this->line('127.0.0.1', '-', 'GET', '/', 200)]);
        $after = TrafficGateV1::captureLogSet($this->workspace);

        $report = TrafficGateV1::evaluate(
            $before,
            $after,
            $this->catalog,
            'customers-ui-smoke',
            'normal',
            self::EPOCH,
            self::EPOCH + 1,
            self::PRODUCER_SHA,
        );

        self::assertTrue($report['rotation_complete']);
        self::assertTrue($report['evidence_complete']);
        self::assertSame(0, $report['exit_code']);
    }

    public function testMissingPreWindowIdentityFailsClosed(): void
    {
        $this->write('app-access.log', [$this->line('127.0.0.1', '-', 'GET', '/health', 200)]);
        $before = TrafficGateV1::captureLogSet($this->workspace);
        self::assertTrue(unlink($this->workspace . '/app-access.log'));
        $this->write('app-access.log', [$this->line('127.0.0.1', '-', 'GET', '/', 200)]);
        $after = TrafficGateV1::captureLogSet($this->workspace);

        $report = TrafficGateV1::evaluate(
            $before,
            $after,
            $this->catalog,
            'deploy',
            'normal',
            self::EPOCH,
            self::EPOCH + 1,
            self::PRODUCER_SHA,
        );

        self::assertFalse($report['rotation_complete']);
        self::assertSame(1, $report['counts']['rotation_errors']);
        self::assertSame(['invalid', 21], [$report['decision'], $report['exit_code']]);
    }

    public function testAppendAfterCapturedCutoffIsNotReadIntoEvidence(): void
    {
        $this->write('app-access.log', [$this->line('127.0.0.1', '-', 'GET', '/health', 200)]);
        $before = TrafficGateV1::captureLogSet($this->workspace);
        $after = TrafficGateV1::captureLogSet($this->workspace);
        self::assertNotFalse(
            file_put_contents($this->workspace . '/app-access.log', "MALFORMED_POST_CUTOFF_SECRET\n", FILE_APPEND),
        );

        $report = TrafficGateV1::evaluate(
            $before,
            $after,
            $this->catalog,
            'deploy',
            'normal',
            self::EPOCH,
            self::EPOCH + 1,
            self::PRODUCER_SHA,
        );

        self::assertTrue($report['parse_complete']);
        self::assertSame(0, $report['counts']['parse_errors']);
        self::assertSame(0, $report['exit_code']);
        self::assertStringNotContainsString('POST_CUTOFF', json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function testCorruptGzipFailsClosed(): void
    {
        $this->write('app-access.log', [$this->line('127.0.0.1', '-', 'GET', '/health', 200)]);
        self::assertNotFalse(file_put_contents($this->workspace . '/app-access.log.2.gz', 'not-a-gzip'));
        $entries = TrafficGateV1::captureLogSet($this->workspace);

        $this->expectException(RuntimeException::class);
        TrafficGateV1::evaluate(
            $entries,
            $entries,
            $this->catalog,
            'deploy',
            'normal',
            self::EPOCH,
            self::EPOCH + 1,
            self::PRODUCER_SHA,
        );
    }

    public function testFingerprintsAreFixedSecretFreeHashesAndLogSetFingerprintTracksMetadata(): void
    {
        $sentinels = ['198.51.100.77', '/?email=pii@example.invalid', 'SECRET_UA'];
        $this->write('app-access.log', [$this->line($sentinels[0], '-', 'GET', $sentinels[1], 200, $sentinels[2])]);
        $before = TrafficGateV1::captureLogSet($this->workspace);
        $first = TrafficGateV1::evaluate(
            $before,
            $before,
            $this->catalog,
            'deploy',
            'normal',
            self::EPOCH,
            self::EPOCH + 1,
            self::PRODUCER_SHA,
        );
        $second = TrafficGateV1::evaluate(
            $before,
            $before,
            $this->catalog,
            'deploy',
            'normal',
            self::EPOCH,
            self::EPOCH + 1,
            self::PRODUCER_SHA,
        );

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first['producer_sha256']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first['log_set_sha256']);
        self::assertSame($first['log_set_sha256'], $second['log_set_sha256']);
        $json = json_encode($first, JSON_THROW_ON_ERROR);
        foreach ($sentinels as $sentinel) {
            self::assertStringNotContainsString($sentinel, $json);
        }

        $this->write('app-access.log.1', [$this->line('127.0.0.1', '-', 'GET', '/health', 200)]);
        $changed = TrafficGateV1::captureLogSet($this->workspace);
        $changedReport = TrafficGateV1::evaluate(
            $before,
            $changed,
            $this->catalog,
            'deploy',
            'normal',
            self::EPOCH,
            self::EPOCH + 1,
            self::PRODUCER_SHA,
        );
        self::assertNotSame($first['log_set_sha256'], $changedReport['log_set_sha256']);
    }

    public function testProducerFingerprintBindsTheCatalogActuallySelectedForTheRun(): void
    {
        $firstPath = $this->workspace . '/catalog-first.json';
        $secondPath = $this->workspace . '/catalog-second.json';
        $first = $this->catalog;
        $second = $this->catalog;
        $first['version'] = '2026-08-09.10';
        $second['version'] = '2026-08-09.11';
        self::assertNotFalse(file_put_contents($firstPath, json_encode($first, JSON_THROW_ON_ERROR)));
        self::assertNotFalse(file_put_contents($secondPath, json_encode($second, JSON_THROW_ON_ERROR)));

        self::assertNotSame(trafficGateProducerSha256($firstPath), trafficGateProducerSha256($secondPath));
    }

    public function testDirectEvaluatorAcceptsDocumentedSpaceSeparatedArguments(): void
    {
        $options = trafficGateParseArguments([
            'traffic_gate_v1.php',
            '--purpose',
            'deploy',
            '--mode',
            'normal',
            '--window-seconds',
            '90',
            '--output-json',
            '/tmp/traffic-gate.json',
        ]);

        self::assertSame('deploy', $options['purpose']);
        self::assertSame('normal', $options['mode']);
        self::assertSame('90', $options['window-seconds']);
        self::assertSame('/tmp/traffic-gate.json', $options['output-json']);
    }

    public function testCliAndProducerExposeFrozenExitContractWithoutReadingProduction(): void
    {
        $php = $this->runCommand(['php', 'scripts/ops/traffic_gate_v1.php']);
        $shell = $this->runCommand(['bash', 'scripts/ops/prod_traffic_gate.sh', '--purpose', 'deploy']);
        $help = $this->runCommand(['bash', 'scripts/ops/prod_traffic_gate.sh', '--help']);

        self::assertSame(64, $php['exit_code']);
        self::assertSame("traffic_gate status=invalid reason=invocation\n", $php['output']);
        self::assertSame(64, $shell['exit_code']);
        self::assertSame(0, $help['exit_code']);
        self::assertStringContainsString(
            '0 allow/advisory, 20 traffic hard stop, 21 invalid/incomplete',
            $help['output'],
        );
    }

    /**
     * @param list<string> $lines
     * @return array<string, mixed>
     */
    private function evaluate(array $lines, string $mode): array
    {
        $this->write('app-access.log', $lines);
        $entries = TrafficGateV1::captureLogSet($this->workspace);

        return TrafficGateV1::evaluate(
            $entries,
            $entries,
            $this->catalog,
            'customers-ui-smoke',
            $mode,
            self::EPOCH,
            self::EPOCH + 1,
            self::PRODUCER_SHA,
        );
    }

    private function line(
        string $source,
        string $user,
        string $method,
        string $target,
        int $status,
        string $userAgent = 'fixture-agent',
    ): string {
        return sprintf(
            '%s - %s [09/Aug/2026:20:00:00 +0200] "%s %s HTTP/1.1" %d 123 "https://referrer.invalid/" "%s"',
            $source,
            $user,
            $method,
            $target,
            $status,
            $userAgent,
        );
    }

    /** @param list<string> $lines */
    private function write(string $name, array $lines): void
    {
        self::assertNotFalse(file_put_contents($this->workspace . '/' . $name, implode("\n", $lines) . "\n"));
    }

    /** @param list<string> $lines */
    private function writeGzip(string $name, array $lines): void
    {
        $encoded = gzencode(implode("\n", $lines) . "\n");
        self::assertIsString($encoded);
        self::assertNotFalse(file_put_contents($this->workspace . '/' . $name, $encoded));
    }

    /**
     * @param list<string> $command
     * @return array{exit_code:int,output:string}
     */
    private function runCommand(array $command): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__, 3));
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit_code' => proc_close($process),
            'output' => ($stdout === false ? '' : $stdout) . ($stderr === false ? '' : $stderr),
        ];
    }
}
