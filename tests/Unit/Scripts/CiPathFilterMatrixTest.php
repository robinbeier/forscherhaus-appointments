<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CiPathFilterMatrixTest extends TestCase
{
    public function testOnlyMarkdownDocumentationChangesSkipRootDeploymentJob(): void
    {
        $matches = $this->applyFilters(['docs/file.md', 'docs/monitoring/target-concept.md', 'docs/nested/notes.md']);

        self::assertFalse($matches['runtime_checks_required']);
    }

    public function testPureProseChangesSkipPhpBuildJobs(): void
    {
        foreach (
            [
                'docs/security/ROB-403-hsts-policy-decision.md',
                'docs/monitoring/parent-confirmation-pdf-synthetic-decision.md',
            ]
            as $path
        ) {
            self::assertFalse($this->applyFilters([$path])['runtime_checks_required'], $path);
        }
    }

    public function testMachineConsumedDocsAndMixedChangesKeepPhpBuildProtection(): void
    {
        foreach (
            [
                'AGENTS.md',
                'docs/AGENTS.md',
                'docs/ops/AGENTS.md',
                'system/core/CodeIgniter.php',
                'config-sample.php',
                'future-tool.py',
                'docs/deployment.md',
                'docs/deployment-run-v1.md',
                'docs/deployment-evidence-authority-v1.md',
                'docs/ops/production-backup-set-producer.md',
                'docs/ops/production-dump-producer-admission.md',
                'docs/ops/production-release-archive-dump-retention.md',
                'docs/architecture-map.md',
                'docs/ownership-map.md',
                'docs/agent-harness-index.md',
                'docs/maps/component_ownership_map.json',
                ['docs/deployment.md', 'application/controllers/Booking.php'],
            ]
            as $changedPaths
        ) {
            $paths = is_array($changedPaths) ? $changedPaths : [$changedPaths];
            self::assertTrue($this->applyFilters($paths)['runtime_checks_required'], implode(', ', $paths));
        }
    }

    public function testNonMarkdownAndCodeToMarkdownChangesKeepRootDeploymentProtection(): void
    {
        foreach (
            [
                'docs/monitoring/target-concept.txt',
                'README.md',
                '.github/workflows/ci.yml',
                'application/controllers/Booking.php',
                ['application/controllers/Legacy.php', 'docs/Legacy.md'],
            ]
            as $changedPaths
        ) {
            $paths = is_array($changedPaths) ? $changedPaths : [$changedPaths];
            self::assertTrue($this->applyFilters($paths)['runtime_checks_required'], implode(', ', $paths));
        }
    }

    public function testCoverageGateScriptChangeOnlyTriggersCoverageHeavyJobs(): void
    {
        $matches = $this->applyFilters(['scripts/ci/check_coverage_delta.php']);

        self::assertTrue($matches['coverage_required']);
        self::assertFalse($matches['pdf_renderer_tests_required']);
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
        self::assertFalse($matches['pdf_renderer_tests_required']);
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
        self::assertFalse($matches['coverage_required']);
        self::assertFalse($matches['pdf_renderer_tests_required']);
        self::assertFalse($matches['api_contract']);
        self::assertFalse($matches['booking_flows']);
        self::assertFalse($matches['integration_smoke']);
        self::assertFalse($matches['ldap_guardrail_required']);
        self::assertFalse($matches['write_contract_booking']);
        self::assertFalse($matches['write_contract_api']);
    }

    public function testRetainedIntegrationTestChangesTriggerCoverageGate(): void
    {
        foreach (
            [
                'tests/Integration/Controllers/ApiIntegrationSecretsWriteOnlyFlowTest.php',
                'tests/Integration/Controllers/CalendarProviderDataTest.php',
                'tests/Integration/Controllers/EntityStoreAuthorizationTest.php',
                'tests/Integration/Controllers/CalendarEventPermissionsTest.php',
            ]
            as $path
        ) {
            self::assertTrue($this->applyFilters([$path])['coverage_required'], $path);
        }
    }

    public function testBookingControllerChangeKeepsRuntimeAndCoverageProtection(): void
    {
        $matches = $this->applyFilters(['application/controllers/Booking.php']);

        self::assertTrue($matches['request_contracts_required']);
        self::assertTrue($matches['coverage_required']);
        self::assertFalse($matches['pdf_renderer_tests_required']);
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
        self::assertTrue($matches['coverage_required']);
        self::assertTrue($matches['pdf_renderer_tests_required']);
        self::assertTrue($matches['api_contract']);
        self::assertTrue($matches['booking_flows']);
        self::assertTrue($matches['integration_smoke']);
        self::assertTrue($matches['ldap_guardrail_required']);
        self::assertTrue($matches['write_contract_booking']);
        self::assertTrue($matches['write_contract_api']);
    }

    public function testBookingFlowsConfigChangeStillTriggersBookingFlowJob(): void
    {
        $matches = $this->applyFilters(['phpunit.booking-flows.xml']);

        self::assertFalse($matches['request_contracts_required']);
        self::assertFalse($matches['coverage_required']);
        self::assertFalse($matches['pdf_renderer_tests_required']);
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
        self::assertTrue($matches['coverage_required']);
        self::assertFalse($matches['pdf_renderer_tests_required']);
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
        self::assertStringContainsString("needs.changes.outputs.coverage_required == 'true'", $workflow);
        self::assertStringContainsString("needs.changes.outputs.pdf_renderer_tests_required == 'true'", $workflow);
        self::assertStringNotContainsString("needs.changes.outputs.deep_required == 'true'", $workflow);
    }

    public function testDeepRuntimeAssetBuildGuardStaysPinnedToTheBrowserSuiteContract(): void
    {
        $workflow = file_get_contents($this->workflowPath());
        self::assertNotFalse($workflow);

        $deepRuntimeJob = $this->extractJobBlock($workflow, 'deep-runtime-suite', 'coverage-shard-unit');
        $job = \Symfony\Component\Yaml\Yaml::parse($workflow)['jobs']['deep-runtime-suite'];
        $steps = array_column($job['steps'], null, 'name');
        foreach (['Setup Node.js', 'Install Node.js dependencies', 'Build runtime assets'] as $name) {
            self::assertSame("needs.changes.outputs.integration_smoke == 'true'", $steps[$name]['if'], $name);
        }
        self::assertSame(
            'npm ci --ignore-scripts --no-audit --no-fund',
            $this->gateBody($steps['Install Node.js dependencies']['run'], 'deep-runtime-suite-4'),
        );
        self::assertSame(
            'npm run build',
            $this->gateBody($steps['Build runtime assets']['run'], 'deep-runtime-suite-5'),
        );
        self::assertStringNotContainsString('npx gulp scripts', $deepRuntimeJob);
    }

    public function testPdfRendererTestsFilterStaysScopedToPdfRendererAndGuardFiles(): void
    {
        $matches = $this->applyFilters(['pdf-renderer/server.js']);

        self::assertFalse($matches['request_contracts_required']);
        self::assertFalse($matches['coverage_required']);
        self::assertTrue($matches['pdf_renderer_tests_required']);
        self::assertFalse($matches['api_contract']);
        self::assertFalse($matches['booking_flows']);
        self::assertFalse($matches['integration_smoke']);
        self::assertFalse($matches['ldap_guardrail_required']);
        self::assertFalse($matches['write_contract_booking']);
        self::assertFalse($matches['write_contract_api']);
    }

    public function testPdfRendererTestsFilterIncludesComposeRuntimeChanges(): void
    {
        $matches = $this->applyFilters(['docker-compose.yml']);

        self::assertFalse($matches['request_contracts_required']);
        self::assertFalse($matches['coverage_required']);
        self::assertTrue($matches['pdf_renderer_tests_required']);
        self::assertFalse($matches['api_contract']);
        self::assertFalse($matches['booking_flows']);
        self::assertTrue($matches['integration_smoke']);
        self::assertTrue($matches['ldap_guardrail_required']);
        self::assertFalse($matches['write_contract_booking']);
        self::assertFalse($matches['write_contract_api']);
    }

    #[DataProvider('rendererRegressionTriggerPathProvider')]
    public function testRendererRegressionPathsTriggerRendererRegressionJob(string $path): void
    {
        $matches = $this->applyFilters([$path]);

        self::assertTrue($matches['pdf_renderer_tests_required']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rendererRegressionTriggerPathProvider(): array
    {
        return [
            'isolated replay compose overlay' => ['docker/compose.zero-surprise.yml'],
            'dashboard export controller' => ['application/controllers/Dashboard_export.php'],
            'pdf renderer library' => ['application/libraries/Pdf_renderer.php'],
            'teacher pdf view' => ['application/views/exports/dashboard_teacher_pdf.php'],
            'renderer source' => ['pdf-renderer/server.js'],
            'renderer regression test' => ['pdf-renderer/server.test.js'],
            'dashboard release gate' => ['scripts/release-gate/dashboard_release_gate.php'],
            'view payload regression test' => ['tests/Unit/Views/DashboardTeacherPdfViewTest.php'],
        ];
    }

    public function testRendererRegressionStepsAreBlocking(): void
    {
        $workflow = file_get_contents($this->workflowPath());
        self::assertNotFalse($workflow);

        $job = $this->extractJobBlock($workflow, 'pdf-renderer-tests', 'architecture-ownership-map');

        self::assertStringContainsString('docker compose exec -T pdf-renderer npm test', $job);
        self::assertStringContainsString('docker compose up -d pdf-renderer', $job);
        self::assertStringContainsString('curl -fsS http://localhost:3003/healthz', $job);
        self::assertStringContainsString('docker compose down --remove-orphans', $job);
        self::assertStringNotContainsString('check_pdf_renderer_latency.php', $job);
        self::assertStringNotContainsString('set +e', $job);
    }

    public function testUptimeKumaDesiredStateDoesNotTriggerIntegrationSmoke(): void
    {
        $matches = $this->applyFilters(['docker/compose.uptime-kuma.yml', 'docs/uptime-kuma.md']);
        self::assertTrue($matches['coverage_required']);
        self::assertTrue($matches['api_contract']);
        self::assertFalse($matches['integration_smoke']);
    }

    public function testMariaDbRestoreComposeDoesNotTriggerIntegrationSmoke(): void
    {
        $matches = $this->applyFilters(['docker/compose.mariadb-restore.yml']);
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

    public function testDeepRuntimeWorkflowUsesHostChromeForPlaywrightSmoke(): void
    {
        $workflow = file_get_contents($this->workflowPath());
        self::assertNotFalse($workflow);

        $deepRuntimeJob = $this->extractJobBlock($workflow, 'deep-runtime-suite', 'coverage-shard-unit');

        self::assertStringContainsString("if: needs.changes.outputs.integration_smoke == 'true'", $deepRuntimeJob);
        self::assertStringContainsString(
            'bash scripts/release-gate/playwright/playwright_cli.sh install-browser',
            $deepRuntimeJob,
        );
        self::assertStringContainsString('command -v google-chrome', $deepRuntimeJob);
        self::assertStringContainsString('PLAYWRIGHT_MCP_BROWSER: chromium', $deepRuntimeJob);
        self::assertStringContainsString('PLAYWRIGHT_MCP_EXECUTABLE_PATH=$chrome_path', $deepRuntimeJob);
        self::assertStringContainsString(
            'PLAYWRIGHT_MCP_READY_DIR: storage/logs/ci/deep-runtime-suite/playwright-ready',
            $deepRuntimeJob,
        );
        self::assertStringContainsString(
            'PLAYWRIGHT_RUNTIME_PACKAGE: playwright@1.59.0-alpha-1771104257000',
            $deepRuntimeJob,
        );
        self::assertStringContainsString('PLAYWRIGHT_USE_LOCAL_BINS: "1"', $deepRuntimeJob);
        self::assertStringContainsString('--integration-smoke-browser-bootstrap-timeout=900', $deepRuntimeJob);
    }

    public function testLdapSmokeScriptChangeTriggersLdapGuardrailFilter(): void
    {
        $matches = $this->applyFilters(['scripts/ci/dashboard_integration_smoke.php']);

        self::assertFalse($matches['request_contracts_required']);
        self::assertFalse($matches['coverage_required']);
        self::assertFalse($matches['pdf_renderer_tests_required']);
        self::assertFalse($matches['api_contract']);
        self::assertFalse($matches['booking_flows']);
        self::assertTrue($matches['integration_smoke']);
        self::assertTrue($matches['ldap_guardrail_required']);
        self::assertFalse($matches['write_contract_booking']);
        self::assertFalse($matches['write_contract_api']);
    }

    public function testBrowserRuntimeEvidenceLibraryChangeTriggersIntegrationSmoke(): void
    {
        $matches = $this->applyFilters(['scripts/ci/lib/BrowserRuntimeEvidence.php']);

        self::assertFalse($matches['request_contracts_required']);
        self::assertFalse($matches['coverage_required']);
        self::assertFalse($matches['pdf_renderer_tests_required']);
        self::assertFalse($matches['api_contract']);
        self::assertFalse($matches['booking_flows']);
        self::assertTrue($matches['integration_smoke']);
        self::assertFalse($matches['ldap_guardrail_required']);
        self::assertFalse($matches['write_contract_booking']);
        self::assertFalse($matches['write_contract_api']);
    }

    public function testDirectDashboardBrowserRunnerChangeTriggersIntegrationSmoke(): void
    {
        $matches = $this->applyFilters(['scripts/ci/dashboard_summary_browser.js']);
        self::assertTrue($matches['integration_smoke']);
    }

    public function testDashboardSummaryBrowserCheckLibraryChangeTriggersIntegrationSmoke(): void
    {
        $matches = $this->applyFilters(['scripts/ci/lib/DashboardSummaryBrowserCheck.php']);

        self::assertFalse($matches['request_contracts_required']);
        self::assertFalse($matches['coverage_required']);
        self::assertFalse($matches['pdf_renderer_tests_required']);
        self::assertFalse($matches['api_contract']);
        self::assertFalse($matches['booking_flows']);
        self::assertTrue($matches['integration_smoke']);
        self::assertFalse($matches['ldap_guardrail_required']);
        self::assertFalse($matches['write_contract_booking']);
        self::assertFalse($matches['write_contract_api']);
    }

    public function testGitHelpersChangeTriggersIntegrationSmoke(): void
    {
        $matches = $this->applyFilters(['scripts/ci/git_helpers.sh']);

        self::assertFalse($matches['request_contracts_required']);
        self::assertFalse($matches['coverage_required']);
        self::assertFalse($matches['pdf_renderer_tests_required']);
        self::assertFalse($matches['api_contract']);
        self::assertFalse($matches['booking_flows']);
        self::assertTrue($matches['integration_smoke']);
        self::assertFalse($matches['ldap_guardrail_required']);
        self::assertFalse($matches['write_contract_booking']);
        self::assertFalse($matches['write_contract_api']);
    }

    public function testDockerComposeHelpersChangeTriggersIntegrationSmoke(): void
    {
        $matches = $this->applyFilters(['scripts/ci/docker_compose_helpers.sh']);

        self::assertFalse($matches['request_contracts_required']);
        self::assertFalse($matches['coverage_required']);
        self::assertFalse($matches['pdf_renderer_tests_required']);
        self::assertFalse($matches['api_contract']);
        self::assertFalse($matches['booking_flows']);
        self::assertTrue($matches['integration_smoke']);
        self::assertFalse($matches['ldap_guardrail_required']);
        self::assertFalse($matches['write_contract_booking']);
        self::assertFalse($matches['write_contract_api']);
    }

    public function testLocalFullGateSelectorReusesIntegrationSmokeFilterForOperationsOnlyChanges(): void
    {
        $result = $this->runLocalSelector(['scripts/ops/kuma_push_app_logs.sh', 'docs/observability.md']);

        self::assertSame(0, $result['status']);
        self::assertSame("false\n", $result['stdout']);
    }

    public function testLocalFullGateSelectorIncludesSelectorChangesInSmokeScope(): void
    {
        $result = $this->runLocalSelector(['scripts/ci/select_local_full_gate.php']);

        self::assertSame(0, $result['status']);
        self::assertSame("true\n", $result['stdout']);
    }

    public function testLocalAndHostedSmokeKeepFrontendAndRuntimeInputs(): void
    {
        foreach (
            [
                'assets/js/pages/booking.js',
                'assets/css/themes/default.scss',
                'system/core/CodeIgniter.php',
                'docker-compose.yml',
                'config-sample.php',
                'package.json',
                'package-lock.json',
                'gulpfile.js',
                'babel.config.json',
                'resources/vendor/qrcode/qrcode.min.js',
                'scripts/postinstall-assets.js',
                'scripts/ci/pre_pr_full.sh',
            ]
            as $path
        ) {
            self::assertTrue($this->applyFilters([$path])['integration_smoke'], $path);
            $result = $this->runLocalSelector([$path]);
            self::assertSame(0, $result['status'], $result['stderr']);
            self::assertSame("true\n", $result['stdout'], $path);
        }
    }

    public function testLocalSelectorRejectsUnsupportedGlobEvenForUnrelatedPaths(): void
    {
        $workflow = tempnam(sys_get_temp_dir(), 'ci-workflow-');
        self::assertNotFalse($workflow);
        file_put_contents(
            $workflow,
            "jobs:\n  changes:\n    steps:\n      - id: filter\n        with:\n          filters: |\n            integration_smoke:\n              - 'application/[ab].php'\n",
        );
        try {
            $result = $this->runLocalSelector(['notes.md'], $workflow);
        } finally {
            unlink($workflow);
        }
        self::assertSame(1, $result['status']);
        self::assertStringContainsString('Unsupported integration_smoke filter pattern', $result['stderr']);
    }

    public function testLocalFullGateSelectorFailsClosedForInvalidFilter(): void
    {
        $workflow = tempnam(sys_get_temp_dir(), 'ci-workflow-');
        self::assertNotFalse($workflow);
        file_put_contents(
            $workflow,
            "jobs:\n  changes:\n    steps:\n      - id: filter\n        with:\n          filters: |\n            integration_smoke:\n              - !invalid\n",
        );

        try {
            $result = $this->runLocalSelector(['application/controllers/Booking.php'], $workflow);
        } finally {
            unlink($workflow);
        }

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('Selection failed:', $result['stderr']);
    }

    public function testLdapConstantsChangeTriggersLdapGuardrailFilter(): void
    {
        $matches = $this->applyFilters(['application/config/constants.php']);

        self::assertFalse($matches['request_contracts_required']);
        self::assertTrue($matches['coverage_required']);
        self::assertFalse($matches['pdf_renderer_tests_required']);
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
        self::assertTrue($matches['coverage_required']);
        self::assertFalse($matches['pdf_renderer_tests_required']);
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
        self::assertTrue($matches['coverage_required']);
        self::assertFalse($matches['pdf_renderer_tests_required']);
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
        self::assertFalse($matches['coverage_required']);
        self::assertFalse($matches['pdf_renderer_tests_required']);
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
        self::assertTrue($matches['coverage_required']);
        self::assertFalse($matches['pdf_renderer_tests_required']);
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
        self::assertTrue($matches['coverage_required']);
        self::assertFalse($matches['pdf_renderer_tests_required']);
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
                    $isNegated = str_starts_with($pattern, '!');
                    $glob = $isNegated ? substr($pattern, 1) : $pattern;
                    $matchesPattern = preg_match($this->globToRegex($glob), $path) === 1;

                    if ($matchesPattern !== $isNegated) {
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
        $quoted = preg_quote($pattern, '/');
        $quoted = str_replace('\*\*\/', '(?:.*\/)?', $quoted);
        $quoted = str_replace('\*\*', '.*', $quoted);
        $quoted = str_replace('\*', '[^\/]*', $quoted);

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
        self::assertArrayHasKey('coverage_required', $filters);
        self::assertArrayHasKey('pdf_renderer_tests_required', $filters);
        self::assertArrayHasKey('ldap_guardrail_required', $filters);
        self::assertArrayHasKey('runtime_checks_required', $filters);

        return $filters;
    }

    private function workflowPath(): string
    {
        return __DIR__ . '/../../../.github/workflows/ci.yml';
    }

    private function gateBody(mixed $run, string $label): string
    {
        self::assertIsString($run);
        $run = trim($run);
        $prefix = "python3 -B scripts/ci/run_gate_with_summary.py --label {$label} -- bash --noprofile --norc -e <<'ROB557_GATE_COMMAND'\n";
        $suffix = "\nROB557_GATE_COMMAND";
        self::assertStringStartsWith($prefix, $run);
        self::assertStringEndsWith($suffix, $run);

        return substr($run, strlen($prefix), -strlen($suffix));
    }

    /** @param array<int, string> $paths @return array{status:int,stdout:string,stderr:string} */
    private function runLocalSelector(array $paths, ?string $workflow = null): array
    {
        $workflow ??= $this->workflowPath();
        $process = proc_open(
            [PHP_BINARY, 'scripts/ci/select_local_full_gate.php', '--workflow=' . $workflow],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
        );
        self::assertIsResource($process);
        fwrite($pipes[0], implode("\0", $paths) . "\0");
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
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
