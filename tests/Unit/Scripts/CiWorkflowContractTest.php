<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class CiWorkflowContractTest extends TestCase
{
    public function testDefenseCachePreparationPreservesBlockingSuiteAndEvidence(): void
    {
        $restoreKeys = [];
        $saveKeys = [];
        foreach ([1, 2] as $shard) {
            $jobName = "defense-cycle-ordinary-flows-shard-{$shard}";
            $job = $this->workflowJob($jobName);
            self::assertSame(['changes'], $job['needs']);
            self::assertSame("needs.changes.outputs.runtime_checks_required == 'true'", $job['if']);
            self::assertArrayNotHasKey('continue-on-error', $job);
            self::assertSame(['contents' => 'read'], $job['permissions']);
            $steps = $this->namedSteps($job);
            $optional = ['Restore complete PHP layer archive', 'Save complete PHP layer archive'];
            foreach ($steps as $name => $step) {
                if (in_array($name, $optional, true)) {
                    self::assertTrue($step['continue-on-error']);
                    self::assertSame(1, $step['timeout-minutes']);
                } else {
                    self::assertArrayNotHasKey('continue-on-error', $step);
                }
            }
            $restore = $steps['Restore complete PHP layer archive'];
            $save = $steps['Save complete PHP layer archive'];
            self::assertSame($restore['with']['path'], $save['with']['path']);
            self::assertSame($restore['with']['key'], $save['with']['key']);
            self::assertSame(
                '${{ steps.php-cache-key.outputs.shard-prefix }}' . "\n" . '${{ steps.php-cache-key.outputs.prefix }}',
                trim($restore['with']['restore-keys']),
            );
            self::assertStringContainsString(
                'shard-prefix=defense-php-oci-v1-${image##*:}-shard-' . $shard . '-',
                $this->stepRun($steps, 'Resolve PHP cache recipe'),
            );
            self::assertStringContainsString('github.run_id', $save['with']['key']);
            self::assertStringContainsString('steps.php-cache-key.outputs.shard-prefix', $save['with']['key']);
            self::assertSame("steps.php-cache-save-preparation.outputs.cache_export_ready == 'true'", $save['if']);
            $restoreKeys[] = $restore['with']['key'];
            $saveKeys[] = $this->stepRun($steps, 'Resolve PHP cache recipe');
            $builder = $steps['Set up PHP layer-cache builder'];
            self::assertSame('docker/setup-buildx-action@v4', $builder['uses']);
            self::assertFalse($builder['with']['cache-binary']);
            self::assertTrue($builder['with']['cleanup']);
            $build = $steps['Build PHP with bounded layer cache'];
            self::assertStringContainsString(
                'python3 -B scripts/ci/defense_php_cache.py --resolve-compose --cache-dir "$PHP_CACHE_DIR" --report storage/logs/ci/php-build/summary.json',
                $build['run'],
            );
            self::assertStringContainsString(
                'if [[ "$RESTORE_OUTCOME" != success || -z "$RESTORED_KEY" ]]',
                $build['run'],
            );
            self::assertStringContainsString(
                "--label defense-cycle-shard-{$shard} -- bash scripts/ci/run_defense_cycle.sh --shard {$shard}",
                $this->stepRun($steps, 'Run isolated ordinary application and session lifecycle checks'),
            );
            foreach (['Upload PHP build timing evidence', 'Upload gate diagnostic evidence'] as $name) {
                self::assertSame('always()', $steps[$name]['if']);
                self::assertSame('actions/upload-artifact@v7', $steps[$name]['uses']);
            }
            $receipt = $steps['Upload Defense phase and testcase evidence'];
            self::assertSame('always()', $receipt['if']);
            self::assertSame('storage/logs/ci/defense-cycle/*.summary.json', $receipt['with']['path']);
            self::assertSame('storage/logs/ci/php-build/', $steps['Upload PHP build timing evidence']['with']['path']);
            self::assertSame(
                'storage/logs/ci/gate-summary/',
                $steps['Upload gate diagnostic evidence']['with']['path'],
            );
        }
        self::assertSame($restoreKeys[0], $restoreKeys[1]);
        self::assertNotSame($saveKeys[0], $saveKeys[1]);
        $aggregator = $this->workflowJob('defense-cycle-ordinary-flows');
        self::assertSame(
            ['changes', 'defense-cycle-ordinary-flows-shard-1', 'defense-cycle-ordinary-flows-shard-2'],
            $aggregator['needs'],
        );
        self::assertSame("always() && needs.changes.outputs.runtime_checks_required == 'true'", $aggregator['if']);
        $aggregatorSteps = $this->namedSteps($aggregator);
        self::assertStringContainsString(
            'needs.defense-cycle-ordinary-flows-shard-1.result',
            $this->stepRun($aggregatorSteps, 'Assert both Defense shards passed with receipts'),
        );
        self::assertStringContainsString(
            'needs.defense-cycle-ordinary-flows-shard-2.result',
            $this->stepRun($aggregatorSteps, 'Assert both Defense shards passed with receipts'),
        );
        self::assertStringContainsString(
            'report.get(\'shard\') != shard',
            $this->stepRun($aggregatorSteps, 'Assert both Defense shards passed with receipts'),
        );
        self::assertStringContainsString(
            "report.get('source', {}).get('commit') != '\${{ github.sha }}'",
            $this->stepRun($aggregatorSteps, 'Assert both Defense shards passed with receipts'),
        );
        self::assertStringContainsString(
            "report.get('source', {}).get('dirty') is not False",
            $this->stepRun($aggregatorSteps, 'Assert both Defense shards passed with receipts'),
        );
        self::assertStringContainsString(
            'set(canonical) != set(shard_1) | set(shard_2)',
            $this->stepRun($aggregatorSteps, 'Assert Defense shard configs partition canonical suite'),
        );
        foreach ([1, 2] as $shard) {
            $download = $aggregatorSteps["Download Defense shard {$shard} receipts"];
            self::assertSame(
                "defense-cycle-receipts-defense-cycle-ordinary-flows-shard-{$shard}",
                $download['with']['name'],
            );
        }
    }

    public function testDefenseSelectionRetainsPullRequestAndMainPushDiffSemantics(): void
    {
        $workflow = Yaml::parseFile(__DIR__ . '/../../../.github/workflows/ci.yml');
        foreach (['push', 'pull_request'] as $event) {
            self::assertSame(['main'], $workflow['on'][$event]['branches']);
            self::assertArrayNotHasKey('paths', $workflow['on'][$event]);
            self::assertArrayNotHasKey('paths-ignore', $workflow['on'][$event]);
        }
        $changes = $workflow['jobs']['changes'];
        self::assertArrayNotHasKey('if', $changes);
        self::assertArrayNotHasKey('continue-on-error', $changes);
        $steps = $this->namedSteps($changes);
        self::assertSame(0, $steps['Git clone']['with']['fetch-depth']);
        $filter = $steps['Detect relevant file changes'];
        self::assertSame('dorny/paths-filter@v3', $filter['uses']);
        self::assertSame('filter', $filter['id']);
        // Preserve the action defaults: PR base diff, or previous push on main.
        foreach (['base', 'ref', 'predicate-quantifier'] as $input) {
            self::assertArrayNotHasKey($input, $filter['with']);
        }
        self::assertArrayNotHasKey('if', $filter);
        self::assertArrayNotHasKey('continue-on-error', $filter);
        self::assertSame(
            '${{ steps.filter.outputs.runtime_checks_required }}',
            $changes['outputs']['runtime_checks_required'],
        );
    }

    public function testDefenseShardConfigsPartitionTheCompleteSuiteWithoutSplittingMovedClasses(): void
    {
        $filesFrom = static function (string $path): array {
            $xml = simplexml_load_file($path);
            self::assertNotFalse($xml);
            $files = [];
            foreach ($xml->testsuites->testsuite->file as $file) {
                $files[] = trim((string) $file);
            }
            self::assertCount(count(array_unique($files)), $files, $path . ' contains duplicate files');
            return $files;
        };
        $canonical = $filesFrom(__DIR__ . '/../../../phpunit.defense-cycle.xml');
        $shards = [];
        foreach ([1, 2] as $shard) {
            $shards[$shard] = $filesFrom(__DIR__ . "/../../../phpunit.defense-cycle-shard-{$shard}.xml");
        }
        $canonicalSet = array_values(array_unique($canonical));
        sort($canonicalSet);
        $union = array_values(array_unique(array_merge($shards[1], $shards[2])));
        sort($union);
        self::assertSame($canonicalSet, $union);
        self::assertNotEmpty($shards[1]);
        self::assertNotEmpty($shards[2]);
        self::assertLessThanOrEqual(
            (int) ceil(count($canonicalSet) / 5),
            abs(count($shards[1]) - count($shards[2])),
            'Defense shard file counts should stay within a coarse 20% balance bound; runtime is measured in CI.',
        );
        self::assertSame([], array_intersect($shards[1], $shards[2]));
        self::assertContains('./tests/Integration/Controllers/BookingAvailabilityAuthorityHttpTest.php', $shards[1]);
        self::assertContains('./tests/Integration/Controllers/StaffSettingsApiHttpTest.php', $shards[1]);
        self::assertNotContains('./tests/Integration/Controllers/StaffSettingsApiHttpTest.php', $shards[2]);
        foreach (
            [
                './tests/Integration/SessionLifecycleTest.php',
                './tests/Integration/Controllers/BlockedPeriodsApiHttpWriteTest.php',
            ]
            as $moved
        ) {
            self::assertNotContains($moved, $shards[1]);
            self::assertContains($moved, $shards[2]);
        }
        self::assertContains('./tests/Integration/DefenseVerificationFixtureTest.php', $shards[2]);
        self::assertContains('./tests/Integration/Controllers/CustomersApiHttpReadTest.php', $shards[2]);
    }

    public function testJavaScriptLintSelectsChangesBeforeInstallingDependencies(): void
    {
        $steps = $this->namedSteps($this->workflowJob('js-lint-changed'));
        self::assertSame(
            [
                'Git clone',
                'Check frontend validation inputs',
                'Setup Node.js',
                'Install npm dependencies',
                'ESLint changed JS files',
                'Resolve system Chrome for CSP probe',
                'Frontend compiler regression tests',
                'Gate diagnostic summary',
                'Upload gate diagnostic evidence',
            ],
            array_keys($steps),
        );
        self::assertSame('js_changes', $steps['Check frontend validation inputs']['id']);
        self::assertArrayNotHasKey('if', $steps['Check frontend validation inputs']);
        self::assertSame(
            './scripts/ci/js-lint-changed.sh --check-only',
            $this->gateBody($steps, 'Check frontend validation inputs', 'js-lint-changed-1'),
        );
        foreach (['Setup Node.js', 'Install npm dependencies', 'Frontend compiler regression tests'] as $name) {
            self::assertSame("steps.js_changes.outputs.needs_node == 'true'", $steps[$name]['if']);
            self::assertArrayNotHasKey('continue-on-error', $steps[$name]);
        }
        self::assertSame(
            'npm ci --ignore-scripts --no-audit --no-fund',
            $this->gateBody($steps, 'Install npm dependencies', 'js-lint-changed-2'),
        );
        self::assertSame($steps['Check frontend validation inputs']['env'], $steps['ESLint changed JS files']['env']);
        self::assertSame(
            './scripts/ci/js-lint-changed.sh',
            $this->gateBody($steps, 'ESLint changed JS files', 'js-lint-changed-3'),
        );
        self::assertSame("steps.js_changes.outputs.has_changes == 'true'", $steps['ESLint changed JS files']['if']);
        self::assertSame(
            "steps.js_changes.outputs.csp_probe_changed == 'true'",
            $steps['Resolve system Chrome for CSP probe']['if'],
        );
        self::assertSame(
            "chrome_path=\"\$(command -v google-chrome || true)\"\ntest -n \"\$chrome_path\"\ntest -x \"\$chrome_path\"\necho \"PLAYWRIGHT_MCP_EXECUTABLE_PATH=\$chrome_path\" >> \"\$GITHUB_ENV\"",
            $this->gateBody($steps, 'Resolve system Chrome for CSP probe', 'js-lint-changed-5'),
        );
        self::assertSame(
            'node --test tests/JavaScript/gulp_build.test.js tests/JavaScript/dashboard_date_range.test.js tests/JavaScript/dashboard_zero_target.test.js tests/JavaScript/blocked_periods.test.js tests/JavaScript/csp_compatibility_probe.test.js tests/JavaScript/admins_secretaries_username_validation.test.js',
            $this->gateBody($steps, 'Frontend compiler regression tests', 'js-lint-changed-4'),
        );
    }

    public function testArchitectureDocsChecksRemainWithoutPhpPreparation(): void
    {
        $job = $this->workflowJob('architecture-boundaries');
        self::assertSame(['changes'], $job['needs']);
        self::assertArrayNotHasKey('if', $job);
        $steps = $this->namedSteps($job);
        foreach (['Setup PHP', 'Install dependencies'] as $name) {
            self::assertSame("needs.changes.outputs.runtime_checks_required == 'true'", $steps[$name]['if']);
        }
        foreach (
            ['Check generated CODEOWNERS', 'Run Deptrac changed-file gate', 'Run component boundary check']
            as $name
        ) {
            self::assertArrayNotHasKey('if', $steps[$name]);
            self::assertArrayNotHasKey('continue-on-error', $steps[$name]);
        }
    }

    public function testGeneralAndRootSuitesRunIndependentlyAndFailClosed(): void
    {
        $job = $this->workflowJob('build-test');
        $steps = $this->namedSteps($job);

        foreach ($steps as $step) {
            self::assertArrayNotHasKey('continue-on-error', $step);
            self::assertStringNotContainsString('php-actions/phpunit', (string) ($step['uses'] ?? ''));
        }

        $requiredOrder = [
            'Git clone',
            'Start build-test database',
            'Setup Node.js',
            'Install frontend test dependencies',
            'Setup PHP',
            'Install dependencies',
            'Prepare root test configuration',
            'Wait for build-test MySQL readiness',
            'Install deterministic build-test instance',
            'PHPUnit Tests',
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

        self::assertSame(
            'docker compose up -d mysql',
            $this->gateBody($steps, 'Start build-test database', 'build-test-1'),
        );
        self::assertSame(['changes'], $job['needs'] ?? null);
        self::assertSame("needs.changes.outputs.runtime_checks_required == 'true'", $job['if'] ?? null);
        self::assertSame(
            'bash scripts/ci/wait_for_mysql_readiness.sh',
            $this->gateBody($steps, 'Wait for build-test MySQL readiness', 'build-test-5'),
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
        self::assertStringContainsString('--log-junit storage/logs/ci/phpunit-general.junit.xml', $general);
        self::assertStringContainsString('--log-otr storage/logs/ci/phpunit-general.otr.xml', $general);
        self::assertStringContainsString('| tee storage/logs/ci/phpunit-general.log', $general);
        self::assertStringContainsString('The general PHPUnit suite failed.', $general);
        self::assertStringContainsString('exit 1', $general);
        self::assertStringContainsString(
            "grep -Eq '^(OK \\([1-9][0-9]* tests?,|Tests: [1-9][0-9]*,)' storage/logs/ci/phpunit-general.log",
            $general,
        );

        self::assertStringContainsString('--exclude-group root-deployment', $general);
        self::assertSame('always()', $steps['Summarize general PHPUnit cases']['if']);
        self::assertStringContainsString(
            '--input storage/logs/ci/phpunit-general.junit.xml',
            $this->stepRun($steps, 'Summarize general PHPUnit cases'),
        );
        self::assertStringContainsString(
            '--otr-input storage/logs/ci/phpunit-general.otr.xml',
            $this->stepRun($steps, 'Summarize general PHPUnit cases'),
        );
        self::assertSame('always()', $steps['Upload general PHPUnit receipt']['if']);
        self::assertSame('actions/upload-artifact@v7', $steps['Upload general PHPUnit receipt']['uses']);
        self::assertSame('error', $steps['Upload general PHPUnit receipt']['with']['if-no-files-found']);
        $changesJob = $this->workflowJob('changes');
        self::assertSame(
            '${{ steps.filter.outputs.runtime_checks_required }}',
            $changesJob['outputs']['runtime_checks_required'] ?? null,
        );
        $rootJob = $this->workflowJob('root-deployment-tests');
        self::assertSame(['changes'], $rootJob['needs'] ?? null);
        self::assertSame("needs.changes.outputs.runtime_checks_required == 'true'", $rootJob['if'] ?? null);
        self::assertArrayNotHasKey('continue-on-error', $rootJob);
        foreach (['phpstan-application', 'typed-request-dto'] as $jobName) {
            $selectedJob = $this->workflowJob($jobName);
            self::assertSame(['changes'], $selectedJob['needs'] ?? null, $jobName);
            self::assertSame(
                "needs.changes.outputs.runtime_checks_required == 'true'",
                $selectedJob['if'] ?? null,
                $jobName,
            );
        }
        $rootSteps = $this->namedSteps($rootJob);
        foreach ($rootSteps as $step) {
            self::assertArrayNotHasKey('continue-on-error', $step);
        }
        self::assertSame(
            [
                'Git clone',
                'Setup PHP',
                'Install dependencies',
                'Root deployment regression tests',
                'Summarize root deployment PHPUnit cases',
                'Upload root deployment PHPUnit receipt',
                'Gate diagnostic summary',
                'Upload gate diagnostic evidence',
            ],
            array_keys($rootSteps),
        );
        self::assertSame(
            'composer install --no-interaction --no-progress',
            $this->gateBody($rootSteps, 'Install dependencies', 'root-deployment-tests-1'),
        );
        $rootDeployment = $this->gateBody($rootSteps, 'Root deployment regression tests', 'root-deployment-tests-2');
        self::assertSame('bash scripts/ci/run_root_deployment_regressions.sh', $rootDeployment);
        $rootDeploymentScript = (string) file_get_contents(
            __DIR__ . '/../../../scripts/ci/run_root_deployment_regressions.sh',
        );
        self::assertStringContainsString('set -euo pipefail', $rootDeploymentScript);
        self::assertStringContainsString('cd "$ROOT_DIR"', $rootDeploymentScript);
        self::assertStringContainsString('--fail-on-skipped', $rootDeploymentScript);
        self::assertStringContainsString('--display-warnings', $rootDeploymentScript);
        self::assertStringContainsString(
            '--log-junit storage/logs/ci/root-deployment.junit.xml',
            $rootDeploymentScript,
        );
        self::assertStringContainsString('--log-otr storage/logs/ci/root-deployment.otr.xml', $rootDeploymentScript);
        self::assertStringContainsString(
            'tests/Unit/Scripts/MaintenanceActivityIdentityContractTest.php',
            $rootDeploymentScript,
        );
        self::assertStringContainsString('tests/Unit/Scripts/ProdBuildCacheRetentionTest.php', $rootDeploymentScript);
        self::assertSame('always()', $rootSteps['Summarize root deployment PHPUnit cases']['if']);
        self::assertStringContainsString(
            '--input storage/logs/ci/root-deployment.junit.xml',
            $this->stepRun($rootSteps, 'Summarize root deployment PHPUnit cases'),
        );
        self::assertStringContainsString(
            '--otr-input storage/logs/ci/root-deployment.otr.xml',
            $this->stepRun($rootSteps, 'Summarize root deployment PHPUnit cases'),
        );
        self::assertSame('always()', $rootSteps['Upload root deployment PHPUnit receipt']['if']);
        self::assertSame('actions/upload-artifact@v7', $rootSteps['Upload root deployment PHPUnit receipt']['uses']);
        self::assertSame('error', $rootSteps['Upload root deployment PHPUnit receipt']['with']['if-no-files-found']);
        self::assertStringContainsString('systemd-analyze verify', $rootDeploymentScript);
        self::assertStringContainsString('scripts/ops/systemd/fh-session-retention.service', $rootDeploymentScript);
        self::assertStringContainsString('scripts/ops/systemd/fh-session-retention.timer', $rootDeploymentScript);
        self::assertStringContainsString(
            'sudo env FH_ROOT_HOST_TESTS_REQUIRED=1 php vendor/bin/phpunit',
            $rootDeploymentScript,
        );
        self::assertStringContainsString(
            'docker pull mariadb@sha256:2f2b6bbcdbaf88afe53b76cb8d73927b623559180c5ab15db2049736f32ec590',
            $rootDeploymentScript,
        );
        self::assertStringContainsString(
            'tests/Unit/Scripts/DeploymentDumpAttestationProducerV1RootTest.php',
            $rootDeploymentScript,
        );
        self::assertStringContainsString('tests/Unit/Scripts/BackupSetProducerRootTest.php', $rootDeploymentScript);
        self::assertStringContainsString(
            'tests/Unit/Scripts/BackupTimerTransitionContractTest.php',
            $rootDeploymentScript,
        );
        self::assertStringContainsString(
            'tests/Unit/Scripts/ProdReleaseReadinessPreflightTest.php',
            $rootDeploymentScript,
        );
        self::assertStringContainsString('tests/Unit/Scripts/PublishReleasePairRootTest.php', $rootDeploymentScript);
        self::assertStringContainsString('tests/Unit/Scripts/SessionRetentionRootTest.php', $rootDeploymentScript);
        self::assertStringContainsString(
            'tests/Unit/Scripts/ReleaseArchiveDumpRetentionRootTest.php',
            $rootDeploymentScript,
        );
        self::assertStringContainsString(
            'sudo python3 -m unittest tests.Unit.Scripts.release_archive_dump_retention_v1_test',
            $rootDeploymentScript,
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
            $this->gateBody($steps, 'Cleanup build-test database', 'build-test-9'),
        );
    }

    public function testRootDependentApplicationFixturesRunWithSeparateReceiptsAndCleanup(): void
    {
        $base = Yaml::parseFile(__DIR__ . '/../../../docker-compose.yml');
        $ci = Yaml::parseFile(__DIR__ . '/../../../docker/compose.ci-local.yml', Yaml::PARSE_CUSTOM_TAGS);
        $zeroSurprise = Yaml::parseFile(
            __DIR__ . '/../../../docker/compose.zero-surprise.yml',
            Yaml::PARSE_CUSTOM_TAGS,
        );
        self::assertSame('docker/php-fpm', $base['services']['php-fpm']['build']);
        self::assertArrayNotHasKey('php-fpm', $ci['services'] ?? []);
        self::assertArrayNotHasKey('build', $zeroSurprise['services']['php-fpm'] ?? []);

        $steps = $this->namedSteps($this->workflowJob('calendar-canary-regressions'));
        $run = $this->stepRun($steps, 'Run isolated application root suites');
        self::assertSame(
            'calendar-canary-regressions',
            $this->workflowJob('calendar-canary-regressions')['env']['COMPOSE_PROJECT_NAME'],
        );
        $resolve = $this->stepRun($steps, 'Resolve PHP cache recipe');
        self::assertStringContainsString(
            'python3 -B scripts/ci/local_php_image_key.py --platform "$platform"',
            $resolve,
        );
        self::assertStringContainsString('[[ "$image" =~ ^forscherhaus-local/php-fpm:[0-9a-f]{64}$ ]]', $resolve);
        self::assertStringContainsString(
            'echo "prefix=defense-php-oci-v1-${image##*:}-" >> "$GITHUB_OUTPUT"',
            $resolve,
        );
        $build = $this->stepRun($steps, 'Build PHP with bounded layer cache');
        self::assertStringContainsString(
            'docker compose config --format json | python3 -B scripts/ci/defense_php_cache.py',
            $build,
        );
        self::assertStringContainsString('--cache-dir "$PHP_CACHE_DIR"', $build);
        self::assertStringContainsString('docker tag "$php_image" "${COMPOSE_PROJECT_NAME}-php-fpm"', $build);
        self::assertStringContainsString('cat > storage/logs/ci/calendar-php-image.yml <<EOF', $build);
        self::assertStringContainsString('image: ${COMPOSE_PROJECT_NAME}-php-fpm', $build);
        self::assertStringContainsString('build: !reset null', $build);
        self::assertStringContainsString(
            'COMPOSE_FILE=docker-compose.yml:docker/compose.zero-surprise.yml:storage/logs/ci/calendar-php-image.yml',
            $build,
        );
        self::assertStringContainsString(
            'if [[ "$RESTORE_OUTCOME" != success || -z "$RESTORED_KEY" ]]; then' .
                "\n" .
                '  rm -rf -- "$PHP_CACHE_DIR/import"' .
                "\n" .
                'fi',
            $build,
        );
        self::assertSame('docker/setup-buildx-action@v4', $steps['Set up PHP layer-cache builder']['uses']);
        $restore = $steps['Restore complete PHP layer archive'];
        $save = $steps['Save complete PHP layer archive'];
        $defenseSteps = $this->namedSteps($this->workflowJob('defense-cycle-ordinary-flows-shard-1'));
        $defenseRestore = $defenseSteps['Restore complete PHP layer archive'];
        $defenseSave = $defenseSteps['Save complete PHP layer archive'];
        self::assertTrue($restore['continue-on-error']);
        self::assertTrue($save['continue-on-error']);
        self::assertSame($restore['with']['path'], $save['with']['path']);
        self::assertSame($restore['with']['key'], $save['with']['key']);
        self::assertSame($defenseRestore['with']['path'], $restore['with']['path']);
        self::assertSame($defenseSave['with']['path'], $save['with']['path']);
        self::assertNotSame($defenseRestore['with']['key'], $restore['with']['key']);
        self::assertStringContainsString('-calendar', $restore['with']['key']);
        self::assertStringContainsString('-calendar', $save['with']['key']);
        self::assertSame(
            $defenseSteps['Build PHP with bounded layer cache']['env']['PHP_CACHE_DIR'],
            $steps['Build PHP with bounded layer cache']['env']['PHP_CACHE_DIR'],
        );
        self::assertSame(
            '${{ runner.temp }}/defense-php-cache',
            $steps['Build PHP with bounded layer cache']['env']['PHP_CACHE_DIR'],
        );
        self::assertStringContainsString('${{ github.run_id }}-${{ github.run_attempt }}', $restore['with']['key']);
        self::assertSame('${{ steps.php-cache-key.outputs.prefix }}', $restore['with']['restore-keys']);
        self::assertSame("steps.php-cache-save-preparation.outputs.cache_export_ready == 'true'", $save['if']);
        $prepare = $this->stepRun($steps, 'Prepare complete PHP layer archive');
        self::assertStringContainsString("ready = report.get('cache_export_ready') is True", $prepare);
        self::assertStringContainsString("shutil.rmtree(root / 'import', ignore_errors=True)", $prepare);
        self::assertStringContainsString("(root / 'export').rename(root / 'import')", $prepare);
        self::assertStringContainsString('except OSError:', $prepare);
        self::assertStringContainsString('ready = False', $prepare);
        self::assertStringContainsString('output.write(f"cache_export_ready={str(ready).lower()}\\n")', $prepare);
        self::assertStringNotContainsString('--no-build', $run);
        $effectiveConfig = 'docker compose config --format json > storage/logs/ci/calendar-effective-compose.json';
        self::assertStringContainsString($effectiveConfig, $run);
        self::assertStringContainsString("expected_image = f\"{os.environ['COMPOSE_PROJECT_NAME']}-php-fpm\"", $run);
        self::assertStringContainsString("php_fpm.get('image') != expected_image or 'build' in php_fpm", $run);
        self::assertLessThan(strpos($run, 'docker compose up -d mysql'), strpos($run, $effectiveConfig));
        self::assertLessThan(strpos($run, 'docker compose run --rm'), strpos($run, 'docker compose up -d mysql'));
        self::assertStringContainsString('docker compose run --rm --user root -T php-fpm bash -lc', $run);

        foreach (
            [
                'customers-ui-smoke-fixture-lifecycle.junit.xml',
                'provider-ui-smoke-fixture-lifecycle.junit.xml',
                'tests/Unit/Libraries/CustomersUiSmokeFixtureLifecycleTest.php',
                'tests/Unit/Libraries/ProviderUiSmokeFixtureLifecycleTest.php',
                'csp-report-only-root.junit.xml',
                'tests/Unit/Scripts/CspReportOnlyActivationScriptTest.php',
                'tests/Unit/Scripts/CspReportOnlyStatusScriptTest.php',
            ]
            as $expected
        ) {
            self::assertStringContainsString($expected, $run);
        }
        self::assertSame(5, substr_count($run, '--fail-on-skipped'));
        self::assertSame(5, substr_count($run, '--log-junit'));
        self::assertSame(5, substr_count($run, '--log-otr'));
        self::assertStringContainsString('suite_status=0', $run);
        self::assertSame(5, substr_count($run, '|| suite_status=1'));
        self::assertStringContainsString('exit "$suite_status"', $run);
        self::assertSame('always()', $steps['Summarize application root test cases']['if']);
        self::assertSame(5, substr_count($this->stepRun($steps, 'Summarize application root test cases'), '--input'));
        self::assertSame(
            5,
            substr_count($this->stepRun($steps, 'Summarize application root test cases'), '--otr-input'),
        );
        self::assertStringContainsString('--bootstrap tests/bootstrap.php', $run);
        self::assertSame('always()', $steps['Cleanup isolated database and canary fixture']['if']);
        self::assertSame(
            'docker compose down -v --remove-orphans',
            $this->stepRun($steps, 'Cleanup isolated database and canary fixture'),
        );

        $receipt = $steps['Upload per-test receipt'];
        self::assertSame('always()', $receipt['if']);
        self::assertSame('actions/upload-artifact@v7', $receipt['uses']);
        self::assertStringContainsString(
            'storage/logs/ci/customers-ui-smoke-fixture-lifecycle.junit.xml',
            $receipt['with']['path'],
        );
        self::assertStringContainsString(
            'storage/logs/ci/provider-ui-smoke-fixture-lifecycle.junit.xml',
            $receipt['with']['path'],
        );
        self::assertStringContainsString(
            'storage/logs/ci/calendar-canary-regressions.cases.json',
            $receipt['with']['path'],
        );
        self::assertStringContainsString('storage/logs/ci/php-build/', $receipt['with']['path']);
    }

    public function testDeepRuntimeWorkloadProfileInputsStayExplicitInTheWorkflow(): void
    {
        $steps = $this->namedSteps($this->workflowJob('deep-runtime-suite'));
        $stepNames = array_keys($steps);
        $serviceIndex = array_search('Start deep runtime services', $stepNames, true);
        $setupIndex = array_search('Setup PHP', $stepNames, true);
        $readinessIndex = array_search('Wait for MySQL readiness', $stepNames, true);
        $seedIndex = array_search('Install deterministic seed instance', $stepNames, true);
        self::assertIsInt($serviceIndex);
        self::assertIsInt($setupIndex);
        self::assertIsInt($readinessIndex);
        self::assertIsInt($seedIndex);
        self::assertLessThan($setupIndex, $serviceIndex);
        self::assertLessThan($seedIndex, $readinessIndex);
        $installBrowser = $this->gateBody($steps, 'Install Playwright smoke browser', 'deep-runtime-suite-12');
        $deepRuntime = $this->gateBody($steps, 'Run deep runtime suite', 'deep-runtime-suite-13');

        self::assertStringContainsString(
            'bash scripts/release-gate/playwright/playwright_cli.sh install-browser',
            $installBrowser,
        );
        self::assertSame(
            'playwright@1.59.0-alpha-1771104257000',
            $steps['Install Playwright smoke browser']['env']['PLAYWRIGHT_RUNTIME_PACKAGE'] ?? null,
        );
        self::assertSame(
            'chromium',
            $steps['Install Playwright smoke browser']['env']['PLAYWRIGHT_MCP_BROWSER'] ?? null,
        );
        foreach (
            [
                '--booking-search-days=14',
                '--retry-count=1',
                '--start-date=2026-01-12',
                '--end-date=2026-01-16',
                '--integration-smoke-browser-bootstrap-timeout=900',
            ]
            as $profileInput
        ) {
            self::assertStringContainsString($profileInput, $deepRuntime);
        }
        self::assertSame(
            'playwright@1.59.0-alpha-1771104257000',
            $steps['Run deep runtime suite']['env']['PLAYWRIGHT_RUNTIME_PACKAGE'] ?? null,
        );
        self::assertSame('chromium', $steps['Run deep runtime suite']['env']['PLAYWRIGHT_MCP_BROWSER'] ?? null);
    }

    public function testDeepRuntimeArtifactsAreAttemptScopedAndConsumersFailClosed(): void
    {
        $producer = $this->workflowJob('deep-runtime-suite');
        self::assertSame(
            'deep-runtime-suite-artifacts-${{ github.run_attempt }}',
            $producer['outputs']['artifact-name'] ?? null,
        );

        $producerSteps = $this->namedSteps($producer);
        $upload = $producerSteps['Upload deep runtime suite artifacts'];
        self::assertSame('always()', $upload['if'] ?? null);
        self::assertSame('deep-runtime-suite-artifacts-${{ github.run_attempt }}', $upload['with']['name'] ?? null);
        self::assertSame('error', $upload['with']['if-no-files-found'] ?? null);

        $consumerArtifactExpression =
            "\${{ needs.deep-runtime-suite.outputs.artifact-name || 'missing-deep-runtime-artifact' }}";
        foreach (
            [
                'api-contract-openapi',
                'write-contract-booking',
                'write-contract-api',
                'booking-controller-flows',
                'integration-smoke',
            ]
            as $jobName
        ) {
            $consumerSteps = $this->namedSteps($this->workflowJob($jobName));
            self::assertSame(
                'actions/download-artifact@v8',
                $consumerSteps['Download deep runtime suite artifacts']['uses'],
            );
            self::assertSame(
                $consumerArtifactExpression,
                $consumerSteps['Download deep runtime suite artifacts']['with']['name'] ?? null,
                $jobName,
            );
        }

        self::assertSame('always()', $producerSteps['Cleanup deep runtime services']['if'] ?? null);
    }

    public function testCoverageIntegrationInitializesItsOwnDatabaseAfterReadiness(): void
    {
        $job = $this->workflowJob('coverage-shard-integration');
        self::assertSame(['changes'], $job['needs'] ?? null);
        $steps = $this->namedSteps($job);
        foreach ($steps as $step) {
            self::assertArrayNotHasKey('continue-on-error', $step);
        }
        $setupPhp = $steps['Setup PHP'];
        self::assertSame('shivammathur/setup-php@v2', $setupPhp['uses'] ?? null);
        self::assertSame('8.4.1', $setupPhp['with']['php-version'] ?? null);
        self::assertSame('xdebug', $setupPhp['with']['coverage'] ?? null);
        self::assertSame('gd', $setupPhp['with']['extensions'] ?? null);
        self::assertSame('composer:v2', $setupPhp['with']['tools'] ?? null);
        self::assertSame(
            'composer install --no-interaction --no-progress',
            $this->gateBody($steps, 'Install Composer dependencies', 'coverage-shard-integration-2'),
        );
        self::assertArrayNotHasKey('Download deterministic seed snapshot artifact', $steps);
        self::assertArrayNotHasKey('Import deterministic seed snapshot', $steps);

        $stepNames = array_keys($steps);
        $prepare = $this->stepRun($steps, 'Prepare root test configuration');
        self::assertStringContainsString('test ! -e config.php', $prepare);
        self::assertStringContainsString('install -m 0600 config-sample.php config.php', $prepare);
        self::assertStringContainsString(
            'sed -i "s/const DB_HOST = \'mysql\';/const DB_HOST = \'127.0.0.1\';/" config.php',
            $prepare,
        );
        self::assertStringContainsString('grep -Fq "const DB_HOST = \'127.0.0.1\';" config.php', $prepare);
        self::assertSame(
            'docker compose up -d mysql',
            $this->gateBody($steps, 'Start coverage shard services', 'coverage-shard-integration-1'),
        );
        $prepareIndex = array_search('Prepare root test configuration', $stepNames, true);
        $readinessIndex = array_search('Wait for MySQL readiness', $stepNames, true);
        $installIndex = array_search('Install deterministic seed instance', $stepNames, true);
        self::assertIsInt($prepareIndex);
        self::assertIsInt($readinessIndex);
        self::assertIsInt($installIndex);
        self::assertLessThan($installIndex, $prepareIndex);
        self::assertGreaterThan($readinessIndex, $installIndex);
        $testIndex = array_search('Run coverage shard (integration)', $stepNames, true);
        self::assertIsInt($testIndex);
        self::assertGreaterThan($installIndex, $testIndex);
        $serviceIndex = array_search('Start coverage shard services', $stepNames, true);
        $setupIndex = array_search('Setup PHP', $stepNames, true);
        self::assertIsInt($serviceIndex);
        self::assertIsInt($setupIndex);
        self::assertLessThan($setupIndex, $serviceIndex);
        self::assertSame('always()', $steps['Cleanup coverage shard services']['if'] ?? null);
        self::assertArrayNotHasKey('continue-on-error', $steps['Install deterministic seed instance']);

        $install = $this->stepRun($steps, 'Install deterministic seed instance');
        self::assertStringContainsString('for attempt in 1 2 3; do', $install);
        self::assertStringContainsString('if php index.php console install; then', $install);
        self::assertStringContainsString('console install failed after 3 attempts.', $install);
        self::assertStringContainsString('exit 1', $install);

        $coverage = $this->gateBody($steps, 'Run coverage shard (integration)', 'coverage-shard-integration-6');
        self::assertSame('composer test:coverage:integration-shard', $coverage);

        $diagnostics = $this->stepRun($steps, 'Diagnostics (failure logs)');
        self::assertStringContainsString('php -v || true', $diagnostics);
        self::assertStringContainsString('docker compose logs --no-color --timestamps mysql || true', $diagnostics);
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

    private function gateBody(array $steps, string $stepName, string $label): string
    {
        $run = $this->stepRun($steps, $stepName);
        $prefix = "python3 -B scripts/ci/run_gate_with_summary.py --label {$label} -- bash --noprofile --norc -e <<'ROB557_GATE_COMMAND'\n";
        $suffix = "\nROB557_GATE_COMMAND";
        self::assertStringStartsWith($prefix, $run, $stepName);
        self::assertStringEndsWith($suffix, $run, $stepName);

        return substr($run, strlen($prefix), -strlen($suffix));
    }
}
