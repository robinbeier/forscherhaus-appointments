<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Driver\Driver;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\ProcessedCodeCoverageData;
use SebastianBergmann\CodeCoverage\RawCodeCoverageData;

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
        $inputA = $this->tmpDir . '/shard-a.phpcov';
        $inputB = $this->tmpDir . '/shard-b.phpcov';
        $outputClover = $this->tmpDir . '/coverage-merged.xml';
        $outputJson = $this->tmpDir . '/coverage-merge.json';

        $this->writeCoverageShard($inputA, '/tmp/shard-a.php', [10 => ['test-a'], 11 => []]);
        $this->writeCoverageShard($inputB, '/tmp/shard-b.php', [20 => ['test-b'], 21 => null]);

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
    }

    public function testRunCoverageShardMergeCliFailsWhenLessThanTwoInputsProvided(): void
    {
        $inputA = $this->tmpDir . '/shard-a.phpcov';
        $this->writeCoverageShard($inputA, '/tmp/shard-a.php', [10 => ['test-a']]);

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

    /**
     * @param array<int, array<int, string>|null> $lineCoverage
     */
    private function writeCoverageShard(string $path, string $file, array $lineCoverage): void
    {
        $data = new ProcessedCodeCoverageData();
        $data->setLineCoverage([$file => $lineCoverage]);

        $coverage = new CodeCoverage(new DummyCoverageDriver(), new Filter());
        $coverage->setData($data);

        file_put_contents($path, serialize($coverage));
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

class DummyCoverageDriver extends Driver
{
    public function nameAndVersion(): string
    {
        return 'dummy-driver';
    }

    public function start(): void
    {
    }

    public function stop(): RawCodeCoverageData
    {
        return RawCodeCoverageData::fromXdebugWithoutPathCoverage([]);
    }
}
