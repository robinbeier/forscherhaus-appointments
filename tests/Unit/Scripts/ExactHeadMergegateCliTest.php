<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

require_once __DIR__ . '/../../../scripts/ci/check_exact_head_mergegate.php';

final class ExactHeadMergegateCliTest extends TestCase
{
    private const REPOSITORY = 'acme/app';
    private const SHA = '0123456789abcdef0123456789abcdef01234567';

    /** @var array<int, string> */
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function testCanonicalPolicyOwnsEveryBlockingJobExactlyOnce(): void
    {
        $root = dirname(__DIR__, 3);
        $policy = loadExactHeadMergegatePolicy($root . '/.codex/contracts/agent-workflow.json');
        $contract = json_decode(
            (string) file_get_contents($root . '/.codex/contracts/agent-workflow.json'),
            true,
            128,
            JSON_THROW_ON_ERROR,
        );

        $classified = array_merge($policy['required_checks'], $policy['conditional_checks']);
        $blocking = array_keys($contract['ci']['blocking_jobs']);
        sort($classified, SORT_STRING);
        sort($blocking, SORT_STRING);

        self::assertSame($blocking, $classified);
        self::assertCount(7, $policy['required_checks']);
        self::assertCount(12, $policy['conditional_checks']);
        self::assertSame(
            ['correctness_security', 'design_maintainability', 'tests_regression_flake'],
            $policy['required_review_lenses'],
        );
        self::assertSame(['OWNER'], $policy['trusted_associations']);
        self::assertSame(['OWNER', 'MEMBER', 'COLLABORATOR'], $policy['blocking_feedback_associations']);
    }

    public function testCheckClassificationAndNamesMatchWorkflowApplicability(): void
    {
        $root = dirname(__DIR__, 3);
        $policy = loadExactHeadMergegatePolicy($root . '/.codex/contracts/agent-workflow.json');
        $workflow = Yaml::parseFile($root . '/.github/workflows/ci.yml');
        self::assertIsArray($workflow['jobs'] ?? null);

        foreach ($policy['required_checks'] as $jobName) {
            $job = $workflow['jobs'][$jobName] ?? null;
            self::assertIsArray($job, $jobName);
            self::assertArrayNotHasKey('name', $job, $jobName);
            self::assertArrayNotHasKey('if', $job, $jobName);
        }

        foreach ($policy['conditional_checks'] as $jobName) {
            $job = $workflow['jobs'][$jobName] ?? null;
            self::assertIsArray($job, $jobName);
            self::assertArrayNotHasKey('name', $job, $jobName);
            self::assertIsString($job['if'] ?? null, $jobName);
            self::assertStringContainsString('needs.changes.outputs.', $job['if'], $jobName);
        }
    }

    public function testCliPassesOnlyWithExactReadOnlySnapshotAndWritesSanitizedReport(): void
    {
        $requestedPaths = [];
        $request = $this->validRequest($requestedPaths);
        $reportPath = $this->temporaryPath();
        $exitCode = runExactHeadMergegateCli(
            [
                'check_exact_head_mergegate.php',
                '--pr=12',
                '--reviewed-sha=' . self::SHA,
                '--output-json=' . $reportPath,
            ],
            $request,
            static fn(): string => self::REPOSITORY,
            dirname(__DIR__, 3),
        );

        self::assertSame(EXACT_HEAD_MERGEGATE_EXIT_SUCCESS, $exitCode);
        self::assertCount(7, $requestedPaths);
        foreach ($requestedPaths as $path) {
            self::assertStringStartsWith('/repos/' . self::REPOSITORY . '/', $path);
        }

        $report = (string) file_get_contents($reportPath);
        self::assertStringContainsString('"status": "pass"', $report);
        self::assertStringNotContainsString('reviewer_ref', $report);
        self::assertStringNotContainsString('secret', strtolower($report));
        self::assertStringNotContainsString('token', strtolower($report));
        self::assertStringNotContainsString('login', strtolower($report));
        self::assertStringNotContainsString($this->attestation(), $report);
    }

