<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/ci/check_agent_harness_readiness.php';

class AgentHarnessReadinessTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/agent-harness-readiness-' . uniqid('', true);
        if (!mkdir($this->tmpDir, 0777, true) && !is_dir($this->tmpDir)) {
            self::fail('Failed to create temp directory for agent harness readiness tests.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function testEvaluateSteeringSourcesFailsWhenCanonicalReferenceIsMissing(): void
    {
        file_put_contents($this->tmpDir . '/README.md', "See WORKFLOW.md only.\n");

        $checks = agentHarnessReadinessEvaluateSteeringSources($this->tmpDir, [
            'README.md' => ['docs/agent-harness-index.md'],
        ]);

        self::assertSame('pass', $checks[0]['status']);
        self::assertSame('fail', $checks[1]['status']);
    }

    public function testEvaluateBlockingJobsFailsForContinueOnError(): void
    {
        $workflow = [
            'jobs' => [
                'coverage-delta' => [
                    'continue-on-error' => true,
                ],
                'phpstan-application' => [],
            ],
        ];

        $checks = agentHarnessReadinessEvaluateBlockingJobs($workflow, ['phpstan-application', 'coverage-delta']);

        self::assertSame('pass', $checks[0]['status']);
        self::assertSame('fail', $checks[1]['status']);
    }

    #[DataProvider('blockingJobProvider')]
    public function testEvaluateBlockingJobContractsAcceptsCanonicalAndEquivalentConditions(
        string $jobName,
        string $changeOutput,
    ): void {
        [$workflow, $contracts] = $this->blockingJobFixture($jobName, $changeOutput);

        $checks = agentHarnessReadinessEvaluateBlockingJobContracts($workflow, $contracts);
        self::assertSame('pass', $checks[0]['status']);

        $workflow['jobs'][$jobName]['if'] = sprintf(
            "(needs.changes.outputs.%s == 'true') && always() && ((github.event.pull_request.draft == false && github.event_name == 'pull_request') || github.event_name == 'push')",
            $changeOutput,
        );
        $checks = agentHarnessReadinessEvaluateBlockingJobContracts($workflow, $contracts);

        self::assertSame('pass', $checks[0]['status']);
    }

    #[DataProvider('blockingJobProvider')]
    public function testEvaluateBlockingJobContractsRejectsDisabledOrMisgroupedConditions(
        string $jobName,
        string $changeOutput,
    ): void {
        [$canonicalWorkflow, $contracts] = $this->blockingJobFixture($jobName, $changeOutput);
        $conditions = [
            'forced false' => $canonicalWorkflow['jobs'][$jobName]['if'] . ' && false',
            'missing pull-request branch' => sprintf(
                "always() && needs.changes.outputs.%s == 'true' && github.event_name == 'push'",
                $changeOutput,
            ),
            'misgrouped event branches' => sprintf(
                "always() && needs.changes.outputs.%s == 'true' && github.event_name == 'push' && github.event_name == 'pull_request' && github.event.pull_request.draft == false",
                $changeOutput,
            ),
            'wrong change output' =>
                "always() && needs.changes.outputs.other == 'true' && (github.event_name == 'push' || (github.event_name == 'pull_request' && github.event.pull_request.draft == false))",
        ];

        foreach ($conditions as $case => $condition) {
            $workflow = $canonicalWorkflow;
            $workflow['jobs'][$jobName]['if'] = $condition;
            $checks = agentHarnessReadinessEvaluateBlockingJobContracts($workflow, $contracts);

            self::assertSame('fail', $checks[0]['status'], $case);
            self::assertStringContainsString('condition', (string) $checks[0]['message'], $case);
        }
    }

    #[DataProvider('blockingJobProvider')]
    public function testEvaluateBlockingJobContractsRejectsEvidenceAndBlockingBypasses(
        string $jobName,
        string $changeOutput,
    ): void {
        [$canonicalWorkflow, $contracts, $assertionRun] = $this->blockingJobFixture($jobName, $changeOutput);
        $mutations = [
            'missing dependency' => static function (array &$workflow) use ($jobName): void {
                $workflow['jobs'][$jobName]['needs'] = ['changes'];
            },
            'unexpected dependency' => static function (array &$workflow) use ($jobName): void {
                $workflow['jobs'][$jobName]['needs'][] = 'untrusted-job';
            },
            'duplicated dependency' => static function (array &$workflow) use ($jobName): void {
                $workflow['jobs'][$jobName]['needs'][] = 'changes';
            },
            'non-blocking job' => static function (array &$workflow) use ($jobName): void {
                $workflow['jobs'][$jobName]['continue-on-error'] = true;
            },
            'manifest rewrite before assertion' => static function (array &$workflow) use ($jobName): void {
                array_splice($workflow['jobs'][$jobName]['steps'], 2, 0, [
                    [
                        'name' => 'Rewrite evidence',
                        'run' => 'printf invalid > storage/logs/ci/deep-runtime-suite/manifest.json',
                    ],
                ]);
            },
            'untrusted action before assertion' => static function (array &$workflow) use ($jobName): void {
                array_splice($workflow['jobs'][$jobName]['steps'], 2, 0, [
                    [
                        'name' => 'Rewrite evidence through an action',
                        'uses' => 'example/untrusted-action@v1',
                    ],
                ]);
            },
            'wrong checkout action' => static function (array &$workflow) use ($jobName): void {
                $workflow['jobs'][$jobName]['steps'][0]['uses'] = 'example/untrusted-checkout@v1';
            },
            'wrong artifact path' => static function (array &$workflow) use ($jobName): void {
                $workflow['jobs'][$jobName]['steps'][1]['with']['path'] = '/tmp/untrusted';
            },
            'wrong artifact name' => static function (array &$workflow) use ($jobName): void {
                $workflow['jobs'][$jobName]['steps'][1]['with']['name'] = 'other-artifact';
            },
            'conditional assertion' => static function (array &$workflow) use ($jobName): void {
                $workflow['jobs'][$jobName]['steps'][2]['if'] = 'success()';
            },
            'non-blocking assertion' => static function (array &$workflow) use ($jobName): void {
                $workflow['jobs'][$jobName]['steps'][2]['continue-on-error'] = true;
            },
            'altered assertion' => static function (array &$workflow) use ($jobName, $assertionRun): void {
                $workflow['jobs'][$jobName]['steps'][2]['run'] = $assertionRun . ' || true';
            },
            'duplicated assertion' => static function (array &$workflow) use ($jobName): void {
                $workflow['jobs'][$jobName]['steps'][] = $workflow['jobs'][$jobName]['steps'][2];
            },
            'invalid steps' => static function (array &$workflow) use ($jobName): void {
                $workflow['jobs'][$jobName]['steps'] = 'invalid';
            },
        ];

        foreach ($mutations as $case => $mutate) {
            $workflow = $canonicalWorkflow;
            $mutate($workflow);
            $checks = agentHarnessReadinessEvaluateBlockingJobContracts($workflow, $contracts);

            self::assertSame('fail', $checks[0]['status'], $case);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function blockingJobProvider(): array
    {
        return [
            'booking write contract' => ['write-contract-booking', 'write_contract_booking'],
            'API write contract' => ['write-contract-api', 'write_contract_api'],
        ];
    }

    public function testWorkflowContractLoaderRejectsUnsupportedSchema(): void
    {
        $path = $this->tmpDir . '/agent-workflow.json';
        file_put_contents(
            $path,
            json_encode(['schema_version' => 1, 'ci' => ['workflow' => 'ci.yml', 'blocking_jobs' => []]]),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('schema version 2');

        agentHarnessReadinessLoadWorkflowContract($path);
    }

    public function testEvaluateHygieneWorkflowRequiresDispatchScheduleAndSteps(): void
    {
        $workflow = [
            'on' => [
                'workflow_dispatch' => [],
                'schedule' => [['cron' => '0 6 * * 1']],
            ],
            'jobs' => [
                'harness-hygiene' => [
                    'steps' => [
                        ['name' => 'Generate harness readiness report'],
                        ['name' => 'Run report date sanity check'],
                        ['name' => 'Check generated architecture/ownership docs'],
                        ['name' => 'Validate architecture/ownership map'],
                        ['name' => 'Check generated CODEOWNERS'],
                        ['name' => 'Upload hygiene artifacts'],
                    ],
                ],
            ],
        ];

        $checks = agentHarnessReadinessEvaluateHygieneWorkflow($workflow, [
            'job' => 'harness-hygiene',
            'required_steps' => [
                'Generate harness readiness report',
                'Run report date sanity check',
                'Check generated architecture/ownership docs',
                'Validate architecture/ownership map',
                'Check generated CODEOWNERS',
                'Upload hygiene artifacts',
            ],
        ]);

        foreach ($checks as $check) {
            self::assertSame('pass', $check['status']);
        }
    }

    public function testRunAgentHarnessReadinessCliFailsForUnknownOption(): void
    {
        $outputFile = $this->tmpDir . '/report.json';

        $exitCode = runAgentHarnessReadinessCli([
            'check_agent_harness_readiness.php',
            '--output-json=' . $outputFile,
            '--bogus',
        ]);

        $report = $this->readReport($outputFile);

        self::assertSame(AGENT_HARNESS_READINESS_EXIT_RUNTIME_ERROR, $exitCode);
        self::assertSame('error', $report['status']);
        self::assertStringContainsString('Unknown CLI option', (string) $report['error']['message']);
    }

    /**
     * @return array{array<string, mixed>, array<string, mixed>, string}
     */
    private function blockingJobFixture(string $jobName, string $changeOutput): array
    {
        $artifactPath = 'storage/logs/ci/deep-runtime-suite';
        $assertionRun = sprintf(
            'php scripts/ci/assert_deep_runtime_suite.php --manifest=%s/manifest.json --suite=%s',
            $artifactPath,
            $jobName,
        );
        $contracts = [
            $jobName => [
                'needs' => ['changes', 'deep-runtime-suite'],
                'condition' => [
                    'all' => [
                        ['call' => 'always'],
                        ['equals' => ['needs.changes.outputs.' . $changeOutput, 'true']],
                        [
                            'any' => [
                                ['equals' => ['github.event_name', 'push']],
                                [
                                    'all' => [
                                        ['equals' => ['github.event_name', 'pull_request']],
                                        ['equals' => ['github.event.pull_request.draft', false]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'evidence' => [
                    'checkout_action' => 'actions/checkout@v6',
                    'download_action' => 'actions/download-artifact@v8',
                    'artifact' => 'deep-runtime-suite-artifacts',
                    'path' => $artifactPath,
                ],
                'assertion' => ['run' => $assertionRun],
            ],
        ];
        $workflow = [
            'jobs' => [
                $jobName => [
                    'needs' => ['changes', 'deep-runtime-suite'],
                    'if' => sprintf(
                        "always() && needs.changes.outputs.%s == 'true' && (github.event_name == 'push' || (github.event_name == 'pull_request' && github.event.pull_request.draft == false))",
                        $changeOutput,
                    ),
                    'steps' => [
                        [
                            'name' => 'Git clone',
                            'uses' => 'actions/checkout@v6',
                        ],
                        [
                            'name' => 'Download deep runtime suite artifacts',
                            'uses' => 'actions/download-artifact@v8',
                            'with' => [
                                'name' => 'deep-runtime-suite-artifacts',
                                'path' => $artifactPath,
                            ],
                        ],
                        [
                            'name' => 'Assert suite result',
                            'run' => $assertionRun,
                        ],
                        [
                            'name' => 'Diagnostics',
                            'if' => 'failure()',
                            'run' => 'cat report.json || true',
                        ],
                    ],
                ],
            ],
        ];

        return [$workflow, $contracts, $assertionRun];
    }

    /**
     * @return array<string, mixed>
     */
    private function readReport(string $path): array
    {
        $content = file_get_contents($path);
        self::assertNotFalse($content);

        $decoded = json_decode($content, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDir()) {
                rmdir($fileInfo->getPathname());
                continue;
            }

            unlink($fileInfo->getPathname());
        }

        rmdir($directory);
    }
}
