<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

class ProviderUiSmokeGateContractTest extends TestCase
{
    public function testGateUsesEphemeralStorageStateAndLeavesNoBrowserArtifacts(): void
    {
        $gate = $this->readRepoFile('scripts/release-gate/provider_ui_smoke.php');

        self::assertStringContainsString("['open', 'about:blank']", $gate);
        self::assertStringContainsString("['state-load', \$statePath]", $gate);
        self::assertStringContainsString('unlink($statePath)', $gate);
        self::assertStringContainsString("putenv('PLAYWRIGHT_MCP_OUTPUT_DIR=' . \$tempDirectory)", $gate);
        self::assertStringContainsString('providerUiSmokeRemovePrivateTempDirectory($tempDirectory)', $gate);
        self::assertLessThan(strpos($gate, 'unlink($statePath)'), strpos($gate, "['state-load', \$statePath]"));
        self::assertStringNotContainsString("['screenshot'", $gate);
        self::assertStringNotContainsString("['network'", $gate);
        self::assertStringNotContainsString("['tracing-start'", $gate);
        self::assertStringNotContainsString("['tracing-stop'", $gate);
    }

    public function testPlaywrightSnippetEnforcesAllowlistAndEmitsNoRawErrors(): void
    {
        $snippet = $this->readRepoFile('scripts/release-gate/playwright/provider_ui_smoke.js');

        self::assertStringContainsString("context.route('**/*', routeHandler)", $snippet);
        self::assertStringContainsString("route.abort('blockedbyclient')", $snippet);
        self::assertStringContainsString('!value.startsWith(allowedOrigin)', $snippet);
        self::assertStringContainsString("!remainder.startsWith('/')", $snippet);
        self::assertStringContainsString('blocked_request_count', $snippet);
        self::assertStringContainsString('script_vars_safe', $snippet);
        self::assertStringContainsString('window.vars(key) === undefined', $snippet);
        self::assertStringContainsString('return `${resultPrefix}${JSON.stringify(result)}`;', $snippet);
        self::assertStringNotContainsString('console.log(`${resultPrefix}', $snippet);
        self::assertStringNotContainsString('error.message', $snippet);
        self::assertStringNotContainsString('message.text()', $snippet);
        self::assertStringNotContainsString('page.screenshot', $snippet);
        self::assertStringNotContainsString('context.tracing', $snippet);
        self::assertStringNotContainsString("context.unroute('**/*'", $snippet);
        self::assertStringContainsString('isAllowedMetricsRequest(parsedRequestUrl, request)', $snippet);
        self::assertStringContainsString('allowedMetricRanges', $snippet);
        self::assertStringContainsString('parseAllowedHttpUrl', $snippet);
        self::assertStringContainsString('parseFormEncoded', $snippet);
        self::assertStringNotContainsString('new URL(', $snippet);
        self::assertStringNotContainsString('URLSearchParams', $snippet);
    }

    public function testGateReportDetailsAcceptOnlyBooleansAndCounts(): void
    {
        $gate = $this->readRepoFile('scripts/release-gate/provider_ui_smoke.php');

        self::assertStringContainsString('providerUiSmokeSafeDetails', $gate);
        self::assertStringContainsString("putenv('PLAYWRIGHT_MCP_BROWSER=' . \$config['browser'])", $gate);
        self::assertStringContainsString('!is_bool($value) && !is_int($value) && !is_float($value)', $gate);
        self::assertStringNotContainsString("'text' => \$inspection['text']", $gate);
        self::assertStringNotContainsString("'stdout' =>", $gate);
        self::assertStringNotContainsString("'stderr' =>", $gate);
    }

    public function testGateConsumesCredentialStreamBeforeDeploymentHashAssertion(): void
    {
        $gate = $this->readRepoFile('scripts/release-gate/provider_ui_smoke.php');
        $credentialCheck = strpos($gate, "\$runCheck('credentials_contract'");
        $deploymentCheck = strpos($gate, "'preparation_four_note_lines_active_deployment'");

        self::assertIsInt($credentialCheck);
        self::assertIsInt($deploymentCheck);
        self::assertLessThan($deploymentCheck, $credentialCheck);
    }

    public function testStandaloneGateContractMatchesApplicationFixtureConstants(): void
    {
        $contract = $this->readRepoFile('scripts/release-gate/lib/ProviderUiSmokeContract.php');
        $fixture = $this->readRepoFile('application/libraries/Provider_ui_smoke_fixture.php');

        foreach (
            [
                'provider-ui-smoke-v1@synthetic.invalid',
                'customer-provider-ui-smoke-v1@synthetic.invalid',
                'Parent UI Smoke V1',
                '0000000000',
                'PROD_PROVIDER_UI_SMOKE_V1_PRIVATE_NOTE_SENTINEL',
                '__EA_PROVIDER_UI_SMOKE_V1_APPOINTMENT_BOOKED_INSIDE__',
                '__EA_PROVIDER_UI_SMOKE_V1_APPOINTMENT_CANCELLED_INSIDE__',
                '__EA_PROVIDER_UI_SMOKE_V1_APPOINTMENT_BOOKED_OUTSIDE__',
                '2099-02-12',
                '2099-02-01',
                '2099-02-28',
                '10:00',
                '10:30',
            ]
            as $expected
        ) {
            self::assertStringContainsString($expected, $contract);
            self::assertStringContainsString($expected, $fixture);
        }
    }

    private function readRepoFile(string $relativePath): string
    {
        $content = file_get_contents(__DIR__ . '/../../../' . $relativePath);
        self::assertIsString($content);

        return $content;
    }
}
