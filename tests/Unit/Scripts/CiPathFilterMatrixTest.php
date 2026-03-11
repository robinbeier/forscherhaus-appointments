<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

class CiPathFilterMatrixTest extends TestCase
{
    public function testCoverageGateScriptChangeOnlyTriggersCoverageHeavyJobs(): void
    {
        $matches = $this->applyFilters(['scripts/ci/check_coverage_delta.php']);

        self::assertTrue($matches['coverage_required']);
        self::assertTrue($matches['deep_bootstrap_required']);
        self::assertFalse($matches['heavy_job_trends_required']);
        self::assertFalse($matches['pdf_renderer_latency_required']);
        self::assertFalse($matches['request_contracts_required']);
        self::assertFalse($matches['api_contract']);
        self::assertFalse($matches['booking_flows']);
        self::assertFalse($matches['integration_smoke']);
        self::assertFalse($matches['ldap_guardrail_required']);
        self::assertFalse($matches['write_contract_booking']);
        self::assertFalse($matches['write_contract_api']);
    }

    public function testRequestContractHarnessChangeDoesNotFanOutIntoCoverageOrRuntimeSuites(): void
    {
        $matches = $this->applyFilters(['scripts/ci/check_request_contract_adoption.php']);

        self::assertTrue($matches['request_contracts_required']);
        self::assertFalse($matches['coverage_required']);
        self::assertFalse($matches['deep_bootstrap_required']);
        self::assertFalse($matches['heavy_job_trends_required']);
        self::assertFalse($matches['pdf_renderer_latency_required']);
        self::assertFalse($matches['api_contract']);
        self::assertFalse($matches['booking_flows']);
        self::assertFalse($matches['integration_smoke']);
        self::assertFalse($matches['ldap_guardrail_required']);
        self::assertFalse($matches['write_contract_booking']);
        self::assertFalse($matches['write_contract_api']);
    }

    public function testScriptsUnitChangeDoesNotTriggerDeepOrCoverageHeavyJobs(): void
    {
        $matches = $this->applyFilters(['tests/Unit/Scripts/CiPathFilterMatrixTest.php']);

        self::assertFalse($matches['request_contracts_required']);
        self::assertFalse($matches['deep_bootstrap_required']);
        self::assertFalse($matches['coverage_required']);
        self::assertFalse($matches['heavy_job_trends_required']);
        self::assertFalse($matches['pdf_renderer_latency_required']);
        self::assertFalse($matches['api_contract']);
        self::assertFalse($matches['booking_flows']);
        self::assertFalse($matches['integration_smoke']);
        self::assertFalse($matches['ldap_guardrail_required']);
        self::assertFalse($matches['write_contract_booking']);
        self::assertFalse($matches['write_contract_api']);
    }

    public function testBookingControllerChangeKeepsRuntimeAndCoverageProtection(): void
    {
        $matches = $this->applyFilters(['application/controllers/Booking.php']);

        self::assertTrue($matches['request_contracts_required']);
        self::assertTrue($matches['deep_bootstrap_required']);
        self::assertTrue($matches['coverage_required']);
        self::assertFalse($matches['heavy_job_trends_required']);
        self::assertFalse($matches['pdf_renderer_latency_required']);
        self::assertFalse($matches['api_contract']);
        self::assertTrue($matches['booking_flows']);
        self::assertTrue($matches['integration_smoke']);
        self::assertFalse($matches['ldap_guardrail_required']);
        self::assertTrue($matches['write_contract_booking']);
        self::assertTrue($matches['write_contract_api']);
    }

    public function testWorkflowEditsStillRerunAllSpecializedHeavyFilters(): void
    {
        $matches = $this->applyFilters(['.github/workflows/ci.yml']);

        self::assertTrue($matches['request_contracts_required']);
        self::assertTrue($matches['deep_bootstrap_required']);
        self::assertTrue($matches['coverage_required']);
        self::assertTrue($matches['heavy_job_trends_required']);
        self::assertTrue($matches['pdf_renderer_latency_required']);
        self::assertTrue($matches['api_contract']);
        self::assertTrue($matches['booking_flows']);
        self::assertTrue($matches['integration_smoke']);
        self::assertTrue($matches['ldap_guardrail_required']);
        self::assertTrue($matches['write_contract_booking']);
        self::assertTrue($matches['write_contract_api']);
    }

