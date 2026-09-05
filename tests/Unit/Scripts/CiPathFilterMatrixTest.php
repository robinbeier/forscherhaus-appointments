<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CiPathFilterMatrixTest extends TestCase
{
    public function testCoverageGateScriptChangeOnlyTriggersCoverageHeavyJobs(): void
    {
        $matches = $this->applyFilters(['scripts/ci/check_coverage_delta.php']);

        self::assertTrue($matches['coverage_required']);
        self::assertTrue($matches['deep_bootstrap_required']);
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
        self::assertFalse($matches['pdf_renderer_latency_required']);
        self::assertTrue($matches['api_contract']);
        self::assertTrue($matches['booking_flows']);
        self::assertTrue($matches['integration_smoke']);
        self::assertTrue($matches['ldap_guardrail_required']);
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
        self::assertStringContainsString(
            'deep_runtime_asset_build_required: ${{ steps.filter.outputs.integration_smoke }}',
            $workflow,
        );
        self::assertStringContainsString("needs.changes.outputs.coverage_required == 'true'", $workflow);
        self::assertStringContainsString("needs.changes.outputs.pdf_renderer_latency_required == 'true'", $workflow);
        self::assertStringNotContainsString("needs.changes.outputs.deep_required == 'true'", $workflow);
    }

    public function testDeepRuntimeAssetBuildGuardStaysPinnedToTheBrowserSuiteContract(): void
    {
        $workflow = file_get_contents($this->workflowPath());
        self::assertNotFalse($workflow);

        $seedSnapshotJob = $this->extractJobBlock($workflow, 'deep-check-seed-snapshot', 'deep-runtime-suite');
        self::assertStringNotContainsString('Setup Node.js', $seedSnapshotJob);
        self::assertStringNotContainsString('Build runtime JS assets', $seedSnapshotJob);
        self::assertStringNotContainsString('npx gulp scripts', $seedSnapshotJob);

        $deepRuntimeJob = $this->extractJobBlock($workflow, 'deep-runtime-suite', 'coverage-shard-unit');
        self::assertStringContainsString(
            "if: needs.changes.outputs.deep_runtime_asset_build_required == 'true' || needs.changes.outputs.integration_smoke == 'true'",
            $deepRuntimeJob,
        );
        self::assertSame(
            2,
            substr_count(
                $deepRuntimeJob,
                "if: needs.changes.outputs.deep_runtime_asset_build_required == 'true' || needs.changes.outputs.integration_smoke == 'true'",
            ),
        );
        self::assertStringContainsString('Build runtime JS assets', $deepRuntimeJob);
        self::assertStringContainsString(
            "if: needs.changes.outputs.deep_runtime_asset_build_required == 'true'\n        run: npx gulp scripts",
            $deepRuntimeJob,
        );
        self::assertStringContainsString('npx gulp scripts', $deepRuntimeJob);
    }

    public function testPdfRendererLatencyFilterStaysScopedToPdfRendererAndGuardFiles(): void
    {
        $matches = $this->applyFilters(['pdf-renderer/server.js']);

        self::assertFalse($matches['request_contracts_required']);
        self::assertFalse($matches['deep_bootstrap_required']);
        self::assertFalse($matches['coverage_required']);
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
        self::assertTrue($matches['pdf_renderer_latency_required']);
        self::assertFalse($matches['api_contract']);
        self::assertFalse($matches['booking_flows']);
        self::assertFalse($matches['integration_smoke']);
        self::assertTrue($matches['ldap_guardrail_required']);
        self::assertFalse($matches['write_contract_booking']);
        self::assertFalse($matches['write_contract_api']);
    }

    #[DataProvider('rendererRegressionTriggerPathProvider')]
    public function testRendererRegressionPathsTriggerRendererRegressionJob(string $path): void
    {
        $matches = $this->applyFilters([$path]);

        self::assertTrue($matches['pdf_renderer_latency_required']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rendererRegressionTriggerPathProvider(): array
    {
        return [
            'dashboard export controller' => ['application/controllers/Dashboard_export.php'],
            'pdf renderer library' => ['application/libraries/Pdf_renderer.php'],
            'teacher pdf view' => ['application/views/exports/dashboard_teacher_pdf.php'],
            'renderer source' => ['pdf-renderer/server.js'],
            'renderer regression test' => ['pdf-renderer/server.test.js'],
            'dashboard release gate' => ['scripts/release-gate/dashboard_release_gate.php'],
            'latency gate' => ['scripts/ci/check_pdf_renderer_latency.php'],
            'latency policy' => ['scripts/ci/config/pdf_renderer_latency_policy.php'],
            'view payload regression test' => ['tests/Unit/Views/DashboardTeacherPdfViewTest.php'],
        ];
    }

    public function testRendererRegressionAndLatencyStepsAreBlocking(): void
    {
        $workflow = file_get_contents($this->workflowPath());
        self::assertNotFalse($workflow);

        $job = $this->extractJobBlock($workflow, 'pdf-renderer-latency', 'architecture-ownership-map');

        self::assertStringContainsString('docker compose exec -T pdf-renderer npm test', $job);
        self::assertStringContainsString('php scripts/ci/check_pdf_renderer_latency.php', $job);
        self::assertStringContainsString('elif [ "$status" -ne 0 ]; then', $job);
        self::assertStringContainsString('exit "$status"', $job);
        self::assertStringNotContainsString('pdf-renderer-latency exited with status', $job);
        self::assertStringNotContainsString('set +e', $job);
    }

    public function testUptimeKumaDesiredStateDoesNotTriggerIntegrationSmoke(): void
    {
        $matches = $this->applyFilters([
            'docker/compose.uptime-kuma.yml',
            'docs/uptime-kuma.md',
            'scripts/ops/uptime-kuma.monitors.yml',
        ]);

        self::assertTrue($matches['deep_bootstrap_required']);
        self::assertTrue($matches['coverage_required']);
        self::assertTrue($matches['api_contract']);
        self::assertFalse($matches['integration_smoke']);
    }

    public function testMariaDbRestoreComposeDoesNotTriggerIntegrationSmoke(): void
    {
        $matches = $this->applyFilters(['docker/compose.mariadb-restore.yml']);

        self::assertTrue($matches['deep_bootstrap_required']);
        self::assertTrue($matches['coverage_required']);
        self::assertTrue($matches['api_contract']);
        self::assertFalse($matches['integration_smoke']);
    }

    public function testAppRuntimeDockerChangesStillTriggerIntegrationSmoke(): void
    {
        $paths = [
            'docker/compose.ci-local.yml',
            'docker/compose.php85-smoke.yml',
            'docker/compose.zero-surprise.yml',
            'docker/php-fpm/Dockerfile',
            'docker/nginx/nginx.conf',
            'docker/ldap/seed/00-readonly-bind-user.ldif',
        ];

        foreach ($paths as $path) {
            $matches = $this->applyFilters([$path]);

            self::assertTrue($matches['integration_smoke'], $path);
        }
    }

    public function testDeepRuntimeWorkflowUsesContainerChromiumForPlaywrightSmoke(): void
    {
        $workflow = file_get_contents($this->workflowPath());
        self::assertNotFalse($workflow);

        $deepRuntimeJob = $this->extractJobBlock($workflow, 'deep-runtime-suite', 'coverage-shard-unit');

        self::assertStringContainsString(
            "if: needs.changes.outputs.deep_runtime_asset_build_required == 'true' || needs.changes.outputs.integration_smoke == 'true'",
            $deepRuntimeJob,
        );
        self::assertStringContainsString(
            'bash scripts/release-gate/playwright/playwright_cli.sh install-browser',
            $deepRuntimeJob,
        );
        self::assertStringContainsString('-e PLAYWRIGHT_MCP_BROWSER=chromium', $deepRuntimeJob);
        self::assertStringContainsString('-e PLAYWRIGHT_MCP_EXECUTABLE_PATH=/usr/bin/chromium', $deepRuntimeJob);
        self::assertStringContainsString(
            '-e PLAYWRIGHT_MCP_READY_DIR=/var/www/html/storage/logs/ci/deep-runtime-suite/playwright-ready',
            $deepRuntimeJob,
        );
        self::assertStringContainsString(
            '-e PLAYWRIGHT_RUNTIME_PACKAGE=playwright@1.59.0-alpha-1771104257000',
            $deepRuntimeJob,
        );
        self::assertStringContainsString('-e PLAYWRIGHT_USE_LOCAL_BINS=1', $deepRuntimeJob);
        self::assertStringContainsString('--integration-smoke-browser-bootstrap-timeout=900', $deepRuntimeJob);
    }

    public function testLdapSmokeScriptChangeTriggersLdapGuardrailFilter(): void
    {
        $matches = $this->applyFilters(['scripts/ci/dashboard_integration_smoke.php']);

        self::assertFalse($matches['request_contracts_required']);
        self::assertTrue($matches['deep_bootstrap_required']);
        self::assertFalse($matches['coverage_required']);
        self::assertFalse($matches['pdf_renderer_latency_required']);
        self::assertFalse($matches['api_contract']);
        self::assertFalse($matches['booking_flows']);
        self::assertTrue($matches['integration_smoke']);
        self::assertTrue($matches['ldap_guardrail_required']);
        self::assertFalse($matches['write_contract_booking']);
        self::assertFalse($matches['write_contract_api']);
    }

    public function testBrowserRuntimeEvidenceLibraryChangeTriggersIntegrationSmokeAndBootstrap(): void
    {
        $matches = $this->applyFilters(['scripts/ci/lib/BrowserRuntimeEvidence.php']);

        self::assertFalse($matches['request_contracts_required']);
        self::assertTrue($matches['deep_bootstrap_required']);
        self::assertFalse($matches['coverage_required']);
        self::assertFalse($matches['pdf_renderer_latency_required']);
        self::assertFalse($matches['api_contract']);
        self::assertFalse($matches['booking_flows']);
        self::assertTrue($matches['integration_smoke']);
        self::assertFalse($matches['ldap_guardrail_required']);
        self::assertFalse($matches['write_contract_booking']);
        self::assertFalse($matches['write_contract_api']);
    }

    public function testDashboardSummaryBrowserCheckLibraryChangeTriggersIntegrationSmokeAndBootstrap(): void
    {
        $matches = $this->applyFilters(['scripts/ci/lib/DashboardSummaryBrowserCheck.php']);

        self::assertFalse($matches['request_contracts_required']);
        self::assertTrue($matches['deep_bootstrap_required']);
        self::assertFalse($matches['coverage_required']);
        self::assertFalse($matches['pdf_renderer_latency_required']);
        self::assertFalse($matches['api_contract']);
        self::assertFalse($matches['booking_flows']);
        self::assertTrue($matches['integration_smoke']);
        self::assertFalse($matches['ldap_guardrail_required']);
        self::assertFalse($matches['write_contract_booking']);
        self::assertFalse($matches['write_contract_api']);
    }

    public function testGitHelpersChangeTriggersIntegrationSmokeAndBootstrap(): void
    {
        $matches = $this->applyFilters(['scripts/ci/git_helpers.sh']);

        self::assertFalse($matches['request_contracts_required']);
        self::assertTrue($matches['deep_bootstrap_required']);
        self::assertFalse($matches['coverage_required']);
        self::assertFalse($matches['pdf_renderer_latency_required']);
        self::assertFalse($matches['api_contract']);
        self::assertFalse($matches['booking_flows']);
        self::assertTrue($matches['integration_smoke']);
        self::assertFalse($matches['ldap_guardrail_required']);
        self::assertFalse($matches['write_contract_booking']);
        self::assertFalse($matches['write_contract_api']);
    }

    public function testDockerComposeHelpersChangeTriggersIntegrationSmokeAndBootstrap(): void
    {
        $matches = $this->applyFilters(['scripts/ci/docker_compose_helpers.sh']);

        self::assertFalse($matches['request_contracts_required']);
        self::assertTrue($matches['deep_bootstrap_required']);
        self::assertFalse($matches['coverage_required']);
        self::assertFalse($matches['pdf_renderer_latency_required']);
        self::assertFalse($matches['api_contract']);
        self::assertFalse($matches['booking_flows']);
        self::assertTrue($matches['integration_smoke']);
        self::assertFalse($matches['ldap_guardrail_required']);
        self::assertFalse($matches['write_contract_booking']);
        self::assertFalse($matches['write_contract_api']);
    }

    public function testLdapConstantsChangeTriggersLdapGuardrailFilter(): void
    {
        $matches = $this->applyFilters(['application/config/constants.php']);

        self::assertFalse($matches['request_contracts_required']);
        self::assertTrue($matches['deep_bootstrap_required']);
        self::assertTrue($matches['coverage_required']);
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
        self::assertFalse($matches['pdf_renderer_latency_required']);
        self::assertFalse($matches['api_contract']);
        self::assertTrue($matches['booking_flows']);
        self::assertTrue($matches['integration_smoke']);
        self::assertTrue($matches['ldap_guardrail_required']);
        self::assertFalse($matches['write_contract_booking']);
        self::assertFalse($matches['write_contract_api']);
    }

    public function testLdapPermissionHelperChangeTriggersLdapGuardrailFilter(): void
    {
        $matches = $this->applyFilters(['application/helpers/permission_helper.php']);

        self::assertFalse($matches['request_contracts_required']);
        self::assertTrue($matches['deep_bootstrap_required']);
        self::assertTrue($matches['coverage_required']);
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
        self::assertFalse($matches['pdf_renderer_latency_required']);
        self::assertFalse($matches['api_contract']);
        self::assertTrue($matches['booking_flows']);
        self::assertTrue($matches['integration_smoke']);
        self::assertTrue($matches['ldap_guardrail_required']);
        self::assertTrue($matches['write_contract_booking']);
        self::assertTrue($matches['write_contract_api']);
    }

    public function testAccountsLibraryChangeTriggersLdapGuardrailFilter(): void
    {
        $matches = $this->applyFilters(['application/libraries/Accounts.php']);

        self::assertTrue($matches['request_contracts_required']);
        self::assertTrue($matches['deep_bootstrap_required']);
        self::assertTrue($matches['coverage_required']);
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
        self::assertArrayHasKey('pdf_renderer_latency_required', $filters);
        self::assertArrayHasKey('ldap_guardrail_required', $filters);

        return $filters;
    }

    private function workflowPath(): string
    {
        return __DIR__ . '/../../../.github/workflows/ci.yml';
    }

    private function extractJobBlock(string $workflow, string $jobName, string $nextJobName): string
    {
        $start = strpos($workflow, "\n  {$jobName}:\n");
        self::assertNotFalse($start, sprintf('Expected job "%s" to exist in the workflow.', $jobName));

        $end = strpos($workflow, "\n  {$nextJobName}:\n", $start + 1);
        self::assertNotFalse(
            $end,
            sprintf('Expected job "%s" to follow "%s" in the workflow.', $nextJobName, $jobName),
        );

        return substr($workflow, $start, $end - $start);
    }
}
