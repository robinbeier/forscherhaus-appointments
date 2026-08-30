<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

class AgentWorkflowContractTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repoRoot = dirname(__DIR__, 3);
    }

    public function testReadyToMergeRequiresUnchangedExactHead(): void
    {
        $workflow = $this->readRepoFile('WORKFLOW.md');
        $template = $this->readRepoFile('.github/pull_request_template.md');
        $pushSkill = $this->readRepoFile('.codex/skills/push/SKILL.md');
        $landSkill = $this->readRepoFile('.codex/skills/land/SKILL.md');
        $contract = $this->readRepoJson('.codex/contracts/agent-workflow.json');

        self::assertStringContainsString('same unchanged exact commit', $workflow);
        self::assertStringContainsString('Any new push returns the issue to `In Review`', $workflow);
        self::assertStringContainsString('reviewed head, CI head, and current PR head are identical', $workflow);
        self::assertStringContainsString('PR-Head, CI-Head und final reviewter Head sind identisch', $template);

        self::assertStringContainsString('../../contracts/agent-workflow.json', $pushSkill);
        self::assertStringContainsString('../../contracts/agent-workflow.json', $landSkill);
        self::assertSame(1, $contract['schema_version'] ?? null);
        self::assertSame('In Review', $contract['publish']['linear_state'] ?? null);
        self::assertFalse($contract['publish']['may_set_ready_to_merge'] ?? null);
        self::assertTrue($contract['publish']['push_invalidates_exact_head_evidence'] ?? null);
        self::assertTrue($contract['land']['requires_exact_head'] ?? null);
        self::assertSame(
            'gh pr merge --merge --match-head-commit <current_head_sha>',
            $contract['land']['merge_command'] ?? null,
        );
        self::assertSame('In Review', $contract['land']['push_after_ready_linear_state'] ?? null);
    }

    public function testSensitiveChangesRequireThreeIndependentReviewLenses(): void
    {
        $workflow = $this->readRepoFile('WORKFLOW.md');
        $reviewGuide = $this->readRepoFile('code_review.md');
        $template = $this->readRepoFile('.github/pull_request_template.md');

        self::assertStringContainsString('Reviewer A: bugs, regressions, security, edge cases', $workflow);
        self::assertStringContainsString('Reviewer B: architecture, readability, maintainability', $workflow);
        self::assertStringContainsString('Reviewer C: tests, regression coverage, and flake risk', $workflow);
        self::assertStringContainsString('three independent final reviews', $reviewGuide);
        self::assertStringContainsString('## Reviewer C (Tests/Regression/Flake-Risiko)', $template);
    }

    public function testPublicWriteAndEvidenceContractsStayFailClosed(): void
    {
        $agents = $this->readRepoFile('AGENTS.md');
        $writeContracts = $this->readRepoFile('docs/ci-write-contracts.md');

        self::assertStringContainsString(
            'Caller-supplied flags, IDs, hashes, tokens, or paths never create public',
            $agents,
        );
        self::assertStringContainsString('## Allgemeiner Mutation-Vertrag', $writeContracts);
        self::assertStringContainsString('## Evidence-Privacy-Vertrag', $writeContracts);
        self::assertStringContainsString('`write-contract-booking` ist aktuell blockierend', $writeContracts);
        self::assertStringContainsString('`write-contract-api` ist aktuell blockierend', $writeContracts);
        self::assertStringNotContainsString('warn-only', $writeContracts);
    }

    public function testHarnessEntryPointsAndGeneratedCachesStayAligned(): void
    {
        $index = $this->readRepoFile('docs/agent-harness-index.md');
        $gitignore = $this->readRepoFile('.gitignore');

        self::assertStringNotContainsString('extended local/CI command matrix', $index);
        self::assertStringContainsString('/.deptrac.cache', $gitignore);
        self::assertStringContainsString('/.playwright-cli/', $gitignore);
        self::assertStringNotContainsString('/.playwright-mcp/', $gitignore);
        self::assertTrue(is_executable($this->repoRoot . '/scripts/setup-worktree.sh'));
    }

    private function readRepoFile(string $relativePath): string
    {
        $contents = file_get_contents($this->repoRoot . '/' . $relativePath);
        self::assertNotFalse($contents, 'Failed to read ' . $relativePath);

        return $contents;
    }

    /**
     * @return array<string, mixed>
     */
    private function readRepoJson(string $relativePath): array
    {
        $decoded = json_decode($this->readRepoFile($relativePath), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