    public function testBookingFlowsConfigChangeStillTriggersBootstrapProducer(): void
    {
        $matches = $this->applyFilters(['phpunit.booking-flows.xml']);

        self::assertFalse($matches['request_contracts_required']);
        self::assertTrue($matches['deep_bootstrap_required']);
        self::assertFalse($matches['coverage_required']);
        self::assertFalse($matches['heavy_job_trends_required']);
        self::assertFalse($matches['pdf_renderer_latency_required']);
        self::assertFalse($matches['api_contract']);
        self::assertTrue($matches['booking_flows']);
        self::assertFalse($matches['integration_smoke']);
        self::assertFalse($matches['ldap_guardrail_required']);
        self::assertFalse($matches['write_contract_booking']);
        self::assertFalse($matches['write_contract_api']);
    }

    public function testModelChangeStillTriggersRequestContractsGate(): void
    {
        $matches = $this->applyFilters(['application/models/Settings_model.php']);

        self::assertTrue($matches['request_contracts_required']);
        self::assertTrue($matches['deep_bootstrap_required']);
        self::assertTrue($matches['coverage_required']);
        self::assertFalse($matches['heavy_job_trends_required']);
        self::assertFalse($matches['pdf_renderer_latency_required']);
        self::assertTrue($matches['api_contract']);
        self::assertTrue($matches['booking_flows']);
        self::assertTrue($matches['integration_smoke']);
        self::assertFalse($matches['ldap_guardrail_required']);
        self::assertTrue($matches['write_contract_booking']);
        self::assertTrue($matches['write_contract_api']);
    }

    public function testRequestContractsFilterCoversEveryAdoptionScopeFile(): void
    {
        /** @var array<int, array{file:string,methods:array<int, string>}> $scope */
        $scope = require __DIR__ . '/../../../scripts/ci/config/request_contract_adoption_scope.php';

        foreach ($scope as $entry) {
            $matches = $this->applyFilters([$entry['file']]);

            self::assertTrue(
                $matches['request_contracts_required'],
                sprintf('Expected request_contracts_required to cover %s.', $entry['file']),
            );
        }
    }

    public function testHeavyJobsReferenceSpecializedOutputs(): void
    {
        $workflow = file_get_contents($this->workflowPath());
        self::assertNotFalse($workflow);

        self::assertStringContainsString("needs.changes.outputs.request_contracts_required == 'true'", $workflow);
        self::assertStringContainsString("needs.changes.outputs.deep_bootstrap_required == 'true'", $workflow);
        self::assertStringContainsString("needs.changes.outputs.coverage_required == 'true'", $workflow);
        self::assertStringContainsString("needs.changes.outputs.heavy_job_trends_required == 'true'", $workflow);
        self::assertStringContainsString("needs.changes.outputs.pdf_renderer_latency_required == 'true'", $workflow);
        self::assertStringNotContainsString("needs.changes.outputs.deep_required == 'true'", $workflow);
    }

    public function testHeavyJobDurationTrendScriptUsesDedicatedFilterOnly(): void
    {
        $matches = $this->applyFilters(['scripts/ci/check_heavy_job_duration_trends.php']);

        self::assertFalse($matches['request_contracts_required']);
        self::assertFalse($matches['deep_bootstrap_required']);
        self::assertFalse($matches['coverage_required']);
        self::assertTrue($matches['heavy_job_trends_required']);
        self::assertFalse($matches['pdf_renderer_latency_required']);
        self::assertFalse($matches['api_contract']);
        self::assertFalse($matches['booking_flows']);
        self::assertFalse($matches['integration_smoke']);
        self::assertFalse($matches['ldap_guardrail_required']);
        self::assertFalse($matches['write_contract_booking']);
        self::assertFalse($matches['write_contract_api']);
    }

