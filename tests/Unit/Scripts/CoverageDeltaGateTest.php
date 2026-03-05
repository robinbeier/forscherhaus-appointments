<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/ci/check_coverage_delta.php';

class CoverageDeltaGateTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $tmpRoot = sys_get_temp_dir() . '/coverage-delta-gate';
        $this->tmpDir = $tmpRoot . '-' . uniqid('', true);

        if (!mkdir($this->tmpDir, 0777, true) && !is_dir($this->tmpDir)) {
            self::fail('Failed to create temp directory for coverage delta tests.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function testRunCoverageDeltaCliPassesWhenCoverageMeetsPolicy(): void
    {
        $policyFile = $this->writePolicy(
            [
                'baseline_line_coverage_pct' => 4.19,
                'max_drop_pct_points' => 0.2,
                'absolute_min_line_coverage_pct' => 3.99,
                'epsilon_pct_points' => 0.02,
            ],
            'policy-pass.php',
        );

        $outputFile = $this->tmpDir . '/coverage-pass.json';

        $exitCode = runCoverageDeltaCli([
            'check_coverage_delta.php',
            '--clover=' . $this->fixturePath('clover-high.xml'),
            '--policy=' . $policyFile,
            '--output-json=' . $outputFile,
        ]);

        self::assertSame(COVERAGE_DELTA_EXIT_SUCCESS, $exitCode);
        self::assertSame('pass', $this->readReport($outputFile)['status']);
    }

    public function testRunCoverageDeltaCliFailsWhenDropExceedsAllowedDelta(): void
    {
        $policyFile = $this->writePolicy(
            [
                'baseline_line_coverage_pct' => 10.0,
                'max_drop_pct_points' => 0.2,
                'absolute_min_line_coverage_pct' => 1.0,
                'epsilon_pct_points' => 0.02,
            ],
            'policy-delta-fail.php',
        );

        $outputFile = $this->tmpDir . '/coverage-delta-fail.json';

        $exitCode = runCoverageDeltaCli([
            'check_coverage_delta.php',
            '--clover=' . $this->fixturePath('clover-low.xml'),
            '--policy=' . $policyFile,
            '--output-json=' . $outputFile,
        ]);

        $report = $this->readReport($outputFile);

        self::assertSame(COVERAGE_DELTA_EXIT_ASSERTION_FAILURE, $exitCode);
        self::assertSame('fail', $report['status']);
        self::assertFalse($report['checks']['delta_pass']);
        self::assertTrue($report['checks']['absolute_min_pass']);
    }

    public function testRunCoverageDeltaCliFailsWhenAbsoluteMinimumIsViolated(): void
    {
        $policyFile = $this->writePolicy(
            [
                'baseline_line_coverage_pct' => 4.19,
                'max_drop_pct_points' => 1.0,
                'absolute_min_line_coverage_pct' => 4.1,
                'epsilon_pct_points' => 0.02,
            ],
            'policy-absolute-fail.php',
        );

        $outputFile = $this->tmpDir . '/coverage-absolute-fail.json';

        $exitCode = runCoverageDeltaCli([
            'check_coverage_delta.php',
            '--clover=' . $this->fixturePath('clover-low.xml'),
            '--policy=' . $policyFile,
            '--output-json=' . $outputFile,
        ]);

        $report = $this->readReport($outputFile);

        self::assertSame(COVERAGE_DELTA_EXIT_ASSERTION_FAILURE, $exitCode);
        self::assertSame('fail', $report['status']);
        self::assertFalse($report['checks']['absolute_min_pass']);
        self::assertTrue($report['checks']['delta_pass']);
    }

    public function testRunCoverageDeltaCliFailsForMalformedCloverInput(): void
    {
        $malformedClover = $this->tmpDir . '/clover-malformed.xml';
        file_put_contents($malformedClover, '<coverage><project>');

        $outputFile = $this->tmpDir . '/coverage-runtime-error.json';

        $exitCode = runCoverageDeltaCli([
            'check_coverage_delta.php',
            '--clover=' . $malformedClover,
            '--policy=' .
            $this->writePolicy(
                [
                    'baseline_line_coverage_pct' => 4.19,
                    'max_drop_pct_points' => 0.2,
                    'absolute_min_line_coverage_pct' => 3.99,
                    'epsilon_pct_points' => 0.02,
                ],
                'policy-malformed.php',
            ),
            '--output-json=' . $outputFile,
        ]);

        $report = $this->readReport($outputFile);

        self::assertSame(COVERAGE_DELTA_EXIT_RUNTIME_ERROR, $exitCode);
        self::assertSame('error', $report['status']);
        self::assertStringContainsString('Failed to parse Clover XML', (string) $report['error']['message']);
    }

    public function testRunCoverageDeltaCliFailsForInvalidCliOption(): void
    {
        $outputFile = $this->tmpDir . '/coverage-invalid-option.json';

        $exitCode = runCoverageDeltaCli([
            'check_coverage_delta.php',
            '--output-json=' . $outputFile,
            '--invalid-option',
        ]);

        $report = $this->readReport($outputFile);

        self::assertSame(COVERAGE_DELTA_EXIT_RUNTIME_ERROR, $exitCode);
        self::assertSame('error', $report['status']);
        self::assertStringContainsString('Unknown CLI option', (string) $report['error']['message']);
    }

    public function testLoadCoverageDeltaPolicyUsesRepositoryDefaults(): void
    {
        $policy = loadCoverageDeltaPolicy($this->repoPolicyPath());

        self::assertSame(22.3, $policy['baseline_line_coverage_pct']);
        self::assertSame(0.2, $policy['max_drop_pct_points']);
        self::assertSame(22.1, $policy['absolute_min_line_coverage_pct']);
        self::assertSame(0.02, $policy['epsilon_pct_points']);
        self::assertGreaterThanOrEqual($policy['baseline_line_coverage_pct'], $policy['absolute_min_line_coverage_pct']);
        self::assertLessThanOrEqual(
            $policy['baseline_line_coverage_pct'],
            $policy['absolute_min_line_coverage_pct'] + $policy['max_drop_pct_points'] + $policy['epsilon_pct_points'],
        );
    }

    private function fixturePath(string $filename): string
    {
        return __DIR__ . '/fixtures/coverage/' . $filename;
    }

    private function repoPolicyPath(): string
    {
        return __DIR__ . '/../../../scripts/ci/config/coverage_delta_policy.php';
    }

    /**
     * @param array{
     *     baseline_line_coverage_pct:float,
     *     max_drop_pct_points:float,
     *     absolute_min_line_coverage_pct:float,
     *     epsilon_pct_points:float
     * } $policy
     */
    private function writePolicy(array $policy, string $filename): string
    {
        $policyPath = $this->tmpDir . '/' . $filename;
        $content = "<?php\n\nreturn " . var_export($policy, true) . ";\n";
        file_put_contents($policyPath, $content);

        return $policyPath;
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

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;

            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
