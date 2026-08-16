<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

class AgentDelegationContractTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repoRoot = dirname(__DIR__, 3);
    }

    public function testImplementationWorkerPinsLunaAndPureSubordinateBoundary(): void
    {
        $role = $this->readRepoFile('.codex/agents/implementation-worker.toml');
        $resolved = $this->resolveImplementationWorkerConfiguration();

        self::assertSame('agents/implementation-worker.toml', $resolved['config_file']);
        self::assertSame('gpt-5.6-luna', $resolved['model']);
        self::assertSame('medium', $resolved['model_reasoning_effort']);
        self::assertSame('workspace-write', $resolved['sandbox_mode']);
        self::assertSame('gpt-5.6-luna', $resolved['default_subagent_model']);
        self::assertSame('medium', $resolved['default_subagent_reasoning_effort']);
        self::assertStringContainsString('Do not delegate to other agents', $role);
        self::assertStringContainsString('Do not:', $role);
        self::assertStringContainsString('Merge, push, publish a PR', $role);
        self::assertStringContainsString('Perform production actions', $role);
        self::assertSame(1, $resolved['max_depth']);
    }

    public function testSteeringSourcesMakeBoundedLunaDelegationTheDefault(): void
    {
        $agents = $this->readRepoFile('AGENTS.md');
        $workflow = $this->readRepoFile('WORKFLOW.md');
        $index = $this->readRepoFile('docs/agent-harness-index.md');

        self::assertStringContainsString('implementation_worker` contract by default', $agents);
        self::assertStringContainsString('## Model-Aware Delegation', $workflow);
        self::assertStringContainsString('gpt-5.6-luna', $workflow);
        self::assertStringContainsString('agent_type="implementation_worker"', $workflow);
        self::assertStringContainsString('only `task_name`, `message`, and `fork_turns`', $workflow);
        self::assertStringContainsString('generic spawn path without a model override', $workflow);
        self::assertStringContainsString('defaults apply Luna/medium', $workflow);
        self::assertStringContainsString('`task_name` always names the delegated task path', $workflow);
        self::assertStringContainsString('task message must repeat the bounded ownership', $workflow);
        self::assertStringContainsString('fail closed', $workflow);
        self::assertStringContainsString('fork_turns="none"', $workflow);
        self::assertStringContainsString('fork_turns="all"', $workflow);
        self::assertStringContainsString('do not rely on it as a', $workflow);
        self::assertStringContainsString('model selector', $workflow);
        self::assertStringContainsString('role remains the model authority', $workflow);
        self::assertStringContainsString('primary agent inspects the diff', $workflow);
        self::assertStringContainsString('.codex/agents/implementation-worker.toml', $index);
    }

    public function testImplementationAndReviewAuthorityRemainIndependent(): void
    {
        $workflow = $this->readRepoFile('WORKFLOW.md');
        $reviewGuide = $this->readRepoFile('code_review.md');

        self::assertStringContainsString('obtains independent', $workflow);
        self::assertStringContainsString(
            'An `implementation_worker` must never be the sole reviewer of its own diff',
            $reviewGuide,
        );
        self::assertStringContainsString('primary-agent synthesis', $reviewGuide);
    }

    private function readRepoFile(string $relativePath): string
    {
        $contents = file_get_contents($this->repoRoot . '/' . $relativePath);
        self::assertNotFalse($contents, 'Failed to read ' . $relativePath);

        return $contents;
    }

    /** @return array<string, int|string> */
    private function resolveImplementationWorkerConfiguration(): array
    {
        $script = <<<'PYTHON'
        import json
        import pathlib
        import sys
        import tomllib

        repo = pathlib.Path(sys.argv[1])
        config_path = repo / ".codex" / "config.toml"
        with config_path.open("rb") as stream:
            config = tomllib.load(stream)

        agents = config["agents"]
        declaration = agents["implementation_worker"]
        role_path = config_path.parent / declaration["config_file"]
        with role_path.open("rb") as stream:
            role = tomllib.load(stream)

        print(json.dumps({
            "config_file": declaration["config_file"],
            "model": role["model"],
            "model_reasoning_effort": role["model_reasoning_effort"],
            "sandbox_mode": role["sandbox_mode"],
            "max_depth": agents["max_depth"],
            "default_subagent_model": agents["default_subagent_model"],
            "default_subagent_reasoning_effort": agents["default_subagent_reasoning_effort"],
        }))
        PYTHON;

        $process = proc_open(
            ['python3', '-I', '-B', '-c', $script, $this->repoRoot],
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process, 'Failed to start the TOML resolver');

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, 'TOML resolver failed: ' . $stderr);
        $resolved = json_decode((string) $stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($resolved);

        return $resolved;
    }
}
