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
    private string $monitorSourcesPath;
    /** @var list<string> */
    private array $tcpStatePaths;
    /** @var array<string, mixed> */
    private array $catalog;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/traffic-gate-v1-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->workspace, 0700));
        $canonicalWorkspace = realpath($this->workspace);
        self::assertIsString($canonicalWorkspace);
        $this->workspace = $canonicalWorkspace;
        $this->monitorSourcesPath = $this->workspace . '/monitor-sources.json';
        self::assertNotFalse(
            file_put_contents(
                $this->monitorSourcesPath,
                json_encode(
                    [
                        'schema' => 'traffic_gate_monitor_sources.v1',
                        'version' => '2026-08-09.1',
                        'exact_cidrs' => ['198.51.100.23/32', '2001:db8::23/128'],
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );
        self::assertTrue(chmod($this->monitorSourcesPath, 0600));
        $this->tcpStatePaths = [$this->workspace . '/tcp', $this->workspace . '/tcp6'];
        foreach ($this->tcpStatePaths as $path) {
            self::assertNotFalse(file_put_contents($path, "  sl  local_address rem_address   st\n"));
        }
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

    public function testExternalScannerNotFoundIsDeniedAdvisory(): void
    {
        $report = $this->evaluate([$this->line('203.0.113.10', '-', 'GET', '/.env', 404)], 'normal');

        self::assertSame(1, $report['counts']['denied_external']);
        self::assertSame(['advisory', 0], [$report['decision'], $report['exit_code']]);
    }

    public function testCatalogLoopbackCidrsControlDocumentedSourceTrust(): void
    {
        $catalog = $this->catalog;
        $catalog['documented_sources']['loopback_cidrs'] = ['127.0.0.1/32', '::1/128'];
        $path = $this->workspace . '/narrow-source-catalog.json';
        self::assertNotFalse(file_put_contents($path, json_encode($catalog, JSON_THROW_ON_ERROR)));
        $catalog = TrafficGateV1::loadCatalog($path);
        $this->write('app-access.log', [$this->line('127.0.0.2', '-', 'GET', '/', 200)]);
        $entries = TrafficGateV1::captureLogSet($this->workspace);

        $report = TrafficGateV1::evaluate(
            $entries,
            $entries,
            $catalog,
            'customers-ui-smoke',
            'no-business-traffic',
            self::EPOCH,
            self::EPOCH + 1,
            self::PRODUCER_SHA,
        );

        self::assertSame(0, $report['counts']['documented_periodic_ops']);
        self::assertSame(1, $report['counts']['public_read']);
    }

    public function testExactRuntimeMonitorSourceRecognizesOnlyTheConfiguredHost(): void
    {
        $catalog = $this->catalog;
        $payload = file_get_contents($this->monitorSourcesPath);
        self::assertIsString($payload);
        $catalog['documented_sources']['monitor_exact_cidrs'] = TrafficGateV1::parseMonitorSourcesPayload($payload);
        foreach (
            [
                ['/health', 200, 'documented_health'],
                ['/index.php/healthz', 200, 'documented_health'],
                ['/', 200, 'documented_periodic_ops'],
            ]
            as [$path, $status, $class]
        ) {
            $this->write('app-access.log', [$this->line('198.51.100.23', '-', 'GET', $path, $status)]);
            $entries = TrafficGateV1::captureLogSet($this->workspace);
            $report = TrafficGateV1::evaluate(
                $entries,
                $entries,
                $catalog,
                'customers-ui-smoke',
                'normal',
                self::EPOCH,
                self::EPOCH + 1,
                self::PRODUCER_SHA,
            );
            self::assertSame(1, $report['counts'][$class], $path);
            self::assertSame(0, $report['exit_code'], $path);
            self::assertStringNotContainsString('198.51.100.23', json_encode($report, JSON_THROW_ON_ERROR), $path);
        }

        $this->write('app-access.log', [$this->line('198.51.100.24', '-', 'GET', '/health', 200)]);
        $entries = TrafficGateV1::captureLogSet($this->workspace);
        $untrusted = TrafficGateV1::evaluate(
            $entries,
            $entries,
            $catalog,
            'customers-ui-smoke',
            'normal',
            self::EPOCH,
            self::EPOCH + 1,
            self::PRODUCER_SHA,
        );
        self::assertSame(1, $untrusted['counts']['public_read']);
        self::assertSame(['hard_stop', 20], [$untrusted['decision'], $untrusted['exit_code']]);
    }

    #[DataProvider('invalidMonitorSourceProvider')]
    public function testRuntimeMonitorSourcesRejectMissingUnsafeOrBroadCidrs(?array $payload): void
    {
        $this->expectException(RuntimeException::class);
        TrafficGateV1::parseMonitorSourcesPayload($payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{array<string,mixed>|null}> */
    public static function invalidMonitorSourceProvider(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [
            [
                'schema' => 'traffic_gate_monitor_sources.v1',
                'version' => '2026-08-09.1',
                'exact_cidrs' => [],
            ],
        ];
        yield 'broad IPv4' => [
            [
                'schema' => 'traffic_gate_monitor_sources.v1',
                'version' => '2026-08-09.1',
                'exact_cidrs' => ['172.16.0.0/12'],
            ],
        ];
        yield 'broad IPv6' => [
            [
                'schema' => 'traffic_gate_monitor_sources.v1',
                'version' => '2026-08-09.1',
                'exact_cidrs' => ['2001:db8::/64'],
            ],
        ];
        yield 'not an address' => [
            [
                'schema' => 'traffic_gate_monitor_sources.v1',
                'version' => '2026-08-09.1',
                'exact_cidrs' => ['monitor.internal/32'],
            ],
        ];
    }

    public function testRuntimeMonitorSourcePermissionsMustBeProtected(): void
    {
        self::assertTrue(chmod($this->monitorSourcesPath, 0644));

        $this->expectException(RuntimeException::class);
        TrafficGateV1::loadCatalog(
            dirname(__DIR__, 3) . '/scripts/ops/config/traffic_gate_catalog.v1.json',
            $this->monitorSourcesPath,
        );
    }

    public function testMissingRuntimeMonitorSourceFailsThroughTheProductionCatalogLoader(): void
    {
        $this->expectException(RuntimeException::class);
        TrafficGateV1::loadCatalog(
            dirname(__DIR__, 3) . '/scripts/ops/config/traffic_gate_catalog.v1.json',
            $this->workspace . '/missing-monitor-sources.json',
        );
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

    #[DataProvider('backofficeRouteProvider')]
    public function testEveryBackofficeGetPrefixRemainsHardWithoutApacheRemoteUser(string $prefix): void
    {
        foreach (['/' . $prefix, '/index.php/' . $prefix] as $path) {
            $report = $this->evaluate([$this->line('203.0.113.10', '-', 'GET', $path, 200)], 'no-business-traffic');
            self::assertSame(['hard_stop', 20], [$report['decision'], $report['exit_code']], $path);
            self::assertSame(1, $report['counts']['customers_or_sensitive'], $path);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function backofficeRouteProvider(): iterable
    {
        foreach (
            [
                'about',
                'account',
                'admins',
                'api_settings',
                'appointments',
                'backend',
                'backend_api',
                'blocked_periods',
                'booking_settings',
                'business_settings',
                'caldav',
                'calendar',
                'consents',
                'customers',
                'dashboard',
                'dashboard/export',
                'dashboard_export',
                'general_settings',
                'google',
                'google_analytics_settings',
                'integrations',
                'ldap_settings',
                'legal_settings',
                'localization',
                'matomo_analytics_settings',
                'providers',
                'secretaries',
                'service_categories',
                'services',
                'unavailabilities',
                'update',
                'user',
                'webhooks',
            ]
            as $prefix
        ) {
            yield $prefix => [$prefix];
        }
    }

    #[DataProvider('encodedHardStopTargetProvider')]
    public function testEncodedScannerAndBackofficeTargetsCannotBecomePublicAdvisory(
        string $target,
        string $overlay,
    ): void {
        $report = $this->evaluate([$this->line('203.0.113.10', '-', 'GET', $target, 200)], 'no-business-traffic');

        self::assertSame(['hard_stop', 20], [$report['decision'], $report['exit_code']]);
        self::assertSame(1, $report['counts'][$overlay]);
    }

    /** @return iterable<string, array{string,string}> */
    public static function encodedHardStopTargetProvider(): iterable
    {
        yield 'lowercase dot' => ['/%2eenv', 'scanner_success'];
        yield 'uppercase dot' => ['/%2Eenv', 'scanner_success'];
        yield 'double encoded dot' => ['/%252eenv', 'scanner_success'];
        yield 'encoded scanner query' => ['/?probe=%77p-admin', 'scanner_success'];
        yield 'encoded backoffice route' => ['/index.php/%64ashboard', 'customers_or_sensitive'];
        yield 'malformed encoding' => ['/public%2', 'target_unknown'];
        yield 'decoded control' => ['/public%00', 'target_unknown'];
    }

    #[DataProvider('customerLifecycleReadProvider')]
    public function testTokenizedCustomerLifecycleReadsRemainHardInBothModes(string $target): void
    {
        foreach (['normal', 'no-business-traffic'] as $mode) {
            $report = $this->evaluate([$this->line('203.0.113.10', '-', 'GET', $target, 200)], $mode);

            self::assertSame(['hard_stop', 20], [$report['decision'], $report['exit_code']], $mode);
            self::assertSame(1, $report['counts']['business_or_authenticated'], $mode);
            self::assertSame(1, $report['counts']['customers_or_sensitive'], $mode);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function customerLifecycleReadProvider(): iterable
    {
        yield 'booking reschedule' => ['/booking/reschedule/opaque-token'];
        yield 'index booking reschedule' => ['/index.php/booking/reschedule/opaque-token'];
        yield 'booking confirmation' => ['/booking_confirmation/of/opaque-token'];
        yield 'index booking confirmation' => ['/index.php/booking_confirmation/of/opaque-token'];
        yield 'booking captcha' => ['/captcha'];
        yield 'index booking captcha' => ['/index.php/captcha'];
        yield 'booking captcha refresh' => ['/captcha?1723233600'];
    }

    #[DataProvider('nonCanonicalSensitiveTargetProvider')]
    public function testNonCanonicalSensitiveTargetsFailClosed(string $target): void
    {
        foreach (['normal', 'no-business-traffic'] as $mode) {
            $report = $this->evaluate([$this->line('203.0.113.10', '-', 'GET', $target, 200)], $mode);

            self::assertSame(['hard_stop', 20], [$report['decision'], $report['exit_code']], $mode);
            self::assertSame(1, $report['counts']['unclassified'], $mode);
            self::assertSame(1, $report['counts']['target_unknown'], $mode);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function nonCanonicalSensitiveTargetProvider(): iterable
    {
        yield 'parent segment' => ['/x/../index.php/dashboard'];
        yield 'encoded parent segment' => ['/x/%2e%2e/index.php/dashboard'];
        yield 'double encoded parent segment' => ['/x/%252e%252e/index.php/dashboard'];
        yield 'current segment' => ['/./index.php/dashboard'];
        yield 'repeated slash' => ['/index.php//dashboard'];
        yield 'encoded slash' => ['/index.php/%2fdashboard'];
    }

    public function testPublicBookingPageRemainsAdvisoryInNoBusinessMode(): void
    {
        $report = $this->evaluate([$this->line('203.0.113.10', '-', 'GET', '/booking', 200)], 'no-business-traffic');

        self::assertSame(1, $report['counts']['public_read']);
        self::assertSame(['advisory', 0], [$report['decision'], $report['exit_code']]);
    }

    public function testEncodedUtf8PublicReadRemainsAdvisoryInNoBusinessMode(): void
    {
        $report = $this->evaluate([$this->line('203.0.113.10', '-', 'GET', '/caf%C3%A9', 200)], 'no-business-traffic');

        self::assertSame(1, $report['counts']['public_read']);
        self::assertSame(['advisory', 0], [$report['decision'], $report['exit_code']]);
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

    public function testEveryRepoFixedScannerProbeSuccessRemainsHard(): void
    {
        $specs = $this->runCommand([
            'bash',
            '-c',
            'source scripts/ops/lib/prod_scanner_paths.sh; prod_scanner_path_specs',
        ]);
        self::assertSame(0, $specs['exit_code'], $specs['output']);

        foreach (array_filter(explode("\n", $specs['output'])) as $spec) {
            [$kind, $label, $target] = explode('|', $spec, 3);
            self::assertContains($kind, ['scanner_path', 'scanner_query'], $label);
            $report = $this->evaluate([$this->line('203.0.113.10', '-', 'GET', $target, 200)], 'no-business-traffic');

            self::assertSame(['hard_stop', 20], [$report['decision'], $report['exit_code']], $label);
            self::assertSame(1, $report['counts']['scanner_success'], $label);
        }
    }

    public function testDocumentedEnvironmentScannerSuccessRemainsHard(): void
    {
        $report = $this->evaluate(
            [$this->line('203.0.113.10', '-', 'GET', '/.environment', 200)],
            'no-business-traffic',
        );

        self::assertSame(['hard_stop', 20], [$report['decision'], $report['exit_code']]);
        self::assertSame(1, $report['counts']['scanner_success']);
    }

    #[DataProvider('scannerInventoryLookalikeProvider')]
    public function testScannerInventoryAdditionsDoNotWidenToPrefixLookalikes(string $target): void
    {
        $report = $this->evaluate([$this->line('203.0.113.10', '-', 'GET', $target, 200)], 'no-business-traffic');

        self::assertSame(1, $report['counts']['public_read']);
        self::assertSame(0, $report['counts']['scanner_success']);
        self::assertSame(['advisory', 0], [$report['decision'], $report['exit_code']]);
    }

    /** @return iterable<string, array{string}> */
    public static function scannerInventoryLookalikeProvider(): iterable
    {
        yield 'environment suffix' => ['/.environment.production'];
        yield 'nested phpinfo suffix' => ['/administrator/phpinfo.php/extra'];
        yield 'wp config suffix' => ['/wp-config.php.bak'];
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

    public function testEscapedApacheHeaderFieldsRemainParseable(): void
    {
        $line =
            '127.0.0.1 - - [09/Aug/2026:20:00:00 +0200] "GET /health HTTP/1.1" 200 123 ' .
            '"https://referrer.invalid/escaped\\"quote" "agent\\\\with\\"quote"';
        $report = $this->evaluate([$line], 'normal');

        self::assertSame(0, $report['counts']['parse_errors']);
        self::assertSame(1, $report['counts']['documented_health']);
        self::assertSame(['allow', 0], [$report['decision'], $report['exit_code']]);
    }

    public function testValidPostWindowRecordIsExcludedWithoutInvalidatingEvidence(): void
    {
        $this->write('app-access.log', [
            $this->line('127.0.0.1', '-', 'GET', '/health', 200),
            str_replace(
                '[09/Aug/2026:20:00:00 +0200]',
                '[09/Aug/2026:20:00:02 +0200]',
                $this->line('203.0.113.10', '-', 'POST', '/index.php/customers', 200),
            ),
        ]);
        $entries = TrafficGateV1::captureLogSet($this->workspace);

        $report = TrafficGateV1::evaluate(
            $entries,
            $entries,
            $this->catalog,
            'deploy',
            'normal',
            self::EPOCH,
            self::EPOCH + 1,
            self::PRODUCER_SHA,
        );

        self::assertSame(2, $report['counts']['lines_seen']);
        self::assertSame(1, $report['counts']['lines_in_window']);
        self::assertSame(0, $report['counts']['parse_errors']);
        self::assertSame(['allow', 0], [$report['decision'], $report['exit_code']]);
    }

    public function testPreWindowRequestCompletedAfterInitialSnapshotFailsClosed(): void
    {
        $old = str_replace(
            '[09/Aug/2026:20:00:00 +0200]',
            '[09/Aug/2026:19:59:50 +0200]',
            $this->line('127.0.0.1', '-', 'GET', '/health', 200),
        );
        $preWindowCompletion = str_replace(
            '[09/Aug/2026:20:00:00 +0200]',
            '[09/Aug/2026:19:59:59 +0200]',
            $this->line('203.0.113.10', '-', 'GET', '/index.php/dashboard', 200),
        );
        $this->write('app-access.log', [$old]);
        $before = TrafficGateV1::captureLogSet($this->workspace);
        self::assertNotFalse(
            file_put_contents($this->workspace . '/app-access.log', $preWindowCompletion . "\n", FILE_APPEND),
        );
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

        self::assertSame(1, $report['counts']['pre_window_completion']);
        self::assertSame(1, $report['counts']['customers_or_sensitive']);
        self::assertSame(['hard_stop', 20], [$report['decision'], $report['exit_code']]);
    }

    public function testActiveHttpConnectionSnapshotCountsOnlyEstablishedHttpPorts(): void
    {
        self::assertNotFalse(
            file_put_contents(
                $this->tcpStatePaths[0],
                "  sl  local_address rem_address   st\n" .
                    "0: 0100007F:0050 0200007F:C350 01\n" .
                    "1: 0100007F:01BB 0200007F:C351 01\n" .
                    "2: 0100007F:0016 0200007F:C352 01\n" .
                    "3: 0100007F:01BB 0200007F:C353 06\n",
            ),
        );

        self::assertSame(2, TrafficGateV1::captureActiveHttpConnections($this->tcpStatePaths));
    }

    public function testMalformedActiveRequestSignalFailsClosed(): void
    {
        self::assertNotFalse(file_put_contents($this->tcpStatePaths[0], "not a tcp table\n"));

        $this->expectException(RuntimeException::class);
        TrafficGateV1::captureActiveHttpConnections($this->tcpStatePaths);
    }

    public function testMissingActiveRequestSignalFailsClosed(): void
    {
        $this->expectException(RuntimeException::class);
        TrafficGateV1::captureActiveHttpConnections([$this->workspace . '/missing-tcp']);
    }

    public function testCollectionFixesCutoffBeforeFinalActiveSignalAndSnapshotWithoutRetry(): void
    {
        $this->write('app-access.log', [$this->line('127.0.0.1', '-', 'GET', '/health', 200)]);
        $entries = TrafficGateV1::captureLogSet($this->workspace);
        $events = [];
        $clockValues = [self::EPOCH, self::EPOCH + 90];
        $report = trafficGateCollectReport(
            [
                'purpose' => 'deploy',
                'mode' => 'normal',
                'window-seconds' => '90',
                'log-dir' => $this->workspace,
            ],
            $this->catalog,
            self::PRODUCER_SHA,
            function () use (&$events, &$clockValues): int {
                $events[] = 'clock';
                return array_shift($clockValues);
            },
            function (int $seconds) use (&$events): void {
                $events[] = 'sleep:' . $seconds;
            },
            function () use (&$events): int {
                $events[] = 'active';
                return 0;
            },
            function () use (&$events, $entries): array {
                $events[] = 'capture';
                return $entries;
            },
        );

        self::assertSame(['clock', 'active', 'capture', 'sleep:90', 'clock', 'active', 'capture'], $events);
        self::assertSame(self::EPOCH + 90, $report['window_end_epoch']);
        self::assertSame(0, $report['exit_code']);
    }

    public function testActiveConnectionAtEitherBoundaryFailsBeforeAllowWithoutRetry(): void
    {
        foreach (
            [
                'start boundary' => [[1], []],
                'end boundary' => [[0, 1], ['capture', 'sleep:90']],
            ]
            as $case => [$activeValues, $expectedEvents]
        ) {
            $events = [];
            $clockValues = [self::EPOCH, self::EPOCH + 90];
            try {
                trafficGateCollectReport(
                    [
                        'purpose' => 'deploy',
                        'mode' => 'normal',
                        'window-seconds' => '90',
                        'log-dir' => $this->workspace,
                    ],
                    $this->catalog,
                    self::PRODUCER_SHA,
                    static fn(): int => array_shift($clockValues),
                    function (int $seconds) use (&$events): void {
                        $events[] = 'sleep:' . $seconds;
                    },
                    static function () use (&$activeValues): int {
                        return array_shift($activeValues);
                    },
                    function () use (&$events): array {
                        $events[] = 'capture';
                        return [];
                    },
                );
                self::fail('active traffic boundary must fail closed');
            } catch (RuntimeException) {
                self::assertSame($expectedEvents, $events, $case);
            }
        }
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

    public function testGzipAppendAfterCapturedCutoffIsNotReadIntoEvidence(): void
    {
        $this->writeGzip('app-access.log.2.gz', [$this->line('127.0.0.1', '-', 'GET', '/health', 200)]);
        $entries = TrafficGateV1::captureLogSet($this->workspace);
        $appended = gzencode($this->line('203.0.113.10', '-', 'POST', '/index.php/customers', 200) . "\n");
        self::assertIsString($appended);
        self::assertNotFalse(file_put_contents($this->workspace . '/app-access.log.2.gz', $appended, FILE_APPEND));

        $report = TrafficGateV1::evaluate(
            $entries,
            $entries,
            $this->catalog,
            'deploy',
            'normal',
            self::EPOCH,
            self::EPOCH + 1,
            self::PRODUCER_SHA,
        );

        self::assertSame(1, $report['counts']['lines_seen']);
        self::assertSame(1, $report['counts']['documented_health']);
        self::assertSame(0, $report['counts']['write']);
        self::assertSame(['allow', 0], [$report['decision'], $report['exit_code']]);
    }

    public function testCapturedConcatenatedGzipMembersAreReadCompletely(): void
    {
        $first = gzencode($this->line('127.0.0.1', '-', 'GET', '/health', 200) . "\n");
        $second = gzencode($this->line('203.0.113.10', '-', 'GET', '/.env', 404) . "\n");
        self::assertIsString($first);
        self::assertIsString($second);
        self::assertNotFalse(file_put_contents($this->workspace . '/app-access.log.2.gz', $first . $second));
        $entries = TrafficGateV1::captureLogSet($this->workspace);

        $report = TrafficGateV1::evaluate(
            $entries,
            $entries,
            $this->catalog,
            'deploy',
            'normal',
            self::EPOCH,
            self::EPOCH + 1,
            self::PRODUCER_SHA,
        );

        self::assertSame(2, $report['counts']['lines_seen']);
        self::assertSame(1, $report['counts']['documented_health']);
        self::assertSame(1, $report['counts']['denied_external']);
        self::assertSame(['advisory', 0], [$report['decision'], $report['exit_code']]);
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
        $withSources = trafficGateProducerSha256($firstPath, null, $this->monitorSourcesPath);
        self::assertNotFalse(file_put_contents($this->monitorSourcesPath, "\n", FILE_APPEND));
        self::assertNotSame($withSources, trafficGateProducerSha256($firstPath, null, $this->monitorSourcesPath));
    }

    public function testProducerFingerprintBindsEveryRuntimeProducerFile(): void
    {
        $runtimePaths = [];
        foreach (['TrafficGateV1.php', 'traffic_gate_v1.php', 'prod_traffic_gate.sh'] as $name) {
            $path = $this->workspace . '/' . $name;
            self::assertNotFalse(file_put_contents($path, 'producer:' . $name));
            $runtimePaths[] = $path;
        }
        $catalogPath = $this->workspace . '/producer-catalog.json';
        self::assertNotFalse(file_put_contents($catalogPath, json_encode($this->catalog, JSON_THROW_ON_ERROR)));
        $baseline = trafficGateProducerSha256($catalogPath, $runtimePaths);
        foreach ($runtimePaths as $index => $path) {
            self::assertNotFalse(file_put_contents($path, "\npolicy-change", FILE_APPEND));
            self::assertNotSame($baseline, trafficGateProducerSha256($catalogPath, $runtimePaths));
            self::assertNotFalse(file_put_contents($path, 'producer:' . basename($path)));
            self::assertSame($baseline, trafficGateProducerSha256($catalogPath, $runtimePaths), (string) $index);
        }
    }

    public function testProducerFingerprintBindsTheRealDefaultRuntimeSet(): void
    {
        $repository = dirname(__DIR__, 3);
        $catalogPath = $repository . '/scripts/ops/config/traffic_gate_catalog.v1.json';
        $producerPaths = [
            $repository . '/scripts/ops/lib/TrafficGateV1.php',
            $repository . '/scripts/ops/traffic_gate_v1.php',
            $repository . '/scripts/ops/prod_traffic_gate.sh',
            $catalogPath,
            $this->monitorSourcesPath,
        ];
        $hashes = [];
        foreach ($producerPaths as $path) {
            $hash = hash_file('sha256', $path);
            self::assertIsString($hash);
            $hashes[] = $hash;
        }
        $expected = hash('sha256', implode("\n", $hashes));

        self::assertSame($expected, trafficGateProducerSha256($catalogPath, null, $this->monitorSourcesPath));
    }

    public function testRootWrapperCanProduceASuccessReportWithTheFixedProductionSignal(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('The production wrapper is intentionally root-only.');
        }
        if (!is_readable('/proc/net/tcp') || !is_readable('/proc/net/tcp6')) {
            self::markTestSkipped('The fixed production connection signal is unavailable.');
        }

        $repository = dirname(__DIR__, 3);
        $catalogPath = $repository . '/scripts/ops/config/traffic_gate_catalog.v1.json';
        $outputPath = $this->workspace . '/traffic-gate-report.json';
        $timestamp = (new \DateTimeImmutable('-5 minutes'))->format('d/M/Y:H:i:s O');
        $this->write('app-access.log', [
            sprintf('127.0.0.1 - - [%s] "GET /health HTTP/1.1" 200 123 "-" "fixture-agent"', $timestamp),
        ]);

        $result = $this->runCommand(
            [
                'bash',
                'scripts/ops/prod_traffic_gate.sh',
                '--purpose',
                'deploy',
                '--mode',
                'normal',
                '--window-seconds',
                '1',
                '--output-json',
                $outputPath,
            ],
            [
                'TRAFFIC_GATE_LOG_DIR' => $this->workspace,
                'TRAFFIC_GATE_CATALOG_PATH' => $catalogPath,
                'TRAFFIC_GATE_MONITOR_SOURCES_PATH' => $this->monitorSourcesPath,
            ],
        );

        self::assertSame(0, $result['exit_code'], $result['output']);
        $report = json_decode((string) file_get_contents($outputPath), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertSame('allow', $report['decision']);
        self::assertSame(0600, fileperms($outputPath) & 0777);
        self::assertSame(
            trafficGateProducerSha256($catalogPath, null, $this->monitorSourcesPath),
            $report['producer_sha256'],
        );
    }

    public function testInvalidInvocationAtomicallyInvalidatesAStaleSuccessReport(): void
    {
        $outputPath = $this->workspace . '/stale-invalid-invocation.json';
        $this->writeStaleSuccess($outputPath);

        $exitCode = trafficGateMain(['traffic_gate_v1.php', '--output-json', $outputPath]);

        self::assertSame(64, $exitCode);
        self::assertSame('', file_get_contents($outputPath));
        self::assertSame(0600, fileperms($outputPath) & 0777);
    }

    public function testInvalidInvocationInvalidatesAnOlderTrafficGateOutput(): void
    {
        $outputPath = $this->workspace . '/stale-arbitrary-output';
        self::assertNotFalse(
            file_put_contents(
                $outputPath,
                json_encode(
                    [
                        'schema' => 'traffic_gate.v0',
                        'decision' => 'allow',
                        'exit_code' => 0,
                        'window_end_epoch' => self::EPOCH,
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );
        self::assertTrue(chmod($outputPath, 0644));

        $exitCode = trafficGateMain(['traffic_gate_v1.php', '--output-json', $outputPath]);

        self::assertSame(64, $exitCode);
        self::assertSame('', file_get_contents($outputPath));
        self::assertSame(0600, fileperms($outputPath) & 0777);
    }

    public function testInvalidInvocationCannotReplaceAnUnrecognizedRegularFile(): void
    {
        $outputPath = $this->workspace . '/unrecognized-output';
        $contents = 'NOT A TRAFFIC GATE ARTIFACT';
        self::assertNotFalse(file_put_contents($outputPath, $contents));
        self::assertTrue(chmod($outputPath, 0600));

        $exitCode = trafficGateMain(['traffic_gate_v1.php', '--output-json', $outputPath]);

        self::assertSame(64, $exitCode);
        self::assertSame($contents, file_get_contents($outputPath));
    }

    public function testValidInvocationCannotReplaceAnUnrecognizedRegularFile(): void
    {
        $outputPath = $this->workspace . '/unrecognized-valid-output';
        $contents = 'ROOT OWNED CONTENT MUST SURVIVE';
        self::assertNotFalse(file_put_contents($outputPath, $contents));
        self::assertTrue(chmod($outputPath, 0600));

        $exitCode = trafficGateMain([
            'traffic_gate_v1.php',
            '--purpose',
            'deploy',
            '--mode',
            'normal',
            '--window-seconds',
            '1',
            '--output-json',
            $outputPath,
        ]);

        self::assertSame(21, $exitCode);
        self::assertSame($contents, file_get_contents($outputPath));
    }

    public function testInvalidInvocationCannotReplaceSymlinkOrMultiplyLinkedOutput(): void
    {
        $targetPath = $this->workspace . '/protected-target';
        $symlinkPath = $this->workspace . '/symlink-output';
        $hardlinkPath = $this->workspace . '/hardlink-output';
        $this->writeStaleSuccess($targetPath);
        $contents = file_get_contents($targetPath);
        self::assertIsString($contents);
        self::assertTrue(symlink($targetPath, $symlinkPath));
        self::assertTrue(link($targetPath, $hardlinkPath));

        self::assertSame(64, trafficGateMain(['traffic_gate_v1.php', '--output-json', $symlinkPath]));
        self::assertSame(64, trafficGateMain(['traffic_gate_v1.php', '--output-json', $hardlinkPath]));

        self::assertTrue(is_link($symlinkPath));
        self::assertSame($contents, file_get_contents($targetPath));
        self::assertSame($contents, file_get_contents($hardlinkPath));
    }

    public function testCollectionFailureCannotLeaveAStaleSuccessReport(): void
    {
        $outputPath = $this->workspace . '/stale-collection.json';
        $this->writeStaleSuccess($outputPath);

        $exitCode = trafficGateMain([
            'traffic_gate_v1.php',
            '--purpose',
            'deploy',
            '--mode',
            'normal',
            '--window-seconds',
            '1',
            '--output-json',
            $outputPath,
            '--log-dir',
            $this->workspace . '/missing',
            '--catalog',
            dirname(__DIR__, 3) . '/scripts/ops/config/traffic_gate_catalog.v1.json',
            '--monitor-sources',
            $this->monitorSourcesPath,
        ]);

        self::assertSame(21, $exitCode);
        self::assertSame('', file_get_contents($outputPath));
    }

    public function testGzipEvidenceFailureCannotLeaveAStaleSuccessReport(): void
    {
        $outputPath = $this->workspace . '/stale-evidence.json';
        $this->writeStaleSuccess($outputPath);
        $this->write('app-access.log', [$this->line('127.0.0.1', '-', 'GET', '/health', 200)]);
        self::assertNotFalse(file_put_contents($this->workspace . '/app-access.log.2.gz', 'not-a-gzip'));

        $exitCode = trafficGateMain([
            'traffic_gate_v1.php',
            '--purpose',
            'deploy',
            '--mode',
            'normal',
            '--window-seconds',
            '1',
            '--output-json',
            $outputPath,
            '--log-dir',
            $this->workspace,
            '--catalog',
            dirname(__DIR__, 3) . '/scripts/ops/config/traffic_gate_catalog.v1.json',
            '--monitor-sources',
            $this->monitorSourcesPath,
        ]);

        self::assertSame(21, $exitCode);
        self::assertSame('', file_get_contents($outputPath));
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
        $stalePath = $this->workspace . '/stale-wrapper-invocation.json';
        $lateStalePath = $this->workspace . '/late-stale-wrapper-invocation.json';
        $protectedPath = $this->workspace . '/protected-wrapper-target';
        $protectedContents = 'DO NOT REPLACE';
        $this->writeStaleSuccess($stalePath);
        $this->writeStaleSuccess($lateStalePath);
        self::assertNotFalse(file_put_contents($protectedPath, $protectedContents));
        self::assertTrue(chmod($protectedPath, 0600));
        $php = $this->runCommand(['php', 'scripts/ops/traffic_gate_v1.php']);
        $shell = $this->runCommand(['bash', 'scripts/ops/prod_traffic_gate.sh', '--purpose', 'deploy']);
        $staleShell = $this->runCommand([
            'bash',
            'scripts/ops/prod_traffic_gate.sh',
            '--purpose',
            'deploy',
            '--output-json',
            $stalePath,
            '--unsupported',
            'value',
        ]);
        $lateStaleShell = $this->runCommand([
            'bash',
            'scripts/ops/prod_traffic_gate.sh',
            '--unsupported',
            'value',
            '--output-json',
            $lateStalePath,
        ]);
        $protectedShell = $this->runCommand([
            'bash',
            'scripts/ops/prod_traffic_gate.sh',
            '--purpose',
            'deploy',
            '--output-json',
            $protectedPath,
            '--unsupported',
            'value',
        ]);
        $help = $this->runCommand(['bash', 'scripts/ops/prod_traffic_gate.sh', '--help']);

        self::assertSame(64, $php['exit_code']);
        self::assertSame("traffic_gate status=invalid reason=invocation\n", $php['output']);
        self::assertSame(64, $shell['exit_code']);
        self::assertSame(64, $staleShell['exit_code']);
        self::assertSame(64, $lateStaleShell['exit_code']);
        self::assertSame(64, $protectedShell['exit_code']);
        self::assertSame('', file_get_contents($stalePath));
        self::assertSame('', file_get_contents($lateStalePath));
        self::assertSame($protectedContents, file_get_contents($protectedPath));
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

    private function writeStaleSuccess(string $path): void
    {
        self::assertNotFalse(
            file_put_contents(
                $path,
                json_encode(
                    [
                        'schema' => TrafficGateV1::SCHEMA,
                        'decision' => 'allow',
                        'exit_code' => 0,
                        'window_end_epoch' => self::EPOCH,
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );
        self::assertTrue(chmod($path, 0600));
    }

    /**
     * @param list<string> $command
     * @param array<string, string>|null $environment
     * @return array{exit_code:int,output:string}
     */
    private function runCommand(array $command, ?array $environment = null): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        if ($environment !== null) {
            $currentEnvironment = getenv();
            self::assertIsArray($currentEnvironment);
            $environment = array_merge($currentEnvironment, $environment);
        }
        $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__, 3), $environment);
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
