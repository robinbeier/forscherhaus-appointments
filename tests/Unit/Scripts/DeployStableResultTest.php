<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class DeployStableResultTest extends TestCase
{
    public function testNormalMainInstallsStableTrapBeforeArgumentValidation(): void
    {
        $result = $this->runCommand(['bash', 'deploy_ea.sh']);

        self::assertSame(30, $result['exit_code'], $result['stderr']);
        self::assertStringContainsString('--rel is required', $result['stdout']);
    }

    public function testPreSwitchDieUsesStableDeployFailedExit(): void
    {
        $result = $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            deploy_result_trap_install
            die 'redacted pre-switch failure'
            BASH
            ,
        );

        self::assertSame(30, $result['exit_code'], $result['stderr']);
    }

    public function testPreSwitchSetEFailureUsesStableDeployFailedExit(): void
    {
        $result = $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            deploy_result_trap_install
            false
            BASH
            ,
        );

        self::assertSame(30, $result['exit_code'], $result['stderr']);
    }

    public function testFirstAtomicMoveFailureReportsDeployFailedWithoutAttemptingSecondMove(): void
    {
        $result = $this->runShell(
            $this->switchHarness(
                <<<'BASH'
                mv() {
                  printf 'move\n'
                  return 1
                }
                perform_atomic_switch
                BASH
                ,
            ),
        );

        self::assertSame(30, $result['exit_code'], $result['stderr']);
        self::assertSame(1, substr_count($result['stdout'], "move\n"));
    }

    public function testSecondAtomicMoveFailureReportsRecoveryRequiredWithoutRetry(): void
    {
        $result = $this->runShell(
            $this->switchHarness(
                <<<'BASH'
                move_count=0
                mv() {
                  move_count=$((move_count + 1))
                  printf 'move\n'
                  [[ "$move_count" -eq 1 ]]
                }
                perform_atomic_switch
                BASH
                ,
            ),
        );

        self::assertSame(32, $result['exit_code'], $result['stderr']);
        self::assertSame(2, substr_count($result['stdout'], "move\n"));
    }

    public function testUnhandledFailureAfterCompletedSwitchRunsExistingRollback(): void
    {
        $result = $this->runShell(
            $this->switchHarness(
                <<<'BASH'
                mv() {
                  printf 'move\n'
                  return 0
                }
                rollback_after_failure() {
                  printf 'rollback\n'
                  exit 30
                }
                perform_atomic_switch
                false
                BASH
                ,
            ),
        );

        self::assertSame(30, $result['exit_code'], $result['stderr']);
        self::assertSame(2, substr_count($result['stdout'], "move\n"));
        self::assertSame(1, substr_count($result['stdout'], "rollback\n"));
    }

    public function testUnhandledPostSwitchFailureReports31OnlyWhenRollbackIsUnverifiable(): void
    {
        $result = $this->runShell(
            $this->switchHarness(
                <<<'BASH'
                mv() {
                  printf 'move\n'
                  return 0
                }
                rollback_after_failure() {
                  printf 'rollback\n'
                  exit 31
                }
                perform_atomic_switch
                false
                BASH
                ,
            ),
        );

        self::assertSame(31, $result['exit_code'], $result['stderr']);
        self::assertSame(2, substr_count($result['stdout'], "move\n"));
        self::assertSame(1, substr_count($result['stdout'], "rollback\n"));
    }

    public function testDryRunNeverEntersLiveSwitchPhaseOrCallsMove(): void
    {
        $result = $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            deploy_result_trap_install
            DRYRUN=1
            APP=/fixed/active
            PREV=/fixed/previous
            STAGE_ROOT=/fixed/stage
            mv() {
              printf 'unexpected-move\n'
              return 0
            }
            perform_atomic_switch
            false
            BASH
            ,
        );

        self::assertSame(30, $result['exit_code'], $result['stderr']);
        self::assertStringNotContainsString('unexpected-move', $result['stdout']);
    }

    public function testCompletedSwitchSuccessRemainsExitZero(): void
    {
        $result = $this->runShell(
            $this->switchHarness(
                <<<'BASH'
                mv() {
                  printf 'move\n'
                  return 0
                }
                perform_atomic_switch
                exit 0
                BASH
                ,
            ),
        );

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame(2, substr_count($result['stdout'], "move\n"));
    }

    public function testExistingRollbackSuccessAndFailureExitsRemainStable(): void
    {
        $success = $this->runShell($this->rollbackHarness(true));
        $failure = $this->runShell($this->rollbackHarness(false));

        self::assertSame(30, $success['exit_code'], $success['stderr']);
        self::assertSame(31, $failure['exit_code'], $failure['stderr']);
    }

    public function testSigtermRemainsTheContractInterruptionExit(): void
    {
        $result = $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            deploy_result_trap_install
            kill -TERM $$
            BASH
            ,
        );

        self::assertSame(143, $result['exit_code'], $result['stderr']);
    }

    public function testSigtermDuringPartialSwitchReportsRecoveryRequired(): void
    {
        $result = $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            deploy_result_trap_install
            DEPLOY_RESULT_PHASE=switch_partial
            kill -TERM $$
            BASH
            ,
        );

        self::assertSame(32, $result['exit_code'], $result['stderr']);
    }

    public function testChildSignalExitDuringPartialSwitchReportsRecoveryRequired(): void
    {
        $result = $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            deploy_result_trap_install
            DEPLOY_RESULT_PHASE=switch_partial
            exit 143
            BASH
            ,
        );

        self::assertSame(32, $result['exit_code'], $result['stderr']);
    }

    public function testSigtermAfterCompletedSwitchRunsExistingRollback(): void
    {
        $result = $this->runShell(
            $this->switchHarness(
                <<<'BASH'
                mv() { return 0; }
                rollback_after_failure() {
                  printf 'rollback\n'
                  exit 30
                }
                perform_atomic_switch
                kill -TERM $$
                BASH
                ,
            ),
        );

        self::assertSame(30, $result['exit_code'], $result['stderr']);
        self::assertSame(1, substr_count($result['stdout'], "rollback\n"));
    }

    public function testSigtermAfterCompletedSwitchReportsUnverifiableRollback(): void
    {
        $result = $this->runShell(
            $this->switchHarness(
                <<<'BASH'
                mv() { return 0; }
                rollback_after_failure() {
                  printf 'rollback\n'
                  exit 31
                }
                perform_atomic_switch
                kill -TERM $$
                BASH
                ,
            ),
        );

        self::assertSame(31, $result['exit_code'], $result['stderr']);
        self::assertSame(1, substr_count($result['stdout'], "rollback\n"));
    }

    public function testChildSignalExitAfterCompletedSwitchRunsExistingRollback(): void
    {
        $result = $this->runShell(
            $this->switchHarness(
                <<<'BASH'
                mv() { return 0; }
                rollback_after_failure() {
                  printf 'rollback\n'
                  exit 30
                }
                perform_atomic_switch
                exit 143
                BASH
                ,
            ),
        );

        self::assertSame(30, $result['exit_code'], $result['stderr']);
        self::assertSame(1, substr_count($result['stdout'], "rollback\n"));
    }

    private function switchHarness(string $body): string
    {
        return <<<'BASH'
        source ./deploy_ea.sh
        deploy_result_trap_install
        DRYRUN=0
        APP=/fixed/active
        PREV=/fixed/previous
        STAGE_ROOT=/fixed/stage
        BASH
        .
            "\n" .
            $body;
    }

    private function rollbackHarness(bool $succeeds): string
    {
        $rollbackResult = $succeeds ? 'return 0' : 'return 1';

        return <<<BASH
        source ./deploy_ea.sh
        deploy_result_trap_install
        DRYRUN=0
        APP=/fixed/active
        PREV=/fixed/previous
        REL=ea_contract
        WEBUSER=www-data
        CURRENT_SCRIPT_PATH=/fixed/deploy_ea.sh
        ZERO_SURPRISE_CANARY_REPORT=''
        deploy_timing_begin_rollback() { :; }
        deploy_timing_finish() { trap - EXIT; }
        emit_zero_surprise_incident() { :; }
        reload_services() { :; }
        restart_renderer_service() { return 0; }
        probe_renderer_health() { return 0; }
        probe_deep_health_contract() { return 0; }
        bash() { {$rollbackResult}; }
        rollback_after_failure 'redacted failure'
        BASH;
    }

    /** @return array{stdout:string,stderr:string,exit_code:int} */
    private function runShell(string $script): array
    {
        return $this->runCommand(['bash', '-c', $script]);
    }

    /** @param list<string> $command @return array{stdout:string,stderr:string,exit_code:int} */
    private function runCommand(array $command): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
            'exit_code' => $exitCode,
        ];
    }
}
