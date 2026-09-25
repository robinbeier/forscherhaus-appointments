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
}
