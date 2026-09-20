<?php

namespace Tests\Unit\Libraries;

use Csp_report_only;
use PHPUnit\Framework\TestCase;

require_once APPPATH . 'core/Csp_report_only.php';

final class CspReportOnlyTest extends TestCase
{
    private function temporaryRoot(): string
    {
        return realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
    }

    private function config(array $overrides = []): array
    {
        return array_merge(
            [
                'schema' => Csp_report_only::CONFIG_SCHEMA,
                'enabled' => true,
                'app_host' => 'app.example.test',
                'www_host' => 'www.example.test',
                'google_analytics_enabled' => true,
                'matomo_origin' => null,
                'max_reports_per_minute' => 2,
                'retention_hours' => 2,
            ],
            $overrides,
        );
    }

    public function testConfigParserIsStrictAndSecretFree(): void
    {
        self::assertStringNotContainsString('/../', Csp_report_only::aggregatePath());
        $parsed = Csp_report_only::parseConfig(json_encode($this->config(), JSON_THROW_ON_ERROR));
        self::assertSame('app.example.test', $parsed['app_host']);
        $missingKey = $this->config();
        unset($missingKey['retention_hours']);
        self::assertNull(Csp_report_only::parseConfig(json_encode($missingKey, JSON_THROW_ON_ERROR)));
        self::assertNull(
            Csp_report_only::parseConfig(
                '{"schema":"csp_report_only_config.v1","enabled":true,"app_host":"app.example.test","www_host":"www.example.test","unexpected":"x"}',
            ),
        );
        self::assertNull(
            Csp_report_only::parseConfig(
                json_encode(
                    $this->config(['matomo_origin' => 'https://matomo.example.test/path']),
                    JSON_THROW_ON_ERROR,
                ),
            ),
        );
        self::assertSame('https://matomo.example.test', Csp_report_only::strictOrigin('https://matomo.example.test'));
        self::assertSame(
            'https://matomo.example.test:8443',
            Csp_report_only::strictOrigin('https://matomo.example.test:8443'),
        );
    }

    public function testInactiveProductionCandidateHasTheExpectedContract(): void
    {
        $path = dirname(__DIR__, 3) . '/scripts/ops/config/csp_report_only.production.v1.json';
        if (!is_file($path)) {
            self::markTestSkipped('Production candidate is supplied by the operations lane.');
        }
        $config = Csp_report_only::parseConfig((string) file_get_contents($path));
        self::assertIsArray($config);
        self::assertIsBool($config['enabled']);
        self::assertSame(120, $config['max_reports_per_minute']);
        self::assertSame(48, $config['retention_hours']);
    }

    public function testPolicyRequiresExactHttpsHtmlHostAndNeverTargetsCollector(): void
    {
        $config = $this->config(['matomo_origin' => 'https://matomo.example.test']);
        $server = ['HTTP_HOST' => 'app.example.test', 'HTTPS' => 'on', 'REQUEST_URI' => '/booking'];
        $policy = Csp_report_only::policyForRequest($server, $config, 'text/html');
        self::assertIsArray($policy);
        self::assertStringContainsString('Content-Security-Policy-Report-Only:', $policy['header']);
        self::assertStringContainsString("object-src 'none'", $policy['header']);
        self::assertStringContainsString('https://matomo.example.test', $policy['header']);
        self::assertNull(Csp_report_only::policyForRequest($server, $config, 'application/json'));
        self::assertNull(
            Csp_report_only::policyForRequest(
                ['HTTP_HOST' => 'other.example.test', 'HTTPS' => 'on'],
                $config,
                'text/html',
            ),
        );
        self::assertNull(
            Csp_report_only::policyForRequest(
                ['HTTP_HOST' => 'app.example.test', 'HTTPS' => 'on', 'REQUEST_URI' => '/csp-report'],
                $config,
                'text/html',
            ),
        );
        self::assertNull(
            Csp_report_only::policyForRequest(
                ['HTTP_HOST' => 'app.example.test', 'HTTPS' => 'off'],
                $config,
                'text/html',
            ),
        );
        self::assertTrue(Csp_report_only::isCollectorRequest(['REQUEST_URI' => '/csp-report?fixed=1']));
        self::assertFalse(Csp_report_only::isCollectorRequest(['REQUEST_URI' => '/csp_report']));
        self::assertFalse(Csp_report_only::isCollectorRequest(['REQUEST_URI' => '/csp-report/']));
    }

