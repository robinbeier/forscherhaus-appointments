<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

class ParallelWorkContractCliTest extends TestCase
{
    private string $repoRoot;
    private string $baseSha;

    protected function setUp(): void
    {
        parent::setUp();
        $sourceRepoRoot = dirname(__DIR__, 3);
        $this->repoRoot = sys_get_temp_dir() . '/parallel-work-repo-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->repoRoot . '/scripts/agent/lib', 0700, true));
        self::assertTrue(mkdir($this->repoRoot . '/.codex/contracts', 0700, true));
        self::assertTrue(mkdir($this->repoRoot . '/docs/maps', 0700, true));
        copy(
            $sourceRepoRoot . '/scripts/agent/check_parallel_work_contract.php',
            $this->repoRoot . '/scripts/agent/check_parallel_work_contract.php',
        );
        copy($sourceRepoRoot . '/scripts/agent/lib/RepoPath.php', $this->repoRoot . '/scripts/agent/lib/RepoPath.php');
        copy(
            $sourceRepoRoot . '/scripts/agent/lib/ParallelWorkContract.php',
            $this->repoRoot . '/scripts/agent/lib/ParallelWorkContract.php',
        );
        copy(
            $sourceRepoRoot . '/.codex/contracts/agent-workflow.json',
            $this->repoRoot . '/.codex/contracts/agent-workflow.json',
        );
        copy(
            $sourceRepoRoot . '/docs/maps/component_ownership_map.json',
            $this->repoRoot . '/docs/maps/component_ownership_map.json',
        );
        $this->runGit($this->repoRoot, ['init', '-q']);
        $this->runGit($this->repoRoot, ['config', 'user.name', 'Parallel Work Test']);
        $this->runGit($this->repoRoot, ['config', 'user.email', 'parallel-work-test@example.invalid']);
        $this->runGit($this->repoRoot, ['add', '.']);
        $this->runGit($this->repoRoot, ['commit', '-qm', 'trusted base']);
        $this->baseSha = $this->runGit($this->repoRoot, ['rev-parse', 'HEAD']);
    }

    public function testCliAcceptsValidManifestThroughCanonicalContract(): void
    {
        $manifestPath = $this->writeJsonFixture('manifest', [
            'schema_version' => 1,
            'base_sha' => $this->baseSha,
            'primary_id' => 'primary',
            'primary_approved_component_ids' => ['platform-quality-tooling'],
            'semantic_independence' => [
                'shared_contracts' => [],
                'cross_lane_dependencies' => [],
                'coordination_required' => false,
            ],
            'lanes' => [
                [
                    'id' => 'lane-a',
                    'role' => 'implementation_worker',
                    'base_sha' => $this->baseSha,
                    'ownership' => [['path' => 'scripts/ci/performance', 'match' => 'exact_or_descendants']],
                    'external_mutations' => [],
                ],
                [
                    'id' => 'lane-b',
                    'role' => 'implementation_worker',
                    'base_sha' => $this->baseSha,
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

    public function testCliAnchorsPolicyAndOwnershipMapToDeclaredBase(): void
    {
        $workingContract = json_decode(
            (string) file_get_contents($this->repoRoot . '/.codex/contracts/agent-workflow.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($workingContract);
        $workingContract['parallel_work']['primary_owned_path_prefixes'] = [];
        self::assertNotFalse(
            file_put_contents(
                $this->repoRoot . '/.codex/contracts/agent-workflow.json',
                json_encode($workingContract, JSON_THROW_ON_ERROR),
            ),
        );

        $manifestPath = $this->writeJsonFixture('base-anchor', $this->manifestForPath('scripts/agent'));
        [$exitCode, $stdout, $stderr] = $this->runCli(['--manifest=' . $manifestPath]);

        self::assertSame(1, $exitCode, $stderr);
        self::assertSame('', $stderr);
        self::assertContains(
            'primary_owned_path:0:scripts/agent',
            json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['errors'],
        );
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
        self::assertSame(2, $exitCode);
        self::assertSame('', $stdout);
        self::assertStringContainsString('invalid shape', $stderr);
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
    private function manifestForPath(string $path, ?string $baseSha = null): array
    {
        $baseSha ??= $this->baseSha;

        return [
            'schema_version' => 1,
            'base_sha' => $baseSha,
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
                    'base_sha' => $baseSha,
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
    private function runCli(array $arguments, ?string $repoRoot = null): array
    {
        $repoRoot ??= $this->repoRoot;
        $process = proc_open(
            [PHP_BINARY, $repoRoot . '/scripts/agent/check_parallel_work_contract.php', ...$arguments],
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $repoRoot,
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /** @param list<string> $arguments */
    private function runGit(string $workingDirectory, array $arguments): string
    {
        $process = proc_open(
            ['git', '-C', $workingDirectory, ...$arguments],
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $stderr);

        return trim((string) $stdout);
    }
}
