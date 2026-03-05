<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/ci/merge_coverage_shards.php';

class CoverageShardMergeTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $tmpRoot = sys_get_temp_dir() . '/coverage-shard-merge';
        $this->tmpDir = $tmpRoot . '-' . uniqid('', true);

        if (!mkdir($this->tmpDir, 0777, true) && !is_dir($this->tmpDir)) {
            self::fail('Failed to create temp directory for coverage shard merge tests.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function testRunCoverageShardMergeCliMergesInputsAndWritesOutputs(): void
    {
        $inputA = $this->tmpDir . '/shard-a-clover.xml';
        $inputB = $this->tmpDir . '/shard-b-clover.xml';
        $outputClover = $this->tmpDir . '/coverage-merged.xml';
        $outputJson = $this->tmpDir . '/coverage-merge.json';

        $this->writeCloverShard($inputA, [
            '/tmp/shard-a.php' => [10 => 1, 11 => 0],
            '/tmp/shard-b.php' => [20 => 1],
        ]);
        $this->writeCloverShard($inputB, [
            '/tmp/shard-a.php' => [11 => 1, 12 => 0],
            '/tmp/shard-c.php' => [30 => 0],
        ]);

        $exitCode = runCoverageShardMergeCli([
            'merge_coverage_shards.php',
            '--input=' . $inputA,
            '--input=' . $inputB,
            '--output-clover=' . $outputClover,
            '--output-json=' . $outputJson,
        ]);

        self::assertSame(COVERAGE_SHARD_MERGE_EXIT_SUCCESS, $exitCode);
        self::assertFileExists($outputClover);
        self::assertFileExists($outputJson);

        $report = $this->readJsonFile($outputJson);
        self::assertSame('pass', $report['status']);
        self::assertSame(2, $report['files_merged']);
        self::assertSame([$inputA, $inputB], $report['inputs']);

        $metrics = $this->readCloverProjectMetrics($outputClover);
        self::assertSame(5, $metrics['statements']);
        self::assertSame(3, $metrics['coveredstatements']);
    }

    public function testRunCoverageShardMergeCliFailsWhenLessThanTwoInputsProvided(): void
    {
        $inputA = $this->tmpDir . '/shard-a-clover.xml';
        $this->writeCloverShard($inputA, ['/tmp/shard-a.php' => [10 => 1]]);

        $exitCode = runCoverageShardMergeCli([
            'merge_coverage_shards.php',
            '--input=' . $inputA,
            '--output-clover=' . $this->tmpDir . '/coverage-merged.xml',
            '--output-json=' . $this->tmpDir . '/coverage-merge.json',
        ]);

        self::assertSame(COVERAGE_SHARD_MERGE_EXIT_RUNTIME_ERROR, $exitCode);
    }

    public function testRunCoverageShardMergeCliFailsForUnknownOption(): void
    {
        $outputJson = $this->tmpDir . '/coverage-merge-invalid-option.json';

        $exitCode = runCoverageShardMergeCli([
            'merge_coverage_shards.php',
            '--output-json=' . $outputJson,
            '--invalid-option',
        ]);

        self::assertSame(COVERAGE_SHARD_MERGE_EXIT_RUNTIME_ERROR, $exitCode);
        self::assertFileExists($outputJson);
        self::assertSame('error', $this->readJsonFile($outputJson)['status']);
    }

    public function testRunCoverageShardMergeCliNormalizesEquivalentRepoPathsAcrossEnvironments(): void
    {
        $inputA = $this->tmpDir . '/shard-a-clover.xml';
        $inputB = $this->tmpDir . '/shard-b-clover.xml';
        $outputClover = $this->tmpDir . '/coverage-merged.xml';

        $this->writeCloverShard($inputA, [
            '/Users/runner/work/forscherhaus-appointments/application/libraries/Request_normalizer.php' => [
                10 => 1,
                11 => 0,
            ],
        ]);
        $this->writeCloverShard($inputB, [
            '/var/www/html/application/libraries/Request_normalizer.php' => [
                11 => 1,
                12 => 0,
            ],
        ]);

        $exitCode = runCoverageShardMergeCli([
            'merge_coverage_shards.php',
            '--input=' . $inputA,
            '--input=' . $inputB,
            '--output-clover=' . $outputClover,
        ]);

        self::assertSame(COVERAGE_SHARD_MERGE_EXIT_SUCCESS, $exitCode);

        $metrics = $this->readCloverProjectMetrics($outputClover);
        self::assertSame(3, $metrics['statements']);
        self::assertSame(2, $metrics['coveredstatements']);

        $xmlContent = file_get_contents($outputClover);
        self::assertNotFalse($xmlContent);
        self::assertStringContainsString('application/libraries/Request_normalizer.php', $xmlContent);
    }

    /**
     * @param array<string, array<int, int>> $lineCoverageByFile
     */
    private function writeCloverShard(string $path, array $lineCoverageByFile): void
    {
        $metrics = $this->calculateMetrics($lineCoverageByFile);

        $lines = ['<?xml version="1.0" encoding="UTF-8"?>', '<coverage generated="1">', '  <project timestamp="1">'];

        foreach ($lineCoverageByFile as $file => $lineCoverage) {
            $lines[] = sprintf('    <file name="%s">', htmlspecialchars($file, ENT_XML1));

            foreach ($lineCoverage as $lineNumber => $count) {
                $lines[] = sprintf('      <line num="%d" type="stmt" count="%d"/>', $lineNumber, $count);
            }

            $fileMetrics = $this->calculateMetrics([$file => $lineCoverage]);
            $lines[] = sprintf(
                '      <metrics files="1" loc="0" ncloc="0" classes="0" methods="0" coveredmethods="0" conditionals="0" coveredconditionals="0" statements="%d" coveredstatements="%d" elements="%d" coveredelements="%d"/>',
                $fileMetrics['statements'],
                $fileMetrics['coveredstatements'],
                $fileMetrics['statements'],
                $fileMetrics['coveredstatements'],
            );
            $lines[] = '    </file>';
        }

        $lines[] = sprintf(
            '    <metrics files="%d" loc="0" ncloc="0" classes="0" methods="0" coveredmethods="0" conditionals="0" coveredconditionals="0" statements="%d" coveredstatements="%d" elements="%d" coveredelements="%d"/>',
            $metrics['files'],
            $metrics['statements'],
            $metrics['coveredstatements'],
            $metrics['statements'],
            $metrics['coveredstatements'],
        );
        $lines[] = '  </project>';
        $lines[] = '</coverage>';

        file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL);
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonFile(string $path): array
    {
        $content = file_get_contents($path);
        self::assertNotFalse($content);

        $decoded = json_decode($content, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<string, array<int, int>> $lineCoverageByFile
     * @return array{files:int,statements:int,coveredstatements:int}
     */
    private function calculateMetrics(array $lineCoverageByFile): array
    {
        $statements = 0;
        $coveredStatements = 0;

        foreach ($lineCoverageByFile as $lineCoverage) {
            $statements += count($lineCoverage);

            foreach ($lineCoverage as $count) {
                if ($count > 0) {
                    $coveredStatements++;
                }
            }
        }

        return [
            'files' => count($lineCoverageByFile),
            'statements' => $statements,
            'coveredstatements' => $coveredStatements,
        ];
    }

    /**
     * @return array{statements:int,coveredstatements:int}
     */
    private function readCloverProjectMetrics(string $path): array
    {
        $xmlContent = file_get_contents($path);
        self::assertNotFalse($xmlContent);

        $xml = simplexml_load_string($xmlContent);
        self::assertNotFalse($xml);

        $metrics = $xml->xpath('/coverage/project/metrics');
        self::assertIsArray($metrics);
        self::assertNotEmpty($metrics);

        $node = $metrics[0];
        self::assertInstanceOf(\SimpleXMLElement::class, $node);

        return [
            'statements' => (int) ($node['statements'] ?? 0),
            'coveredstatements' => (int) ($node['coveredstatements'] ?? 0),
        ];
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