    public function testResponseContentTypeReadsRawCaseInsensitiveHeadersBeforeDefaultHtml(): void
    {
        $output = new class {
            public function get_header(string $name): string
            {
                return 'application/json; charset=UTF-8';
            }

            public function get_content_type(): string
            {
                return 'text/html';
            }
        };
        self::assertSame('application/json', Csp_report_only::responseContentType($output));

        $image = new class {
            public function get_header(string $name): string
            {
                return 'image/png';
            }
        };
        self::assertSame('image/png', Csp_report_only::responseContentType($image));

        $api = new class {
            public function get_header(string $name): ?string
            {
                return null;
            }

            public function get_content_type(): string
            {
                return 'text/html';
            }
        };
        self::assertNull(Csp_report_only::responseContentType($api, ['REQUEST_URI' => '/api/v1/customers']));
        self::assertNull(Csp_report_only::responseContentType($api, ['REQUEST_URI' => '/index.php/api/v1/customers']));
    }

    public function testAppWwwAnalyticsAndExcludedSurfaceMatrix(): void
    {
        $states = [
            'disabled' => [$this->config(['google_analytics_enabled' => false]), []],
            'google' => [
                $this->config(['google_analytics_enabled' => true]),
                ['https://www.google-analytics.com', 'https://www.googletagmanager.com'],
            ],
            'matomo' => [
                $this->config([
                    'google_analytics_enabled' => false,
                    'matomo_origin' => 'https://matomo.example.test:8443',
                ]),
                ['https://matomo.example.test:8443'],
            ],
        ];

        foreach ($states as $state => [$config, $expectedOrigins]) {
            foreach (['app.example.test', 'www.example.test'] as $host) {
                $policy = Csp_report_only::policyForRequest(
                    [
                        'HTTP_HOST' => $host,
                        'HTTPS' => 'on',
                        'REQUEST_URI' => '/booking',
                    ],
                    $config,
                    'text/html; charset=UTF-8',
                );
                self::assertIsArray($policy, $state . ':' . $host);
                foreach ($expectedOrigins as $origin) {
                    self::assertStringContainsString($origin, $policy['header'], $state . ':' . $host);
                }
                if ($state === 'disabled') {
                    self::assertStringNotContainsString('google-analytics.com', $policy['header']);
                    self::assertStringNotContainsString('googletagmanager.com', $policy['header']);
                    self::assertStringNotContainsString('matomo.example.test', $policy['header']);
                }
            }
        }

        $config = $this->config();
        self::assertNull(
            Csp_report_only::policyForRequest(['HTTP_HOST' => 'monitor.example.test', 'HTTPS' => 'on'], $config),
        );
        self::assertNull(
            Csp_report_only::policyForRequest(
                ['HTTP_HOST' => 'app.example.test', 'HTTPS' => 'on'],
                $config,
                'application/json',
            ),
        );
        self::assertNull(
            Csp_report_only::policyForRequest(
                ['HTTP_HOST' => 'app.example.test', 'HTTPS' => 'on'],
                $config,
                'application/pdf',
            ),
        );
        self::assertNull(
            Csp_report_only::policyForRequest(
                ['HTTP_HOST' => 'app.example.test', 'HTTPS' => 'on'],
                $config,
                'application/zip',
            ),
        );
    }

