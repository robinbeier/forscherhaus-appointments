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
        $config = $this->readRepoFile('.codex/config.toml');

        self::assertStringContainsString('name = "implementation_worker"', $role);
        self::assertStringContainsString('model = "gpt-5.6-luna"', $role);
        self::assertStringContainsString('model_reasoning_effort = "medium"', $role);
        self::assertStringContainsString('sandbox_mode = "workspace-write"', $role);
        self::assertStringContainsString('Do not delegate to other agents', $role);
        self::assertStringContainsString('Do not:', $role);
        self::assertStringContainsString('Merge, push, publish a PR', $role);
        self::assertStringContainsString('Perform production actions', $role);
        self::assertStringContainsString('max_depth = 1', $config);
    }

    public function testSteeringSourcesMakeBoundedLunaDelegationTheDefault(): void
    {
        $agents = $this->readRepoFile('AGENTS.md');
        $workflow = $this->readRepoFile('WORKFLOW.md');
        $index = $this->readRepoFile('docs/agent-harness-index.md');

        self::assertStringContainsString('implementation_worker` by default', $agents);
        self::assertStringContainsString('## Model-Aware Delegation', $workflow);
        self::assertStringContainsString('gpt-5.6-luna', $workflow);
        self::assertStringContainsString('fork_turns="none"', $workflow);
        self::assertStringContainsString('fork_turns="all"', $workflow);
        self::assertStringContainsString('do not rely on it as a model selector', $workflow);
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
}