    public function testPdfRendererLatencyFilterStaysScopedToPdfRendererAndGuardFiles(): void
    {
        $matches = $this->applyFilters(['pdf-renderer/server.js']);

        self::assertFalse($matches['request_contracts_required']);
        self::assertFalse($matches['deep_bootstrap_required']);
        self::assertFalse($matches['coverage_required']);
        self::assertFalse($matches['heavy_job_trends_required']);
        self::assertTrue($matches['pdf_renderer_latency_required']);
        self::assertFalse($matches['api_contract']);
        self::assertFalse($matches['booking_flows']);
        self::assertFalse($matches['integration_smoke']);
        self::assertFalse($matches['ldap_guardrail_required']);
        self::assertFalse($matches['write_contract_booking']);
        self::assertFalse($matches['write_contract_api']);
    }

    public function testPdfRendererLatencyFilterIncludesComposeRuntimeChanges(): void
    {
        $matches = $this->applyFilters(['docker-compose.yml']);

        self::assertFalse($matches['request_contracts_required']);
        self::assertFalse($matches['deep_bootstrap_required']);
        self::assertFalse($matches['coverage_required']);
        self::assertFalse($matches['heavy_job_trends_required']);
        self::assertTrue($matches['pdf_renderer_latency_required']);
        self::assertFalse($matches['api_contract']);
        self::assertFalse($matches['booking_flows']);
        self::assertFalse($matches['integration_smoke']);
        self::assertTrue($matches['ldap_guardrail_required']);
        self::assertFalse($matches['write_contract_booking']);
        self::assertFalse($matches['write_contract_api']);
    }

    public function testLdapSmokeScriptChangeTriggersLdapGuardrailFilter(): void
    {
        $matches = $this->applyFilters(['scripts/ci/dashboard_integration_smoke.php']);

        self::assertFalse($matches['request_contracts_required']);
        self::assertTrue($matches['deep_bootstrap_required']);
        self::assertFalse($matches['coverage_required']);
        self::assertFalse($matches['heavy_job_trends_required']);
        self::assertFalse($matches['pdf_renderer_latency_required']);
        self::assertFalse($matches['api_contract']);
        self::assertFalse($matches['booking_flows']);
        self::assertTrue($matches['integration_smoke']);
        self::assertTrue($matches['ldap_guardrail_required']);
        self::assertFalse($matches['write_contract_booking']);
        self::assertFalse($matches['write_contract_api']);
    }

    public function testLdapConstantsChangeTriggersLdapGuardrailFilter(): void
    {
        $matches = $this->applyFilters(['application/config/constants.php']);

        self::assertFalse($matches['request_contracts_required']);
        self::assertTrue($matches['deep_bootstrap_required']);
        self::assertTrue($matches['coverage_required']);
        self::assertFalse($matches['heavy_job_trends_required']);
        self::assertFalse($matches['pdf_renderer_latency_required']);
        self::assertFalse($matches['api_contract']);
        self::assertTrue($matches['booking_flows']);
        self::assertTrue($matches['integration_smoke']);
        self::assertTrue($matches['ldap_guardrail_required']);
        self::assertFalse($matches['write_contract_booking']);
        self::assertTrue($matches['write_contract_api']);
    }

    public function testLdapSettingHelperChangeTriggersLdapGuardrailFilter(): void
    {
        $matches = $this->applyFilters(['application/helpers/setting_helper.php']);

        self::assertFalse($matches['request_contracts_required']);
        self::assertTrue($matches['deep_bootstrap_required']);
        self::assertTrue($matches['coverage_required']);
        self::assertFalse($matches['heavy_job_trends_required']);
        self::assertFalse($matches['pdf_renderer_latency_required']);
        self::assertFalse($matches['api_contract']);
        self::assertTrue($matches['booking_flows']);
        self::assertTrue($matches['integration_smoke']);
        self::assertTrue($matches['ldap_guardrail_required']);
        self::assertFalse($matches['write_contract_booking']);
        self::assertFalse($matches['write_contract_api']);
    }

    public function testCheckSelectionLibraryChangeTriggersLdapGuardrailFilter(): void
    {
        $matches = $this->applyFilters(['scripts/ci/lib/CheckSelection.php']);

        self::assertFalse($matches['request_contracts_required']);
        self::assertTrue($matches['deep_bootstrap_required']);
        self::assertFalse($matches['coverage_required']);
        self::assertFalse($matches['heavy_job_trends_required']);
        self::assertFalse($matches['pdf_renderer_latency_required']);
        self::assertFalse($matches['api_contract']);
        self::assertTrue($matches['booking_flows']);
        self::assertTrue($matches['integration_smoke']);
        self::assertTrue($matches['ldap_guardrail_required']);
        self::assertTrue($matches['write_contract_booking']);
        self::assertTrue($matches['write_contract_api']);
    }

