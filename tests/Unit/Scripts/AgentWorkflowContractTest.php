<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

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

        self::assertStringContainsString('same unchanged exact commit', $workflow);
        self::assertStringContainsString('Any new push returns the issue to `In Review`', $workflow);
        self::assertStringContainsString('reviewed head, CI head, and current PR head are identical', $workflow);
        self::assertStringContainsString('PR-Head, CI-Head und final reviewter Head sind identisch', $template);

        $pushContract = $this->readContractMarkers($pushSkill);
        self::assertSame('In Review', $pushContract['publish_linear_state'] ?? null);
        self::assertSame('false', $pushContract['publish_may_set_ready_to_merge'] ?? null);
        self::assertSame('true', $pushContract['push_invalidates_exact_head_evidence'] ?? null);

        $landContract = $this->readContractMarkers($landSkill);
        self::assertSame('true', $landContract['merge_requires_exact_head'] ?? null);
        self::assertSame(
            'gh pr merge --merge --match-head-commit <current_head_sha>',
            $landContract['merge_command'] ?? null,
        );
        self::assertSame('In Review', $landContract['push_after_ready_linear_state'] ?? null);
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
        $ciWorkflow = Yaml::parseFile($this->repoRoot . '/.github/workflows/ci.yml');

        self::assertStringContainsString(
            'Caller-supplied flags, IDs, hashes, tokens, or paths never create public',
            $agents,
        );
        self::assertStringContainsString('## Allgemeiner Mutation-Vertrag', $writeContracts);
        self::assertStringContainsString('## Evidence-Privacy-Vertrag', $writeContracts);
        self::assertStringContainsString('`write-contract-booking` ist aktuell blockierend', $writeContracts);
        self::assertStringContainsString('`write-contract-api` ist aktuell blockierend', $writeContracts);
        self::assertStringNotContainsString('warn-only', $writeContracts);
        self::assertIsArray($ciWorkflow);
        self::assertIsArray($ciWorkflow['jobs'] ?? null);
        $this->assertBlockingWorkflowJob($ciWorkflow['jobs'], 'write-contract-booking');
        $this->assertBlockingWorkflowJob($ciWorkflow['jobs'], 'write-contract-api');
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
     * @return array<string, string>
     */
    private function readContractMarkers(string $skill): array
    {
        $markerCount = preg_match_all('/^- `(?<key>[a-z0-9_]+)`: `(?<value>[^`]+)`$/m', $skill, $matches);

        self::assertGreaterThan(0, $markerCount, 'Missing contract markers');

        return array_combine($matches['key'], $matches['value']);
    }

    /**
     * @param array<string, mixed> $jobs
     */
    private function assertBlockingWorkflowJob(array $jobs, string $jobName): void
    {
        self::assertArrayHasKey($jobName, $jobs, 'Missing CI workflow job: ' . $jobName);
        self::assertIsArray($jobs[$jobName]);
        self::assertArrayNotHasKey('continue-on-error', $jobs[$jobName]);
    }
}
