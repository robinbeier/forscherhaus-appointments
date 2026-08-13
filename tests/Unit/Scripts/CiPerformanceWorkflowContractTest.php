<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

require_once __DIR__ . '/../../../scripts/ci/measure_ci_performance_baseline.php';

class CiPerformanceWorkflowContractTest extends TestCase
{
    public function testVersionedWorkloadContractCoversEveryWorkflowJobDefinition(): void
    {
        $workflow = Yaml::parseFile(__DIR__ . '/../../../.github/workflows/ci.yml');
        self::assertIsArray($workflow);
        self::assertIsArray($workflow['jobs'] ?? null);
        $jobs = $workflow['jobs'];
        $policy = loadCiPerformanceBaselinePolicy(
            __DIR__ . '/../../../scripts/ci/config/ci_performance_baseline_policy.php',
        );

        self::assertSame(7, $policy['workload_contract']['version']);
        self::assertSame('2026-08-13T03:10:00Z', $policy['workload_contract']['cohort_epoch_utc']);
        self::assertSame(
            array_keys($jobs),
            array_keys($policy['comparison_profile']['consumer_conclusions']),
            'Every workflow job must have an explicit expected conclusion in the full-gate profile.',
        );
        self::assertSame(
            $policy['workload_contract']['workflow_jobs_sha256'],
            fingerprintCiPerformanceBaselineWorkflowJobs($jobs),
        );

        $fingerprint = fingerprintCiPerformanceBaselineWorkflowJobs($jobs);
        foreach (array_keys($jobs) as $jobName) {
            $mutation = $jobs;
            $mutation[$jobName]['x-ci-performance-contract-probe'] = true;
            self::assertNotSame(
                $fingerprint,
                fingerprintCiPerformanceBaselineWorkflowJobs($mutation),
                'Workflow job definition is not covered by the workload contract: ' . $jobName,
            );
        }
    }

