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

    public function testEvaluateContractSurfacesRequiresReferenceAndCriticalClauses(): void
    {
        file_put_contents(
            $this->tmpDir . '/WORKFLOW.md',
            "# Workflow\n\nSee .codex/contracts/agent-workflow.json.\n\n## Review Process\n\nExact-head reviews are required.\n",
        );
        $surfaces = [
            'WORKFLOW.md' => [
                'contract_reference' => '.codex/contracts/agent-workflow.json',
                'required_sections' => [
                    '## Review Process' => ['Exact-head reviews are required.'],
                ],
            ],
        ];

        $checks = agentHarnessReadinessEvaluateContractSurfaces($this->tmpDir, $surfaces);
        self::assertSame('pass', $checks[0]['status']);

        $surfaces['WORKFLOW.md']['required_sections']['## Review Process'][] =
            'Blocking CI must use the reviewed head.';
        $checks = agentHarnessReadinessEvaluateContractSurfaces($this->tmpDir, $surfaces);
        self::assertSame('fail', $checks[0]['status']);
        self::assertStringContainsString('1 required', (string) $checks[0]['message']);

        $surfaces['WORKFLOW.md']['required_sections']['## Review Process'] = ['Exact-head reviews are required.'];
        file_put_contents(
            $this->tmpDir . '/WORKFLOW.md',
            "# Workflow\n\n## Review Process\n\nExact-head reviews are required.\n",
        );
        $checks = agentHarnessReadinessEvaluateContractSurfaces($this->tmpDir, $surfaces);
        self::assertSame('fail', $checks[0]['status']);
        self::assertStringContainsString('1 required', (string) $checks[0]['message']);
    }

    public function testEvaluateContractSurfacesRejectsMisplacedOrDuplicatedClauses(): void
    {
        $surfaces = [
            'WORKFLOW.md' => [
                'contract_reference' => '.codex/contracts/agent-workflow.json',
                'required_sections' => [
                    '## Review Process' => ['Exact-head reviews are required.'],
                ],
            ],
        ];
        file_put_contents(
            $this->tmpDir . '/WORKFLOW.md',
            "# Workflow\n\n.codex/contracts/agent-workflow.json\n\n## Review Process\n\nNo invariant.\n\n## Notes\n\nExact-head reviews are required.\n",
        );

        $checks = agentHarnessReadinessEvaluateContractSurfaces($this->tmpDir, $surfaces);
        self::assertSame('fail', $checks[0]['status']);

        file_put_contents(
            $this->tmpDir . '/WORKFLOW.md',
            "# Workflow\n\n.codex/contracts/agent-workflow.json\n\n## Review Process\n\nExact-head reviews are required. Exact-head reviews are required.\n",
        );
        $checks = agentHarnessReadinessEvaluateContractSurfaces($this->tmpDir, $surfaces);
        self::assertSame('fail', $checks[0]['status']);
    }

    public function testEvaluateContractSurfacesRejectsPathsOutsideRepository(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must stay inside the repository');

        agentHarnessReadinessEvaluateContractSurfaces($this->tmpDir, [
            '../outside.md' => [
                'contract_reference' => 'contract.json',
                'required_sections' => [],
            ],
        ]);
    }

    public function testEvaluateBlockingJobsRejectsAllForbiddenFailureControls(): void
    {
        $mutations = [
            'literal job continue-on-error' => static function (array &$job): void {
                $job['continue-on-error'] = true;
            },
            'expression job continue-on-error' => static function (array &$job): void {
                $job['continue-on-error'] = '${{ true }}';
            },
            'job default shell' => static function (array &$job): void {
                $job['defaults']['run']['shell'] = 'bash {0} || true';
            },
            'literal step continue-on-error' => static function (array &$job): void {
                $job['steps'][0]['continue-on-error'] = true;
            },
            'expression step continue-on-error' => static function (array &$job): void {
                $job['steps'][0]['continue-on-error'] = '${{ true }}';
            },
            'step shell' => static function (array &$job): void {
                $job['steps'][0]['shell'] = 'bash {0} || true';
            },
        ];

        foreach ($mutations as $case => $mutate) {
            $workflow = [
                'jobs' => [
                    'phpstan-application' => ['steps' => [['run' => 'exit 0']]],
                    'coverage-delta' => ['steps' => [['run' => 'exit 0']]],
                ],
            ];
            $mutate($workflow['jobs']['coverage-delta']);
            $checks = agentHarnessReadinessEvaluateBlockingJobs(
                $workflow,
                ['phpstan-application', 'coverage-delta'],
                'strict-v1',
            );

            self::assertSame('pass', $checks[0]['status'], $case);
            self::assertSame('fail', $checks[1]['status'], $case);
            self::assertStringContainsString('forbidden', (string) $checks[1]['message'], $case);
        }
    }

    public function testEvaluateWorkflowFailureMasksRejectsRootShellOverride(): void
    {
        $checks = agentHarnessReadinessEvaluateWorkflowFailureMasks(
            [
                'defaults' => [
                    'run' => [
                        'shell' => 'bash {0} || true',
                    ],
                ],
            ],
            'strict-v1',
        );

        self::assertSame('fail', $checks[0]['status']);
        self::assertStringContainsString('defaults.run.shell', (string) $checks[0]['message']);
    }

    public function testBlockingExecutionFingerprintsIdentifyCommandJobAndWorkflowDrift(): void
    {
        $workflow = [
            'jobs' => [
                'build-test' => [
                    'runs-on' => 'ubuntu-latest',
                    'timeout-minutes' => 20,
                    'steps' => [['name' => 'Run tests', 'run' => 'composer test']],
                ],
            ],
        ];
        $blockingJobs = ['build-test'];
        $expectedFingerprints = agentHarnessReadinessCalculateBlockingExecutionFingerprints(
            $workflow,
            $blockingJobs,
            $this->conditionGrammar(),
        );
        $checks = agentHarnessReadinessEvaluateBlockingExecutionFingerprints(
            $workflow,
            $blockingJobs,
            $this->conditionGrammar(),
            $expectedFingerprints,
        );
        self::assertSame(['pass'], array_values(array_unique(array_column($checks, 'status'))));

        $workflow['jobs']['build-test']['steps'][0]['run'] = 'composer test || true';
        $checks = agentHarnessReadinessEvaluateBlockingExecutionFingerprints(
            $workflow,
            $blockingJobs,
            $this->conditionGrammar(),
            $expectedFingerprints,
        );
        $checksById = array_column($checks, null, 'id');
        self::assertSame('pass', $checksById['blocking_execution_fingerprint_workflow_execution_envelope']['status']);
        self::assertSame('fail', $checksById['blocking_execution_fingerprint_build-test']['status']);
        self::assertStringContainsString(
            'expected',
            $checksById['blocking_execution_fingerprint_build-test']['message'],
        );
        self::assertStringContainsString('actual', $checksById['blocking_execution_fingerprint_build-test']['message']);

        $workflow['jobs']['build-test']['steps'][0]['run'] = 'composer test';
        $workflow['defaults']['run']['working-directory'] = 'application';
        $checks = agentHarnessReadinessEvaluateBlockingExecutionFingerprints(
            $workflow,
            $blockingJobs,
            $this->conditionGrammar(),
            $expectedFingerprints,
        );
        $checksById = array_column($checks, null, 'id');
        self::assertSame('fail', $checksById['blocking_execution_fingerprint_workflow_execution_envelope']['status']);
        self::assertSame('pass', $checksById['blocking_execution_fingerprint_build-test']['status']);

        unset($workflow['defaults']);
        foreach (['runs-on' => 'ubuntu-24.04', 'timeout-minutes' => 25] as $field => $value) {
            $mutated = $workflow;
            $mutated['jobs']['build-test'][$field] = $value;
            $checks = agentHarnessReadinessEvaluateBlockingExecutionFingerprints(
                $mutated,
                $blockingJobs,
                $this->conditionGrammar(),
                $expectedFingerprints,
            );
            $checksById = array_column($checks, null, 'id');
            self::assertSame('fail', $checksById['blocking_execution_fingerprint_build-test']['status'], $field);
        }
    }

    public function testBlockingExecutionFingerprintCoversWorkflowExecutionEnvelope(): void
    {
        $workflow = [
            'on' => ['pull_request' => ['branches' => ['main']]],
            'permissions' => ['contents' => 'read'],
            'env' => ['CI_MODE' => 'strict'],
            'defaults' => ['run' => ['working-directory' => 'application']],
            'concurrency' => ['group' => 'ci-${{ github.ref }}', 'cancel-in-progress' => true],
            'jobs' => [
                'build-test' => ['steps' => [['run' => 'composer test']]],
            ],
        ];
        $blockingJobs = ['build-test'];
        $expectedFingerprints = agentHarnessReadinessCalculateBlockingExecutionFingerprints(
            $workflow,
            $blockingJobs,
            $this->conditionGrammar(),
        );

        $mutations = [
            'trigger' => static function (array &$mutated): void {
                unset($mutated['on']['pull_request']);
            },
            'permissions' => static function (array &$mutated): void {
                $mutated['permissions']['contents'] = 'write';
            },
            'environment' => static function (array &$mutated): void {
                $mutated['env']['CI_MODE'] = 'permissive';
            },
            'defaults' => static function (array &$mutated): void {
                $mutated['defaults']['run']['working-directory'] = '.';
            },
            'concurrency' => static function (array &$mutated): void {
                $mutated['concurrency']['cancel-in-progress'] = false;
            },
        ];

        foreach ($mutations as $case => $mutate) {
            $mutated = $workflow;
            $mutate($mutated);
            $checks = agentHarnessReadinessEvaluateBlockingExecutionFingerprints(
                $mutated,
                $blockingJobs,
                $this->conditionGrammar(),
                $expectedFingerprints,
            );
            $checksById = array_column($checks, null, 'id');
            self::assertSame(
                'fail',
                $checksById['blocking_execution_fingerprint_workflow_execution_envelope']['status'],
                $case,
            );
            self::assertSame('pass', $checksById['blocking_execution_fingerprint_build-test']['status'], $case);
        }
    }

    public function testBlockingExecutionFingerprintNormalizesDisplayNamesAndOrderInsensitiveLists(): void
    {
        $workflow = [
            'on' => [
                'pull_request' => [
                    'branches' => ['main', '!legacy'],
                    'types' => ['synchronize', 'opened'],
                ],
                'workflow_run' => [
                    'workflows' => ['Hygiene', 'CI'],
                    'types' => ['completed'],
                ],
            ],
            'jobs' => [
                'build-test' => [
                    'name' => 'Build and test',
                    'needs' => ['seed', 'changes'],
                    'if' => "needs.changes.outputs.run == 'true' && (github.event_name == 'push' || always())",
                    'steps' => [['name' => 'Run tests', 'if' => 'failure()', 'run' => 'composer test']],
                ],
            ],
        ];
        $blockingJobs = ['build-test'];
        $expectedFingerprints = agentHarnessReadinessCalculateBlockingExecutionFingerprints(
            $workflow,
            $blockingJobs,
            $this->conditionGrammar(),
        );

        $workflow['jobs']['build-test']['name'] = 'Display-only rename';
        $workflow['jobs']['build-test']['needs'] = ['changes', 'seed'];
        $workflow['jobs']['build-test']['steps'][0]['name'] = 'Another display-only rename';
        $workflow['jobs']['build-test']['if'] =
            "( always ( ) || github.event_name == 'push' ) && needs.changes.outputs.run == 'true'";
        $workflow['jobs']['build-test']['steps'][0]['if'] = ' failure ( ) ';
        $workflow['on']['pull_request']['types'] = ['opened', 'synchronize'];
        $workflow['on']['workflow_run']['workflows'] = ['CI', 'Hygiene'];
        $checks = agentHarnessReadinessEvaluateBlockingExecutionFingerprints(
            $workflow,
            $blockingJobs,
            $this->conditionGrammar(),
            $expectedFingerprints,
        );
        self::assertSame(['pass'], array_values(array_unique(array_column($checks, 'status'))));

        $workflow['on']['pull_request']['branches'] = ['!legacy', 'main'];
        $checks = agentHarnessReadinessEvaluateBlockingExecutionFingerprints(
            $workflow,
            $blockingJobs,
            $this->conditionGrammar(),
            $expectedFingerprints,
        );
        $checksById = array_column($checks, null, 'id');
        self::assertSame('fail', $checksById['blocking_execution_fingerprint_workflow_execution_envelope']['status']);
        self::assertSame('pass', $checksById['blocking_execution_fingerprint_build-test']['status']);

        $workflow['on']['pull_request']['branches'] = ['main', '!legacy'];
        $workflow['jobs']['build-test']['if'] = "needs.changes.outputs.run == 'false'";
        $checks = agentHarnessReadinessEvaluateBlockingExecutionFingerprints(
            $workflow,
            $blockingJobs,
            $this->conditionGrammar(),
            $expectedFingerprints,
        );
        $checksById = array_column($checks, null, 'id');
        self::assertSame('pass', $checksById['blocking_execution_fingerprint_workflow_execution_envelope']['status']);
        self::assertSame('fail', $checksById['blocking_execution_fingerprint_build-test']['status']);
    }

    public function testFailureControlPolicyRejectsUnknownVersion(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown workflow contract blocking failure-control policy');

        agentHarnessReadinessFailureControlsForPolicy('strict-v2');
    }

    public function testEvaluateClassifiedJobInventoryRejectsUnclassifiedAndMissingJobs(): void
    {
        $workflow = [
            'jobs' => [
                'coverage-delta' => [],
                'pdf-renderer-latency' => [],
                'new-job' => [],
            ],
        ];

        $checks = agentHarnessReadinessEvaluateClassifiedJobInventory(
            $workflow,
            ['coverage-delta'],
            ['pdf-renderer-latency'],
        );

        self::assertSame('fail', $checks[0]['status']);
        self::assertSame('job_classification_inventory', $checks[0]['id']);
        self::assertStringContainsString('new-job', $checks[0]['message']);

        unset($workflow['jobs']['new-job']);
        $checks = agentHarnessReadinessEvaluateClassifiedJobInventory(
            $workflow,
            ['coverage-delta', 'write-contract-api'],
            ['pdf-renderer-latency'],
        );

        self::assertSame('fail', $checks[0]['status']);
        self::assertStringContainsString('write-contract-api', $checks[0]['message']);
    }

    public function testEvaluateClassifiedJobInventoryAcceptsExplicitBlockingAndAdvisoryJobs(): void
    {
        $workflow = [
            'jobs' => [
                'coverage-delta' => [],
                'pdf-renderer-latency' => [],
            ],
        ];

        $checks = agentHarnessReadinessEvaluateClassifiedJobInventory(
            $workflow,
            ['coverage-delta'],
            ['pdf-renderer-latency'],
        );

        self::assertSame('pass', $checks[0]['status']);
    }

    public function testEvaluateClassifiedJobInventoryRejectsOverlappingClassifications(): void
    {
        $workflow = ['jobs' => ['coverage-delta' => []]];

        $checks = agentHarnessReadinessEvaluateClassifiedJobInventory(
            $workflow,
            ['coverage-delta'],
            ['coverage-delta'],
        );

        self::assertSame('fail', $checks[0]['status']);
        self::assertStringContainsString('both blocking and advisory', $checks[0]['message']);
    }

    #[DataProvider('blockingJobProvider')]
    public function testEvaluateBlockingJobContractsAcceptsCanonicalAndEquivalentConditions(
        string $jobName,
        string $changeOutput,
    ): void {
        [$workflow, $contracts] = $this->blockingJobFixture($jobName, $changeOutput);

        $checks = agentHarnessReadinessEvaluateBlockingJobContracts(
            $workflow,
            $contracts,
            $this->conditionGrammar(),
            'strict-v1',
        );
        self::assertSame('pass', $checks[0]['status']);

        $workflow['jobs'][$jobName]['if'] = sprintf(
            "(needs.changes.outputs.%s == 'true') && always() && ((github.event.pull_request.draft == false && github.event_name == 'pull_request') || github.event_name == 'push')",
            $changeOutput,
        );
        $checks = agentHarnessReadinessEvaluateBlockingJobContracts(
            $workflow,
            $contracts,
            $this->conditionGrammar(),
            'strict-v1',
        );

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
            $checks = agentHarnessReadinessEvaluateBlockingJobContracts(
                $workflow,
                $contracts,
                $this->conditionGrammar(),
                'strict-v1',
            );

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
            'changed runner' => static function (array &$workflow) use ($jobName): void {
                $workflow['jobs'][$jobName]['runs-on'] = 'self-hosted';
            },
            'changed timeout' => static function (array &$workflow) use ($jobName): void {
                $workflow['jobs'][$jobName]['timeout-minutes'] = 60;
            },
            'unexpected job environment' => static function (array &$workflow) use ($jobName): void {
                $workflow['jobs'][$jobName]['env']['BYPASS'] = '1';
            },
            'non-blocking job' => static function (array &$workflow) use ($jobName): void {
                $workflow['jobs'][$jobName]['continue-on-error'] = '${{ true }}';
            },
            'job default shell' => static function (array &$workflow) use ($jobName): void {
                $workflow['jobs'][$jobName]['defaults']['run']['shell'] = 'bash {0} || true';
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
                $workflow['jobs'][$jobName]['steps'][2]['continue-on-error'] = '${{ true }}';
            },
            'assertion shell override' => static function (array &$workflow) use ($jobName): void {
                $workflow['jobs'][$jobName]['steps'][2]['shell'] = 'bash {0} || true';
            },
            'altered assertion' => static function (array &$workflow) use ($jobName, $assertionRun): void {
                $workflow['jobs'][$jobName]['steps'][2]['run'] = $assertionRun . ' || true';
            },
            'duplicated assertion' => static function (array &$workflow) use ($jobName): void {
                $workflow['jobs'][$jobName]['steps'][] = $workflow['jobs'][$jobName]['steps'][2];
            },
            'unexpected post-assertion command' => static function (array &$workflow) use ($jobName): void {
                $workflow['jobs'][$jobName]['steps'][] = [
                    'name' => 'Leak evidence',
                    'if' => 'always()',
                    'run' => 'cat storage/logs/ci/deep-runtime-suite/manifest.json',
                ];
            },
            'altered diagnostics condition' => static function (array &$workflow) use ($jobName): void {
                $workflow['jobs'][$jobName]['steps'][3]['if'] = 'always()';
            },
            'altered diagnostics command' => static function (array &$workflow) use ($jobName): void {
                $workflow['jobs'][$jobName]['steps'][3]['run'] .= 'cat untrusted.txt';
            },
            'missing diagnostics' => static function (array &$workflow) use ($jobName): void {
                array_pop($workflow['jobs'][$jobName]['steps']);
            },
            'invalid steps' => static function (array &$workflow) use ($jobName): void {
                $workflow['jobs'][$jobName]['steps'] = 'invalid';
            },
        ];

        foreach ($mutations as $case => $mutate) {
            $workflow = $canonicalWorkflow;
            $mutate($workflow);
            $checks = agentHarnessReadinessEvaluateBlockingJobContracts(
                $workflow,
                $contracts,
                $this->conditionGrammar(),
                'strict-v1',
            );

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

    #[DataProvider('unsupportedConditionProvider')]
    public function testConditionParserFailsClosedForSyntaxOutsideContract(string $condition): void
    {
        $this->expectException(\InvalidArgumentException::class);

        agentHarnessReadinessParseCondition($condition, $this->conditionGrammar());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsupportedConditionProvider(): array
    {
        return [
            'unary operator' => ['! github.event.pull_request.draft'],
            'inequality' => ["github.event_name != 'push'"],
            'call arguments' => ["contains(github.ref, 'main')"],
            'unsupported zero-argument call' => ['success()'],
            'double-quoted literal' => ['github.event_name == "push"'],
            'numeric literal' => ['github.run_attempt == 1'],
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

    public function testWorkflowContractLoaderRejectsInvalidConditionGrammar(): void
    {
        $path = $this->tmpDir . '/agent-workflow.json';
        $grammar = $this->conditionGrammar();
        $grammar['operators']['equals'] = $grammar['operators']['and'];
        file_put_contents(
            $path,
            json_encode([
                'schema_version' => 2,
                'surfaces' => ['WORKFLOW.md' => []],
                'ci' => [
                    'workflow' => 'ci.yml',
                    'blocking_failure_control_policy' => 'strict-v1',
                    'job_classification_policy' => 'explicit-v1',
                    'advisory_jobs' => [],
                    'blocking_execution_fingerprints' => [
                        'workflow_execution_envelope' => str_repeat('a', 64),
                        'build-test' => str_repeat('b', 64),
                    ],
                    'condition_grammar' => $grammar,
                    'blocking_jobs' => ['build-test' => ['kind' => 'fingerprinted_execution']],
                ],
            ]),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('condition grammar is invalid');

        agentHarnessReadinessLoadWorkflowContract($path);
    }

    public function testWorkflowContractLoaderRejectsUnknownFailureControlPolicy(): void
    {
        $path = $this->tmpDir . '/agent-workflow.json';
        file_put_contents(
            $path,
            json_encode([
                'schema_version' => 2,
                'surfaces' => ['WORKFLOW.md' => []],
                'ci' => [
                    'workflow' => 'ci.yml',
                    'blocking_failure_control_policy' => 'strict-v2',
                    'job_classification_policy' => 'explicit-v1',
                    'advisory_jobs' => [],
                    'blocking_execution_fingerprints' => [
                        'workflow_execution_envelope' => str_repeat('a', 64),
                        'build-test' => str_repeat('b', 64),
                    ],
                    'condition_grammar' => $this->conditionGrammar(),
                    'blocking_jobs' => ['build-test' => ['kind' => 'fingerprinted_execution']],
                ],
            ]),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown workflow contract blocking failure-control policy');

        agentHarnessReadinessLoadWorkflowContract($path);
    }

    public function testWorkflowContractLoaderRejectsUnsupportedJobClassificationPolicy(): void
    {
        $path = $this->tmpDir . '/agent-workflow.json';
        file_put_contents(
            $path,
            json_encode([
                'schema_version' => 2,
                'surfaces' => ['WORKFLOW.md' => []],
                'ci' => [
                    'workflow' => 'ci.yml',
                    'blocking_failure_control_policy' => 'strict-v1',
                    'job_classification_policy' => 'open-v1',
                    'advisory_jobs' => [],
                    'blocking_execution_fingerprints' => [
                        'workflow_execution_envelope' => str_repeat('a', 64),
                        'build-test' => str_repeat('b', 64),
                    ],
                    'condition_grammar' => $this->conditionGrammar(),
                    'blocking_jobs' => ['build-test' => ['kind' => 'fingerprinted_execution']],
                ],
            ]),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('explicit job-classification policy');

        agentHarnessReadinessLoadWorkflowContract($path);
    }

    public function testWorkflowContractLoaderRejectsInvalidBlockingExecutionFingerprint(): void
    {
        $path = $this->tmpDir . '/agent-workflow.json';
        file_put_contents(
            $path,
            json_encode([
                'schema_version' => 2,
                'surfaces' => ['WORKFLOW.md' => []],
                'ci' => [
                    'workflow' => 'ci.yml',
                    'blocking_failure_control_policy' => 'strict-v1',
                    'job_classification_policy' => 'explicit-v1',
                    'advisory_jobs' => [],
                    'blocking_execution_fingerprints' => [
                        'workflow_execution_envelope' => str_repeat('a', 64),
                        'build-test' => 'invalid',
                    ],
                    'condition_grammar' => $this->conditionGrammar(),
                    'blocking_jobs' => ['build-test' => ['kind' => 'fingerprinted_execution']],
                ],
            ]),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('execution fingerprint');

        agentHarnessReadinessLoadWorkflowContract($path);
    }

    public function testWorkflowContractLoaderRejectsMissingOrExtraFingerprintComponents(): void
    {
        $cases = [
            'missing fingerprinted job' => [
                'workflow_execution_envelope' => str_repeat('a', 64),
            ],
            'extra fingerprint component' => [
                'workflow_execution_envelope' => str_repeat('a', 64),
                'build-test' => str_repeat('b', 64),
                'unclassified-component' => str_repeat('c', 64),
            ],
        ];

        foreach ($cases as $case => $fingerprints) {
            $path = $this->tmpDir . '/agent-workflow-' . str_replace(' ', '-', $case) . '.json';
            file_put_contents(
                $path,
                json_encode([
                    'schema_version' => 2,
                    'surfaces' => ['WORKFLOW.md' => []],
                    'ci' => [
                        'workflow' => 'ci.yml',
                        'blocking_failure_control_policy' => 'strict-v1',
                        'job_classification_policy' => 'explicit-v1',
                        'advisory_jobs' => [],
                        'blocking_execution_fingerprints' => $fingerprints,
                        'condition_grammar' => $this->conditionGrammar(),
                        'blocking_jobs' => ['build-test' => ['kind' => 'fingerprinted_execution']],
                    ],
                ]),
            );

            try {
                agentHarnessReadinessLoadWorkflowContract($path);
                self::fail('Expected mismatched execution fingerprint components to be rejected: ' . $case);
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString(
                    'execution fingerprints must match every fingerprinted-execution component exactly',
                    $exception->getMessage(),
                    $case,
                );
            }
        }
    }

    public function testWorkflowContractLoaderRejectsMalformedExactExecutionContract(): void
    {
        $path = $this->tmpDir . '/agent-workflow.json';
        file_put_contents(
            $path,
            json_encode([
                'schema_version' => 2,
                'surfaces' => ['WORKFLOW.md' => []],
                'ci' => [
                    'workflow' => 'ci.yml',
                    'blocking_failure_control_policy' => 'strict-v1',
                    'job_classification_policy' => 'explicit-v1',
                    'advisory_jobs' => [],
                    'blocking_execution_fingerprints' => [
                        'workflow_execution_envelope' => str_repeat('a', 64),
                    ],
                    'condition_grammar' => $this->conditionGrammar(),
                    'blocking_jobs' => ['write-contract-api' => ['kind' => 'exact_execution']],
                ],
            ]),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exact-execution job "write-contract-api" is malformed');

        agentHarnessReadinessLoadWorkflowContract($path);
    }

    public function testWorkflowContractLoaderDerivesExactExecutionJobsFromTheirKind(): void
    {
        $path = $this->tmpDir . '/agent-workflow.json';
        [, $blockingJobs] = $this->blockingJobFixture('write-contract-api', 'write_contract_api');
        file_put_contents(
            $path,
            json_encode([
                'schema_version' => 2,
                'surfaces' => ['WORKFLOW.md' => []],
                'ci' => [
                    'workflow' => 'ci.yml',
                    'blocking_failure_control_policy' => 'strict-v1',
                    'job_classification_policy' => 'explicit-v1',
                    'advisory_jobs' => [],
                    'blocking_execution_fingerprints' => [
                        'workflow_execution_envelope' => str_repeat('a', 64),
                    ],
                    'condition_grammar' => $this->conditionGrammar(),
                    'blocking_jobs' => $blockingJobs,
                ],
            ]),
        );

        $contract = agentHarnessReadinessLoadWorkflowContract($path);

        self::assertSame('exact_execution', $contract['ci']['blocking_jobs']['write-contract-api']['kind']);
        self::assertArrayNotHasKey('required_exact_execution_jobs', $contract['ci']);
    }

    public function testConditionParserUsesContractDefinedTokens(): void
    {
        $grammar = $this->conditionGrammar();
        $grammar['operators'] = ['and' => '&', 'or' => '|', 'equals' => '='];
        $grammar['grouping'] = ['open' => '[', 'close' => ']'];
        $grammar['literals']['string_delimiter'] = '"';
        $grammar['zero_argument_calls'] = ['guard'];

        self::assertSame(
            [
                'all' => [['call' => 'guard'], ['equals' => ['github.event_name', 'push']]],
            ],
            agentHarnessReadinessParseCondition('guard[] & github.event_name = "push"', $grammar),
        );
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

    public function testEvaluateAgentHarnessReadinessWiresFingerprintAndWorkflowFailureControls(): void
    {
        $contractDirectory = $this->tmpDir . '/.codex/contracts';
        $workflowDirectory = $this->tmpDir . '/.github/workflows';
        self::assertTrue(mkdir($contractDirectory, 0777, true));
        self::assertTrue(mkdir($workflowDirectory, 0777, true));

        $contractPath = $contractDirectory . '/agent-workflow.json';
        self::assertTrue(copy(dirname(__DIR__, 3) . '/.codex/contracts/agent-workflow.json', $contractPath));
        $contract = json_decode((string) file_get_contents($contractPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($contract);
        $contract['surfaces'] = [
            '.codex/contracts/agent-workflow.json' => [
                'contract_reference' => '.codex/contracts/agent-workflow.json',
                'required_sections' => [],
            ],
        ];
        self::assertNotFalse(file_put_contents($contractPath, json_encode($contract, JSON_THROW_ON_ERROR)));
        $ciWorkflow = file_get_contents(dirname(__DIR__, 3) . '/.github/workflows/ci.yml');
        self::assertNotFalse($ciWorkflow);
        self::assertTrue(
            copy(dirname(__DIR__, 3) . '/.github/workflows/hygiene.yml', $workflowDirectory . '/hygiene.yml'),
        );
        $ciWorkflowPath = $workflowDirectory . '/ci.yml';
        self::assertNotFalse(file_put_contents($ciWorkflowPath, $ciWorkflow));
        $fingerprintedJobs = array_keys(
            array_filter(
                $contract['ci']['blocking_jobs'],
                static fn(array $job): bool => ($job['kind'] ?? null) === 'fingerprinted_execution',
            ),
        );
        $contract['ci'][
            'blocking_execution_fingerprints'
        ] = agentHarnessReadinessCalculateBlockingExecutionFingerprints(
            agentHarnessReadinessLoadWorkflowYaml($ciWorkflowPath),
            $fingerprintedJobs,
            $contract['ci']['condition_grammar'],
        );
        self::assertNotFalse(file_put_contents($contractPath, json_encode($contract, JSON_THROW_ON_ERROR)));

        $policyPath = $this->tmpDir . '/policy.php';
        $policy = [
            'target_score' => 4.5,
            'dimensions' => [
                'steering_sources' => ['label' => 'Steering sources', 'weight' => 20],
                'blocking_gates' => ['label' => 'Blocking gates', 'weight' => 30],
                'generated_topology' => ['label' => 'Generated topology', 'weight' => 20],
                'report_sanity' => ['label' => 'Report sanity', 'weight' => 15],
                'scheduled_hygiene' => ['label' => 'Scheduled hygiene', 'weight' => 15],
            ],
            'required_sources' => [],
            'generated_topology_commands' => [],
            'hygiene_workflow' => [
                'path' => '.github/workflows/hygiene.yml',
                'job' => 'harness-hygiene',
                'required_steps' => [
                    'Generate harness readiness report',
                    'Run report date sanity check',
                    'Check generated architecture/ownership docs',
                    'Validate architecture/ownership map',
                    'Check generated CODEOWNERS',
                    'Upload hygiene artifacts',
                ],
            ],
        ];
        self::assertNotFalse(file_put_contents($policyPath, "<?php\n\nreturn " . var_export($policy, true) . ";\n"));

        $policy = loadAgentHarnessReadinessPolicy($policyPath);
        $baselineReport = evaluateAgentHarnessReadiness(
            $this->tmpDir,
            $policy,
            new \DateTimeImmutable('2026-08-30', new \DateTimeZone('UTC')),
            0,
        );
        $baselineBlockingGates = array_values(
            array_filter(
                $baselineReport['dimensions'],
                static fn(array $dimension): bool => ($dimension['id'] ?? null) === 'blocking_gates',
            ),
        );
        self::assertCount(1, $baselineBlockingGates);
        self::assertSame('pass', $baselineBlockingGates[0]['status']);

        $mutatedWorkflow = agentHarnessReadinessLoadWorkflowYaml($ciWorkflowPath);
        self::assertIsArray($mutatedWorkflow['concurrency'] ?? null);
        $mutatedWorkflow['concurrency']['cancel-in-progress'] = false;
        self::assertNotFalse(
            file_put_contents($ciWorkflowPath, \Symfony\Component\Yaml\Yaml::dump($mutatedWorkflow, 20, 2)),
        );

        $report = evaluateAgentHarnessReadiness(
            $this->tmpDir,
            $policy,
            new \DateTimeImmutable('2026-08-30', new \DateTimeZone('UTC')),
            0,
        );

        self::assertSame('fail', $report['status']);
        $blockingGates = array_values(
            array_filter(
                $report['dimensions'],
                static fn(array $dimension): bool => ($dimension['id'] ?? null) === 'blocking_gates',
            ),
        );
        self::assertCount(1, $blockingGates);
        self::assertSame('fail', $blockingGates[0]['status']);
        self::assertTrue(
            array_filter(
                $blockingGates[0]['checks'],
                static fn(array $check): bool => ($check['id'] ?? null) ===
                    'blocking_execution_fingerprint_workflow_execution_envelope' &&
                    ($check['status'] ?? null) === 'fail',
            ) !== [],
        );

        self::assertNotFalse(file_put_contents($ciWorkflowPath, $ciWorkflow));
        $failureMaskedWorkflow = agentHarnessReadinessLoadWorkflowYaml($ciWorkflowPath);
        $failureMaskedWorkflow['defaults']['run']['shell'] = 'bash {0}';
        self::assertNotFalse(
            file_put_contents($ciWorkflowPath, \Symfony\Component\Yaml\Yaml::dump($failureMaskedWorkflow, 20, 2)),
        );

        $failureMaskReport = evaluateAgentHarnessReadiness(
            $this->tmpDir,
            $policy,
            new \DateTimeImmutable('2026-08-30', new \DateTimeZone('UTC')),
            0,
        );
        $failureMaskBlockingGates = array_values(
            array_filter(
                $failureMaskReport['dimensions'],
                static fn(array $dimension): bool => ($dimension['id'] ?? null) === 'blocking_gates',
            ),
        );
        self::assertCount(1, $failureMaskBlockingGates);
        self::assertSame('fail', $failureMaskBlockingGates[0]['status']);
        self::assertTrue(
            array_filter(
                $failureMaskBlockingGates[0]['checks'],
                static fn(array $check): bool => ($check['id'] ?? null) === 'workflow_failure_controls' &&
                    ($check['status'] ?? null) === 'fail',
            ) !== [],
        );
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
        $diagnosticsRun =
            "cat {$artifactPath}/manifest.json || true\n" .
            "cat {$artifactPath}/{$jobName}.log || true\n" .
            "cat {$artifactPath}/{$jobName}.json || true\n";
        $contracts = [
            $jobName => [
                'kind' => 'exact_execution',
                'needs' => ['changes', 'deep-runtime-suite'],
                'runs_on' => 'ubuntu-latest',
                'timeout_minutes' => 35,
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
                'post_assertion_steps' => [
                    [
                        'if' => 'failure()',
                        'run' => $diagnosticsRun,
                    ],
                ],
            ],
        ];
        $workflow = [
            'jobs' => [
                $jobName => [
                    'needs' => ['changes', 'deep-runtime-suite'],
                    'runs-on' => 'ubuntu-latest',
                    'timeout-minutes' => 35,
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
                            'run' => $diagnosticsRun,
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
    private function conditionGrammar(): array
    {
        return [
            'version' => 1,
            'operators' => ['and' => '&&', 'or' => '||', 'equals' => '=='],
            'grouping' => ['open' => '(', 'close' => ')'],
            'identifier_pattern' => '[A-Za-z_][A-Za-z0-9_.-]*',
            'literals' => [
                'string_delimiter' => "'",
                'booleans' => ['true' => true, 'false' => false],
            ],
            'zero_argument_calls' => ['always', 'failure'],
            'unsupported_syntax_fails_closed' => true,
        ];
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