    public function testCliFailsClosedWhenAttestationIsMissing(): void
    {
        $requestedPaths = [];
        $request = $this->validRequest($requestedPaths, false);
        $reportPath = $this->temporaryPath();
        $exitCode = runExactHeadMergegateCli(
            [
                'check_exact_head_mergegate.php',
                '--pr=https://github.com/acme/app/pull/12',
                '--reviewed-sha=' . strtoupper(self::SHA),
                '--output-json=' . $reportPath,
            ],
            $request,
            static fn(): string => self::REPOSITORY,
            dirname(__DIR__, 3),
        );

        self::assertSame(EXACT_HEAD_MERGEGATE_EXIT_NOT_READY, $exitCode);
        self::assertStringContainsString('review_attestation_invalid', (string) file_get_contents($reportPath));
    }

    public function testCliConvertsApiFailuresToSanitizedRuntimeReport(): void
    {
        $reportPath = $this->temporaryPath();
        $request = static function (string $path): array {
            throw new RuntimeException('Required read-only command failed.');
        };
        $exitCode = runExactHeadMergegateCli(
            [
                'check_exact_head_mergegate.php',
                '--pr=12',
                '--reviewed-sha=' . self::SHA,
                '--output-json=' . $reportPath,
            ],
            $request,
            static fn(): string => self::REPOSITORY,
            dirname(__DIR__, 3),
        );

        self::assertSame(EXACT_HEAD_MERGEGATE_EXIT_RUNTIME_ERROR, $exitCode);
        $report = (string) file_get_contents($reportPath);
        self::assertStringContainsString('runtime_error', $report);
        self::assertStringNotContainsString('/repos/', $report);
    }

    public function testMalformedReviewEvidenceFailsClosed(): void
    {
        $requestedPaths = [];
        $validRequest = $this->validRequest($requestedPaths);
        $request = static function (string $path) use ($validRequest): array {
            if (str_contains($path, '/pulls/12/reviews?')) {
                return [['id' => 1]];
            }

            return $validRequest($path);
        };
        $reportPath = $this->temporaryPath();
        $exitCode = runExactHeadMergegateCli(
            [
                'check_exact_head_mergegate.php',
                '--pr=12',
                '--reviewed-sha=' . self::SHA,
                '--output-json=' . $reportPath,
            ],
            $request,
            static fn(): string => self::REPOSITORY,
            dirname(__DIR__, 3),
        );

        self::assertSame(EXACT_HEAD_MERGEGATE_EXIT_RUNTIME_ERROR, $exitCode);
        self::assertStringContainsString('invalid shape', (string) file_get_contents($reportPath));
    }

    public function testPaginationIsCompleteAndBounded(): void
    {
        $paths = [];
        $request = static function (string $path) use (&$paths): array {
            $paths[] = $path;
            parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
            $page = (int) ($query['page'] ?? 0);

            return [
                'items' => $page === 1 ? array_fill(0, EXACT_HEAD_MERGEGATE_PAGE_SIZE, ['id' => 1]) : [['id' => 2]],
            ];
        };

        $items = fetchExactHeadMergegateCollection($request, '/repos/acme/app/items', 'items');

        self::assertCount(EXACT_HEAD_MERGEGATE_PAGE_SIZE + 1, $items);
        self::assertCount(2, $paths);
        self::assertStringContainsString('page=1', $paths[0]);
        self::assertStringContainsString('page=2', $paths[1]);
    }

