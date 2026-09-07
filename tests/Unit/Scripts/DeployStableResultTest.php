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

    public function testReservedRawPreSwitchExitsAreNormalizedWithoutResultProvenance(): void
    {
        foreach ([30, 31, 32] as $rawExitCode) {
            $result = $this->runShell(
                str_replace(
                    'RAW_EXIT_CODE',
                    (string) $rawExitCode,
                    <<<'BASH'
                    source ./deploy_ea.sh
                    deploy_result_trap_install
                    exit RAW_EXIT_CODE
                    BASH
                    ,
                ),
            );

            self::assertSame(30, $result['exit_code'], $rawExitCode . ': ' . $result['stderr']);
        }
    }

    public function testFirstAtomicMoveFailureReportsDeployFailedWithoutAttemptingSecondMove(): void
    {
        $result = $this->runShell(
            $this->switchHarness(
                <<<'BASH'
                deploy_result_path_exists() {
                  case "$1" in
                    /fixed/active|/fixed/stage)
                      return 0
                      ;;
                  esac
                  return 1
                }
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

    public function testSigtermAfterFirstSuccessfulMoveUsesTheReconciledPartialState(): void
    {
        $result = $this->runShell(
            $this->switchHarness(
                <<<'BASH'
                MOCK_SWITCH_STATE=before
                deploy_result_path_exists() {
                  case "$MOCK_SWITCH_STATE:$1" in
                    before:/fixed/active|before:/fixed/stage|partial:/fixed/previous|partial:/fixed/stage)
                      return 0
                      ;;
                  esac
                  return 1
                }
                mv() {
                  MOCK_SWITCH_STATE=partial
                  kill -TERM $$
                }
                perform_atomic_switch
                BASH
                ,
            ),
        );

        self::assertSame(32, $result['exit_code'], $result['stderr']);
    }

    public function testSigtermAfterSecondSuccessfulMoveUsesCompletedStateWhenStageReappears(): void
    {
        $result = $this->runShell(
            $this->switchHarness(
                <<<'BASH'
                MOCK_SWITCH_STATE=before
                move_count=0
                deploy_result_path_exists() {
                  case "$MOCK_SWITCH_STATE:$1" in
                    before:/fixed/active|before:/fixed/stage|partial:/fixed/previous|partial:/fixed/stage|complete_with_stage:/fixed/active|complete_with_stage:/fixed/previous|complete_with_stage:/fixed/stage)
                      return 0
                      ;;
                  esac
                  return 1
                }
                mv() {
                  move_count=$((move_count + 1))
                  if [[ "$move_count" -eq 1 ]]; then
                    MOCK_SWITCH_STATE=partial
                    return 0
                  fi
                  MOCK_SWITCH_STATE=complete_with_stage
                  kill -TERM $$
                }
                rollback_after_failure() {
                  printf 'rollback\n'
                  deploy_result_exit 30
                }
                perform_atomic_switch
                BASH
                ,
            ),
        );

        self::assertSame(30, $result['exit_code'], $result['stderr']);
        self::assertSame(1, substr_count($result['stdout'], "rollback\n"));
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
                  deploy_result_exit 30
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
                  deploy_result_exit 31
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

    public function testReservedRawPostSwitchExitsStillRunExistingRollback(): void
    {
        foreach ([30, 31, 32] as $rawExitCode) {
            $result = $this->runShell(
                $this->switchHarness(
                    str_replace(
                        'RAW_EXIT_CODE',
                        (string) $rawExitCode,
                        <<<'BASH'
                        mv() { return 0; }
                        rollback_after_failure() {
                          printf 'rollback\n'
                          deploy_result_exit 30
                        }
                        perform_atomic_switch
                        exit RAW_EXIT_CODE
                        BASH
                        ,
                    ),
                ),
            );

            self::assertSame(30, $result['exit_code'], $rawExitCode . ': ' . $result['stderr']);
            self::assertSame(1, substr_count($result['stdout'], "rollback\n"), (string) $rawExitCode);
        }
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

    public function testSignalAfterSuccessfulFinalizationDoesNotRollbackCompletedDeploy(): void
    {
        $result = $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            deploy_result_trap_install
            DRYRUN=0
            DEPLOY_RESULT_PHASE=switch_complete

            deploy_result_after_finalize() { printf 'success-boundary\n'; kill -TERM $$; }
            rollback_after_failure() {
              printf 'unexpected-rollback\n'
              deploy_result_exit 31
            }
            deploy_result_finish 0
            BASH
            ,
        );

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame(1, substr_count($result['stdout'], "success-boundary\n"));
        self::assertStringNotContainsString('unexpected-rollback', $result['stdout']);
    }

    public function testSuccessIsFinalizedBeforeTheSuccessEpilogueCanBeInterrupted(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3) . '/deploy_ea.sh');
        self::assertIsString($script);
        $finalizationPosition = strpos($script, "\ndeploy_result_finalize 0\n");
        $successBannerPosition = strpos($script, "\necho \"[✓] Deployment completed: \$APP\"\n");

        self::assertIsInt($finalizationPosition);
        self::assertIsInt($successBannerPosition);
        self::assertLessThan($successBannerPosition, $finalizationPosition);

        $result = $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            deploy_result_trap_install
            DRYRUN=0
            DEPLOY_RESULT_PHASE=switch_complete
            rollback_after_failure() {
              printf 'unexpected-rollback\n'
              deploy_result_exit 31
            }
            deploy_result_finalize 0
            printf 'success-epilogue-boundary\n'
            kill -TERM $$
            BASH
            ,
        );

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame(1, substr_count($result['stdout'], "success-epilogue-boundary\n"));
        self::assertStringNotContainsString('unexpected-rollback', $result['stdout']);
    }

    public function testExistingRollbackSuccessAndFailureExitsRemainStable(): void
    {
        $success = $this->runShell($this->rollbackHarness(true));
        $failure = $this->runShell($this->rollbackHarness(false));

        self::assertSame(30, $success['exit_code'], $success['stderr']);
        self::assertSame(31, $failure['exit_code'], $failure['stderr']);
    }

    public function testReloadServicesAttemptsEveryCsvUnitAndAggregatesFailures(): void
    {
        $result = $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            DRYRUN=0
            CALLS="$(mktemp)"
            fake_systemctl() {
              printf '%s\n' "$*" >> "$CALLS"
              [[ "${2:-}" == 'first' ]] && return 1
              return 0
            }
            SYSTEMCTL_BASE=(fake_systemctl)
            RELOAD_SERVICES='first,second,third'
            if reload_services; then
              status=0
            else
              status=$?
            fi
            printf 'status=%s\n' "$status"
            cat "$CALLS"
            rm -f "$CALLS"
            BASH
            ,
        );

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame("status=1\nreload first\nreload second\nreload third\n", $result['stdout']);
    }

    public function testEmptyReloadListAndDryRunDoNotInvokeServiceControl(): void
    {
        $result = $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            SYSTEMCTL_BASE=(false)
            DRYRUN=0
            RELOAD_SERVICES=''
            reload_services
            RELOAD_SERVICES=' , '
            reload_services
            printf 'empty-lists-ok\n'
            DRYRUN=1
            RELOAD_SERVICES='first,second'
            reload_services
            printf 'dry-run-ok\n'
            BASH
            ,
        );
        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertStringContainsString("empty-lists-ok\n", $result['stdout']);
        self::assertStringContainsString("dry-run-ok\n", $result['stdout']);
    }

    public function testPostSwitchReloadPrecedesHealthAndFailureEntersRollback(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/deploy_ea.sh');
        $boundary = "\nperform_atomic_switch\n";
        $position = strrpos($source, $boundary);
        self::assertNotFalse($position);
        $postSwitch = substr($source, $position + strlen($boundary));
        $script = <<<'BASH'
        source ./deploy_ea.sh
        DRYRUN=0
        RELOAD_SERVICES=php-test
        RELOAD_EXIT="$2"
        fake_systemctl() { printf 'reload\n'; return "$RELOAD_EXIT"; }
        SYSTEMCTL_BASE=(fake_systemctl)
        verify_post_switch_runtime_config_contracts() { :; }
        rollback_after_failure() { printf 'rollback:%s\n' "$1"; exit 30; }
        probe_renderer_health() { printf 'health\n'; exit 0; }
        probe_deep_health_contract() { printf 'unexpected-deep-health\n'; return 0; }
        run_zero_surprise_live_canary() { printf 'unexpected-canary\n'; return 0; }
        eval "$1"
        BASH;
        foreach (
            [0 => [0, "reload\nhealth\n"], 1 => [30, "reload\nrollback:service reload failed\n"]]
            as $reloadExit => $expected
        ) {
            $result = $this->runCommand(['bash', '-c', $script, 'bash', $postSwitch, (string) $reloadExit]);
            self::assertSame($expected[0], $result['exit_code'], $result['stderr']);
            self::assertSame($expected[1], $result['stdout']);
        }
    }

    public function testRollbackReloadFailureRemainsUnverifiedExit31(): void
    {
        $result = $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            deploy_result_trap_install
            DRYRUN=0
            APP=/fixed/active
            PREV=/fixed/previous
            REL=ea_contract
            WEBUSER=www-data
            CURRENT_SCRIPT_PATH=/fixed/deploy_ea.sh
            DEPLOY_RESULT_PHASE=switch_complete

            emit_zero_surprise_incident() { :; }
            reload_services() { return 1; }
            probe_renderer_health() { echo 'unexpected-health-check'; return 0; }
            probe_deep_health_contract() { return 0; }
            bash() { return 0; }
            rollback_after_failure 'service reload failed'
            BASH
            ,
        );

        self::assertSame(31, $result['exit_code'], $result['stderr']);
        self::assertStringContainsString('Rollback failed: service reload failed.', $result['stdout']);
        self::assertStringNotContainsString('unexpected-health-check', $result['stdout']);
    }

    public function testUnhealthyRendererKeepsRollbackFailureExit31(): void
    {
        $result = $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            deploy_result_trap_install
            DRYRUN=0
            APP=/fixed/active
            PREV=/fixed/previous
            REL=ea_contract
            WEBUSER=www-data
            CURRENT_SCRIPT_PATH=/fixed/deploy_ea.sh
            ZERO_SURPRISE_CANARY_REPORT=''
            DEPLOY_RESULT_PHASE=switch_complete

            emit_zero_surprise_incident() { :; }
            reload_services() { :; }
            probe_renderer_health() { return 1; }
            probe_deep_health_contract() { return 0; }
            bash() { return 0; }
            rollback_after_failure 'renderer health check failed'
            BASH
            ,
        );

        self::assertSame(31, $result['exit_code'], $result['stderr']);
        self::assertStringContainsString('Renderer check      : failed', $result['stdout']);
    }

    public function testSignalAfterRollbackVerificationPreservesTheFinalResult(): void
    {
        $success = $this->runShell($this->rollbackHarness(true, true));
        $failure = $this->runShell($this->rollbackHarness(false, true));

        self::assertSame(30, $success['exit_code'], $success['stderr']);
        self::assertSame(31, $failure['exit_code'], $failure['stderr']);
    }

    public function testSignalDuringIncidentAfterVerifiedRollbackPreservesSuccessResult(): void
    {
        $result = $this->runShell($this->rollbackHarness(true, false, true));

        self::assertSame(30, $result['exit_code'], $result['stderr']);
        self::assertSame(1, substr_count($result['stdout'], "incident-boundary\n"));
    }

    public function testSignalDuringSummaryAfterVerifiedRollbackPreservesSuccessResult(): void
    {
        $result = $this->runShell($this->rollbackHarness(true, false, false, true));

        self::assertSame(30, $result['exit_code'], $result['stderr']);
        self::assertSame(1, substr_count($result['stdout'], "summary-boundary\n"));
    }

    public function testSignalDuringDirectAutomaticRollbackDoesNotStartSecondRollback(): void
    {
        $result = $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            deploy_result_trap_install
            DRYRUN=0
            APP=/fixed/active
            PREV=/fixed/previous
            REL=ea_contract
            WEBUSER=www-data
            CURRENT_SCRIPT_PATH=/fixed/deploy_ea.sh
            ZERO_SURPRISE_CANARY_REPORT=''
            DEPLOY_RESULT_PHASE=switch_complete


            emit_zero_surprise_incident() { :; }
            reload_services() { :; }
            probe_renderer_health() { return 0; }
            probe_deep_health_contract() { return 0; }
            bash() {
              if [[ "${signal_sent:-0}" == "0" ]]; then
                signal_sent=1
                kill -TERM $$
              fi
              printf 'runtime-config-rollback-finished\n'
              return 0
            }
            rollback_after_failure 'redacted failure'
            BASH
            ,
        );

        self::assertSame(31, $result['exit_code'], $result['stderr']);
        self::assertSame(1, substr_count($result['stdout'], "runtime-config-rollback-finished\n"));
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

    public function testDocumentedResultSeamIncludesPreSwitchSigterm(): void
    {
        $documentation = file_get_contents(dirname(__DIR__, 3) . '/docs/deployment.md');

        self::assertIsString($documentation);
        self::assertMatchesRegularExpression('/`143`[^\n]*SIGTERM[^\n]*before[^\n]*live switch/i', $documentation);
    }

    public function testOtherCommonPreSwitchSignalsUseStableDeployFailedExit(): void
    {
        foreach (['HUP', 'INT', 'QUIT'] as $signal) {
            $result = $this->runShell(
                <<<BASH
                source ./deploy_ea.sh
                deploy_result_trap_install
                kill -{$signal} \$\$
                BASH
                ,
            );

            self::assertSame(30, $result['exit_code'], $signal . ': ' . $result['stderr']);
        }
    }

    public function testPreSwitchSignalsDuringFinalizationRemainStableDeployFailed(): void
    {
        foreach (['HUP', 'INT', 'QUIT'] as $signal) {
            $result = $this->runShell(
                str_replace(
                    'SIGNAL_NAME',
                    $signal,
                    <<<'BASH'
                    source ./deploy_ea.sh
                    deploy_result_trap_install
                    injected=0
                    deploy_result_reconcile_switch_phase() {
                      if [[ "$injected" == "0" ]]; then
                        injected=1
                        printf 'finalization-boundary\n'
                        kill -SIGNAL_NAME $$
                      fi
                    }
                    exit 1
                    BASH
                    ,
                ),
            );

            self::assertSame(30, $result['exit_code'], $signal . ': ' . $result['stderr']);
            self::assertSame(1, substr_count($result['stdout'], "finalization-boundary\n"), $signal);
        }
    }

    public function testCallerHangupDuringPartialSwitchReportsRecoveryRequired(): void
    {
        $result = $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            deploy_result_trap_install
            DEPLOY_RESULT_PHASE=switch_partial
            kill -HUP $$
            BASH
            ,
        );

        self::assertSame(32, $result['exit_code'], $result['stderr']);
    }

    public function testCallerHangupAfterCompletedSwitchRunsExistingRollback(): void
    {
        $result = $this->runShell(
            $this->switchHarness(
                <<<'BASH'
                mv() { return 0; }
                rollback_after_failure() {
                  printf 'rollback\n'
                  deploy_result_exit 30
                }
                perform_atomic_switch
                kill -HUP $$
                BASH
                ,
            ),
        );

        self::assertSame(30, $result['exit_code'], $result['stderr']);
        self::assertSame(1, substr_count($result['stdout'], "rollback\n"));
    }

    public function testInterruptAndQuitAfterCompletedSwitchRunExistingRollback(): void
    {
        foreach (['INT', 'QUIT'] as $signal) {
            $result = $this->runShell(
                $this->switchHarness(
                    str_replace(
                        'SIGNAL_NAME',
                        $signal,
                        <<<'BASH'
                        mv() { return 0; }
                        rollback_after_failure() {
                          printf 'rollback\n'
                          deploy_result_exit 30
                        }
                        perform_atomic_switch
                        kill -SIGNAL_NAME $$
                        BASH
                        ,
                    ),
                ),
            );

            self::assertSame(30, $result['exit_code'], $signal . ': ' . $result['stderr']);
            self::assertSame(1, substr_count($result['stdout'], "rollback\n"), $signal);
        }
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
                  deploy_result_exit 30
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
                  deploy_result_exit 31
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
                  deploy_result_exit 30
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

    private function rollbackHarness(
        bool $succeeds,
        bool $signalAfterFinalize = false,
        bool $signalDuringIncident = false,
        bool $signalDuringSummary = false,
    ): string {
        $rollbackResult = $succeeds ? 'return 0' : 'return 1';
        $afterFinalize = $signalAfterFinalize ? 'trap - EXIT; kill -TERM $$' : 'trap - EXIT';
        $incident = $signalDuringIncident ? "printf 'incident-boundary\\n'; kill -TERM \$\$" : ':';
        $summary = $signalDuringSummary
            ? "echo() { if [[ \"\$*\" == '[!] Deployment failed; rollback result summary' ]]; then builtin printf 'summary-boundary\\n'; kill -TERM \$\$; fi; builtin echo \"\$@\"; }"
            : '';

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
        DEPLOY_RESULT_PHASE=switch_complete

        deploy_result_after_finalize() { {$afterFinalize}; }
        emit_zero_surprise_incident() { {$incident}; }
        reload_services() { :; }
        probe_renderer_health() { return 0; }
        probe_deep_health_contract() { return 0; }
        bash() { {$rollbackResult}; }
        {$summary}
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
