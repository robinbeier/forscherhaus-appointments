<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class CiWorkflowContractTest extends TestCase
{
    public function testBuildTestRunsGeneralSuiteFailClosedBeforeRootDeploymentTests(): void
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

        $rootDeployment = $this->stepRun($steps, 'ROB-442 root deployment regression tests');
        self::assertStringContainsString('systemd-analyze verify', $rootDeployment);
        self::assertStringContainsString('scripts/ops/systemd/fh-app-log-retention.service', $rootDeployment);
        self::assertStringContainsString('scripts/ops/systemd/fh-app-log-retention.timer', $rootDeployment);
        self::assertStringContainsString('scripts/ops/systemd/fh-session-retention.service', $rootDeployment);
        self::assertStringContainsString('scripts/ops/systemd/fh-session-retention.timer', $rootDeployment);
        self::assertStringContainsString('scripts/ops/systemd/fh-dump-producer-admission.service', $rootDeployment);
        self::assertStringContainsString('scripts/ops/systemd/fh-dump-producer-admission.timer', $rootDeployment);
        self::assertStringContainsString(
            'sudo env FH_ROOT_HOST_TESTS_REQUIRED=1 php vendor/bin/phpunit',
            $rootDeployment,
        );
        self::assertStringContainsString(
            'docker pull mariadb@sha256:2f2b6bbcdbaf88afe53b76cb8d73927b623559180c5ab15db2049736f32ec590',
            $rootDeployment,
        );
        self::assertStringContainsString('tests/Unit/Scripts/DeploymentHostRunnerV1RootTest.php', $rootDeployment);
        self::assertStringContainsString(
            'tests/Unit/Scripts/DeploymentDumpAttestationProducerV1RootTest.php',
            $rootDeployment,
        );
        self::assertStringContainsString('tests/Unit/Scripts/BackupSetProducerRootTest.php', $rootDeployment);
        self::assertStringContainsString('tests/Unit/Scripts/AppLogRetentionRootTest.php', $rootDeployment);
        self::assertStringContainsString('tests/Unit/Scripts/PinDeployTimingRootTest.php', $rootDeployment);
        self::assertStringContainsString('tests/Unit/Scripts/PublishReleasePairRootTest.php', $rootDeployment);
        self::assertStringContainsString('tests/Unit/Scripts/LegacyReleaseHoldRootTest.php', $rootDeployment);
        self::assertStringContainsString('tests/Unit/Scripts/SessionRetentionRootTest.php', $rootDeployment);
        self::assertStringContainsString(
            'tests/Unit/Scripts/ZeroSurpriseProductionImageCleanupRootTest.php',
            $rootDeployment,
        );
        self::assertStringContainsString('tests/Unit/Scripts/ReleaseArchiveDumpRetentionRootTest.php', $rootDeployment);
        self::assertStringContainsString(
            'sudo python3 -m unittest tests.Unit.Scripts.legacy_release_hold_v1_test',
            $rootDeployment,
        );

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
