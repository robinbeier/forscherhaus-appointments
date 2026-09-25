<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Exact inactive HTTP boundary for the public CSP Report-Only receiver. */
final class CspReportHttpTest extends TestCase
{
    private ?DefenseCycleHttpServer $server = null;
    private bool $ownedConfig = false;
    private bool $ownedAggregate = false;
    private bool $ownedConfigDirectory = false;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || !is_file('/.dockerenv')) {
            self::markTestSkipped('Requires the explicitly owned isolated Docker runner.');
        }

        $this->server = new DefenseCycleHttpServer();
    }

    protected function tearDown(): void
    {
        try {
            $this->server?->close();
        } finally {
            $this->cleanupOwnedRateLimitState();
        }
    }

    public function testInactiveCollectorUsesOnlyTheExactPostRoute(): void
    {
        self::assertNotNull($this->server);
        $aggregatePath = \Csp_report_only::aggregatePath();
        $aggregateExistedBefore = is_file($aggregatePath);
        $aggregateBefore = $aggregateExistedBefore ? (string) file_get_contents($aggregatePath) : null;
        $client = new GateHttpClient(
            $this->server->baseUrl,
            additionalHeaders: [
                'Accept' => '*/*',
                'Content-Type' => 'application/csp-report',
            ],
        );

        $exact = $client->post('csp-report', ['synthetic' => 'fixed'], withCsrfToken: false);
        self::assertSame(404, $exact->statusCode);
        self::assertNull($exact->header('content-security-policy'));
        self::assertNull($exact->header('content-security-policy-report-only'));

        $automaticControllerPath = $client->post('csp_report', ['synthetic' => 'fixed'], withCsrfToken: false);
        self::assertSame(403, $automaticControllerPath->statusCode);
        self::assertNull($automaticControllerPath->header('content-security-policy'));
        self::assertNull($automaticControllerPath->header('content-security-policy-report-only'));

        $wrongMethod = $client->get('csp-report');
        self::assertSame(404, $wrongMethod->statusCode);
        self::assertNull($wrongMethod->header('content-security-policy'));
        self::assertNull($wrongMethod->header('content-security-policy-report-only'));

        self::assertSame($aggregateExistedBefore, is_file($aggregatePath));
        if ($aggregateExistedBefore) {
            self::assertSame($aggregateBefore, (string) file_get_contents($aggregatePath));
        }
    }

    /**
     * Exercise the controller's early return after the first accepted report.
     * The fixed production config path is created only when absent and is
     * removed again only when this test created it.
     */
    public function testRateLimitedBatchReturns429AndPersistsOnlyOneReport(): void
    {
        self::assertNotNull($this->server);
        $this->prepareOwnedRateLimitState();
        $payload = [
            [
                'type' => 'csp-violation',
                'body' => [
                    'document-uri' => 'https://app.example.test/calendar',
                    'effective-directive' => 'script-src',
                    'blocked-uri' => 'inline',
                    'disposition' => 'report',
                ],
            ],
            [
                'type' => 'csp-violation',
                'body' => [
                    'document-uri' => 'https://app.example.test/calendar',
                    'effective-directive' => 'script-src',
                    'blocked-uri' => 'inline',
                    'disposition' => 'report',
                ],
            ],
        ];

        $response = $this->server
            ->client()
            ->requestRawApp('POST', 'csp-report', json_encode($payload, JSON_THROW_ON_ERROR), 'application/csp-report');
        self::assertSame(429, $response->statusCode, $response->body);

        $aggregatePath = \Csp_report_only::aggregatePath();
        self::assertFileExists($aggregatePath);
        $summary = \Csp_report_only::summarizeAggregateJson((string) file_get_contents($aggregatePath), [
            'retention_hours' => 48,
        ]);
        self::assertIsArray($summary);
        self::assertSame(1, $summary['accepted']);
        self::assertSame(0, $summary['dropped']['rate_limited']);
        $aggregate = json_decode((string) file_get_contents($aggregatePath), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($aggregate);
        self::assertSame(1, $aggregate['rate_window']['count']);
    }

    public function testMalformedActiveReportReturns204WithoutAggregateOrLock(): void
    {
        self::assertNotNull($this->server);
        $this->prepareOwnedRateLimitState();

        $response = $this->server
            ->client()
            ->requestRawApp('POST', 'csp-report', '{"csp-report":{"disposition":"report"}}', 'application/csp-report');
        self::assertSame(204, $response->statusCode, $response->body);

        $aggregatePath = \Csp_report_only::aggregatePath();
        self::assertFileDoesNotExist($aggregatePath);
        self::assertFileDoesNotExist($aggregatePath . '.lock');
    }

    private function prepareOwnedRateLimitState(): void
    {
        $configDirectory = dirname(\Csp_report_only::CONFIG_PATH);
        $configPath = \Csp_report_only::CONFIG_PATH;
        $aggregatePath = \Csp_report_only::aggregatePath();

        if (
            is_link($configDirectory) ||
            is_link($configPath) ||
            is_link($aggregatePath) ||
            is_link($aggregatePath . '.lock')
        ) {
            throw new RuntimeException('CSP isolated paths must not be symlinks.');
        }
        if (is_file($configPath) || is_file($aggregatePath) || is_file($aggregatePath . '.lock')) {
            throw new RuntimeException('CSP isolated paths already contain state.');
        }

        if (!is_dir($configDirectory)) {
            if (!mkdir($configDirectory, 0700, true)) {
                throw new RuntimeException('Could not create the isolated CSP config directory.');
            }
            $this->ownedConfigDirectory = true;
        }
        if (!is_dir($configDirectory) || is_link($configDirectory)) {
            throw new RuntimeException('CSP config directory is not a safe directory.');
        }

        $config = [
            'schema' => \Csp_report_only::CONFIG_SCHEMA,
            'enabled' => true,
            'app_host' => 'app.example.test',
            'www_host' => 'www.example.test',
            'google_analytics_enabled' => false,
            'matomo_origin' => null,
            'max_reports_per_minute' => 1,
            'retention_hours' => 48,
        ];
        $handle = @fopen($configPath, 'x');
        if (!is_resource($handle)) {
            $this->cleanupOwnedRateLimitState();
            throw new RuntimeException('Could not create the CSP config without clobbering state.');
        }
        $json = json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        $written = fwrite($handle, $json);
        fclose($handle);
        if ($written !== strlen($json) || !chmod($configPath, 0644)) {
            @unlink($configPath);
            $this->cleanupOwnedRateLimitState();
            throw new RuntimeException('Could not safely write the isolated CSP config.');
        }
        $this->ownedConfig = true;
        $this->ownedAggregate = true;
    }

    private function cleanupOwnedRateLimitState(): void
    {
        $aggregatePath = \Csp_report_only::aggregatePath();
        if ($this->ownedAggregate && is_file($aggregatePath)) {
            unlink($aggregatePath);
        }
        if ($this->ownedAggregate && is_file($aggregatePath . '.lock')) {
            unlink($aggregatePath . '.lock');
        }
        if ($this->ownedConfig && is_file(\Csp_report_only::CONFIG_PATH)) {
            unlink(\Csp_report_only::CONFIG_PATH);
        }
        if ($this->ownedConfigDirectory && is_dir(dirname(\Csp_report_only::CONFIG_PATH))) {
            rmdir(dirname(\Csp_report_only::CONFIG_PATH));
        }
        $this->ownedAggregate = false;
        $this->ownedConfig = false;
        $this->ownedConfigDirectory = false;
    }
}