    public function testWorkloadContractFailsClosedWhenAWorkflowJobChangesWithoutAPolicyUpdate(): void
    {
        $workflow = Yaml::parseFile(__DIR__ . '/../../../.github/workflows/ci.yml');
        self::assertIsArray($workflow);
        self::assertIsArray($workflow['jobs'] ?? null);
        $workflow['jobs']['build-test']['x-ci-performance-contract-probe'] = true;
        $workflowPath = tempnam(sys_get_temp_dir(), 'ci-workload-contract-');
        self::assertNotFalse($workflowPath);
        self::assertNotFalse(
            file_put_contents($workflowPath, json_encode(['jobs' => $workflow['jobs']], JSON_THROW_ON_ERROR)),
        );
        $policy = loadCiPerformanceBaselinePolicy(
            __DIR__ . '/../../../scripts/ci/config/ci_performance_baseline_policy.php',
        );

        try {
            assertCiPerformanceBaselineWorkflowContract($policy, $workflowPath);
            self::fail('A changed workflow job must not match an unchanged workload contract.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('workload contract mismatch:', $e->getMessage());
            self::assertStringContainsString(
                'increment workload_contract.version and cohort_epoch_utc',
                $e->getMessage(),
            );
        } finally {
            @unlink($workflowPath);
        }
    }

    public function testBuildTestRunsTheGeneralSuiteFailClosedBeforePinnedRob444Tests(): void
    {
        $job = $this->workflowJob('build-test');
        $steps = $this->namedSteps($job);

        foreach ($steps as $step) {
            self::assertArrayNotHasKey('continue-on-error', $step);
            self::assertStringNotContainsString('php-actions/phpunit', (string) ($step['uses'] ?? ''));
        }

        $requiredOrder = [
            'Prepare root test configuration',
            'Start build-test database',
            'Wait for build-test MySQL readiness',
            'Install deterministic build-test instance',
            'PHPUnit Tests',
            'ROB-444 CI baseline regression tests',
            'ROB-442 root deployment regression tests',
            'Diagnostics (build-test database)',
            'Cleanup build-test database',
        ];
        $stepNames = array_keys($steps);
        self::assertSame(
            $requiredOrder,
            array_values(
                array_filter($stepNames, static fn(string $name): bool => in_array($name, $requiredOrder, true)),
            ),
        );

        $prepare = $this->stepRun($steps, 'Prepare root test configuration');
        self::assertStringContainsString('test ! -e config.php', $prepare);
        self::assertStringContainsString('install -m 0600 config-sample.php config.php', $prepare);
        self::assertStringContainsString(
            'sed -i "s/const DB_HOST = \'mysql\';/const DB_HOST = \'127.0.0.1\';/" config.php',
            $prepare,
        );
        self::assertStringContainsString('grep -Fq "const DB_HOST = \'127.0.0.1\';" config.php', $prepare);

        self::assertSame('docker compose up -d mysql', $this->stepRun($steps, 'Start build-test database'));
        self::assertSame(
            'bash scripts/ci/wait_for_mysql_readiness.sh',
            $this->stepRun($steps, 'Wait for build-test MySQL readiness'),
        );

        $installDatabase = $this->stepRun($steps, 'Install deterministic build-test instance');
        self::assertStringContainsString('for attempt in 1 2 3; do', $installDatabase);
        self::assertStringContainsString('if php index.php console install; then', $installDatabase);
        self::assertStringContainsString('console install failed after 3 attempts.', $installDatabase);
        self::assertStringContainsString('exit 1', $installDatabase);

        $general = $this->stepRun($steps, 'PHPUnit Tests');
        self::assertStringNotContainsString('|| true', $general);
        self::assertStringContainsString('if ! APP_ENV=testing php -d memory_limit=512M vendor/bin/phpunit', $general);
        self::assertStringContainsString('--configuration phpunit.xml', $general);
        self::assertStringContainsString('--fail-on-empty-test-suite', $general);
        self::assertStringContainsString('| tee storage/logs/ci/phpunit-general.log', $general);
        self::assertStringContainsString('The general PHPUnit suite failed.', $general);
        self::assertStringContainsString('exit 1', $general);
        self::assertStringContainsString(
            "grep -Eq '^(OK \\([1-9][0-9]* tests?,|Tests: [1-9][0-9]*,)' storage/logs/ci/phpunit-general.log",
            $general,
        );

        $rob444 = $this->stepRun($steps, 'ROB-444 CI baseline regression tests');
        self::assertStringNotContainsString('|| true', $rob444);
        self::assertStringContainsString('if ! php -d memory_limit=512M vendor/bin/phpunit', $rob444);
        self::assertStringContainsString('--no-configuration', $rob444);
        self::assertStringContainsString('--bootstrap vendor/autoload.php', $rob444);
        self::assertStringContainsString('--fail-on-empty-test-suite', $rob444);
        self::assertSame(
            [
                'tests/Unit/Scripts/CiPerformanceBaselineTest.php',
                'tests/Unit/Scripts/CiPerformanceWorkflowContractTest.php',
                'tests/Unit/Scripts/CiPathFilterMatrixTest.php',
            ],
            array_values(
                array_filter(
                    array_map(
                        static fn(string $line): string => preg_replace('/\\s+\\\\$/', '', trim($line)) ?? '',
                        explode("\n", $rob444),
                    ),
                    static fn(string $line): bool => str_starts_with($line, 'tests/Unit/Scripts/'),
                ),
            ),
        );
        self::assertStringContainsString('| tee storage/logs/ci/phpunit-rob444.log', $rob444);
        self::assertStringContainsString('The ROB-444 PHPUnit suite failed.', $rob444);
        self::assertStringContainsString('exit 1', $rob444);
        self::assertStringContainsString(
            "grep -Eq '^(OK \\([1-9][0-9]* tests?,|Tests: [1-9][0-9]*,)' storage/logs/ci/phpunit-rob444.log",
            $rob444,
        );

        $rootDeployment = $this->stepRun($steps, 'ROB-442 root deployment regression tests');
        self::assertStringContainsString('systemd-analyze verify', $rootDeployment);
        self::assertStringContainsString('scripts/ops/systemd/fh-session-retention.service', $rootDeployment);
        self::assertStringContainsString('scripts/ops/systemd/fh-session-retention.timer', $rootDeployment);
        self::assertStringContainsString('sudo php vendor/bin/phpunit', $rootDeployment);
        self::assertStringContainsString('tests/Unit/Scripts/DeploymentHostRunnerV1RootTest.php', $rootDeployment);
        self::assertStringContainsString('tests/Unit/Scripts/PinDeployTimingRootTest.php', $rootDeployment);
        self::assertStringContainsString('tests/Unit/Scripts/PublishReleasePairRootTest.php', $rootDeployment);
        self::assertStringContainsString('tests/Unit/Scripts/SessionRetentionRootTest.php', $rootDeployment);
        self::assertStringContainsString('tests/Unit/Scripts/ReleaseArchiveDumpRetentionRootTest.php', $rootDeployment);

        $diagnostics = $steps['Diagnostics (build-test database)'];
        self::assertSame('failure()', $diagnostics['if'] ?? null);
        self::assertStringContainsString(
            'docker compose logs --no-color --timestamps mysql || true',
            $this->stepRun($steps, 'Diagnostics (build-test database)'),
        );

        $cleanup = $steps['Cleanup build-test database'];
        self::assertSame('always()', $cleanup['if'] ?? null);
        self::assertSame(
            'docker compose down -v --remove-orphans',
            $this->stepRun($steps, 'Cleanup build-test database'),
        );
    }

    public function testBaselineMeasurementStaysInsideTheExistingAdvisorySignalJob(): void
    {
        $job = $this->workflowJob('heavy-job-duration-trends');
        $steps = $this->namedSteps($job);
        $condition = (string) ($job['if'] ?? '');

        self::assertStringContainsString("github.event_name == 'push'", $condition);
        self::assertStringContainsString("github.ref == 'refs/heads/main'", $condition);
        foreach ($steps as $step) {
            self::assertArrayNotHasKey('continue-on-error', $step);
        }

        $requiredOrder = [
            'Measure full-gate PR performance baseline',
            'Upload heavy job trend artifacts',
            'Diagnostics (heavy job trend report)',
        ];
        $stepNames = array_keys($steps);
        self::assertSame(
            $requiredOrder,
            array_values(
                array_filter($stepNames, static fn(string $name): bool => in_array($name, $requiredOrder, true)),
            ),
        );

        $measurement = $this->stepRun($steps, 'Measure full-gate PR performance baseline');
        self::assertStringContainsString('set +e', $measurement);
        self::assertStringContainsString('ci-performance-baseline exited with status', $measurement);
        self::assertStringContainsString('ci-performance-baseline-latest.json', $measurement);

        $upload = $steps['Upload heavy job trend artifacts'];
        self::assertSame('actions/upload-artifact@v7', $upload['uses'] ?? null);
        self::assertSame(
            [
                'storage/logs/ci/heavy-job-duration-trends-latest.json',
                'storage/logs/ci/ci-performance-baseline-latest.json',
            ],
            array_values(array_filter(array_map('trim', explode("\n", (string) ($upload['with']['path'] ?? ''))))),
        );
    }

    public function testDeepRuntimeWorkloadProfileInputsStayExplicitInTheWorkflow(): void
    {
        $steps = $this->namedSteps($this->workflowJob('deep-runtime-suite'));
        $installBrowser = $this->stepRun($steps, 'Install Playwright smoke browser');
        $deepRuntime = $this->stepRun($steps, 'Run deep runtime suite');

        self::assertStringContainsString(
            '-e PLAYWRIGHT_RUNTIME_PACKAGE=playwright@1.59.0-alpha-1771104257000',
            $installBrowser,
        );
        foreach (
            [
                '-e PLAYWRIGHT_RUNTIME_PACKAGE=playwright@1.59.0-alpha-1771104257000',
                '--booking-search-days=14',
                '--retry-count=1',
                '--start-date=2026-01-01',
                '--end-date=2026-01-31',
                '--integration-smoke-browser-bootstrap-timeout=900',
            ]
            as $profileInput
        ) {
            self::assertStringContainsString($profileInput, $deepRuntime);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function workflowJob(string $jobName): array
    {
        $workflow = Yaml::parseFile(__DIR__ . '/../../../.github/workflows/ci.yml');
        self::assertIsArray($workflow);
        self::assertIsArray($workflow['jobs'] ?? null);
        self::assertArrayHasKey($jobName, $workflow['jobs']);
        self::assertIsArray($workflow['jobs'][$jobName]);

        return $workflow['jobs'][$jobName];
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, array<string, mixed>>
     */
    private function namedSteps(array $job): array
    {
        self::assertIsArray($job['steps'] ?? null);
        $namedSteps = [];

        foreach ($job['steps'] as $step) {
            self::assertIsArray($step);
            self::assertIsString($step['name'] ?? null);
            self::assertArrayNotHasKey($step['name'], $namedSteps, 'Workflow step names must be unique within a job.');
            $namedSteps[$step['name']] = $step;
        }

        return $namedSteps;
    }

    /**
     * @param array<string, array<string, mixed>> $steps
     */
    private function stepRun(array $steps, string $stepName): string
    {
        self::assertArrayHasKey($stepName, $steps);
        self::assertIsString($steps[$stepName]['run'] ?? null);

        return trim($steps[$stepName]['run']);
    }
}
