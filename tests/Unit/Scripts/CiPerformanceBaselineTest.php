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
        self::assertContains('deep-runtime-suite', $policy['required_success_jobs']);
        self::assertSame('coverage-delta', $policy['critical_path_jobs'][4]);
    }

    public function testBuildRunSampleMeasuresElapsedQueueJobsAndPhases(): void
    {
        $policy = $this->policy();
        $run = [
            'id' => 123,
            'created_at' => '2026-08-07T22:00:00Z',
            'conclusion' => 'success',
            'html_url' => 'https://example.test/actions/runs/123',
            'head_branch' => 'codex/example',
            'head_sha' => str_repeat('a', 40),
            'run_attempt' => 1,
        ];
        $jobs = [
            $this->job('changes', 2, 10),
            $this->job('deep-check-bootstrap', 12, 55),
            $this->job('deep-check-seed-snapshot', 57, 267, [$this->step('Start seed snapshot services', 60, 242)]),
            $this->job('deep-runtime-suite', 57, 324, [$this->step('Start deep runtime services', 63, 250)]),
            $this->job('coverage-shard-unit', 57, 86),
            $this->job('coverage-shard-integration', 269, 486, [
                $this->step('Start coverage shard services', 275, 459),
            ]),
            $this->job('coverage-delta', 488, 519),
        ];

        $candidate = buildCiPerformanceBaselineRunSample($run, $jobs, $policy);
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
    }

    public function testBuildRunSampleExcludesDraftOrPartialProfiles(): void
    {
        $policy = $this->policy();
        $run = [
            'id' => 456,
            'created_at' => '2026-08-07T22:00:00Z',
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

    public function testCliProcessWritesPassJsonAndSummaryToRequestedPaths(): void
    {
        $result = $this->runCliProcessWithFixture(2, 2, 'pass');
        $report = $result['report'];

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame('pass', $report['status']);
        self::assertSame('owner/repository', $report['repository']);
        self::assertSame(2, $report['selection']['eligible_runs']);
        self::assertSame(519, $report['metrics']['workflow_elapsed']['median_seconds']);
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
            'created_at' => '2026-08-07T22:00:00Z',
            'conclusion' => 'success',
            'html_url' => 'https://example.test/actions/runs/123',
            'head_branch' => 'codex/example',
            'head_sha' => str_repeat('a', 40),
            'run_attempt' => 1,
        ];
        $secondRun = $run;
        $secondRun['id'] = 124;
        $secondRun['html_url'] = 'https://example.test/actions/runs/124';
        $secondRun['head_sha'] = str_repeat('b', 40);
        $jobs = [
            $this->job('changes', 2, 10),
            $this->job('deep-check-bootstrap', 12, 55),
            $this->job('deep-check-seed-snapshot', 57, 267, [$this->step('Start seed snapshot services', 60, 242)]),
            $this->job('deep-runtime-suite', 57, 324, [$this->step('Start deep runtime services', 63, 250)]),
            $this->job('coverage-shard-unit', 57, 86),
            $this->job('coverage-shard-integration', 269, 486, [
                $this->step('Start coverage shard services', 275, 459),
            ]),
            $this->job('coverage-delta', 488, 519),
        ];
        $apiFixturePath = $fixtureDir . '/api-fixture.json';
        file_put_contents(
            $apiFixturePath,
            json_encode(
                [
                    'workflow_runs' => $name === 'insufficient' ? [] : [$run, $secondRun],
                    'jobs_by_run_id' => ['123' => $jobs, '124' => $jobs],
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
        return [
            'cohort_size' => 7,
            'minimum_samples' => 5,
            'percentile_method' => 'nearest_rank',
            'required_success_jobs' => [
                'changes',
                'deep-check-bootstrap',
                'deep-check-seed-snapshot',
                'deep-runtime-suite',
                'coverage-shard-unit',
                'coverage-shard-integration',
                'coverage-delta',
            ],
            'tracked_jobs' => [
                'changes',
                'deep-check-bootstrap',
                'deep-check-seed-snapshot',
                'deep-runtime-suite',
                'coverage-shard-unit',
                'coverage-shard-integration',
                'coverage-delta',
            ],
            'critical_path_jobs' => [
                'changes',
                'deep-check-bootstrap',
                'deep-check-seed-snapshot',
                'coverage-shard-integration',
                'coverage-delta',
            ],
        ];
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
        return gmdate('Y-m-d\TH:i:s\Z', strtotime('2026-08-07T22:00:00Z') + $afterSeconds);
    }

    private function repoPolicyPath(): string
    {
        return __DIR__ . '/../../../scripts/ci/config/ci_performance_baseline_policy.php';
    }
}
