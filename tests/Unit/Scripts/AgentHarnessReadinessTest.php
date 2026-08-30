<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

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

    public function testEvaluateBlockingJobContractsChecksScopeAndAssertionStep(): void
    {
        $command = 'php scripts/ci/assert_deep_runtime_suite.php --manifest=manifest.json --suite=write-contract-api';
        $contract = [
            'write-contract-api' => [
                'needs' => ['changes', 'deep-runtime-suite'],
                'condition_fragments' => [
                    'always()',
                    "needs.changes.outputs.write_contract_api == 'true'",
                    "github.event_name == 'push'",
                    'github.event.pull_request.draft == false',
                ],
                'run' => $command,
            ],
        ];
        $workflow = [
            'jobs' => [
                'write-contract-api' => [
                    'needs' => ['changes', 'deep-runtime-suite'],
                    'if' =>
                        "always() && needs.changes.outputs.write_contract_api == 'true' && (github.event_name == 'push' || github.event.pull_request.draft == false)",
                    'steps' => [['run' => $command]],
                ],
            ],
        ];

        $checks = agentHarnessReadinessEvaluateBlockingJobContracts($workflow, $contract);

        self::assertSame('pass', $checks[0]['status']);

        $workflow['jobs']['write-contract-api']['if'] =
            "always() && needs.changes.outputs.write_contract_api == 'true'";
        $workflow['jobs']['write-contract-api']['steps'][0]['continue-on-error'] = true;
        $checks = agentHarnessReadinessEvaluateBlockingJobContracts($workflow, $contract);

        self::assertSame('fail', $checks[0]['status']);
        self::assertStringContainsString('github.event_name', (string) $checks[0]['message']);
        self::assertStringContainsString('pull_request.draft', (string) $checks[0]['message']);
        self::assertStringContainsString('assertion step is non-blocking', (string) $checks[0]['message']);

        $workflow['jobs']['write-contract-api']['if'] =
            "always() && needs.changes.outputs.write_contract_api == 'true' && (github.event_name == 'push' || github.event.pull_request.draft == false)";
        $workflow['jobs']['write-contract-api']['needs'] = ['changes'];
        $workflow['jobs']['write-contract-api']['steps'][0] = [
            'run' => $command,
            'if' => 'success()',
        ];
        $checks = agentHarnessReadinessEvaluateBlockingJobContracts($workflow, $contract);

        self::assertSame('fail', $checks[0]['status']);
        self::assertStringContainsString('missing need deep-runtime-suite', (string) $checks[0]['message']);
        self::assertStringContainsString('assertion step is conditional', (string) $checks[0]['message']);

        $workflow['jobs']['write-contract-api']['steps'] = 'invalid';
        $checks = agentHarnessReadinessEvaluateBlockingJobContracts($workflow, $contract);

        self::assertSame('fail', $checks[0]['status']);
        self::assertStringContainsString('steps are invalid', (string) $checks[0]['message']);

        $workflow['jobs']['write-contract-api']['needs'][] = 'deep-runtime-suite';
        $workflow['jobs']['write-contract-api']['steps'] = [['run' => $command . ' || true']];
        $checks = agentHarnessReadinessEvaluateBlockingJobContracts($workflow, $contract);

        self::assertSame('fail', $checks[0]['status']);
        self::assertStringContainsString('expected exactly one assertion step', (string) $checks[0]['message']);
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
