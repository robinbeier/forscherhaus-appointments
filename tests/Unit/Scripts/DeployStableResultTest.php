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

    public function testTimingActivePostSwitchFailureReportsVerifiedRollbackSummary(): void
    {
        $result = $this->runShell(
            $this->switchHarness(
                <<<'BASH'
                deploy_timing_init deploy 0 preparation_artifact
                mv() { return 0; }
                rollback_after_failure() {
                  deploy_timing_finish failed rollback_succeeded 30
                  deploy_result_exit 30
                }
                perform_atomic_switch
                false
                BASH
                ,
            ),
        );

        self::assertSame(30, $result['exit_code'], $result['stderr']);
        self::assertStringContainsString('"outcome":"rollback_succeeded"', $result['stdout']);
        self::assertStringContainsString('"exit_code":30', $result['stdout']);
    }

    public function testTimingActivePostSwitchFailureReportsUnverifiableRollbackSummary(): void
    {
        $result = $this->runShell(
            $this->switchHarness(
                <<<'BASH'
                deploy_timing_init deploy 0 preparation_artifact
                mv() { return 0; }
                rollback_after_failure() {
                  deploy_timing_finish failed rollback_failed 31
                  deploy_result_exit 31
                }
                perform_atomic_switch
                false
                BASH
                ,
            ),
        );

        self::assertSame(31, $result['exit_code'], $result['stderr']);
        self::assertStringContainsString('"outcome":"rollback_failed"', $result['stdout']);
        self::assertStringContainsString('"exit_code":31', $result['stdout']);
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

    public function testSignalAfterSuccessfulTimingFinishDoesNotRollbackCompletedDeploy(): void
    {
        $result = $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            deploy_result_trap_install
            DRYRUN=0
            DEPLOY_RESULT_PHASE=switch_complete
            deploy_timing_finish() { trap - EXIT; }
            deploy_result_after_timing_finish() { printf 'success-boundary\n'; kill -TERM $$; }
            rollback_after_failure() {
              printf 'unexpected-rollback\n'
              deploy_result_exit 31
            }
            deploy_result_finish_with_timing 0 ok succeeded
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

    public function testSignalAfterSuccessfulTimingPhaseWriteDoesNotDuplicatePhase(): void
    {
        $result = $this->runShell($this->successfulRealTimingSignalHarness('phase_after_write'));

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame(1, substr_count($result['stdout'], "timing-phase-boundary\n"));
        self::assertSame(1, substr_count($result['stdout'], '"event":"phase"'));
        self::assertSame(1, substr_count($result['stdout'], '"event":"summary"'));
        self::assertStringContainsString('"outcome":"succeeded"', $result['stdout']);
        self::assertStringContainsString('"exit_code":0', $result['stdout']);
        self::assertStringNotContainsString('"outcome":"failed_post_switch"', $result['stdout']);
    }

    public function testSignalBeforeSuccessfulTimingSummaryWriteDoesNotLoseSummary(): void
    {
        $result = $this->runShell($this->successfulRealTimingSignalHarness('summary_before_write'));

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame(1, substr_count($result['stdout'], "timing-summary-boundary\n"));
        self::assertSame(1, substr_count($result['stdout'], '"event":"summary"'));
        self::assertStringContainsString('"outcome":"succeeded"', $result['stdout']);
        self::assertStringContainsString('"exit_code":0', $result['stdout']);
    }

    public function testSignalAfterSuccessfulTimingSummaryWriteDoesNotDuplicateSummary(): void
    {
        $result = $this->runShell($this->successfulRealTimingSignalHarness('summary_after_write'));

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame(1, substr_count($result['stdout'], "timing-summary-boundary\n"));
        self::assertSame(1, substr_count($result['stdout'], '"event":"summary"'));
        self::assertStringContainsString('"outcome":"succeeded"', $result['stdout']);
        self::assertStringContainsString('"exit_code":0', $result['stdout']);
    }

    public function testExistingRollbackSuccessAndFailureExitsRemainStable(): void
    {
        $success = $this->runShell($this->rollbackHarness(true));
        $failure = $this->runShell($this->rollbackHarness(false));

        self::assertSame(30, $success['exit_code'], $success['stderr']);
        self::assertSame(31, $failure['exit_code'], $failure['stderr']);
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

    public function testRealTimingSummaryRemainsBoundToRollbackResultWhenSignalArrivesBeforeExit(): void
    {
        foreach (
            [[true, 30, 'rollback_succeeded'], [false, 31, 'rollback_failed']]
            as [$rollbackSucceeds, $expectedExitCode, $expectedOutcome]
        ) {
            $result = $this->runShell($this->rollbackWithRealTimingAndSignalHarness($rollbackSucceeds));

            self::assertSame($expectedExitCode, $result['exit_code'], $result['stderr']);
            self::assertSame(1, substr_count($result['stdout'], "signal-boundary\n"));
            self::assertSame(1, substr_count($result['stdout'], '"event":"summary"'));
            self::assertStringContainsString('"outcome":"' . $expectedOutcome . '"', $result['stdout']);
            self::assertStringContainsString('"exit_code":' . $expectedExitCode, $result['stdout']);
            self::assertStringNotContainsString('"outcome":"failed_pre_switch"', $result['stdout']);
        }
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
            deploy_timing_begin_rollback() { printf 'rollback-start\n'; }
            deploy_timing_finish() { :; }
            emit_zero_surprise_incident() { :; }
            reload_services() { :; }
            restart_renderer_service() { return 0; }
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
        self::assertSame(1, substr_count($result['stdout'], "rollback-start\n"));
        self::assertSame(1, substr_count($result['stdout'], "runtime-config-rollback-finished\n"));
    }

    public function testRealTimingDoesNotReplaceRecoverySignalHandlers(): void
    {
        foreach (['HUP', 'INT', 'QUIT', 'TERM'] as $signal) {
            $result = $this->runShell($this->realTimingRecoverySignalHarness($signal, false));

            self::assertSame(31, $result['exit_code'], $signal . ': ' . $result['stderr']);
            self::assertSame(1, substr_count($result['stdout'], "real-timing-recovery-finished\n"), $signal);
            self::assertSame(1, substr_count($result['stdout'], '"event":"summary"'), $signal);
            self::assertStringContainsString('"outcome":"rollback_failed"', $result['stdout'], $signal);
        }
    }

    public function testDeferredRealTimingSignalsDoNotPreemptRecovery(): void
    {
        foreach (['HUP', 'INT', 'QUIT', 'TERM'] as $signal) {
            $result = $this->runShell($this->realTimingRecoverySignalHarness($signal, true));

            self::assertSame(31, $result['exit_code'], $signal . ': ' . $result['stderr']);
            self::assertSame(1, substr_count($result['stdout'], "rollback-timing-write-boundary\n"), $signal);
            self::assertSame(1, substr_count($result['stdout'], "real-timing-recovery-finished\n"), $signal);
            self::assertSame(1, substr_count($result['stdout'], '"event":"summary"'), $signal);
            self::assertStringContainsString('"outcome":"rollback_failed"', $result['stdout'], $signal);
        }
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

    public function testPreSwitchSignalsDuringTimingExitRemainStableDeployFailed(): void
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
                        printf 'timing-exit-boundary\n'
                        kill -SIGNAL_NAME $$
                      fi
                    }
                    exit 1
                    BASH
                    ,
                ),
            );

            self::assertSame(30, $result['exit_code'], $signal . ': ' . $result['stderr']);
            self::assertSame(1, substr_count($result['stdout'], "timing-exit-boundary\n"), $signal);
        }
    }

    public function testPreSwitchSignalsDuringFailedTimingPhaseStillCommitSummary(): void
    {
        foreach (['HUP', 'INT', 'QUIT', 'TERM'] as $signal) {
            $result = $this->runShell($this->failedRealTimingSignalHarness($signal, 'phase'));

            self::assertSame(30, $result['exit_code'], $signal . ': ' . $result['stderr']);
            self::assertSame(1, substr_count($result['stdout'], "failed-timing-phase-boundary\n"), $signal);
            self::assertSame(1, substr_count($result['stdout'], '"event":"phase"'), $signal);
            self::assertSame(1, substr_count($result['stdout'], '"event":"summary"'), $signal);
            self::assertStringContainsString('"outcome":"failed_pre_switch"', $result['stdout'], $signal);
            self::assertStringContainsString('"exit_code":30', $result['stdout'], $signal);
        }
    }

    public function testPreSwitchSignalsDuringFailedTimingSummaryPreserveStableExit(): void
    {
        foreach (['HUP', 'INT', 'QUIT', 'TERM'] as $signal) {
            $result = $this->runShell($this->failedRealTimingSignalHarness($signal, 'summary'));

            self::assertSame(30, $result['exit_code'], $signal . ': ' . $result['stderr']);
            self::assertSame(1, substr_count($result['stdout'], "failed-timing-summary-boundary\n"), $signal);
            self::assertSame(1, substr_count($result['stdout'], '"event":"phase"'), $signal);
            self::assertSame(1, substr_count($result['stdout'], '"event":"summary"'), $signal);
            self::assertStringContainsString('"outcome":"failed_pre_switch"', $result['stdout'], $signal);
            self::assertStringContainsString('"exit_code":30', $result['stdout'], $signal);
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
        bool $signalAfterTimingFinish = false,
        bool $signalDuringIncident = false,
        bool $signalDuringSummary = false,
    ): string {
        $rollbackResult = $succeeds ? 'return 0' : 'return 1';
        $timingFinish = $signalAfterTimingFinish ? 'trap - EXIT; kill -TERM $$' : 'trap - EXIT';
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
        deploy_timing_begin_rollback() { :; }
        deploy_timing_finish() { {$timingFinish}; }
        emit_zero_surprise_incident() { {$incident}; }
        reload_services() { :; }
        restart_renderer_service() { return 0; }
        probe_renderer_health() { return 0; }
        probe_deep_health_contract() { return 0; }
        bash() { {$rollbackResult}; }
        {$summary}
        rollback_after_failure 'redacted failure'
        BASH;
    }

    private function rollbackWithRealTimingAndSignalHarness(bool $succeeds): string
    {
        $rollbackResult = $succeeds ? 'return 0' : 'return 1';

        return str_replace(
            'ROLLBACK_RESULT',
            $rollbackResult,
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
            deploy_monotonic_ms() { printf '1000\n'; }
            deploy_timing_new_run_id() { printf '00000000-0000-4000-8000-000000000001\n'; }
            emit_zero_surprise_incident() { :; }
            reload_services() { :; }
            restart_renderer_service() { return 0; }
            probe_renderer_health() { return 0; }
            probe_deep_health_contract() { return 0; }
            bash() { ROLLBACK_RESULT; }
            deploy_result_after_timing_finish() { printf 'signal-boundary\n'; kill -TERM $$; }
            deploy_timing_init deploy 0 postdeploy_validation
            rollback_after_failure 'redacted failure'
            BASH
            ,
        );
    }

    private function realTimingRecoverySignalHarness(string $signal, bool $duringTimingWrite): string
    {
        $timingInjection = $duringTimingWrite
            ? <<<'BASH'
            deploy_timing_emit_record() {
              builtin printf '%s\n' "$1"
              if [[ "$1" == *'"event":"phase"'* && "${signal_sent:-0}" == "0" ]]; then
                signal_sent=1
                printf 'rollback-timing-write-boundary\n'
                kill -SIGNAL $$
              fi
            }
            BASH
            : '';
        $rollbackSignal = $duringTimingWrite
            ? ''
            : <<<'BASH'
              if [[ "${signal_sent:-0}" == "0" ]]; then
                signal_sent=1
                kill -SIGNAL $$
              fi
            BASH;

        return str_replace(
            ['__TIMING_INJECTION__', '__ROLLBACK_SIGNAL__', 'SIGNAL'],
            [$timingInjection, $rollbackSignal, $signal],
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
            deploy_monotonic_ms() { printf '1000\n'; }
            deploy_timing_new_run_id() { printf '00000000-0000-4000-8000-000000000001\n'; }
            emit_zero_surprise_incident() { :; }
            reload_services() { :; }
            restart_renderer_service() { return 0; }
            probe_renderer_health() { return 0; }
            probe_deep_health_contract() { return 0; }
            bash() {
            __ROLLBACK_SIGNAL__
              printf 'real-timing-recovery-finished\n'
              return 0
            }
            __TIMING_INJECTION__
            deploy_timing_init deploy 0 postdeploy_validation
            rollback_after_failure 'redacted failure'
            BASH
            ,
        );
    }

    private function failedRealTimingSignalHarness(string $signal, string $event): string
    {
        return str_replace(
            ['__SIGNAL__', '__EVENT__'],
            [$signal, $event],
            <<<'BASH'
            source ./deploy_ea.sh
            deploy_result_trap_install
            DRYRUN=0
            injected=0
            deploy_monotonic_ms() { printf '1000\n'; }
            deploy_timing_new_run_id() { printf '00000000-0000-4000-8000-000000000001\n'; }
            deploy_timing_emit_record() {
              builtin printf '%s\n' "$1"
              if [[ "$1" == *'"event":"__EVENT__"'* && "$injected" == "0" ]]; then
                injected=1
                printf 'failed-timing-__EVENT__-boundary\n'
                kill -__SIGNAL__ $$
              fi
            }
            deploy_timing_init deploy 0 preparation_artifact
            false
            BASH
            ,
        );
    }

    private function successfulRealTimingSignalHarness(string $boundary): string
    {
        $injection = match ($boundary) {
            'phase_after_write' => <<<'BASH'
            deploy_timing_emit_record() {
              builtin printf '%s\n' "$1"
              if [[ "$1" == *'"event":"phase"'* && "$injected" == "0" ]]; then
                injected=1
                printf 'timing-phase-boundary\n'
                kill -TERM $$
              fi
            }
            BASH,
            'summary_before_write' => <<<'BASH'
            deploy_timing_emit_record() {
              if [[ "$1" == *'"event":"summary"'* && "$injected" == "0" ]]; then
                injected=1
                printf 'timing-summary-boundary\n'
                kill -TERM $$
              fi
              builtin printf '%s\n' "$1"
            }
            BASH,
            'summary_after_write' => <<<'BASH'
            deploy_timing_emit_record() {
              builtin printf '%s\n' "$1"
              if [[ "$1" == *'"event":"summary"'* && "$injected" == "0" ]]; then
                injected=1
                printf 'timing-summary-boundary\n'
                kill -TERM $$
              fi
            }
            BASH,
            default => throw new \InvalidArgumentException('Unknown timing signal boundary.'),
        };

        return str_replace(
            '__INJECTION__',
            $injection,
            <<<'BASH'
            source ./deploy_ea.sh
            deploy_result_trap_install
            DRYRUN=0
            DEPLOY_RESULT_PHASE=switch_complete
            injected=0
            deploy_monotonic_ms() { printf '1000\n'; }
            deploy_timing_new_run_id() { printf '00000000-0000-4000-8000-000000000001\n'; }
            rollback_after_failure() {
              printf 'unexpected-rollback\n'
              deploy_result_exit 31
            }
            __INJECTION__
            deploy_timing_init deploy 0 postdeploy_validation
            deploy_result_finish_with_timing 0 ok succeeded
            BASH
            ,
        );
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
