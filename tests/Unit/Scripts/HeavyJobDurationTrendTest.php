<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/ci/check_heavy_job_duration_trends.php';

class HeavyJobDurationTrendTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/heavy-job-duration-trend-' . uniqid('', true);

        if (!mkdir($this->tmpDir, 0777, true) && !is_dir($this->tmpDir)) {
            self::fail('Failed to create temp directory for heavy-job-duration-trend tests.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function testEvaluateHeavyJobDurationTrendsAlertsForSustainedRegression(): void
    {
        $policy = $this->policy();
        $currentRunJobs = [
            'deep-runtime-suite' => [
                'job_name' => 'deep-runtime-suite',
                'conclusion' => 'success',
                'duration_seconds' => 1800.0,
            ],
        ];
        $history = [
            'deep-runtime-suite' => $this->historySamples([
                1760,
                1780,
                1790,
                1810,
                1200,
                1210,
                1190,
                1220,
                1180,
                1230,
                1215,
                1195,
                1225,
                1205,
            ]),
        ];

        $evaluation = evaluateHeavyJobDurationTrends($policy, $currentRunJobs, $history);
        $deepRuntimeJob = $this->jobReport($evaluation, 'deep-runtime-suite');

        self::assertSame('alert', $evaluation['status']);
        self::assertSame('alert', $deepRuntimeJob['status']);
        self::assertSame(1790.0, $deepRuntimeJob['recent_median_seconds']);
        self::assertSame(1200.0, $deepRuntimeJob['baseline_median_seconds']);
        self::assertGreaterThan(15.0, $deepRuntimeJob['percent_increase']);
    }

    public function testEvaluateHeavyJobDurationTrendsPassesWhenAbsoluteIncreaseIsTooSmall(): void
    {
        $policy = $this->policy();
        $currentRunJobs = [
            'coverage-delta' => [
                'job_name' => 'coverage-delta',
                'conclusion' => 'success',
                'duration_seconds' => 150.0,
            ],
        ];
        $history = [
            'coverage-delta' => $this->historySamples([148, 152, 151, 149, 125, 126, 124, 125, 126, 124, 125, 126]),
        ];

        $evaluation = evaluateHeavyJobDurationTrends($policy, $currentRunJobs, $history);
        $coverageDeltaJob = $this->jobReport($evaluation, 'coverage-delta');

        self::assertSame('pass', $evaluation['status']);
        self::assertSame('pass', $coverageDeltaJob['status']);
        self::assertLessThan(300.0, $coverageDeltaJob['absolute_increase_seconds']);
    }

    public function testEvaluateHeavyJobDurationTrendsReportsInsufficientData(): void
    {
        $policy = $this->policy();
        $history = [
            'coverage-shard-unit' => $this->historySamples([260, 258, 257, 255]),
        ];

        $evaluation = evaluateHeavyJobDurationTrends($policy, [], $history);
        $coverageShardUnitJob = $this->jobReport($evaluation, 'coverage-shard-unit');

        self::assertSame('insufficient_data', $coverageShardUnitJob['status']);
        self::assertStringContainsString('Need at least 3 recent and 5 baseline', $coverageShardUnitJob['message']);
    }

    public function testEvaluateHeavyJobDurationTrendsExcludesFailedCurrentRunSample(): void
    {
        $policy = $this->policy();
        $currentRunJobs = [
            'coverage-shard-integration' => [
                'job_name' => 'coverage-shard-integration',
                'conclusion' => 'failure',
            ],
        ];
        $history = [
            'coverage-shard-integration' => $this->historySamples([
                500,
                510,
                505,
                515,
                480,
                482,
                485,
                488,
                487,
                486,
                489,
                491,
            ]),
        ];

        $evaluation = evaluateHeavyJobDurationTrends($policy, $currentRunJobs, $history);
        $coverageIntegrationJob = $this->jobReport($evaluation, 'coverage-shard-integration');

        self::assertSame('pass', $coverageIntegrationJob['status']);
        self::assertFalse($coverageIntegrationJob['current_run_included']);
        self::assertStringContainsString(
            'excluded because the job concluded as failure',
            $coverageIntegrationJob['message'],
        );
    }

    public function testRunHeavyJobDurationTrendsCliFailsForInvalidOption(): void
    {
        $outputFile = $this->tmpDir . '/heavy-job-duration-trends.json';

        $exitCode = runHeavyJobDurationTrendsCli([
            'check_heavy_job_duration_trends.php',
            '--output-json=' . $outputFile,
            '--bogus',
        ]);

        $report = $this->readReport($outputFile);

        self::assertSame(HEAVY_JOB_DURATION_TRENDS_EXIT_RUNTIME_ERROR, $exitCode);
        self::assertSame('error', $report['status']);
        self::assertStringContainsString('Unknown CLI option', (string) $report['error']['message']);
    }

    public function testLoadHeavyJobDurationTrendPolicyUsesRepositoryDefaults(): void
    {
        $policy = loadHeavyJobDurationTrendPolicy($this->repoPolicyPath());

        self::assertSame(5, $policy['recent_window_size']);
        self::assertSame(10, $policy['baseline_window_size']);
        self::assertSame(3, $policy['min_recent_samples']);
        self::assertSame(5, $policy['min_baseline_samples']);
        self::assertSame(0.15, $policy['alert_threshold_ratio']);
        self::assertSame(300.0, $policy['min_absolute_increase_seconds']);
        self::assertCount(4, $policy['jobs']);
    }

    /**
     * @param array<int, int|float> $durations
     * @return array<int, array<string, mixed>>
     */
    private function historySamples(array $durations): array
    {
        $samples = [];

        foreach ($durations as $index => $duration) {
            $samples[] = [
                'job_name' => 'fixture-job',
                'conclusion' => 'success',
                'duration_seconds' => (float) $duration,
                'source_run_id' => 1000 + $index,
            ];
        }

        return $samples;
    }

    /**
     * @return array{
     *     recent_window_size:int,
     *     baseline_window_size:int,
     *     min_recent_samples:int,
     *     min_baseline_samples:int,
     *     alert_threshold_ratio:float,
     *     min_absolute_increase_seconds:float,
     *     jobs:array<int, array{job_name:string,min_baseline_median_seconds:float}>
     * }
     */
    private function policy(): array
    {
        return [
            'recent_window_size' => 5,
            'baseline_window_size' => 5,
            'min_recent_samples' => 3,
            'min_baseline_samples' => 5,
            'alert_threshold_ratio' => 0.15,
            'min_absolute_increase_seconds' => 300.0,
            'jobs' => [
                ['job_name' => 'deep-runtime-suite', 'min_baseline_median_seconds' => 900.0],
                ['job_name' => 'coverage-shard-unit', 'min_baseline_median_seconds' => 240.0],
                ['job_name' => 'coverage-shard-integration', 'min_baseline_median_seconds' => 420.0],
                ['job_name' => 'coverage-delta', 'min_baseline_median_seconds' => 120.0],
            ],
        ];
    }

    private function repoPolicyPath(): string
    {
        return __DIR__ . '/../../../scripts/ci/config/heavy_job_duration_trend_policy.php';
    }

    /**
     * @return array<string, mixed>
     */
    private function readReport(string $path): array
    {
        $content = file_get_contents($path);
        self::assertNotFalse($content);

        $decoded = json_decode($content, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array{jobs:array<int, array<string, mixed>>} $evaluation
     * @return array<string, mixed>
     */
    private function jobReport(array $evaluation, string $jobName): array
    {
        foreach ($evaluation['jobs'] as $jobReport) {
            if (($jobReport['job_name'] ?? null) === $jobName) {
                return $jobReport;
            }
        }

        self::fail('Missing job report for ' . $jobName . '.');
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}