    public function testLegacyAndReportingApiReportsBecomeFixedClassesOnly(): void
    {
        $config = $this->config();
        $legacy = Csp_report_only::classifyReport(
            [
                'csp-report' => [
                    'document-uri' => 'https://app.example.test/booking?secret=never-store',
                    'effective-directive' => 'script-src',
                    'blocked-uri' => 'https://www.google-analytics.com/collect?secret=never-store',
                    'disposition' => 'report',
                    'source-file' => 'https://attacker.invalid/private',
                ],
            ],
            $config,
        );
        self::assertSame(
            [
                'surface' => 'app',
                'directive' => 'script-src',
                'blocked_origin' => 'google-analytics',
                'disposition' => 'report',
            ],
            $legacy,
        );
        self::assertSame(
            ['surface' => 'www', 'directive' => 'other', 'blocked_origin' => 'self', 'disposition' => 'report'],
            Csp_report_only::classifyReport(
                [
                    [
                        'type' => 'csp-violation',
                        'body' => [
                            'documentURL' => 'https://www.example.test/',
                            'effectiveDirective' => 'unknown-directive',
                            'blockedURL' => 'self',
                            'disposition' => 'report',
                        ],
                    ],
                ],
                $config,
            ),
        );
        self::assertNull(
            Csp_report_only::classifyReport(
                ['csp-report' => ['document-uri' => 'https://app.example.test/', 'disposition' => 'enforce']],
                $config,
            ),
        );
        self::assertNull(
            Csp_report_only::classifyReport(
                ['csp-report' => ['document-uri' => 'https://other.example.test/']],
                $config,
            ),
        );
        self::assertSame(
            'self',
            Csp_report_only::classifyReport(
                [
                    'csp-report' => [
                        'document-uri' => 'https://app.example.test/booking',
                        'effective-directive' => 'img-src',
                        'blocked-uri' => 'https://app.example.test/private/path?token=never-store',
                        'disposition' => 'report',
                    ],
                ],
                $config,
            )['blocked_origin'],
        );
        self::assertSame(
            'unknown-external',
            Csp_report_only::blockedOriginClass('http://www.google-analytics.com/collect', $config),
        );
        self::assertSame(
            'unknown-external',
            Csp_report_only::blockedOriginClass('https://www.google-analytics.com:444/collect', $config),
        );
        self::assertSame(
            'unknown-external',
            Csp_report_only::blockedOriginClass(
                'https://www.google-analytics.com/collect',
                $this->config(['google_analytics_enabled' => false]),
            ),
        );
    }

    public function testReportingApiBatchesAreBoundedAndEveryEntryIsClassified(): void
    {
        $entry = static fn(string $host, string $blocked): array => [
            'type' => 'csp-violation',
            'body' => [
                'documentURL' => 'https://' . $host . '/private?never=store',
                'effectiveDirective' => 'connect-src',
                'blockedURL' => $blocked,
                'disposition' => 'report',
            ],
        ];
        $batch = [
            $entry('app.example.test', 'https://www.google-analytics.com/collect'),
            $entry('www.example.test', 'https://matomo.example.test:8443/matomo.php'),
        ];
        $classified = Csp_report_only::classifyReports(
            $batch,
            $this->config(['matomo_origin' => 'https://matomo.example.test:8443']),
        );
        self::assertCount(2, $classified);
        self::assertSame('app', $classified[0]['surface']);
        self::assertSame('google-analytics', $classified[0]['blocked_origin']);
        self::assertSame('www', $classified[1]['surface']);
        self::assertSame('matomo', $classified[1]['blocked_origin']);
        self::assertNull(Csp_report_only::classifyReport($batch, $this->config()));
        self::assertNull(
            Csp_report_only::classifyReports(
                array_fill(0, Csp_report_only::MAX_REPORTS_PER_BATCH + 1, $entry('app.example.test', 'self')),
                $this->config(),
            ),
        );
    }

