<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/ci/measure_ci_performance_baseline.php';

class CiPerformanceBaselineTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/ci-performance-baseline-' . uniqid('', true);
        if (!mkdir($this->tmpDir, 0777, true) && !is_dir($this->tmpDir)) {
            self::fail('Failed to create CI performance baseline test directory.');
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getPathname());
                } else {
                    @unlink($file->getPathname());
                }
            }
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    public function testRepositoryPolicyDefinesAComparableFullGateCohort(): void
    {
        $policy = loadCiPerformanceBaselinePolicy($this->repoPolicyPath());

        self::assertSame(7, $policy['cohort_size']);
        self::assertSame(5, $policy['minimum_samples']);
        self::assertSame('nearest_rank', $policy['percentile_method']);
        self::assertSame(15, $policy['workload_contract']['version']);
        self::assertSame('2026-09-05T10:27:42Z', $policy['workload_contract']['cohort_epoch_utc']);
        self::assertMatchesRegularExpression(
            '/^sha256:[a-f0-9]{64}$/',
            $policy['workload_contract']['workflow_jobs_sha256'],
        );
        self::assertContains('deep-runtime-suite', $policy['required_success_jobs']);
        self::assertSame('coverage-delta', $policy['critical_path_jobs'][4]);
        self::assertSame(2, $policy['comparison_profile']['profile_version']);
        foreach (
            [
                'changes',
                'build-test',
                'js-lint-changed',
                'phpstan-application',
                'typed-request-dto',
                'architecture-ownership-map',
                'architecture-boundaries',
            ]
            as $fullGateJob
        ) {
            self::assertSame(
                'success',
                $policy['comparison_profile']['consumer_conclusions'][$fullGateJob],
                $fullGateJob,
            );
        }
        self::assertSame('success', $policy['comparison_profile']['consumer_conclusions']['api-contract-openapi']);
        self::assertSame('skipped', $policy['comparison_profile']['consumer_conclusions']['heavy-job-duration-trends']);
        self::assertSame(
            900,
            $policy['comparison_profile']['mode_flags']['integration_smoke_browser_bootstrap_timeout'],
        );
        self::assertSame(14, $policy['comparison_profile']['mode_flags']['booking_search_days']);
        self::assertSame(1, $policy['comparison_profile']['mode_flags']['retry_count']);
        self::assertSame('2026-01-01', $policy['comparison_profile']['mode_flags']['start_date']);
        self::assertSame('2026-01-31', $policy['comparison_profile']['mode_flags']['end_date']);
        self::assertSame(
            'playwright@1.59.0-alpha-1771104257000',
            $policy['comparison_profile']['mode_flags']['playwright_runtime_package'],
        );
        $documentation = file_get_contents(__DIR__ . '/../../../docs/ci-performance-baseline.md');
        self::assertNotFalse($documentation);
        self::assertStringContainsString(
            fingerprintCiPerformanceBaselineComparisonProfile($policy['comparison_profile']),
            $documentation,
        );
        self::assertStringContainsString('0 valid post-epoch baseline samples', $documentation);
        self::assertStringContainsString('they are not ROB-446 baseline samples', $documentation);
        self::assertStringContainsString($policy['workload_contract']['workflow_jobs_sha256'], $documentation);
    }

    public function testBuildRunSampleMeasuresElapsedQueueJobsAndPhases(): void
    {
        $policy = $this->policy();
        $run = [
            'id' => 123,
            'created_at' => '2026-09-05T10:30:00Z',
            'conclusion' => 'success',
            'html_url' => 'https://example.test/actions/runs/123',
            'head_branch' => 'codex/example',
            'head_sha' => str_repeat('a', 40),
            'run_attempt' => 1,
            'event' => 'pull_request',
        ];
        $jobs = [
            $this->job('changes', 2, 10),
            $this->job('deep-check-bootstrap', 12, 55),
            $this->job('deep-check-seed-snapshot', 57, 267, [$this->step('Start seed snapshot services', 60, 242)]),
            $this->job('deep-runtime-suite', 57, 324, [
                $this->step('Build runtime JS assets', 58, 62),
                $this->step('Start deep runtime services', 63, 250),
            ]),
            $this->job('coverage-shard-unit', 57, 86),
            $this->job('coverage-shard-integration', 269, 486, [
                $this->step('Start coverage shard services', 275, 459),
            ]),
            $this->job('coverage-delta', 488, 519),
            ...$this->profileConsumerJobs(),
        ];

        $candidate = buildCiPerformanceBaselineRunSample($run, $jobs, $policy, $this->profileLog());
        $sample = $candidate['sample'];

        self::assertTrue($candidate['eligible']);
        self::assertSame(519.0, $sample['workflow_elapsed_seconds']);
        self::assertSame(2.0, $sample['initial_queue_seconds']);
        self::assertSame(2.0, $sample['max_job_queue_seconds']);
        self::assertSame(['coverage-delta'], $sample['latest_jobs']);
        self::assertSame(267.0, $sample['tracked_job_durations_seconds']['deep-runtime-suite']);
        self::assertSame(
            184.0,
            $sample['tracked_step_durations_seconds']['coverage-shard-integration :: Start coverage shard services'],
        );
        self::assertSame(
            fingerprintCiPerformanceBaselineComparisonProfile($policy['comparison_profile']),
            $sample['comparison_profile']['fingerprint'],
        );
        self::assertSame($policy['comparison_profile'], $sample['comparison_profile']['components']);
        self::assertSame($policy['workload_contract'], $sample['workload_contract']);
    }

    public function testBuildRunSampleRejectsRunsBeforeTheWorkloadContractEpoch(): void
    {
        $policy = $this->policy();
        $run = [
            'id' => 122,
            'created_at' => '2026-08-07T22:35:00Z',
            'conclusion' => 'success',
            'run_attempt' => 1,
            'event' => 'pull_request',
        ];
        $jobs = [
            $this->job('changes', 2, 10),
            $this->job('deep-check-bootstrap', 12, 55),
            $this->job('deep-check-seed-snapshot', 57, 267),
            $this->job('deep-runtime-suite', 57, 324, [$this->step('Build runtime JS assets', 58, 62)]),
            $this->job('coverage-shard-unit', 57, 86),
            $this->job('coverage-shard-integration', 269, 486),
            $this->job('coverage-delta', 488, 519),
            ...$this->profileConsumerJobs(),
        ];

        $candidate = buildCiPerformanceBaselineRunSample($run, $jobs, $policy, $this->profileLog());

        self::assertFalse($candidate['eligible']);
        self::assertContains(
            'workload_contract_mismatch: run predates cohort epoch 2026-09-05T10:27:42Z',
            $candidate['reasons'],
        );
    }

    public function testBuildRunSampleExcludesDraftOrPartialProfiles(): void
    {
        $policy = $this->policy();
        $run = [
            'id' => 456,
            'created_at' => '2026-09-05T10:30:00Z',
        ];
        $jobs = [
            $this->job('changes', 2, 10),
            $this->job('deep-check-bootstrap', 12, 55),
            $this->job('deep-check-seed-snapshot', 55, 55, [], 'skipped'),
            $this->job('deep-runtime-suite', 55, 55, [], 'skipped'),
            $this->job('coverage-shard-unit', 55, 55, [], 'skipped'),
            $this->job('coverage-shard-integration', 55, 55, [], 'skipped'),
            $this->job('coverage-delta', 55, 55, [], 'skipped'),
        ];

        $candidate = buildCiPerformanceBaselineRunSample($run, $jobs, $policy);

        self::assertFalse($candidate['eligible']);
        self::assertContains('required job not successful: coverage-delta', $candidate['reasons']);
    }

    public function testBuildRunSampleAllowsDeepRuntimeToBeTheObservedTerminalJob(): void
    {
        $policy = $this->policy();
        $run = [
            'id' => 321,
            'created_at' => '2026-09-05T10:30:00Z',
            'conclusion' => 'success',
            'run_attempt' => 1,
            'event' => 'pull_request',
        ];
        $jobs = [
            $this->job('changes', 2, 10),
            $this->job('deep-check-bootstrap', 12, 55),
            $this->job('deep-check-seed-snapshot', 57, 267),
            $this->job('deep-runtime-suite', 57, 530, [$this->step('Build runtime JS assets', 58, 62)]),
            $this->job('coverage-shard-unit', 57, 86),
            $this->job('coverage-shard-integration', 269, 486),
            $this->job('coverage-delta', 488, 519),
            ...$this->profileConsumerJobs(),
        ];

        $candidate = buildCiPerformanceBaselineRunSample($run, $jobs, $policy, $this->profileLog());

        self::assertTrue($candidate['eligible'], implode('; ', $candidate['reasons']));
        self::assertSame(530.0, $candidate['sample']['workflow_elapsed_seconds']);
        self::assertSame(['deep-runtime-suite'], $candidate['sample']['latest_jobs']);

        $evaluation = evaluateCiPerformanceBaseline($policy, [$candidate['sample']]);
        self::assertSame(
            ['deep-runtime-suite' => 1],
            $evaluation['metrics']['critical_path']['observed_terminal_job_counts'],
        );
    }

    public function testBuildRunSampleRejectsMissingAndNullRunAttempts(): void
    {
        $policy = $this->policy();
        $validRun = [
            'id' => 654,
            'created_at' => '2026-09-05T10:30:00Z',
            'conclusion' => 'success',
            'run_attempt' => 1,
            'event' => 'pull_request',
        ];
        $jobs = [
            $this->job('changes', 2, 10),
            $this->job('deep-check-bootstrap', 12, 55),
            $this->job('deep-check-seed-snapshot', 57, 267),
            $this->job('deep-runtime-suite', 57, 324, [$this->step('Build runtime JS assets', 58, 62)]),
            $this->job('coverage-shard-unit', 57, 86),
            $this->job('coverage-shard-integration', 269, 486),
            $this->job('coverage-delta', 488, 519),
            ...$this->profileConsumerJobs(),
        ];

        $missingAttempt = $validRun;
        unset($missingAttempt['run_attempt']);
        $nullAttempt = $validRun;
        $nullAttempt['run_attempt'] = null;

        foreach (['missing' => $missingAttempt, 'null' => $nullAttempt] as $case => $run) {
            $candidate = buildCiPerformanceBaselineRunSample($run, $jobs, $policy, $this->profileLog());
            self::assertFalse($candidate['eligible'], $case);
            self::assertContains('workflow run_attempt was missing or invalid', $candidate['reasons'], $case);
        }
    }

    public function testBuildRunSampleRejectsAChangedConsumerProfile(): void
    {
        $policy = $this->policy();
        $run = [
            'id' => 789,
            'created_at' => '2026-09-05T10:30:00Z',
            'conclusion' => 'success',
            'run_attempt' => 1,
            'event' => 'pull_request',
        ];
        $jobs = [
            $this->job('changes', 2, 10),
            $this->job('deep-check-bootstrap', 12, 55),
            $this->job('deep-check-seed-snapshot', 57, 267),
            $this->job('deep-runtime-suite', 57, 324, [$this->step('Build runtime JS assets', 58, 62)]),
            $this->job('coverage-shard-unit', 57, 86),
            $this->job('coverage-shard-integration', 269, 486),
            $this->job('coverage-delta', 488, 519),
            ...array_map(
                fn(array $job): array => ($job['name'] ?? null) === 'pdf-renderer-latency'
                    ? $this->job('pdf-renderer-latency', 55, 55, [], 'skipped')
                    : $job,
                $this->profileConsumerJobs(),
            ),
        ];

        $candidate = buildCiPerformanceBaselineRunSample($run, $jobs, $policy, $this->profileLog());

        self::assertFalse($candidate['eligible']);
        self::assertStringStartsWith('comparison profile fingerprint mismatch:', $candidate['reasons'][0]);
    }

    public function testBuildRunSampleRejectsChangedOrMissingRuntimeLoadInputs(): void
    {
        $policy = $this->policy();
        $run = [
            'id' => 987,
            'created_at' => '2026-09-05T10:30:00Z',
            'conclusion' => 'success',
            'run_attempt' => 1,
            'event' => 'pull_request',
        ];
        $jobs = [
            $this->job('changes', 2, 10),
            $this->job('deep-check-bootstrap', 12, 55),
            $this->job('deep-check-seed-snapshot', 57, 267),
            $this->job('deep-runtime-suite', 57, 324, [$this->step('Build runtime JS assets', 58, 62)]),
            $this->job('coverage-shard-unit', 57, 86),
            $this->job('coverage-shard-integration', 269, 486),
            $this->job('coverage-delta', 488, 519),
            ...$this->profileConsumerJobs(),
        ];
        $loadInputs = [
            '--booking-search-days=14' => '--booking-search-days=7',
            '--retry-count=1' => '--retry-count=0',
            '--start-date=2026-01-01' => '--start-date=2026-02-01',
            '--end-date=2026-01-31' => '--end-date=2026-02-28',
            'PLAYWRIGHT_RUNTIME_PACKAGE=playwright@1.59.0-alpha-1771104257000' =>
                'PLAYWRIGHT_RUNTIME_PACKAGE=playwright@1.58.0',
        ];

        foreach ($loadInputs as $expected => $changed) {
            foreach (['changed' => $changed, 'missing' => ''] as $case => $replacement) {
                $log = str_replace($expected, $replacement, $this->profileLog());
                $candidate = buildCiPerformanceBaselineRunSample($run, $jobs, $policy, $log);

                self::assertFalse($candidate['eligible'], $expected . ' ' . $case);
                self::assertStringStartsWith(
                    'comparison profile fingerprint mismatch:',
                    $candidate['reasons'][0],
                    $expected . ' ' . $case,
                );
            }
        }
    }

    public function testFingerprintCoversEveryComparisonProfileSection(): void
    {
        $profile = $this->policy()['comparison_profile'];
        $fingerprint = fingerprintCiPerformanceBaselineComparisonProfile($profile);

        foreach ($this->comparisonProfileScalarMutations($profile) as $path => $mutation) {
            self::assertNotSame(
                $fingerprint,
                fingerprintCiPerformanceBaselineComparisonProfile($mutation),
                sprintf('Comparison profile leaf "%s" must affect the fingerprint.', $path),
            );
        }

        $reorderedSuites = $profile;
        [$reorderedSuites['requested_suites'][0], $reorderedSuites['requested_suites'][1]] = [
            $reorderedSuites['requested_suites'][1],
            $reorderedSuites['requested_suites'][0],
        ];
        self::assertNotSame(
            $fingerprint,
            fingerprintCiPerformanceBaselineComparisonProfile($reorderedSuites),
            'Requested suite order must affect the fingerprint.',
        );

        $missingSuite = $profile;
        array_pop($missingSuite['requested_suites']);
        self::assertNotSame($fingerprint, fingerprintCiPerformanceBaselineComparisonProfile($missingSuite));

        $additionalSuite = $profile;
        $additionalSuite['requested_suites'][] = 'unexpected-suite';
        self::assertNotSame($fingerprint, fingerprintCiPerformanceBaselineComparisonProfile($additionalSuite));
    }

    public function testEvaluationUsesNearestRankP75AndRanksCompletePhases(): void
    {
        $policy = $this->policy();
        $elapsed = [519, 614, 533, 514, 521, 507, 544];
        $queue = [4, 2, 3, 2, 3, 2, 33];
        $runtime = [263, 267, 271, 260, 300, 266, 267];
        $integration = [217, 299, 229, 203, 210, 225, 216];
        $seed = [208, 213, 217, 217, 217, 206, 215];
        $samples = [];

        foreach ($elapsed as $index => $seconds) {
            $samples[] = [
                'workflow_elapsed_seconds' => (float) $seconds,
                'initial_queue_seconds' => (float) $queue[$index],
                'max_job_queue_seconds' => 9.0,
                'tracked_job_durations_seconds' => [
                    'changes' => 8.0,
                    'deep-check-bootstrap' => 43.0,
                    'deep-check-seed-snapshot' => (float) $seed[$index],
                    'deep-runtime-suite' => (float) $runtime[$index],
                    'coverage-shard-unit' => 29.0,
                    'coverage-shard-integration' => (float) $integration[$index],
                    'coverage-delta' => 14.0,
                ],
                'tracked_step_durations_seconds' => [
                    'deep-runtime-suite :: Start deep runtime services' => 187.0,
                    'coverage-shard-integration :: Start coverage shard services' => 184.0,
                    'deep-check-seed-snapshot :: Start seed snapshot services' => 182.0,
                ],
            ];
        }

        $evaluation = evaluateCiPerformanceBaseline($policy, $samples);
        $jobs = array_column($evaluation['metrics']['jobs'], null, 'job_name');

        self::assertSame('pass', $evaluation['status']);
        self::assertSame(521.0, $evaluation['metrics']['workflow_elapsed']['median_seconds']);
        self::assertSame(544.0, $evaluation['metrics']['workflow_elapsed']['p75_seconds']);
        self::assertSame(3.0, $evaluation['metrics']['initial_queue']['median_seconds']);
        self::assertSame(4.0, $evaluation['metrics']['initial_queue']['p75_seconds']);
        self::assertSame(267.0, $jobs['deep-runtime-suite']['median_seconds']);
        self::assertSame(271.0, $jobs['deep-runtime-suite']['p75_seconds']);
        self::assertSame(
            'deep-runtime-suite :: Start deep runtime services',
            $evaluation['metrics']['phases'][0]['phase'],
        );
    }

    public function testEvaluationReportsInsufficientDataBelowMinimumSampleCount(): void
    {
        $evaluation = evaluateCiPerformanceBaseline($this->policy(), []);

        self::assertSame('insufficient_data', $evaluation['status']);
        self::assertSame(0, $evaluation['metrics']['workflow_elapsed']['sample_count']);

        $summary = renderCiPerformanceBaselineSummary([
            'status' => $evaluation['status'],
            'metrics' => $evaluation['metrics'],
        ]);
        self::assertStringContainsString(
            'End-to-end: median n/a, p75 n/a. Initial queue: median n/a, p75 n/a.',
            $summary,
        );
        self::assertStringNotContainsString('median 0s', $summary);
    }

    public function testEvaluationKeepsTheFourOfFiveBoundaryFailClosed(): void
    {
        $policy = $this->policy();
        $sample = [
            'workflow_elapsed_seconds' => 500.0,
            'initial_queue_seconds' => 3.0,
            'max_job_queue_seconds' => 8.0,
            'latest_jobs' => ['coverage-delta'],
            'tracked_job_durations_seconds' => [],
            'tracked_step_durations_seconds' => [],
        ];

        $fourSamples = evaluateCiPerformanceBaseline($policy, array_fill(0, 4, $sample));
        $fiveSamples = evaluateCiPerformanceBaseline($policy, array_fill(0, 5, $sample));

        self::assertSame('insufficient_data', $fourSamples['status']);
        self::assertSame(4, $fourSamples['metrics']['workflow_elapsed']['sample_count']);
        self::assertSame('pass', $fiveSamples['status']);
        self::assertSame(5, $fiveSamples['metrics']['workflow_elapsed']['sample_count']);
    }

    public function testCliProcessPaginatesJobsAndWritesPassJsonAndSummaryToRequestedPaths(): void
    {
        $result = $this->runCliProcessWithFixture(2, 2, 'pass');
        $report = $result['report'];

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame('pass', $report['status']);
        self::assertSame('owner/repository', $report['repository']);
        self::assertSame(2, $report['selection']['eligible_runs']);
        self::assertSame(519, $report['metrics']['workflow_elapsed']['median_seconds']);
        self::assertSame(
            $report['method']['comparison_profile']['fingerprint'],
            $report['runs'][0]['comparison_profile']['fingerprint'],
        );
        self::assertStringContainsString('[PASS] ci-performance-baseline', $result['stdout']);
        self::assertFileExists($result['summary_path']);
        self::assertStringContainsString(
            'Status: `pass`; 2 comparable successful full-gate PR runs.',
            (string) file_get_contents($result['summary_path']),
        );
    }

    public function testCliProcessWritesInsufficientDataJsonToRequestedPath(): void
    {
        $result = $this->runCliProcessWithFixture(2, 2, 'insufficient');
        $report = $result['report'];

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame('insufficient_data', $report['status']);
        self::assertSame(0, $report['selection']['eligible_runs']);
        self::assertStringContainsString('needs more comparable successful PR runs', $result['stdout']);
        self::assertFileExists($result['json_path']);
        self::assertStringContainsString(
            'End-to-end: median n/a, p75 n/a. Initial queue: median n/a, p75 n/a.',
            (string) file_get_contents($result['summary_path']),
        );
    }

    public function testCliWritesAJsonErrorReportForInvalidOptions(): void
    {
        $output = $this->tmpDir . '/report.json';
        $exitCode = runCiPerformanceBaselineCli([
            'measure_ci_performance_baseline.php',
            '--repo=owner/repository',
            '--output-json=' . $output,
            '--bogus',
        ]);
        $content = file_get_contents($output);
        self::assertNotFalse($content);
        $report = json_decode($content, true);

        self::assertSame(CI_PERFORMANCE_BASELINE_EXIT_RUNTIME_ERROR, $exitCode);
        self::assertIsArray($report);
        self::assertSame(1, $report['schema_version']);
        self::assertSame('error', $report['status']);
        self::assertStringContainsString('Unknown CLI option', $report['error']['message']);
    }

    public function testWorkflowRunPageSizeAcceptsOneHundredAndRejectsLargerValues(): void
    {
        $config = ciPerformanceBaselineDefaultConfig();
        parseCiPerformanceBaselineCliOptions(
            ['measure_ci_performance_baseline.php', '--repo=owner/repository', '--per-page=100'],
            $config,
        );
        self::assertSame(100, $config['per_page']);

        foreach ([101, 200] as $pageSize) {
            $config = ciPerformanceBaselineDefaultConfig();
            try {
                parseCiPerformanceBaselineCliOptions(
                    ['measure_ci_performance_baseline.php', '--repo=owner/repository', '--per-page=' . $pageSize],
                    $config,
                );
                self::fail('Expected --per-page=' . $pageSize . ' to be rejected.');
            } catch (\RuntimeException $e) {
                self::assertSame(
                    '--per-page must not exceed GitHub API maximum 100.',
                    $e->getMessage(),
                    (string) $pageSize,
                );
            }
        }
    }

    public function testWorkflowRunPaginationContinuesAfterAFullOneHundredRunPage(): void
    {
        $policy = $this->policy();
        $runs = [];
        for ($runId = 1; $runId <= 100; $runId++) {
            $runs[] = [
                'id' => $runId,
                'created_at' => '2026-08-07T22:00:00Z',
                'conclusion' => 'success',
                'run_attempt' => 1,
                'event' => 'pull_request',
            ];
        }
        $runs[] = [
            'id' => 101,
            'created_at' => '2026-09-05T10:30:00Z',
            'conclusion' => 'success',
            'run_attempt' => 1,
            'event' => 'pull_request',
        ];
        $eligibleJobs = [
            $this->job('changes', 2, 10),
            $this->job('deep-check-bootstrap', 12, 55),
            $this->job('deep-check-seed-snapshot', 57, 267),
            $this->job('deep-runtime-suite', 57, 324, [$this->step('Build runtime JS assets', 58, 62)]),
            $this->job('coverage-shard-unit', 57, 86),
            $this->job('coverage-shard-integration', 269, 486),
            $this->job('coverage-delta', 488, 519),
            ...$this->profileConsumerJobs(),
        ];
        $requestJson = static function (string $path) use ($runs, $eligibleJobs): array {
            if (str_contains($path, '/actions/workflows/')) {
                parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
                $page = (int) ($query['page'] ?? 1);

                return ['workflow_runs' => array_slice($runs, ($page - 1) * 100, 100)];
            }

            if (preg_match('~/actions/runs/(?<run_id>[0-9]+)/jobs~', $path, $matches) === 1) {
                return ['jobs' => (int) $matches['run_id'] === 101 ? $eligibleJobs : []];
            }

            throw new \RuntimeException('Unexpected fixture path: ' . $path);
        };
        $requestText = fn(string $path): string => $this->profileLog();
        $config = ciPerformanceBaselineDefaultConfig();
        $config['repo'] = 'owner/repository';
        $config['per_page'] = 100;
        $config['max_runs'] = 101;

        $cohort = fetchCiPerformanceBaselineCohort($requestJson, $requestText, $config, $policy);

        self::assertSame(101, $cohort['runs_scanned']);
        self::assertCount(1, $cohort['samples']);
        self::assertSame(101, $cohort['samples'][0]['run_id']);
        self::assertCount(100, $cohort['exclusions']);
    }

    /**
     * @return array{exit_code:int,stdout:string,stderr:string,json_path:string,summary_path:string,report:array<string,mixed>}
     */
    private function runCliProcessWithFixture(int $cohortSize, int $minimumSamples, string $name): array
    {
        $fixtureDir = $this->tmpDir . '/' . $name;
        self::assertTrue(mkdir($fixtureDir, 0777, true));

        $policy = $this->policy();
        $policy['cohort_size'] = $cohortSize;
        $policy['minimum_samples'] = $minimumSamples;
        $policyPath = $fixtureDir . '/policy.php';
        file_put_contents($policyPath, "<?php\n\nreturn " . var_export($policy, true) . ";\n");

        $run = [
            'id' => 123,
            'created_at' => '2026-09-05T10:30:00Z',
            'conclusion' => 'success',
            'html_url' => 'https://example.test/actions/runs/123',
            'head_branch' => 'codex/example',
            'head_sha' => str_repeat('a', 40),
            'run_attempt' => 1,
            'event' => 'pull_request',
        ];
        $secondRun = $run;
        $secondRun['id'] = 124;
        $secondRun['html_url'] = 'https://example.test/actions/runs/124';
        $secondRun['head_sha'] = str_repeat('b', 40);
        $jobs = [
            $this->job('changes', 2, 10),
            $this->job('deep-check-bootstrap', 12, 55),
            $this->job('deep-check-seed-snapshot', 57, 267, [$this->step('Start seed snapshot services', 60, 242)]),
            $this->job('deep-runtime-suite', 57, 324, [
                $this->step('Build runtime JS assets', 58, 62),
                $this->step('Start deep runtime services', 63, 250),
            ]),
            $this->job('coverage-shard-unit', 57, 86),
            $this->job('coverage-shard-integration', 269, 486, [
                $this->step('Start coverage shard services', 275, 459),
            ]),
            $this->job('coverage-delta', 488, 519),
            ...$this->profileConsumerJobs(),
        ];
        $fillerJobs = [];
        for ($index = 0; $index < 100; $index++) {
            $fillerJobs[] = $this->job('page-one-skipped-' . $index, 1, 1, [], 'skipped');
        }
        $pagedJobs = [...$fillerJobs, ...$jobs];
        $deepRuntimeJobId = null;
        foreach ($jobs as $job) {
            if ($job['name'] === 'deep-runtime-suite') {
                $deepRuntimeJobId = (string) $job['id'];
                break;
            }
        }
        self::assertNotNull($deepRuntimeJobId);
        $apiFixturePath = $fixtureDir . '/api-fixture.json';
        file_put_contents(
            $apiFixturePath,
            json_encode(
                [
                    'workflow_runs' => $name === 'insufficient' ? [] : [$run, $secondRun],
                    'jobs_by_run_id' => ['123' => $pagedJobs, '124' => $pagedJobs],
                    'job_logs_by_job_id' => [$deepRuntimeJobId => $this->profileLog()],
                ],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
            ),
        );

        $jsonPath = $fixtureDir . '/output/reports/baseline.json';
        $summaryPath = $fixtureDir . '/output/summary/baseline.md';
        $process = proc_open(
            [
                PHP_BINARY,
                realpath(__DIR__ . '/../../../scripts/ci/measure_ci_performance_baseline.php'),
                '--repo=owner/repository',
                '--policy=' . $policyPath,
                '--output-json=' . $jsonPath,
                '--output-summary=' . $summaryPath,
                '--api-fixture=' . $apiFixturePath,
                '--per-page=1',
            ],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $fixtureDir,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $content = file_get_contents($jsonPath);
        self::assertNotFalse($content);
        $report = json_decode($content, true);
        self::assertIsArray($report);

        return [
            'exit_code' => $exitCode,
            'stdout' => (string) $stdout,
            'stderr' => (string) $stderr,
            'json_path' => $jsonPath,
            'summary_path' => $summaryPath,
            'report' => $report,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function policy(): array
    {
        return loadCiPerformanceBaselinePolicy($this->repoPolicyPath());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function profileConsumerJobs(): array
    {
        return [
            $this->job('build-test', 12, 100),
            $this->job('js-lint-changed', 12, 31),
            $this->job('phpstan-application', 12, 45),
            $this->job('typed-request-dto', 12, 29),
            $this->job('typed-request-contracts', 12, 30),
            $this->job('api-contract-openapi', 57, 90),
            $this->job('write-contract-booking', 57, 91),
            $this->job('write-contract-api', 57, 92),
            $this->job('booking-controller-flows', 57, 93),
            $this->job('integration-smoke', 57, 94),
            $this->job('pdf-renderer-latency', 57, 95),
            $this->job('architecture-ownership-map', 12, 20),
            $this->job('architecture-boundaries', 12, 35),
            $this->job('heavy-job-duration-trends', 55, 55, [], 'skipped'),
        ];
    }

    private function profileLog(): string
    {
        return implode(' ', [
            '-e PLAYWRIGHT_RUNTIME_PACKAGE=playwright@1.59.0-alpha-1771104257000',
            'php scripts/ci/run_deep_runtime_suite.php',
            '--suites=api-contract-openapi,write-contract-booking,write-contract-api,booking-controller-flows,integration-smoke',
            '--booking-search-days=14',
            '--retry-count=1',
            '--start-date=2026-01-01',
            '--end-date=2026-01-31',
            '--integration-smoke-include-ldap=true',
            '--integration-smoke-browser-bootstrap-timeout=900',
            '--integration-smoke-browser-evidence=on-failure',
        ]);
    }

    /**
     * @param array<string|int, mixed> $profile
     * @return array<string, array<string|int, mixed>>
     */
    private function comparisonProfileScalarMutations(array $profile): array
    {
        $mutations = [];
        $walk = function (array $value, array $path = []) use (&$walk, &$mutations, $profile): void {
            foreach ($value as $key => $item) {
                $itemPath = [...$path, $key];
                if (is_array($item)) {
                    $walk($item, $itemPath);
                    continue;
                }

                $mutation = $profile;
                $target = &$mutation;
                foreach (array_slice($itemPath, 0, -1) as $pathPart) {
                    $target = &$target[$pathPart];
                }
                $leafKey = $itemPath[array_key_last($itemPath)];
                $target[$leafKey] = match (true) {
                    is_bool($item) => !$item,
                    is_int($item) => $item + 1,
                    is_string($item) => $item . '-changed',
                    default => throw new \LogicException('Unsupported comparison profile scalar type.'),
                };
                unset($target);

                $mutations[implode('.', array_map('strval', $itemPath))] = $mutation;
            }
        };
        $walk($profile);

        return $mutations;
    }

    /**
     * @param array<int, array<string, mixed>> $steps
     * @return array<string, mixed>
     */
    private function job(
        string $name,
        int $startedAfterSeconds,
        int $completedAfterSeconds,
        array $steps = [],
        string $conclusion = 'success',
    ): array {
        $createdAfterSeconds = max(0, $startedAfterSeconds - 2);

        return [
            'id' => (int) sprintf('%u', crc32($name)),
            'name' => $name,
            'conclusion' => $conclusion,
            'created_at' => $this->timestamp($createdAfterSeconds),
            'started_at' => $this->timestamp($startedAfterSeconds),
            'completed_at' => $this->timestamp($completedAfterSeconds),
            'steps' => $steps,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function step(string $name, int $startedAfterSeconds, int $completedAfterSeconds): array
    {
        return [
            'name' => $name,
            'conclusion' => 'success',
            'started_at' => $this->timestamp($startedAfterSeconds),
            'completed_at' => $this->timestamp($completedAfterSeconds),
        ];
    }

    private function timestamp(int $afterSeconds): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', strtotime('2026-09-05T10:30:00Z') + $afterSeconds);
    }

    private function repoPolicyPath(): string
    {
        return __DIR__ . '/../../../scripts/ci/config/ci_performance_baseline_policy.php';
    }
}
