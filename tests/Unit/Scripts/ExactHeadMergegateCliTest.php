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
        self::assertFalse($policy['ci_execution_contract_verified']);
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
        self::assertSame('vendor/symfony/yaml', $policy['workflow_yaml_package_path']);
        self::assertSame(
            $policy['workflow_yaml_runtime_sha256'],
            exactHeadMergegateRuntimeSha256(
                $root . '/' . $policy['workflow_yaml_package_path'],
                $policy['workflow_yaml_runtime_files'],
            ),
        );
        self::assertNotContains('README.md', $policy['workflow_yaml_runtime_files']);
        self::assertNotContains('Dumper.php', $policy['workflow_yaml_runtime_files']);
        self::assertNotContains('Command/LintCommand.php', $policy['workflow_yaml_runtime_files']);
    }

    public function testLegacyMergegateReadsRequiredLensesFromItsOwnPolicySection(): void
    {
        $root = dirname(__DIR__, 3);
        $contract = json_decode(
            (string) file_get_contents($root . '/.codex/contracts/agent-workflow.json'),
            true,
            128,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($contract);
        $contract['land']['exact_head_mergegate']['required_review_lenses'] = ['legacy_lens'];
        unset($contract['review']);

        $policy = decodeExactHeadMergegatePolicy(json_encode($contract, JSON_THROW_ON_ERROR));

        self::assertSame(['legacy_lens'], $policy['required_review_lenses']);
    }

    public function testLegacyMergegateRejectsMissingNestedLensesWithoutGlobalFallback(): void
    {
        $root = dirname(__DIR__, 3);
        $contract = json_decode(
            (string) file_get_contents($root . '/.codex/contracts/agent-workflow.json'),
            true,
            128,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($contract);
        unset($contract['land']['exact_head_mergegate']['required_review_lenses']);
        $contract['review']['sensitive_change_lenses'] = ['legacy_global_lens'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Exact-head mergegate contract contains an invalid list.');
        decodeExactHeadMergegatePolicy(json_encode($contract, JSON_THROW_ON_ERROR));
    }

    public function testLegacyMergegateRejectsAnIncorrectLensSourceDeclaration(): void
    {
        $root = dirname(__DIR__, 3);
        $contract = json_decode(
            (string) file_get_contents($root . '/.codex/contracts/agent-workflow.json'),
            true,
            128,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($contract);
        $contract['land']['exact_head_mergegate']['review_lens_source'] = 'review.sensitive_change_lenses';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Exact-head mergegate contract is malformed.');
        decodeExactHeadMergegatePolicy(json_encode($contract, JSON_THROW_ON_ERROR));
    }

    public function testIsolatedWorkflowParserPinsYamlRuntimeIgnoresAmbientClassAndUsesPortableDigits(): void
    {
        $root = dirname(__DIR__, 3);
        $policy = loadExactHeadMergegatePolicy($root . '/.codex/contracts/agent-workflow.json');
        self::assertTrue(class_exists(Yaml::class));

        $workflow = parseExactHeadMergegateWorkflowYamlIsolated(
            $root,
            $root . '/.github/workflows/ci.yml',
            $policy['workflow_yaml_package_path'],
            $policy['workflow_yaml_runtime_files'],
            $policy['workflow_yaml_runtime_sha256'],
        );
        self::assertIsArray($workflow['jobs'] ?? null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('YAML runtime is not bound to reviewed HEAD');
        parseExactHeadMergegateWorkflowYamlIsolated(
            $root,
            $root . '/.github/workflows/ci.yml',
            $policy['workflow_yaml_package_path'],
            $policy['workflow_yaml_runtime_files'],
            str_repeat('0', 64),
        );
    }

    public function testWorkflowRunPullRequestAssociationsAreCanonicalizedAsASet(): void
    {
        $run = normalizeExactHeadMergegateWorkflowRun([
            'id' => 101,
            'name' => 'CI',
            'event' => 'pull_request',
            'status' => 'completed',
            'conclusion' => 'success',
            'head_sha' => self::SHA,
            'head_branch' => 'codex/example',
            'head_repository' => ['full_name' => self::REPOSITORY],
            'pull_requests' => [['number' => 13], ['number' => 12], ['number' => 13]],
            'check_suite_id' => 202,
        ]);

        self::assertSame([12, 13], $run['pr_numbers']);
        self::assertSame(
            [12, 13],
            normalizeExactHeadMergegateAssociatedPullRequests([['number' => 13], ['number' => 12], ['number' => 13]]),
        );
    }

    public function testAssociatedPullRequestEvidenceFailsClosedWhenEntriesAreMalformed(): void
    {
        foreach (
            [
                'string number' => [['number' => 12], ['number' => '13']],
                'non-positive number' => [['number' => 12], ['number' => 0]],
            ]
            as $case => $payload
        ) {
            $exception = null;

            try {
                normalizeExactHeadMergegateAssociatedPullRequests($payload);
            } catch (RuntimeException $caught) {
                $exception = $caught;
            }

            self::assertInstanceOf(RuntimeException::class, $exception, $case);
        }
    }

    public function testFailedReadOnlyProcessReportsOnlyBoundedDiagnosticClass(): void
    {
        $sensitiveStderr = 'https://example.invalid/path?token=do-not-leak';

        try {
            runExactHeadMergegateProcess(
                [PHP_BINARY, '-r', 'fwrite(STDERR, $argv[1]); exit(17);', $sensitiveStderr],
                dirname(__DIR__, 3),
            );
            self::fail('Failing subprocess was accepted.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Required read-only php command failed (exit_code_17_stderr_present).',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString($sensitiveStderr, $exception->getMessage());
            self::assertLessThan(128, strlen($exception->getMessage()));
        }
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
            $this->mockPolicyLoader(),
        );

        self::assertSame(EXACT_HEAD_MERGEGATE_EXIT_SUCCESS, $exitCode);
        self::assertSame(
            [
                '/repos/acme/app/pulls/12',
                '/repos/acme/app/actions/workflows/ci.yml/runs?event=pull_request&head_sha=' .
                self::SHA .
                '&per_page=100&page=1',
                '/repos/acme/app/commits/' . self::SHA . '/check-runs?filter=latest&per_page=100&page=1',
                '/repos/acme/app/commits/' . self::SHA . '/pulls?per_page=100&page=1',
                '/repos/acme/app/issues/12/comments?per_page=100&page=1',
                '/repos/acme/app/pulls/12/reviews?per_page=100&page=1',
                '/repos/acme/app/pulls/12/comments?per_page=100&page=1',
                '/repos/acme/app/pulls/12',
                '/repos/acme/app/actions/workflows/ci.yml/runs?event=pull_request&head_sha=' .
                self::SHA .
                '&per_page=100&page=1',
                '/repos/acme/app/commits/' . self::SHA . '/check-runs?filter=latest&per_page=100&page=1',
                '/repos/acme/app/commits/' . self::SHA . '/pulls?per_page=100&page=1',
                '/repos/acme/app/issues/12/comments?per_page=100&page=1',
                '/repos/acme/app/pulls/12/reviews?per_page=100&page=1',
                '/repos/acme/app/pulls/12/comments?per_page=100&page=1',
                '/repos/acme/app/pulls/12',
            ],
            $requestedPaths,
        );

        $report = (string) file_get_contents($reportPath);
        self::assertStringContainsString('"status": "pass"', $report);
        self::assertStringContainsString('"review_activity_watermark"', $report);
        self::assertStringContainsString('"review_payload_digest"', $report);
        self::assertStringNotContainsString('actor_ref', $report);
        self::assertStringNotContainsString('content_digest', $report);
        self::assertStringNotContainsString('reviewer_ref', $report);
        self::assertStringNotContainsString('secret', strtolower($report));
        self::assertStringNotContainsString('token', strtolower($report));
        self::assertStringNotContainsString('login', strtolower($report));
        self::assertStringNotContainsString($this->attestation(), $report);
    }

    public function testCliRejectsDuplicateAuthorityFlagsBeforeAnyGitHubRead(): void
    {
        foreach (
            [
                'duplicate PR' => ['--pr=12', '--pr=13', '--reviewed-sha=' . self::SHA],
                'duplicate reviewed SHA' => [
                    '--pr=12',
                    '--reviewed-sha=' . self::SHA,
                    '--reviewed-sha=' . str_repeat('f', 40),
                ],
            ]
            as $case => $arguments
        ) {
            $githubReads = 0;
            $reportPath = $this->temporaryPath();
            $exitCode = runExactHeadMergegateCli(
                array_merge(['check_exact_head_mergegate.php'], $arguments, ['--output-json=' . $reportPath]),
                static function (string $path) use (&$githubReads): array {
                    $githubReads++;
                    return [];
                },
                static fn(): string => self::REPOSITORY,
                dirname(__DIR__, 3),
                $this->mockPolicyLoader(),
            );

            self::assertSame(EXACT_HEAD_MERGEGATE_EXIT_RUNTIME_ERROR, $exitCode, $case);
            self::assertSame(0, $githubReads, $case);
        }
    }

    public function testCliFailsClosedWhenAnyPullRequestIdentityFieldChangesDuringEvidenceCollection(): void
    {
        $mutations = [
            'number' => static function (array $payload): array {
                $payload['number'] = 13;
                return $payload;
            },
            'state' => static function (array $payload): array {
                $payload['state'] = 'closed';
                return $payload;
            },
            'draft' => static function (array $payload): array {
                $payload['draft'] = true;
                return $payload;
            },
            'base_ref' => static function (array $payload): array {
                $payload['base']['ref'] = 'release';
                return $payload;
            },
            'head_sha' => static function (array $payload): array {
                $payload['head']['sha'] = str_repeat('f', 40);
                return $payload;
            },
            'head_ref' => static function (array $payload): array {
                $payload['head']['ref'] = 'other-feature';
                return $payload;
            },
            'head_repository' => static function (array $payload): array {
                $payload['head']['repo']['full_name'] = 'acme/other';
                return $payload;
            },
        ];

        foreach ($mutations as $field => $mutate) {
            $requestedPaths = [];
            $pullRequestReads = 0;
            $validRequest = $this->validRequest($requestedPaths);
            $request = static function (string $path) use ($validRequest, &$pullRequestReads, $mutate): array {
                $payload = $validRequest($path);
                if ($path === '/repos/' . self::REPOSITORY . '/pulls/12') {
                    $pullRequestReads++;
                    if ($pullRequestReads === 2) {
                        $payload = $mutate($payload);
                    }
                }

                return $payload;
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
                $this->mockPolicyLoader(),
            );

            self::assertSame(EXACT_HEAD_MERGEGATE_EXIT_NOT_READY, $exitCode, $field);
            self::assertSame(3, $pullRequestReads, $field);
            self::assertStringContainsString(
                'pr_head_drift_during_evaluation',
                (string) file_get_contents($reportPath),
                $field,
            );
        }
    }

    public function testCliFailsClosedWhenReviewEvidenceChangesDuringEvaluation(): void
    {
        $requestedPaths = [];
        $reviewCommentReads = 0;
        $validRequest = $this->validRequest($requestedPaths);
        $request = static function (string $path) use ($validRequest, &$reviewCommentReads): array {
            $payload = $validRequest($path);
            if (str_contains($path, '/pulls/12/comments?')) {
                $reviewCommentReads++;
                if ($reviewCommentReads === 2) {
                    return [
                        [
                            'id' => 650,
                            'author_association' => 'MEMBER',
                            'commit_id' => self::SHA,
                            'created_at' => '2026-08-30T20:00:01Z',
                            'updated_at' => '2026-08-30T20:00:01Z',
                            'body' => 'inline review comment',
                            'edit_count' => 0,
                            'user' => ['id' => 42],
                        ],
                    ];
                }
            }

            return $payload;
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
            $this->mockPolicyLoader(),
        );

        self::assertSame(EXACT_HEAD_MERGEGATE_EXIT_NOT_READY, $exitCode);
        self::assertSame(2, $reviewCommentReads);
        self::assertStringContainsString(
            'review_evidence_drift_during_evaluation',
            (string) file_get_contents($reportPath),
        );
    }

    public function testCliFailsClosedWhenCiEvidenceDriftsOnSecondObservation(): void
    {
        $requestedPaths = [];
        $workflowReads = 0;
        $validRequest = $this->validRequest($requestedPaths);
        $request = static function (string $path) use ($validRequest, &$workflowReads): array {
            $payload = $validRequest($path);
            if (str_contains($path, '/actions/workflows/ci.yml/runs?')) {
                $workflowReads++;
                if ($workflowReads === 2) {
                    $payload['workflow_runs'][0]['status'] = 'in_progress';
                    $payload['workflow_runs'][0]['conclusion'] = null;
                }
            }

            return $payload;
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
            $this->mockPolicyLoader(),
        );

        self::assertSame(EXACT_HEAD_MERGEGATE_EXIT_NOT_READY, $exitCode);
        self::assertSame(2, $workflowReads);
        self::assertStringContainsString(
            'ci_evidence_drift_during_evaluation',
            (string) file_get_contents($reportPath),
        );
    }

    public function testCliFailsClosedWhenCommitPullRequestAssociationDriftsOnSecondObservation(): void
    {
        $requestedPaths = [];
        $associationReads = 0;
        $validRequest = $this->validRequest($requestedPaths);
        $request = static function (string $path) use ($validRequest, &$associationReads): array {
            $payload = $validRequest($path);
            if (str_contains($path, '/commits/' . self::SHA . '/pulls?')) {
                $associationReads++;
                if ($associationReads === 2) {
                    return [['number' => 99]];
                }
            }

            return $payload;
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
            $this->mockPolicyLoader(),
        );

        self::assertSame(EXACT_HEAD_MERGEGATE_EXIT_NOT_READY, $exitCode);
        self::assertSame(2, $associationReads);
        self::assertStringContainsString(
            'ci_evidence_drift_during_evaluation',
            (string) file_get_contents($reportPath),
        );
    }

    public function testCliFailsClosedWhenEvidencePaginationExceedsBoundedWindow(): void
    {
        $requestedPaths = [];
        $validRequest = $this->validRequest($requestedPaths);
        $request = static function (string $path) use ($validRequest): array {
            $payload = $validRequest($path);
            if (str_contains($path, '/actions/workflows/ci.yml/runs?')) {
                parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
                $page = (int) ($query['page'] ?? 0);
                $run = [
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
                ];

                return [
                    'workflow_runs' =>
                        $page === EXACT_HEAD_MERGEGATE_MAX_PAGES + 1
                            ? [$run]
                            : array_fill(0, EXACT_HEAD_MERGEGATE_PAGE_SIZE, $run),
                ];
            }

            return $payload;
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
            $this->mockPolicyLoader(),
        );

        self::assertSame(EXACT_HEAD_MERGEGATE_EXIT_RUNTIME_ERROR, $exitCode);
        self::assertSame(
            EXACT_HEAD_MERGEGATE_MAX_PAGES + 1,
            count(
                array_filter(
                    $requestedPaths,
                    static fn(string $path): bool => str_contains($path, '/actions/workflows/ci.yml/runs?'),
                ),
            ),
        );
        self::assertStringContainsString('runtime_error', (string) file_get_contents($reportPath));
        self::assertStringNotContainsString('reviewer_ref', (string) file_get_contents($reportPath));
    }

    public function testCliRejectsMalformedNewerWorkflowRunInsteadOfSelectingOlderGreenRun(): void
    {
        $requestedPaths = [];
        $validRequest = $this->validRequest($requestedPaths);
        $request = static function (string $path) use ($validRequest): array {
            $payload = $validRequest($path);
            if (str_contains($path, '/actions/workflows/ci.yml/runs?')) {
                $payload['workflow_runs'][] = [
                    'id' => 102,
                    'name' => 'CI',
                    'event' => 'pull_request',
                    'status' => 'completed',
                    'conclusion' => 'success',
                    'head_sha' => self::SHA,
                    'head_branch' => 'feature',
                    'head_repository' => ['full_name' => self::REPOSITORY],
                    'pull_requests' => [['number' => '12']],
                    'check_suite_id' => 203,
                ];
            }

            return $payload;
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
            $this->mockPolicyLoader(),
        );

        self::assertSame(EXACT_HEAD_MERGEGATE_EXIT_RUNTIME_ERROR, $exitCode);
        self::assertStringContainsString('runtime_error', (string) file_get_contents($reportPath));
    }

    public function testCliRejectsReviewCommentWithoutUpdatedAt(): void
    {
        $requestedPaths = [];
        $validRequest = $this->validRequest($requestedPaths);
        $request = static function (string $path) use ($validRequest): array {
            $payload = $validRequest($path);
            if (str_contains($path, '/pulls/12/comments?')) {
                return [
                    [
                        'id' => 650,
                        'author_association' => 'MEMBER',
                        'commit_id' => self::SHA,
                        'created_at' => '2026-08-30T20:00:01Z',
                        'user' => ['id' => 42],
                        'body' => 'inline review comment',
                        'edit_count' => 0,
                    ],
                ];
            }

            return $payload;
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
            $this->mockPolicyLoader(),
        );

        self::assertSame(EXACT_HEAD_MERGEGATE_EXIT_RUNTIME_ERROR, $exitCode);
        self::assertStringContainsString('runtime_error', (string) file_get_contents($reportPath));
    }

    public function testCliCanonicalizesReorderedCheckRunsAcrossObservations(): void
    {
        $requestedPaths = [];
        $checkRunReads = 0;
        $validRequest = $this->validRequest($requestedPaths);
        $request = static function (string $path) use ($validRequest, &$checkRunReads): array {
            $payload = $validRequest($path);
            if (str_contains($path, '/check-runs?')) {
                $checkRunReads++;
                if ($checkRunReads === 2) {
                    $payload['check_runs'] = array_reverse($payload['check_runs']);
                }
            }

            return $payload;
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
            $this->mockPolicyLoader(),
        );

        self::assertSame(EXACT_HEAD_MERGEGATE_EXIT_SUCCESS, $exitCode);
        self::assertSame(2, $checkRunReads);
    }

    public function testCliFailsClosedWhenIssueCommentDriftsOnSecondObservation(): void
    {
        $requestedPaths = [];
        $commentReads = 0;
        $validRequest = $this->validRequest($requestedPaths);
        $request = static function (string $path) use ($validRequest, &$commentReads): array {
            $payload = $validRequest($path);
            if (str_contains($path, '/issues/12/comments?')) {
                $commentReads++;
                if ($commentReads === 2) {
                    $payload[] = [
                        'id' => 501,
                        'author_association' => 'MEMBER',
                        'created_at' => '2026-08-30T20:00:01Z',
                        'updated_at' => '2026-08-30T20:00:01Z',
                        'body' => 'later feedback',
                        'edit_count' => 0,
                    ];
                }
            }

            return $payload;
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
            $this->mockPolicyLoader(),
        );

        self::assertSame(EXACT_HEAD_MERGEGATE_EXIT_NOT_READY, $exitCode);
        self::assertSame(2, $commentReads);
        self::assertStringContainsString(
            'review_evidence_drift_during_evaluation',
            (string) file_get_contents($reportPath),
        );
    }

    public function testCliFailsClosedWhenFormalReviewDriftsOnSecondObservation(): void
    {
        $requestedPaths = [];
        $reviewReads = 0;
        $validRequest = $this->validRequest($requestedPaths);
        $request = static function (string $path) use ($validRequest, &$reviewReads): array {
            $payload = $validRequest($path);
            if (str_contains($path, '/pulls/12/reviews?')) {
                $reviewReads++;
                if ($reviewReads === 2) {
                    $payload[] = [
                        'id' => 701,
                        'user' => ['id' => 42],
                        'author_association' => 'MEMBER',
                        'state' => 'COMMENTED',
                        'commit_id' => self::SHA,
                        'submitted_at' => '2026-08-30T20:00:01Z',
                        'body' => 'later review edit',
                        'edit_count' => 0,
                    ];
                }
            }

            return $payload;
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
            $this->mockPolicyLoader(),
        );

        self::assertSame(EXACT_HEAD_MERGEGATE_EXIT_NOT_READY, $exitCode);
        self::assertSame(2, $reviewReads);
        self::assertStringContainsString(
            'review_evidence_drift_during_evaluation',
            (string) file_get_contents($reportPath),
        );
    }

    public function testCliTreatsUnsubmittedFormalReviewAsNotReady(): void
    {
        $requestedPaths = [];
        $validRequest = $this->validRequest($requestedPaths);
        $request = static function (string $path) use ($validRequest): array {
            if (str_contains($path, '/pulls/12/reviews?')) {
                return [
                    [
                        'id' => 701,
                        'user' => ['id' => 42],
                        'author_association' => 'MEMBER',
                        'state' => 'PENDING',
                        'commit_id' => self::SHA,
                        'body' => '',
                        'edit_count' => 0,
                    ],
                ];
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
            $this->mockPolicyLoader(),
        );

        self::assertSame(EXACT_HEAD_MERGEGATE_EXIT_NOT_READY, $exitCode);
        $report = (string) file_get_contents($reportPath);
        self::assertStringContainsString('review_pending', $report);
        self::assertStringNotContainsString('runtime_error', $report);
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
            $this->mockPolicyLoader(),
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
            $this->mockPolicyLoader(),
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
            $this->mockPolicyLoader(),
        );

        self::assertSame(EXACT_HEAD_MERGEGATE_EXIT_RUNTIME_ERROR, $exitCode);
        self::assertStringContainsString('invalid shape', (string) file_get_contents($reportPath));
    }

    public function testMalformedAttestationCollectionsFailClosed(): void
    {
        foreach (['/issues/12/comments?', '/pulls/12/comments?'] as $malformedEndpoint) {
            $requestedPaths = [];
            $validRequest = $this->validRequest($requestedPaths);
            $request = static function (string $path) use ($validRequest, $malformedEndpoint): array {
                if (str_contains($path, $malformedEndpoint)) {
                    return ['not' => 'a list'];
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
                $this->mockPolicyLoader(),
            );

            self::assertSame(EXACT_HEAD_MERGEGATE_EXIT_RUNTIME_ERROR, $exitCode, $malformedEndpoint);
            self::assertStringContainsString(
                'invalid shape',
                (string) file_get_contents($reportPath),
                $malformedEndpoint,
            );
        }
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

    public function testPaginationAcceptsExactlyTheBoundedNumberOfFullPages(): void
    {
        $paths = [];
        $request = static function (string $path) use (&$paths): array {
            $paths[] = $path;
            parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
            $page = (int) ($query['page'] ?? 0);

            return [
                'items' =>
                    $page === EXACT_HEAD_MERGEGATE_MAX_PAGES + 1
                        ? []
                        : array_fill(0, EXACT_HEAD_MERGEGATE_PAGE_SIZE, ['id' => $page]),
            ];
        };

        $items = fetchExactHeadMergegateCollection($request, '/repos/acme/app/items', 'items');

        self::assertCount(EXACT_HEAD_MERGEGATE_MAX_PAGES * EXACT_HEAD_MERGEGATE_PAGE_SIZE, $items);
        self::assertCount(EXACT_HEAD_MERGEGATE_MAX_PAGES + 1, $paths);
        self::assertStringContainsString(
            'page=' . EXACT_HEAD_MERGEGATE_MAX_PAGES,
            $paths[EXACT_HEAD_MERGEGATE_MAX_PAGES - 1],
        );
        self::assertStringContainsString(
            'page=' . (EXACT_HEAD_MERGEGATE_MAX_PAGES + 1),
            $paths[EXACT_HEAD_MERGEGATE_MAX_PAGES],
        );
    }

    public function testGitHubRestAdapterInvokesOnlyExplicitGetMethodAtRuntime(): void
    {
        $commands = [];
        $request = buildExactHeadMergegateGitHubReadClosure(static function (
            array $command,
            ?string $workingDirectory,
        ) use (&$commands): string {
            $commands[] = ['command' => $command, 'working_directory' => $workingDirectory];

            return '{"ok":true}';
        });

        self::assertSame(['ok' => true], $request('/repos/acme/app/pulls/12'));
        self::assertCount(1, $commands);
        self::assertNull($commands[0]['working_directory']);
        $command = $commands[0]['command'];
        self::assertSame('gh', $command[0] ?? null);
        $methodIndex = array_search('--method', $command, true);
        self::assertIsInt($methodIndex);
        self::assertSame('GET', $command[$methodIndex + 1] ?? null);
        self::assertSame('/repos/acme/app/pulls/12', $command[array_key_last($command)] ?? null);
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $mutationMethod) {
            self::assertNotContains($mutationMethod, $command);
        }
    }

    public function testGitHubAdapterBatchesGraphQlEvidenceForAllUserContentTypes(): void
    {
        foreach (
            [
                '/repos/acme/app/issues/12/comments?per_page=100&page=1' => 'IssueComment',
                '/repos/acme/app/pulls/12/comments?per_page=100&page=1' => 'PullRequestReviewComment',
                '/repos/acme/app/pulls/12/reviews?per_page=100&page=1' => 'PullRequestReview',
            ]
            as $path => $typename
        ) {
            $commands = [];
            $request = buildExactHeadMergegateGitHubReadClosure(static function (
                array $command,
                ?string $workingDirectory,
            ) use (&$commands, $typename): string {
                $commands[] = $command;
                if (in_array('graphql', $command, true)) {
                    return json_encode(
                        [
                            'data' => [
                                'nodes' => [
                                    [
                                        '__typename' => $typename,
                                        'id' => $typename === 'PullRequestReview' ? 'PRR_kwDOabc123' : 'IC_kwDOabc123',
                                        'includesCreatedEdit' => true,
                                        'userContentEdits' => ['totalCount' => 3],
                                    ],
                                ],
                            ],
                        ],
                        JSON_THROW_ON_ERROR,
                    );
                }
                return json_encode(
                    [
                        [
                            'id' => 10,
                            'node_id' => $typename === 'PullRequestReview' ? 'PRR_kwDOabc123' : 'IC_kwDOabc123',
                            'author_association' => 'OWNER',
                            'created_at' => '2026-08-30T20:00:00Z',
                            'updated_at' => '2026-08-30T20:00:00Z',
                            'body' => 'comment',
                        ],
                    ],
                    JSON_THROW_ON_ERROR,
                );
            });

            $result = $request($path);
            self::assertSame(2, $result[0]['edit_count']);
            self::assertCount(2, $commands);
            self::assertSame('POST', $commands[1][array_search('--method', $commands[1], true) + 1]);
            self::assertContains(
                'ids[]=' . ($typename === 'PullRequestReview' ? 'PRR_kwDOabc123' : 'IC_kwDOabc123'),
                $commands[1],
            );
            self::assertStringContainsString('userContentEdits(first:1)', implode(' ', $commands[1]));
            self::assertStringNotContainsString('mutation', implode(' ', $commands[1]));
        }

        $commands = [];
        $request = buildExactHeadMergegateGitHubReadClosure(static function (array $command) use (&$commands): string {
            $commands[] = $command;

            return '[]';
        });
        self::assertSame([], $request('/repos/acme/app/issues/12/comments?per_page=100&page=1'));
        self::assertCount(1, $commands);
    }

    public function testGitHubAdapterRejectsMalformedGraphQlEvidence(): void
    {
        $cases = [
            [],
            [
                [
                    '__typename' => 'IssueComment',
                    'id' => 'wrong',
                    'includesCreatedEdit' => false,
                    'userContentEdits' => ['totalCount' => 0],
                ],
            ],
            [
                [
                    '__typename' => 'PullRequestReviewComment',
                    'id' => 'IC_kwDOabc123',
                    'includesCreatedEdit' => false,
                    'userContentEdits' => ['totalCount' => -1],
                ],
            ],
            [
                [
                    '__typename' => 'PullRequestReview',
                    'id' => 'PRR_kwDOabc123',
                    'includesCreatedEdit' => false,
                    'userContentEdits' => ['totalCount' => -1],
                ],
            ],
            [
                [
                    '__typename' => 'IssueComment',
                    'id' => 'IC_kwDOabc123',
                    'includesCreatedEdit' => false,
                    'userContentEdits' => ['totalCount' => 0],
                ],
                [
                    '__typename' => 'IssueComment',
                    'id' => 'IC_kwDOabc123',
                    'includesCreatedEdit' => false,
                    'userContentEdits' => ['totalCount' => 0],
                ],
            ],
        ];
        foreach ($cases as $nodes) {
            $request = buildExactHeadMergegateGitHubReadClosure(static function (array $command) use ($nodes): string {
                if (in_array('graphql', $command, true)) {
                    return json_encode(['data' => ['nodes' => $nodes]], JSON_THROW_ON_ERROR);
                }
                return json_encode([['id' => 10, 'node_id' => 'IC_kwDOabc123']], JSON_THROW_ON_ERROR);
            });
            try {
                $request(
                    ($nodes[0]['__typename'] ?? 'IssueComment') === 'PullRequestReview'
                        ? '/repos/acme/app/pulls/12/reviews?per_page=100&page=1'
                        : '/repos/acme/app/issues/12/comments?per_page=100&page=1',
                );
                self::fail('Malformed GraphQL evidence was accepted.');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function testGitHubAdapterRejectsGraphQlErrorsAndDuplicateRestNodeIds(): void
    {
        $request = buildExactHeadMergegateGitHubReadClosure(static function (array $command): string {
            if (in_array('graphql', $command, true)) {
                return json_encode(
                    [
                        'data' => [
                            'nodes' => [
                                [
                                    '__typename' => 'IssueComment',
                                    'id' => 'IC_kwDOabc123',
                                    'includesCreatedEdit' => false,
                                    'userContentEdits' => ['totalCount' => 0],
                                ],
                            ],
                        ],
                        'errors' => [['message' => 'partial response']],
                    ],
                    JSON_THROW_ON_ERROR,
                );
            }

            return json_encode([['id' => 10, 'node_id' => 'IC_kwDOabc123']], JSON_THROW_ON_ERROR);
        });
        try {
            $request('/repos/acme/app/issues/12/comments?per_page=100&page=1');
            self::fail('Partial GraphQL evidence was accepted.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }

        $graphQlCalled = false;
        $request = buildExactHeadMergegateGitHubReadClosure(static function (array $command) use (
            &$graphQlCalled,
        ): string {
            if (in_array('graphql', $command, true)) {
                $graphQlCalled = true;

                return '{}';
            }

            return json_encode(
                [['id' => 10, 'node_id' => 'IC_kwDOabc123'], ['id' => 11, 'node_id' => 'IC_kwDOabc123']],
                JSON_THROW_ON_ERROR,
            );
        });
        try {
            $request('/repos/acme/app/issues/12/comments?per_page=100&page=1');
            self::fail('Duplicate REST node IDs were accepted.');
        } catch (RuntimeException) {
            self::assertFalse($graphQlCalled);
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

    public function testVerifiedPolicyRequiresExactReviewedHeadAndUsesCommittedContractBlob(): void
    {
        $commands = [];
        $policy = loadExactHeadMergegateVerifiedPolicy(
            dirname(__DIR__, 3),
            dirname(__DIR__, 3) . '/.codex/contracts/agent-workflow.json',
            self::SHA,
            $this->verifiedPolicyProcessRunner($commands),
        );

        self::assertSame('main', $policy['base_ref']);
        self::assertTrue($policy['ci_execution_contract_verified']);
        self::assertSame(['git', 'rev-parse', 'HEAD'], $commands[0]['command']);
        self::assertSame(['git', 'show', self::SHA . ':.codex/contracts/agent-workflow.json'], $commands[2]['command']);
        self::assertContains(
            ['git', 'show', self::SHA . ':.github/workflows/ci.yml'],
            array_column($commands, 'command'),
        );
    }

    public function testVerifiedPolicyRejectsManipulatedReviewedContractRuntimeDigest(): void
    {
        $commands = [];
        $exception = null;
        try {
            loadExactHeadMergegateVerifiedPolicy(
                dirname(__DIR__, 3),
                dirname(__DIR__, 3) . '/.codex/contracts/agent-workflow.json',
                self::SHA,
                $this->verifiedPolicyProcessRunner($commands, null, static function (string $contract): string {
                    $decoded = json_decode($contract, true, 128, JSON_THROW_ON_ERROR);
                    $decoded['land']['exact_head_mergegate']['workflow_parser']['runtime_files_sha256'] = str_repeat(
                        '0',
                        64,
                    );

                    return json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                }),
            );
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }

        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertSame('Exact-head mergegate YAML runtime is not bound to reviewed HEAD.', $exception->getMessage());
        self::assertContains(
            ['git', 'show', self::SHA . ':.github/workflows/ci.yml'],
            array_column($commands, 'command'),
        );
    }

    public function testVerifiedPolicyRejectsManipulatedCanonicalHarnessSupport(): void
    {
        foreach (
            [
                'readiness' => 'scripts/ci/check_agent_harness_readiness.php',
                'report dates' => 'scripts/ci/check_harness_report_dates.php',
            ]
            as $case => $supportPath
        ) {
            $exception = null;
            $commands = [];
            try {
                loadExactHeadMergegateVerifiedPolicy(
                    dirname(__DIR__, 3),
                    dirname(__DIR__, 3) . '/.codex/contracts/agent-workflow.json',
                    self::SHA,
                    $this->verifiedPolicyProcessRunner($commands, null, null, static function (
                        string $path,
                        string $contents,
                    ) use ($supportPath): string {
                        return $path === $supportPath ? $contents . "\n// mutated" : $contents;
                    }),
                );
            } catch (RuntimeException $caught) {
                $exception = $caught;
            }

            self::assertInstanceOf(RuntimeException::class, $exception, $case);
            self::assertSame(
                'Exact-head mergegate canonical harness support is not bound to reviewed HEAD.',
                $exception->getMessage(),
                $case,
            );
        }
    }

    public function testYamlRuntimeManifestRejectsMalformedEntriesFailClosed(): void
    {
        $root = dirname(__DIR__, 3) . '/vendor/symfony/yaml';
        $cases = [
            'empty' => [[], 'Exact-head mergegate YAML runtime manifest is malformed.'],
            'duplicate' => [['Yaml.php', 'Yaml.php'], 'Exact-head mergegate YAML runtime manifest is malformed.'],
            'non-canonical' => [
                ['Yaml.php', 'Parser.php'],
                'Exact-head mergegate YAML runtime manifest is not canonical.',
            ],
            'invalid path' => [
                ['../Parser.php'],
                'Exact-head mergegate YAML runtime manifest contains an invalid path.',
            ],
            'missing entry' => [['Missing.php'], 'Exact-head mergegate YAML runtime manifest entry is unavailable.'],
        ];

        foreach ($cases as $case => [$runtimeFiles, $message]) {
            $exception = null;
            try {
                exactHeadMergegateRuntimeSha256($root, $runtimeFiles);
            } catch (RuntimeException $caught) {
                $exception = $caught;
            }

            self::assertInstanceOf(RuntimeException::class, $exception, $case);
            self::assertSame($message, $exception->getMessage(), $case);
        }
    }

    public function testPolicyDecoderRejectsNonCanonicalRuntimeFileList(): void
    {
        $root = dirname(__DIR__, 3);
        $contract = json_decode(
            (string) file_get_contents($root . '/.codex/contracts/agent-workflow.json'),
            true,
            128,
            JSON_THROW_ON_ERROR,
        );
        $runtimeFiles = $contract['land']['exact_head_mergegate']['workflow_parser']['runtime_files'];
        $contract['land']['exact_head_mergegate']['workflow_parser']['runtime_files'] = array_reverse($runtimeFiles);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Exact-head mergegate workflow parser contract is malformed.');
        decodeExactHeadMergegatePolicy(json_encode($contract, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function testPolicyDecoderRejectsWeakenedCommentEditEvidence(): void
    {
        $root = dirname(__DIR__, 3);
        $contract = json_decode(
            (string) file_get_contents($root . '/.codex/contracts/agent-workflow.json'),
            true,
            128,
            JSON_THROW_ON_ERROR,
        );
        $contract['land']['exact_head_mergegate']['review_attestation']['comment_edit_evidence'] =
            'rest_timestamp_equality';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Exact-head mergegate review authority model is malformed.');
        decodeExactHeadMergegatePolicy(json_encode($contract, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function testVerifiedPolicyRejectsWeakenedFingerprintedAndExactExecution(): void
    {
        $cases = [
            'conditional applicability' => static function (string $workflow): string {
                $needle = "      needs.changes.outputs.write_contract_api == 'true' &&";
                $mutated = str_replace($needle, '      false &&', $workflow, $replacementCount);
                self::assertSame(1, $replacementCount);

                return $mutated;
            },
            'fingerprinted execution' => static function (string $workflow): string {
                $needle =
                    'run: php scripts/ci/assert_deep_runtime_suite.php --manifest=storage/logs/ci/deep-runtime-suite/manifest.json --suite=integration-smoke';
                $mutated = str_replace($needle, "run: ':'", $workflow, $replacementCount);
                self::assertSame(1, $replacementCount);

                return $mutated;
            },
            'exact execution' => static function (string $workflow): string {
                $needle =
                    'run: php scripts/ci/assert_deep_runtime_suite.php --manifest=storage/logs/ci/deep-runtime-suite/manifest.json --suite=write-contract-api';
                $mutated = str_replace($needle, "run: ':'", $workflow, $replacementCount);
                self::assertSame(1, $replacementCount);

                return $mutated;
            },
        ];

        foreach ($cases as $case => $mutateWorkflow) {
            $commands = [];
            $exception = null;
            try {
                loadExactHeadMergegateVerifiedPolicy(
                    dirname(__DIR__, 3),
                    dirname(__DIR__, 3) . '/.codex/contracts/agent-workflow.json',
                    self::SHA,
                    $this->verifiedPolicyProcessRunner($commands, $mutateWorkflow),
                );
            } catch (RuntimeException $caught) {
                $exception = $caught;
            }

            self::assertInstanceOf(RuntimeException::class, $exception, $case);
            self::assertSame(
                'Exact-head mergegate reviewed CI execution contract is invalid.',
                $exception->getMessage(),
                $case,
            );
        }
    }

    public function testVerifiedPolicyRejectsHeadMismatch(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exact reviewed HEAD');
        loadExactHeadMergegateVerifiedPolicy(
            dirname(__DIR__, 3),
            dirname(__DIR__, 3) . '/.codex/contracts/agent-workflow.json',
            self::SHA,
            static function (array $command, ?string $workingDirectory): string {
                return str_repeat('f', 40) . PHP_EOL;
            },
        );
    }

    public function testVerifiedPolicyRejectsDirtySecurityCriticalFiles(): void
    {
        $commands = [];
        $exception = null;
        try {
            loadExactHeadMergegateVerifiedPolicy(
                dirname(__DIR__, 3),
                dirname(__DIR__, 3) . '/.codex/contracts/agent-workflow.json',
                self::SHA,
                static function (array $command, ?string $workingDirectory) use (&$commands): string {
                    $commands[] = ['command' => $command, 'working_directory' => $workingDirectory];
                    if ($command === ['git', 'rev-parse', 'HEAD']) {
                        return self::SHA . PHP_EOL;
                    }

                    throw new RuntimeException('simulated dirty tree');
                },
            );
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }

        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertSame('Exact-head mergegate security-critical files must be clean.', $exception->getMessage());

        self::assertSame(
            [
                'git',
                'diff',
                '--quiet',
                'HEAD',
                '--',
                '.codex/contracts/agent-workflow.json',
                'scripts/ci/check_exact_head_mergegate.php',
                'scripts/ci/lib/ExactHeadMergegate.php',
                'scripts/ci/check_agent_harness_readiness.php',
                'scripts/ci/check_harness_report_dates.php',
            ],
            $commands[1]['command'],
        );
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
                foreach (array_merge($policy['required_checks'], $policy['conditional_checks']) as $index => $name) {
                    $runs[] = [
                        'id' => 1_000 + $index,
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
                            'created_at' => '2026-08-30T20:00:00Z',
                            'updated_at' => '2026-08-30T20:00:00Z',
                            'body' => $this->attestation(),
                            'edit_count' => 0,
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

        return "<!-- exact-head-review-attestation:v2\n" .
            json_encode(
                [
                    'head_sha' => self::SHA,
                    'review_activity_watermark' => [
                        'review_id' => 0,
                        'review_comment_id' => 0,
                        'review_payload_digest' => hash('sha256', json_encode([], JSON_THROW_ON_ERROR)),
                    ],
                    'reviews' => $reviews,
                ],
                JSON_UNESCAPED_SLASHES,
            ) .
            "\n-->";
    }

    /** @return Closure(string, string, string): array<string, mixed> */
    private function mockPolicyLoader(): Closure
    {
        return static function (string $root, string $contractPath, string $reviewedSha): array {
            $policy = loadExactHeadMergegatePolicy($contractPath);
            $policy['ci_execution_contract_verified'] = true;

            return $policy;
        };
    }

    /**
     * @param array<int, array{command: array<int, string>, working_directory: ?string}> $commands
     * @param null|Closure(string): string $mutateWorkflow
     * @param null|Closure(string): string $mutateContract
     * @param null|Closure(string, string): string $mutateHarnessSupport
     * @return Closure(array<int, string>, ?string): string
     */
    private function verifiedPolicyProcessRunner(
        array &$commands,
        ?Closure $mutateWorkflow = null,
        ?Closure $mutateContract = null,
        ?Closure $mutateHarnessSupport = null,
    ): Closure {
        return static function (array $command, ?string $workingDirectory) use (
            &$commands,
            $mutateWorkflow,
            $mutateContract,
            $mutateHarnessSupport,
        ): string {
            $commands[] = ['command' => $command, 'working_directory' => $workingDirectory];
            if ($command === ['git', 'rev-parse', 'HEAD']) {
                return self::SHA . PHP_EOL;
            }
            if (
                $command === [
                    'git',
                    'diff',
                    '--quiet',
                    'HEAD',
                    '--',
                    '.codex/contracts/agent-workflow.json',
                    'scripts/ci/check_exact_head_mergegate.php',
                    'scripts/ci/lib/ExactHeadMergegate.php',
                    'scripts/ci/check_agent_harness_readiness.php',
                    'scripts/ci/check_harness_report_dates.php',
                ]
            ) {
                return '';
            }

            $blobs = [
                '.codex/contracts/agent-workflow.json' => '.codex/contracts/agent-workflow.json',
                'scripts/ci/check_agent_harness_readiness.php' => 'scripts/ci/check_agent_harness_readiness.php',
                'scripts/ci/check_harness_report_dates.php' => 'scripts/ci/check_harness_report_dates.php',
                '.github/workflows/ci.yml' => '.github/workflows/ci.yml',
            ];
            foreach ($blobs as $suffix => $relativePath) {
                if ($command !== ['git', 'show', self::SHA . ':' . $suffix]) {
                    continue;
                }

                $contents = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $relativePath);
                if ($suffix === '.codex/contracts/agent-workflow.json' && $mutateContract !== null) {
                    return $mutateContract($contents);
                }
                if ($suffix === '.github/workflows/ci.yml' && $mutateWorkflow !== null) {
                    return $mutateWorkflow($contents);
                }
                if (
                    in_array(
                        $suffix,
                        ['scripts/ci/check_agent_harness_readiness.php', 'scripts/ci/check_harness_report_dates.php'],
                        true,
                    ) &&
                    $mutateHarnessSupport !== null
                ) {
                    return $mutateHarnessSupport($suffix, $contents);
                }

                return $contents;
            }

            self::fail('Unexpected command: ' . json_encode($command, JSON_THROW_ON_ERROR));
        };
    }

    private function temporaryPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'exact-head-mergegate-test-');
        self::assertNotFalse($path);
        $this->temporaryPaths[] = $path;

        return $path;
    }
}