    public function testAggregateIsBoundedRateLimitedAndContainsNoRawReport(): void
    {
        $directory = $this->temporaryRoot() . '/csp-report-only-' . bin2hex(random_bytes(4));
        mkdir($directory, 0700, true);
        $path = $directory . '/aggregate.json';
        $config = $this->config(['max_reports_per_minute' => 1]);
        $report = [
            'surface' => 'app',
            'directive' => 'script-src',
            'blocked_origin' => 'unknown-external',
            'disposition' => 'report',
        ];
        self::assertSame('accepted', Csp_report_only::record($report, $config, $path, 1700000000)['status']);
        self::assertSame('rate_limited', Csp_report_only::record($report, $config, $path, 1700000001)['status']);
        $state = json_decode((string) file_get_contents($path), true);
        self::assertSame(Csp_report_only::AGGREGATE_SCHEMA, $state['schema']);
        self::assertArrayHasKey('rate_window', $state);
        self::assertArrayHasKey('dropped', $state);
        self::assertArrayHasKey('buckets', $state);
        $serialized = json_encode($state, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secret=never-store', $serialized);
        self::assertCount(1, $state['buckets']);
        $summary = Csp_report_only::summarizeAggregateJson((string) file_get_contents($path), $config, 1700000001);
        self::assertSame('csp_report_only_aggregate.v1', $summary['schema']);
        self::assertSame(1, $summary['accepted']);
        self::assertSame(1, $summary['classes']['blocked_origin']['unknown-external']);
        self::assertNull(Csp_report_only::summarizeAggregateJson('{"schema":"bad"}', $config));
        $state['buckets']['2023-11-14T22:00:00Z']['counts']['app']['script-src'] = 'corrupt';
        self::assertNull(Csp_report_only::summarizeAggregateJson(json_encode($state, JSON_THROW_ON_ERROR), $config));
        self::assertSame(
            'invalid_report',
            Csp_report_only::record(
                [
                    'surface' => 'attacker-controlled',
                    'directive' => 'script-src',
                    'blocked_origin' => 'unknown-external',
                    'disposition' => 'report',
                ],
                $config,
                $path,
                1700000002,
            )['reason'],
        );
        unlink($path);
        rmdir($directory);
    }

    public function testAggregateWriterRejectsSymlinksHardlinksAndOversizedState(): void
    {
        $directory = $this->temporaryRoot() . '/csp-report-only-identity-' . bin2hex(random_bytes(4));
        mkdir($directory, 0700, true);
        $realPath = $directory . '/real.json';
        $symlinkPath = $directory . '/symlink.json';
        $hardlinkPath = $directory . '/hardlink.json';
        file_put_contents($realPath, '');
        symlink($realPath, $symlinkPath);
        link($realPath, $hardlinkPath);
        $report = [
            'surface' => 'app',
            'directive' => 'script-src',
            'blocked_origin' => 'self',
            'disposition' => 'report',
        ];

        try {
            self::assertSame(
                'storage_unavailable',
                Csp_report_only::record($report, $this->config(), $symlinkPath)['reason'],
            );
            self::assertSame(
                'storage_unavailable',
                Csp_report_only::record($report, $this->config(), $hardlinkPath)['reason'],
            );
            unlink($hardlinkPath);
            file_put_contents($realPath, str_repeat('x', Csp_report_only::MAX_AGGREGATE_BYTES + 1));
            self::assertSame(
                'storage_unavailable',
                Csp_report_only::record($report, $this->config(), $realPath)['reason'],
            );
        } finally {
            if (is_link($symlinkPath)) {
                unlink($symlinkPath);
            }
            if (is_file($hardlinkPath)) {
                unlink($hardlinkPath);
            }
            if (is_file($realPath)) {
                unlink($realPath);
            }
            rmdir($directory);
        }
    }

    public function testAggregateRetentionUsesElapsedHoursAcrossSparseAndConsecutiveReports(): void
    {
        $directory = $this->temporaryRoot() . '/csp-report-only-retention-' . bin2hex(random_bytes(4));
        mkdir($directory, 0700, true);
        $path = $directory . '/aggregate.json';
        $config = $this->config(['max_reports_per_minute' => 100, 'retention_hours' => 2]);
        $report = [
            'surface' => 'app',
            'directive' => 'script-src',
            'blocked_origin' => 'self',
            'disposition' => 'report',
        ];
        $baseHour = intdiv(1700000000, 3600) * 3600;

        try {
            self::assertSame('accepted', Csp_report_only::record($report, $config, $path, $baseHour + 1)['status']);
            self::assertSame('accepted', Csp_report_only::record($report, $config, $path, $baseHour + 3601)['status']);
            self::assertSame('accepted', Csp_report_only::record($report, $config, $path, $baseHour + 7201)['status']);

            $summary = Csp_report_only::summarizeAggregateJson(
                (string) file_get_contents($path),
                $config,
                $baseHour + 7201,
            );
            self::assertSame(2, $summary['bucket_count']);
            self::assertSame(2, $summary['accepted']);

            self::assertSame('accepted', Csp_report_only::record($report, $config, $path, $baseHour + 18001)['status']);
            $summary = Csp_report_only::summarizeAggregateJson(
                (string) file_get_contents($path),
                $config,
                $baseHour + 18001,
            );
            self::assertSame(1, $summary['bucket_count']);
            self::assertSame(1, $summary['accepted']);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