    public function testIntegrationsRequestDtoFactoryChangeTriggersLdapGuardrailFilter(): void
    {
        $matches = $this->applyFilters(['application/libraries/Integrations_request_dto_factory.php']);

        self::assertTrue($matches['request_contracts_required']);
        self::assertTrue($matches['deep_bootstrap_required']);
        self::assertTrue($matches['coverage_required']);
        self::assertFalse($matches['heavy_job_trends_required']);
        self::assertFalse($matches['pdf_renderer_latency_required']);
        self::assertFalse($matches['api_contract']);
        self::assertTrue($matches['booking_flows']);
        self::assertTrue($matches['integration_smoke']);
        self::assertTrue($matches['ldap_guardrail_required']);
        self::assertTrue($matches['write_contract_booking']);
        self::assertTrue($matches['write_contract_api']);
    }

    public function testAuthRequestDtoFactoryChangeTriggersLdapGuardrailFilter(): void
    {
        $matches = $this->applyFilters(['application/libraries/Auth_request_dto_factory.php']);

        self::assertTrue($matches['request_contracts_required']);
        self::assertTrue($matches['deep_bootstrap_required']);
        self::assertTrue($matches['coverage_required']);
        self::assertFalse($matches['heavy_job_trends_required']);
        self::assertFalse($matches['pdf_renderer_latency_required']);
        self::assertFalse($matches['api_contract']);
        self::assertTrue($matches['booking_flows']);
        self::assertTrue($matches['integration_smoke']);
        self::assertTrue($matches['ldap_guardrail_required']);
        self::assertTrue($matches['write_contract_booking']);
        self::assertTrue($matches['write_contract_api']);
    }

    /**
     * @param array<int, string> $changedPaths
     * @return array<string, bool>
     */
    private function applyFilters(array $changedPaths): array
    {
        $matches = [];

        foreach ($this->loadFilters() as $name => $patterns) {
            $matches[$name] = false;

            foreach ($changedPaths as $path) {
                foreach ($patterns as $pattern) {
                    if (preg_match($this->globToRegex($pattern), $path) === 1) {
                        $matches[$name] = true;
                        break 2;
                    }
                }
            }
        }

        return $matches;
    }

    private function globToRegex(string $pattern): string
    {
        $placeholder = '__DOUBLE_STAR__';
        $quoted = preg_quote(str_replace('**', $placeholder, $pattern), '/');
        $quoted = str_replace('\*', '[^\/]*', $quoted);
        $quoted = str_replace($placeholder, '.*', $quoted);

        return '/^' . $quoted . '$/';
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function loadFilters(): array
    {
        $lines = file($this->workflowPath(), FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);

        $filters = [];
        $currentFilter = null;
        $capturing = false;

        foreach ($lines as $line) {
            if (!$capturing && str_contains($line, 'filters: |')) {
                $capturing = true;
                continue;
            }

            if ($capturing && preg_match('/^  [a-z0-9_-]+:\s*$/i', $line) === 1) {
                break;
            }

            if (!$capturing) {
                continue;
            }

            if (preg_match('/^\s{12}([a-z_]+):\s*$/', $line, $matches) === 1) {
                $currentFilter = $matches[1];
                $filters[$currentFilter] = [];
                continue;
            }

            if ($currentFilter !== null && preg_match("/^\s{14}- '([^']+)'\s*$/", $line, $matches) === 1) {
                $filters[$currentFilter][] = $matches[1];
            }
        }

        self::assertArrayHasKey('request_contracts_required', $filters);
        self::assertArrayHasKey('deep_bootstrap_required', $filters);
        self::assertArrayHasKey('coverage_required', $filters);
        self::assertArrayHasKey('heavy_job_trends_required', $filters);
        self::assertArrayHasKey('pdf_renderer_latency_required', $filters);
        self::assertArrayHasKey('ldap_guardrail_required', $filters);

        return $filters;
    }

    private function workflowPath(): string
    {
        return __DIR__ . '/../../../.github/workflows/ci.yml';
    }
}
