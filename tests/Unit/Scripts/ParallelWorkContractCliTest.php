<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

class ParallelWorkContractCliTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repoRoot = dirname(__DIR__, 3);
    }

    public function testCliAcceptsValidManifestThroughCanonicalContract(): void
    {
        $manifestPath = $this->writeJsonFixture('manifest', [
            'schema_version' => 1,
            'base_sha' => str_repeat('a', 40),
            'primary_id' => 'primary',
            'primary_approved_component_ids' => [],
            'semantic_independence' => [
                'shared_contracts' => [],
                'cross_lane_dependencies' => [],
                'coordination_required' => false,
            ],
            'lanes' => [
                [
                    'id' => 'lane-a',
                    'role' => 'implementation_worker',
                    'base_sha' => str_repeat('a', 40),
                    'ownership' => [['path' => 'tests/Fixtures/parallel/lane-a', 'match' => 'exact_or_descendants']],
                    'external_mutations' => [],
                ],
                [
                    'id' => 'lane-b',
                    'role' => 'implementation_worker',
                    'base_sha' => str_repeat('a', 40),
                    'ownership' => [['path' => 'tests/Fixtures/parallel/lane-b', 'match' => 'exact_or_descendants']],
                    'external_mutations' => [],
                ],
            ],
        ]);

        [$exitCode, $stdout, $stderr] = $this->runCli(['--manifest=' . $manifestPath]);

        self::assertSame(0, $exitCode, $stderr);
        self::assertSame('', $stderr);
        self::assertSame(
            ['schema_version' => 1, 'status' => 'pass', 'errors' => []],
            json_decode($stdout, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testCliRejectsAContractOverrideAndUsesCanonicalPolicy(): void
    {
        $manifestPath = $this->writeJsonFixture('manifest', $this->manifestForPath('scripts/agent'));

        [$exitCode, $stdout, $stderr] = $this->runCli([
            '--manifest=' . $manifestPath,
            '--contract=/tmp/permissive.json',
        ]);

        self::assertSame(2, $exitCode);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Unknown option', $stderr);
    }

    public function testCliRejectsCanonicalPrimaryOwnedPath(): void
    {
        $manifestPath = $this->writeJsonFixture('manifest', $this->manifestForPath('scripts/agent'));

        [$exitCode, $stdout, $stderr] = $this->runCli(['--manifest=' . $manifestPath]);

        self::assertSame(1, $exitCode);
        self::assertSame('', $stderr);
        $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertContains('primary_owned_path:0:scripts/agent', $result['errors']);
    }

    public function testCliRejectsInvalidManifestJsonAndShape(): void
    {
        $invalidJsonPath = sys_get_temp_dir() . '/parallel-work-invalid-' . bin2hex(random_bytes(8)) . '.json';
        self::assertNotFalse(file_put_contents($invalidJsonPath, '{'));

        [$exitCode, $stdout, $stderr] = $this->runCli(['--manifest=' . $invalidJsonPath]);
        self::assertSame(2, $exitCode);
        self::assertSame('', $stdout);
        self::assertStringContainsString('not valid JSON', $stderr);

        $invalidShapePath = $this->writeJsonFixture('shape', []);
        [$exitCode, $stdout, $stderr] = $this->runCli(['--manifest=' . $invalidShapePath]);
        self::assertSame(1, $exitCode);
        self::assertSame('', $stderr);
        self::assertSame('fail', json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['status']);
    }

    public function testCliRejectsUnknownOptionBeforeReadingInputs(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runCli(['--bogus']);

        self::assertSame(2, $exitCode);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Unknown option', $stderr);
    }

    /** @param array<string, mixed> $value */
    private function writeJsonFixture(string $label, array $value): string
    {
        $path = sys_get_temp_dir() . '/parallel-work-' . $label . '-' . bin2hex(random_bytes(8)) . '.json';
        self::assertNotFalse(file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR)));

        return $path;
    }

    /** @return array<string, mixed> */
    private function manifestForPath(string $path): array
    {
        return [
            'schema_version' => 1,
            'base_sha' => str_repeat('a', 40),
            'primary_id' => 'primary',
            'primary_approved_component_ids' => [],
            'semantic_independence' => [
                'shared_contracts' => [],
                'cross_lane_dependencies' => [],
                'coordination_required' => false,
            ],
            'lanes' => [
                [
                    'id' => 'lane-a',
                    'role' => 'implementation_worker',
                    'base_sha' => str_repeat('a', 40),
                    'ownership' => [['path' => $path, 'match' => 'exact_or_descendants']],
                    'external_mutations' => [],
                ],
            ],
        ];
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string, string}
     */
    private function runCli(array $arguments): array
    {
        $process = proc_open(
            [PHP_BINARY, $this->repoRoot . '/scripts/agent/check_parallel_work_contract.php', ...$arguments],
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $this->repoRoot,
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
