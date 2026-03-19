<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

class PrePrFullLdapGuardrailTest extends TestCase
{
    public function testLdapGuardrailMatcherNoLongerIncludesScriptUnitTestFiles(): void
    {
        $script = $this->prePrFullScript();

        self::assertStringNotContainsString('tests/Unit/Scripts/DeepRuntimeSuiteTest.php', $script);
        self::assertStringNotContainsString('tests/Unit/Scripts/CiPathFilterMatrixTest.php', $script);
    }

    public function testLdapGuardrailMatcherKeepsRuntimeRelevantPaths(): void
    {
        $script = $this->prePrFullScript();

        self::assertStringContainsString('application/controllers/Login.php', $script);
        self::assertStringContainsString('scripts/ci/dashboard_integration_smoke.php', $script);
        self::assertStringContainsString('docker-compose.yml', $script);
    }

    public function testLdapGuardrailExplicitOverrideFlagsRemainSupported(): void
    {
        $script = $this->prePrFullScript();

        self::assertStringContainsString('if [[ "${PRE_PR_INCLUDE_LDAP_GUARDRAIL:-}" == "1" ]]; then', $script);
        self::assertStringContainsString('if [[ "${PRE_PR_INCLUDE_LDAP_GUARDRAIL:-}" == "0" ]]; then', $script);
    }

    public function testIntegrationSmokeBrowserOpenTimeoutOverrideIsForwarded(): void
    {
        $script = $this->prePrFullScript();

        self::assertStringContainsString(
            'INTEGRATION_SMOKE_BROWSER_BOOTSTRAP_TIMEOUT="${PRE_PR_INTEGRATION_SMOKE_BROWSER_BOOTSTRAP_TIMEOUT:-180}"',
            $script,
        );
        self::assertStringContainsString(
            'INTEGRATION_SMOKE_BROWSER_OPEN_TIMEOUT="${PRE_PR_INTEGRATION_SMOKE_BROWSER_OPEN_TIMEOUT:-20}"',
            $script,
        );
        self::assertStringContainsString(
            '--integration-smoke-browser-bootstrap-timeout="${INTEGRATION_SMOKE_BROWSER_BOOTSTRAP_TIMEOUT}"',
            $script,
        );
        self::assertStringContainsString(
            '--integration-smoke-browser-open-timeout="${INTEGRATION_SMOKE_BROWSER_OPEN_TIMEOUT}"',
            $script,
        );
    }

    private function prePrFullScript(): string
    {
        $contents = file_get_contents(__DIR__ . '/../../../scripts/ci/pre_pr_full.sh');

        self::assertNotFalse($contents);

        return $contents;
    }
}
