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
                    'ownership' => ['tests/Fixtures/parallel/lane-a'],
                    'external_mutations' => [],
                ],
                [
                    'id' => 'lane-b',
                    'role' => 'implementation_worker',
                    'base_sha' => str_repeat('a', 40),
                    'ownership' => ['tests/Fixtures/parallel/lane-b'],
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

    public function testCliRejectsOwnershipMapPathOutsideRepository(): void
    {
        $contract = json_decode(
            (string) file_get_contents($this->repoRoot . '/.codex/contracts/agent-workflow.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($contract);
        $contract['parallel_work']['ownership_map'] = '../outside.json';
        $contractPath = $this->writeJsonFixture('contract', $contract);
        $manifestPath = $this->writeJsonFixture('manifest', []);

        [$exitCode, $stdout, $stderr] = $this->runCli(['--manifest=' . $manifestPath, '--contract=' . $contractPath]);

        self::assertSame(2, $exitCode);
        self::assertSame('', $stdout);
        self::assertStringContainsString('ownership-map policy is invalid', $stderr);
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
