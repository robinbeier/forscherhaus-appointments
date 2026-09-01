<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Forscherhaus\AgentHarness\ReadonlyReviewerContract;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once __DIR__ . '/../../../scripts/agent/lib/ReadonlyReviewerContract.php';

final class ReviewerPolicyAttestationGeneratorTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repoRoot = dirname(__DIR__, 3);
    }

    public function testEveryReviewerPolicyFieldIsPinnedByTheCodeSideAttestation(): void
    {
        $policy = $this->reviewerPolicy($this->repoRoot);
        $expectedKeys = array_keys($policy);
        sort($expectedKeys, SORT_STRING);
        $attestedBoundary = $policy;
        ksort($attestedBoundary, SORT_STRING);

        $reflection = new ReflectionClass(ReadonlyReviewerContract::class);
        self::assertSame(
            $expectedKeys,
            $reflection->getReflectionConstant('RUNTIME_BOUNDARY_ATTESTATION_KEYS')?->getValue(),
        );
        self::assertSame(
            hash('sha256', json_encode($attestedBoundary, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            $reflection->getReflectionConstant('RUNTIME_BOUNDARY_ATTESTATION_SHA256')?->getValue(),
        );

        [$status, $stdout, $stderr] = $this->runRuntimeAttestationGenerator($this->repoRoot, ['--check']);
        self::assertSame(0, $status, $stdout . $stderr);
    }

    public function testGeneratorAddsNewPolicyFieldsToThePinnedSourceBlockWithoutManualLockstep(): void
    {
        $fixtureRoot = sys_get_temp_dir() . '/reviewer-attestation-generator-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($fixtureRoot . '/scripts/agent/lib', 0700, true));
        self::assertTrue(mkdir($fixtureRoot . '/.codex/contracts', 0700, true));
        foreach (
            [
                'scripts/agent/generate_reviewer_runtime_attestation.php',
                'scripts/agent/lib/ReadonlyReviewerContract.php',
                '.codex/contracts/agent-workflow.json',
            ]
            as $path
        ) {
            self::assertTrue(copy($this->repoRoot . '/' . $path, $fixtureRoot . '/' . $path));
        }

        try {
            $contractPath = $fixtureRoot . '/.codex/contracts/agent-workflow.json';
            $contract = json_decode((string) file_get_contents($contractPath), true, 512, JSON_THROW_ON_ERROR);
            $contract['authority']['reviewer']['future_security_boundary'] = 'fail_closed';
            self::assertNotFalse(
                file_put_contents(
                    $contractPath,
                    json_encode($contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
                ),
            );

            $staleRuntimeSource = (string) file_get_contents(
                $fixtureRoot . '/scripts/agent/lib/ReadonlyReviewerContract.php',
            );

            [$staleStatus, $staleOutput, $staleError] = $this->runRuntimeAttestationGenerator($fixtureRoot, [
                '--check',
            ]);
            self::assertSame(1, $staleStatus, $staleOutput . $staleError);
            self::assertStringContainsString('runtime boundary attestation is stale', $staleError);
            self::assertSame(
                $staleRuntimeSource,
                file_get_contents($fixtureRoot . '/scripts/agent/lib/ReadonlyReviewerContract.php'),
            );

            [$updateStatus, $updateOutput, $updateError] = $this->runRuntimeAttestationGenerator($fixtureRoot);
            self::assertSame(0, $updateStatus, $updateOutput . $updateError);
            self::assertStringContainsString(
                "        'future_security_boundary',",
                (string) file_get_contents($fixtureRoot . '/scripts/agent/lib/ReadonlyReviewerContract.php'),
            );
            [$checkStatus, $checkOutput, $checkError] = $this->runRuntimeAttestationGenerator($fixtureRoot, [
                '--check',
            ]);
            self::assertSame(0, $checkStatus, $checkOutput . $checkError);
        } finally {
            $this->removeFixtureTree($fixtureRoot);
        }
    }

    /** @return array<string, mixed> */
    private function reviewerPolicy(string $root): array
    {
        $contract = json_decode(
            (string) file_get_contents($root . '/.codex/contracts/agent-workflow.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($contract['authority']['reviewer'] ?? null);

        return $contract['authority']['reviewer'];
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string, string}
     */
    private function runRuntimeAttestationGenerator(string $root, array $arguments = []): array
    {
        $process = proc_open(
            array_merge([PHP_BINARY, $root . '/scripts/agent/generate_reviewer_runtime_attestation.php'], $arguments),
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $root,
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    private function removeFixtureTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        self::assertIsArray($entries);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $entryPath = $path . '/' . $entry;
            if (is_dir($entryPath) && !is_link($entryPath)) {
                $this->removeFixtureTree($entryPath);
            } else {
                self::assertTrue(unlink($entryPath));
            }
        }
        self::assertTrue(rmdir($path));
    }
}