    public function testGitHubAdapterContainsOnlyExplicitGetMethod(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/scripts/ci/check_exact_head_mergegate.php');

        self::assertStringContainsString("'--method',\n                'GET'", $source);
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $mutationMethod) {
            self::assertStringNotContainsString("'" . $mutationMethod . "'", $source);
        }
    }

    public function testRepositoryIdentityIsPinnedToOriginAndRejectsEnvironmentDrift(): void
    {
        $root = dirname(__DIR__, 3);
        $previous = getenv('GITHUB_REPOSITORY');

        try {
            putenv('GITHUB_REPOSITORY');
            self::assertSame(
                'robinbeier/forscherhaus-appointments',
                resolveExactHeadMergegateRepository(
                    $root,
                    'https://github.com/robinbeier/forscherhaus-appointments.git',
                ),
            );

            putenv('GITHUB_REPOSITORY=foreign/repository');
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('does not match the canonical origin');
            resolveExactHeadMergegateRepository($root, 'https://github.com/robinbeier/forscherhaus-appointments.git');
        } finally {
            if ($previous === false) {
                putenv('GITHUB_REPOSITORY');
            } else {
                putenv('GITHUB_REPOSITORY=' . $previous);
            }
        }
    }

    /**
     * @param array<int, string> $requestedPaths
     * @return Closure(string): array<string, mixed>
     */
    private function validRequest(array &$requestedPaths, bool $includeAttestation = true): Closure
    {
        return function (string $path) use (&$requestedPaths, $includeAttestation): array {
            $requestedPaths[] = $path;
            if ($path === '/repos/' . self::REPOSITORY . '/pulls/12') {
                return [
                    'number' => 12,
                    'state' => 'open',
                    'draft' => false,
                    'base' => ['ref' => 'main'],
                    'head' => [
                        'sha' => self::SHA,
                        'ref' => 'feature',
                        'repo' => ['full_name' => self::REPOSITORY],
                    ],
                    'mergeable' => true,
                    'mergeable_state' => 'clean',
                ];
            }

            if (str_contains($path, '/actions/workflows/ci.yml/runs?')) {
                return [
                    'workflow_runs' => [
                        [
                            'id' => 101,
                            'name' => 'CI',
                            'event' => 'pull_request',
                            'status' => 'completed',
                            'conclusion' => 'success',
                            'head_sha' => self::SHA,
                            'head_branch' => 'feature',
                            'head_repository' => ['full_name' => self::REPOSITORY],
                            'pull_requests' => [['number' => 12]],
                            'check_suite_id' => 202,
                        ],
                    ],
                ];
            }

            if (str_contains($path, '/check-runs?')) {
                $policy = loadExactHeadMergegatePolicy(dirname(__DIR__, 3) . '/.codex/contracts/agent-workflow.json');
                $runs = [];
                foreach (array_merge($policy['required_checks'], $policy['conditional_checks']) as $name) {
                    $runs[] = [
                        'name' => $name,
                        'check_suite' => ['id' => 202],
                        'head_sha' => self::SHA,
                        'status' => 'completed',
                        'conclusion' => 'success',
                    ];
                }

                return ['check_runs' => $runs];
            }

            if (str_contains($path, '/commits/' . self::SHA . '/pulls?')) {
                return [['number' => 12]];
            }

            if (str_contains($path, '/issues/12/comments?')) {
                return $includeAttestation
                    ? [
                        [
                            'id' => 500,
                            'author_association' => 'OWNER',
                            'updated_at' => '2026-08-30T20:00:00Z',
                            'body' => $this->attestation(),
                        ],
                    ]
                    : [];
            }

            if (str_contains($path, '/pulls/12/reviews?')) {
                return [];
            }

            if (str_contains($path, '/pulls/12/comments?')) {
                return [];
            }

            self::fail('Unexpected GitHub GET path: ' . $path);
        };
    }

    private function attestation(): string
    {
        $reviews = [];
        foreach (
            [
                'correctness_security' => 'a',
                'design_maintainability' => 'b',
                'tests_regression_flake' => 'c',
            ]
            as $lens => $prefix
        ) {
            $reviews[] = [
                'lens' => $lens,
                'reviewer_ref' => str_repeat($prefix, 64),
                'verdict' => 'no_findings',
            ];
        }

        return "<!-- exact-head-review-attestation:v1\n" .
            json_encode(['head_sha' => self::SHA, 'reviews' => $reviews], JSON_UNESCAPED_SLASHES) .
            "\n-->";
    }

    private function temporaryPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'exact-head-mergegate-test-');
        self::assertNotFalse($path);
        $this->temporaryPaths[] = $path;

        return $path;
    }
}
