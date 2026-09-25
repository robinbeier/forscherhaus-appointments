<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class DeployStableResultTest extends TestCase
{
    public function testPendingRecoveryGuardBlocksDirectPrimitiveWithoutBoundIdentity(): void
    {
        $result = $this->runShell(
            <<<'BASH'
            set -eu
            fixture="$(mktemp -d /root/deploy-bound-guard.XXXXXX)"
            release="ea_guard_direct_${BASHPID}"
            run_id='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
            intent="/root/fh-deploy-intent-${release}.json"
            trap 'rm -rf "$fixture"; rm -f "$intent"' EXIT
            chmod 700 "$fixture"
            printf '%s\n' "{\"bindings\":{},\"commit\":\"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\",\"release\":\"$release\",\"run_id\":\"$run_id\",\"schema\":\"bound_release_deploy_intent.v1\"}" > "$intent"
            chmod 600 "$intent"
            printf '%s\n' "{\"expected_active_release\":\"ea_previous\",\"intent_path\":\"$intent\",\"release\":\"$release\",\"result_path\":\"/root/result.json\",\"run_id\":\"$run_id\",\"schema\":\"bound_release_deploy_recovery_guard.v1\"}" > "$fixture/guard"
            chmod 600 "$fixture/guard"
            exec 8< "$fixture/guard"
            source ./deploy_ea.sh
            RECOVERY_GUARD="$fixture/guard"
            REL="$release"
            DEPLOY_RESULT_RECEIPT_PATH='/root/result.json'
            ORDINARY_CHANGE_LOCK_FD=8
            unset BOUND_RELEASE_RUN_ID
            ! ordinary_assert_bound_recovery_guard
            BASH
            ,
        );

        self::assertSame(0, $result['exit_code'], $result['stdout'] . $result['stderr']);
    }

    public function testRejectedGuardAdmissionCannotActivateOrPublishProtectedReceipt(): void
    {
        $result = $this->runShell(
            <<<'BASH'
            set -eu
            fixture="$(mktemp -d /root/deploy-bound-guard.XXXXXX)"
            trap 'rm -rf "$fixture"' EXIT
            chmod 700 "$fixture"
            printf 'pending\n' > "$fixture/guard"
            chmod 600 "$fixture/guard"
            : > "$fixture/lock"
            chmod 600 "$fixture/lock"
            exec 10< "$fixture/lock"
            source ./deploy_ea.sh
            RECOVERY_GUARD="$fixture/guard"
            REL='ea_guard_rejected'
            DEPLOY_RESULT_RECEIPT_PATH="$fixture/result.json"
            ORDINARY_CHANGE_LOCK_FD=10
            DEPLOY_RESULT_RECEIPT_ACTIVE=0
            unset BOUND_RELEASE_RUN_ID
            ! ordinary_assert_bound_recovery_guard
            [[ "$DEPLOY_RESULT_RECEIPT_ACTIVE" == 0 ]]
            [[ ! -e "$DEPLOY_RESULT_RECEIPT_PATH" ]]
            BASH
            ,
        );

        self::assertSame(0, $result['exit_code'], $result['stdout'] . $result['stderr']);
    }

    public function testFullEntryRejectsPendingGuardBeforePublishingResult(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            self::markTestSkipped('root fixture required');
        }
        if (!is_file('/.dockerenv') || str_starts_with((string) gethostname(), 'booking-server')) {
            self::markTestSkipped('fixed production-path fixture restricted to isolated Docker tests');
        }
        $guard = '/root/fh-deploy-recovery-pending.v1.json';
        if (is_file($guard) || is_link($guard)) {
            self::markTestSkipped('fixed recovery guard already exists');
        }
        $lockDirectory = '/var/lib/fh-deploy-orchestrator/locks';
        $lock = $lockDirectory . '/fh-production-change.lock';
        $createdDirectory = false;
        $createdLock = false;
        $guardIdentity = null;
        $lockIdentity = null;
        if (!is_dir($lockDirectory)) {
            if (!mkdir($lockDirectory, 0700, true)) {
                self::markTestSkipped('production lock fixture directory unavailable');
            }
            $createdDirectory = true;
        }
        if (!is_file($lock)) {
            $lockStream = @fopen($lock, 'x+b');
            if (!is_resource($lockStream)) {
                if ($createdDirectory) {
                    @rmdir($lockDirectory);
                }
                self::markTestSkipped('production lock fixture unavailable');
            }
            fflush($lockStream);
            if (function_exists('fsync')) {
                fsync($lockStream);
            }
            fclose($lockStream);
            if (!chmod($lock, 0600)) {
                if ($createdDirectory) {
                    @unlink($lock);
                    @rmdir($lockDirectory);
                }
                self::markTestSkipped('production lock fixture unavailable');
            }
            $lockIdentity = lstat($lock);
            if (!is_array($lockIdentity)) {
                self::markTestSkipped('production lock fixture unavailable');
            }
            $createdLock = true;
        }
        $result = '/root/fh-bound-entry-result-' . getmypid() . '.json';
        $trustedScript = '/root/fh-bound-entry-deploy-' . getmypid() . '.sh';
        $trustedScriptIdentity = null;
        try {
            $sourceScript = dirname(__DIR__, 3) . '/deploy_ea.sh';
            $sourceContents = file_get_contents($sourceScript);
            $scriptStream = @fopen($trustedScript, 'x+b');
            if (!is_string($sourceContents) || !is_resource($scriptStream)) {
                self::markTestSkipped('root-controlled deploy script fixture unavailable');
            }
            fwrite($scriptStream, $sourceContents);
            fflush($scriptStream);
            if (function_exists('fsync')) {
                fsync($scriptStream);
            }
            fclose($scriptStream);
            if (!chmod($trustedScript, 0700)) {
                self::markTestSkipped('root-controlled deploy script fixture unavailable');
            }
            $trustedScriptIdentity = lstat($trustedScript);
            self::assertIsArray($trustedScriptIdentity);
            $guardStream = @fopen($guard, 'x+b');
            if (!is_resource($guardStream)) {
                self::markTestSkipped('fixed recovery guard became occupied');
            }
            fwrite($guardStream, "pending\n");
            fflush($guardStream);
            if (function_exists('fsync')) {
                fsync($guardStream);
            }
            fclose($guardStream);
            chmod($guard, 0600);
            $guardIdentity = lstat($guard);
            self::assertIsArray($guardIdentity);
            $run = $this->runCommand([
                'bash',
                $trustedScript,
                '--rel',
                'ea_guard_full_entry_' . getmypid(),
                '--result-file',
                $result,
                '--reload',
                'php8.2-fpm',
                '--require-zero-surprise',
                '0',
                '--zero-surprise-canary-enabled',
                '0',
                '--zero-surprise-breakglass-file',
                '/root/fh-bound-entry-ack.json',
            ]);
            self::assertSame(30, $run['exit_code'], $run['stdout'] . $run['stderr']);
            self::assertStringContainsString(
                'Pending bound-release recovery requires the matching guarded invocation.',
                $run['stdout'] . $run['stderr'],
            );
            self::assertFileDoesNotExist($result);
        } finally {
            $currentGuard = @lstat($guard);
            if (
                is_array($guardIdentity) &&
                is_array($currentGuard) &&
                $currentGuard['dev'] === $guardIdentity['dev'] &&
                $currentGuard['ino'] === $guardIdentity['ino'] &&
                $currentGuard['uid'] === $guardIdentity['uid'] &&
                $currentGuard['gid'] === $guardIdentity['gid'] &&
                $currentGuard['mode'] === $guardIdentity['mode'] &&
                $currentGuard['nlink'] === $guardIdentity['nlink']
            ) {
                @unlink($guard);
            }
            $currentTrustedScript = @lstat($trustedScript);
            if (
                is_array($trustedScriptIdentity) &&
                is_array($currentTrustedScript) &&
                $currentTrustedScript['dev'] === $trustedScriptIdentity['dev'] &&
                $currentTrustedScript['ino'] === $trustedScriptIdentity['ino'] &&
                $currentTrustedScript['uid'] === $trustedScriptIdentity['uid'] &&
                $currentTrustedScript['gid'] === $trustedScriptIdentity['gid'] &&
                $currentTrustedScript['mode'] === $trustedScriptIdentity['mode'] &&
                $currentTrustedScript['nlink'] === $trustedScriptIdentity['nlink']
            ) {
                @unlink($trustedScript);
            }
            if ($createdLock) {
                $currentLock = @lstat($lock);
                if (
                    is_array($lockIdentity) &&
                    is_array($currentLock) &&
                    $currentLock['dev'] === $lockIdentity['dev'] &&
                    $currentLock['ino'] === $lockIdentity['ino'] &&
                    $currentLock['uid'] === $lockIdentity['uid'] &&
                    $currentLock['gid'] === $lockIdentity['gid'] &&
                    $currentLock['mode'] === $lockIdentity['mode'] &&
                    $currentLock['nlink'] === $lockIdentity['nlink']
                ) {
                    @unlink($lock);
                }
            }
            if ($createdDirectory) {
                @rmdir($lockDirectory);
                @rmdir(dirname($lockDirectory));
            }
        }
    }

    public function testPendingRecoveryGuardAdmitsOnlyMatchingBoundChild(): void
    {
        $result = $this->runShell(
            <<<'BASH'
            set -eu
            fixture="$(mktemp -d /root/deploy-bound-guard.XXXXXX)"
            release="ea_guard_child_${BASHPID}"
            run_id='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
            intent="/root/fh-deploy-intent-${release}.json"
            trap 'rm -rf "$fixture"; rm -f "$intent"' EXIT
            chmod 700 "$fixture"
            printf '%s\n' "{\"bindings\":{},\"commit\":\"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb\",\"release\":\"$release\",\"run_id\":\"$run_id\",\"schema\":\"bound_release_deploy_intent.v1\"}" > "$intent"
            chmod 600 "$intent"
            printf '%s\n' "{\"expected_active_release\":\"ea_previous\",\"intent_path\":\"$intent\",\"release\":\"$release\",\"result_path\":\"/root/result.json\",\"run_id\":\"$run_id\",\"schema\":\"bound_release_deploy_recovery_guard.v1\"}" > "$fixture/guard"
            chmod 600 "$fixture/guard"
            : > "$fixture/lock"
            chmod 600 "$fixture/lock"
            exec 10< "$fixture/lock"
            flock -x 10
            source ./deploy_ea.sh
            RECOVERY_GUARD="$fixture/guard"
            REL="$release"
            DEPLOY_RESULT_RECEIPT_PATH='/root/result.json'
            ORDINARY_CHANGE_LOCK_FD=10
            BOUND_RELEASE_RUN_ID='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
            ordinary_assert_bound_recovery_guard
            BOUND_RELEASE_RUN_ID='cccccccccccccccccccccccccccccccc'
            ! ordinary_assert_bound_recovery_guard
            [[ "$(stat -Lc '%d:%i' -- /proc/$$/fd/10)" == "$(stat -c '%d:%i' -- "$fixture/lock")" ]]
            ! flock -n "$fixture/lock" true
            BASH
            ,
        );

        self::assertSame(0, $result['exit_code'], $result['stdout'] . $result['stderr']);
    }

    public function testZeroSurpriseStageRuntimePreparesConfiguredRuntimeCacheDirectory(): void
    {
        $result = $this->runShell(
            <<<'BASH'
            set -eu
            fixture="$(mktemp -d)"
            trap 'rm -rf "$fixture"' EXIT
            mkdir -p "$fixture/stage/scripts/release-gate"
            printf '<?php exit(0);\n' > "$fixture/stage/scripts/release-gate/prepare_zero_surprise_stage_config.php"
            printf 'sample\n' > "$fixture/stage/config-sample.php"
            source ./deploy_ea.sh
            STAGE_ROOT="$fixture/stage"
            REQUIRE_ZERO_SURPRISE=1
            DRYRUN=0
            read_zero_surprise_predeploy_base_url() { echo 'http://fixture.test/'; }
            prepare_zero_surprise_stage_runtime
            test -d "$STAGE_ROOT/storage/logs/release-gate"
            test -d "$STAGE_ROOT/storage/cache"
            BASH
            ,
        );

        self::assertSame(0, $result['exit_code'], $result['stdout'] . $result['stderr']);
    }

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

    public function testStagePermissionPolicyRejectsCodeLinksBeforeMutatingOutsideTargets(): void
    {
        $result = $this->runShell(
            <<<'BASH'
            set -Eeuo pipefail
            source ./deploy_ea.sh
            WEBUSER=www-data
            DRYRUN=0
            REQUIRE_ZERO_SURPRISE=0

            for kind in symlink hardlink; do
              fixture="$(mktemp -d)"
              trap 'rm -rf "$fixture"' EXIT
              chmod 755 "$fixture"
              STAGE_ROOT="$fixture/stage"
              mkdir -p "$STAGE_ROOT/application" "$STAGE_ROOT/storage"
              printf 'outside-original\n' > "$fixture/outside-target"
              chmod 600 "$fixture/outside-target"
              if [[ "$kind" == symlink ]]; then
                ln -s "$fixture/outside-target" "$STAGE_ROOT/application/link.php"
              else
                ln "$fixture/outside-target" "$STAGE_ROOT/application/link.php"
              fi

              if apply_stage_permission_policy; then
                exit 1
              fi
              [[ "$(cat "$fixture/outside-target")" == 'outside-original' ]]
              [[ "$(stat -c '%a' "$fixture/outside-target")" == 600 ]]
              rm -rf "$fixture"
              trap - EXIT
            done
            BASH
            ,
        );

        self::assertSame(0, $result['exit_code'], $result['stdout'] . $result['stderr']);
    }

    public function testStorageSyncAndStageNormalizationKeepSessionFilesPrivate(): void
    {
        if ((int) trim((string) shell_exec('id -u')) !== 0) {
            self::markTestSkipped('Root is required to verify release ownership transitions.');
        }
        if (posix_getpwnam('www-data') === false) {
            self::markTestSkipped('The www-data runtime account is required to verify the write boundary.');
        }

        $result = $this->runShell(
            <<<'BASH'
            set -Eeuo pipefail
            fixture="$(mktemp -d)"
            trap 'rm -rf "$fixture"' EXIT
            chmod 755 "$fixture"
            source ./deploy_ea.sh
            APP="$fixture/app"
            STAGE_ROOT="$fixture/stage"
            WEBUSER=www-data
            rsync() {
              [[ "$1" == '-a' && "$2" == '--' ]]
              # Match rsync -a mode copying without claiming hardlink preservation.
              cp -a --no-preserve=links -- "$3/." "$4/"
            }
            mkdir -p "$APP/storage/sessions" "$STAGE_ROOT/storage" "$fixture/outside"
            mkdir -p "$STAGE_ROOT/application" "$STAGE_ROOT/storage/logs" "$STAGE_ROOT/storage/cache"
            mkdir -p "$STAGE_ROOT/application/helpers" "$STAGE_ROOT/vendor/ezyang/htmlpurifier"
            cp -a --no-preserve=links "$PWD/application/helpers/html_helper.php" "$STAGE_ROOT/application/helpers/html_helper.php"
            cp -a --no-preserve=links "$PWD/vendor/ezyang/htmlpurifier/library" "$STAGE_ROOT/vendor/ezyang/htmlpurifier/"
            printf 'code\n' > "$STAGE_ROOT/application/index.php"
            printf 'runtime\n' > "$STAGE_ROOT/storage/logs/runtime.log"
            printf 'config\n' > "$STAGE_ROOT/config.php"
            chmod 666 "$STAGE_ROOT/application/index.php"
            chown www-data:www-data "$STAGE_ROOT/application/index.php"
            chmod 600 "$STAGE_ROOT/storage/logs/runtime.log"
            chmod 440 "$STAGE_ROOT/config.php"
            printf 'private\n' > "$APP/storage/sessions/ea_sessionaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
            printf 'also private\n' > "$APP/storage/sessions/ea_sessionbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
            printf 'foreign\n' > "$APP/storage/sessions/foreign-file"
            printf 'hardlinked\n' > "$APP/storage/sessions/ea_session_hardlink"
            ln "$APP/storage/sessions/ea_session_hardlink" "$APP/storage/sessions/ea_session_hardlink_alias"
            printf 'ordinary\n' > "$APP/storage/ordinary.txt"
            printf 'outside\n' > "$fixture/outside/target.txt"
            chmod 600 "$APP/storage/sessions/ea_sessionaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
            chmod 644 "$APP/storage/sessions/ea_sessionbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb" "$APP/storage/sessions/foreign-file" "$APP/storage/sessions/ea_session_hardlink" "$APP/storage/ordinary.txt" "$fixture/outside/target.txt"
            ln -s "$fixture/outside/target.txt" "$APP/storage/sessions/ea_session_link"
            printf 'stage\n' > "$STAGE_ROOT/storage/sessions-placeholder"
            mkdir -p "$STAGE_ROOT/storage/sessions"
            printf 'predeploy\n' > "$STAGE_ROOT/storage/sessions/ea_sessioncccccccccccccccccccccccccccccccc"
            chmod 600 "$STAGE_ROOT/storage/sessions/ea_sessioncccccccccccccccccccccccccccccccc"
            prepare_zero_surprise_stage_runtime() { :; }
            REQUIRE_ZERO_SURPRISE=0
            prepare_predeploy_stage_permissions
            [[ "$(stat -c '%a' "$STAGE_ROOT/storage/sessions/ea_sessioncccccccccccccccccccccccccccccccc")" == 600 ]]
            sync_live_storage_to_stage
            printf 'outside-hardlink\n' > "$fixture/outside/hard-target"
            chmod 600 "$fixture/outside/hard-target"
            ln "$fixture/outside/hard-target" "$STAGE_ROOT/storage/sessions/ea_session_outside_hard"
            normalize_stage_permissions
            [[ "$(stat -c '%u:%g:%a' "$STAGE_ROOT/application/index.php")" == "0:0:644" ]]
            [[ "$(stat -c '%a' "$STAGE_ROOT/config.php")" == 440 ]]
            [[ "$(stat -c '%a' "$STAGE_ROOT/storage/logs/runtime.log")" == 644 ]]
            runuser -u www-data -- test -w "$STAGE_ROOT/storage/logs/runtime.log"
            runuser -u www-data -- sh -c "printf 'appended\\n' >> '$STAGE_ROOT/storage/logs/runtime.log'"
            pure_html_output="$(runuser -u www-data -- env STAGE_ROOT="$STAGE_ROOT" php -d display_errors=stderr -d log_errors=0 -r '
              define("BASEPATH", getenv("STAGE_ROOT") . "/system/");
              set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
                throw new ErrorException($message, 0, $severity, $file, $line);
              });
              require getenv("STAGE_ROOT") . "/vendor/ezyang/htmlpurifier/library/HTMLPurifier.auto.php";
              function config(string $key, mixed $default = null): mixed {
                return $key === "cache_path" ? getenv("STAGE_ROOT") . "/storage/cache" : $default;
              }
              require getenv("STAGE_ROOT") . "/application/helpers/html_helper.php";
              echo pure_html("<p>Allowed</p><script>alert(1)</script>");
            ')"
            [[ "$pure_html_output" == '<p>Allowed</p>' ]]
            find "$STAGE_ROOT/storage/cache" -type f -print -quit | grep -q .
            ! runuser -u www-data -- test -w "$STAGE_ROOT/vendor/ezyang/htmlpurifier/library/HTMLPurifier/DefinitionCache/Serializer"
            ! runuser -u www-data -- test -w "$STAGE_ROOT/application/index.php"
            ! runuser -u www-data -- sh -c "printf 'must-not-write\\n' > '$STAGE_ROOT/application/index.php'"
            [[ "$(stat -c '%a' "$STAGE_ROOT/storage/sessions/ea_session_outside_hard")" == 600 ]]
            [[ "$(stat -c '%a' "$fixture/outside/hard-target")" == 600 ]]
            stat_mode() { stat -c '%a' "$1"; }
            printf 'private=%s\n' "$(stat_mode "$STAGE_ROOT/storage/sessions/ea_sessionaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa")"
            printf 'public=%s\n' "$(stat_mode "$STAGE_ROOT/storage/sessions/ea_sessionbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb")"
            printf 'foreign=%s\n' "$(stat_mode "$STAGE_ROOT/storage/sessions/foreign-file")"
            printf 'hardlink=%s\n' "$(stat_mode "$STAGE_ROOT/storage/sessions/ea_session_hardlink")"
            printf 'hardlink-alias=%s\n' "$(stat_mode "$STAGE_ROOT/storage/sessions/ea_session_hardlink_alias")"
            printf 'ordinary=%s\n' "$(stat_mode "$STAGE_ROOT/storage/ordinary.txt")"
            printf 'target=%s\n' "$(stat_mode "$fixture/outside/target.txt")"
            [[ -L "$STAGE_ROOT/storage/sessions/ea_session_link" ]]
            BASH
            ,
        );

        self::assertSame(0, $result['exit_code'], $result['stdout'] . $result['stderr']);
        self::assertSame(
            "private=600\npublic=644\nforeign=644\nhardlink=644\nhardlink-alias=644\nordinary=644\ntarget=644\n",
            $result['stdout'],
        );
    }

    public function testExtractedPreSwitchArchiveFailuresStopBeforeLiveSwitch(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/deploy_ea.sh');
        $start = strpos($source, '[[ -f "$ARCHIVE" ]] || die');
        $end = strpos($source, "\nvalidate_stage_release_artifact\n", $start === false ? 0 : $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $preSwitch = substr($source, $start, $end - $start);
        self::assertIsString($preSwitch);

        $script = <<<'BASH'
        set -eu
        fixture="$(mktemp -d)"
        trap 'rm -rf "$fixture"' EXIT
        mkdir -p "$fixture/missing/application" "$fixture/valid/application/config" "$fixture/live"
        printf 'placeholder\n' > "$fixture/missing/application/readme.txt"
        printf '<?php\n' > "$fixture/valid/application/config/config.php"
        printf '<?php\n' > "$fixture/live/config.php"
        tar -czf "$fixture/missing-config.tar.gz" -C "$fixture/missing" .
        tar -czf "$fixture/valid.tar.gz" -C "$fixture/valid" .
        printf 'not a gzip archive\n' > "$fixture/corrupt.tar.gz"

        run_probe() (
          local label="$1"
          local archive="$2"
          local stage="$fixture/stage-$label"
          local marker="$fixture/switch-$label"
          rm -rf "$stage" "$marker"
          source ./deploy_ea.sh
          deploy_result_trap_install
          ARCHIVE="$archive"
          APP="$fixture/live"
          PREV="$fixture/previous-$label"
          STAGE="$stage"
          DRYRUN=0
          REQUIRE_ZERO_SURPRISE=0
          require_command() { :; }
          initialize_service_control() { :; }
          perform_atomic_switch() { printf 'live-switch\n' > "$marker"; }
          PRE_SWITCH_BODY
          perform_atomic_switch
        )

        run_probe valid "$fixture/valid.tar.gz"
        [[ -f "$fixture/switch-valid" ]]

        set +e
        run_probe missing-config "$fixture/missing-config.tar.gz"
        missing_status=$?
        run_probe corrupt "$fixture/corrupt.tar.gz"
        corrupt_status=$?
        set -e
        [[ "$missing_status" -eq 30 && "$corrupt_status" -eq 30 ]]
        [[ ! -e "$fixture/switch-missing-config" && ! -e "$fixture/switch-corrupt" ]]
        BASH;
        $script = str_replace('PRE_SWITCH_BODY', $preSwitch, $script);

        $result = $this->runShell($script);
        self::assertSame(0, $result['exit_code'], $result['stdout'] . $result['stderr']);
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

    public function testRendererHealthRejectsTransportFailureWithHttp200AndRetriesAfterTimeout(): void
    {
        $result = $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            DRYRUN=0
            RENDERER_HEALTH_RETRIES=2
            RENDERER_HEALTH_SLEEP_SECONDS=1
            calls_file=$(mktemp)
            printf '0' > "$calls_file"
            trap 'rm -f "$calls_file"' EXIT
            sleep() { printf 'sleep:%s' "$1"; }
            curl() {
              [[ "$1" == '--connect-timeout' && "$2" == '3' && "$3" == '--max-time' && "$4" == '10' && "$5" == '-sS' && "$6" == '-o' && "$7" == '/dev/null' && "$8" == '-w' && "$9" == '%{http_code}' ]] || return 97
              calls=$(( $(<"$calls_file") + 1 ))
              printf '%s' "$calls" > "$calls_file"
              if [[ "$calls" -eq 1 ]]; then printf '200'; return 28; fi
              printf '200'
            }
            probe_renderer_health
            printf 'calls=%s' "$(<"$calls_file")"
            BASH
            ,
        );

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertStringContainsString('curl exit 28', $result['stdout']);
        self::assertStringContainsString('sleep:1', $result['stdout']);
        self::assertStringContainsString('calls=2', $result['stdout']);
        self::assertStringNotContainsString('000000', $result['stdout']);
    }

    public function testRendererHealthSleepsOnlyBetweenExhaustedAttempts(): void
    {
        $result = $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            DRYRUN=0
            RENDERER_HEALTH_RETRIES=3
            RENDERER_HEALTH_SLEEP_SECONDS=1
            sleeps=0
            sleep() { sleeps=$((sleeps + 1)); }
            curl() { printf '503'; return 0; }
            if probe_renderer_health; then exit 1; fi
            printf 'sleeps=%s' "$sleeps"
            BASH
            ,
        );

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertStringContainsString('sleeps=2', $result['stdout']);
    }

    public function testDeepHealthRetriesHttp200TransportTimeoutThenAcceptsValidContract(): void
    {
        $result = $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            DRYRUN=0
            DEEP_HEALTH_RETRIES=2
            calls_file=$(mktemp)
            printf '0' > "$calls_file"
            trap 'rm -f "$calls_file"' EXIT
            sleeps=0
            sleep() { sleeps=$((sleeps + 1)); }
            read_healthz_token() { printf token; }
            curl() {
              [[ "$1" == '--connect-timeout' && "$2" == '3' && "$3" == '--max-time' && "$4" == '30' && "$5" == '-sS' && "$6" == '-o' && "$8" == '-w' && "$9" == '%{http_code}' && "${10}" == '-H' && "${11}" == 'X-Health-Token: token' ]] || return 97
              local output=''
              while (($# > 0)); do
                if [[ "$1" == '-o' ]]; then output="$2"; shift 2; else shift; fi
              done
              printf '%s' '{"status":"ok","checks":{"pdf_renderer":{"ok":true}}}' > "$output"
              calls=$(( $(<"$calls_file") + 1 ))
              printf '%s' "$calls" > "$calls_file"
              printf '200'
              [[ "$calls" -eq 1 ]] && return 28
              return 0
            }
            if ! probe_deep_health_contract; then exit 1; fi
            printf 'calls=%s sleeps=%s' "$(<"$calls_file")" "$sleeps"
            BASH
            ,
        );

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertStringContainsString('calls=2 sleeps=1', $result['stdout']);
        self::assertStringContainsString('curl exit 28', $result['stdout']);
    }

    public function testDeepHealthRejectsMalformedAndPdfFalseResponses(): void
    {
        $result = $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            DRYRUN=0
            DEEP_HEALTH_RETRIES=1
            sleeps=0
            sleep() { sleeps=$((sleeps + 1)); }
            read_healthz_token() { printf token; }
            mode=malformed
            curl() {
              [[ "$1" == '--connect-timeout' && "$2" == '3' && "$3" == '--max-time' && "$4" == '30' && "$5" == '-sS' && "$6" == '-o' && "$8" == '-w' && "$9" == '%{http_code}' && "${10}" == '-H' && "${11}" == 'X-Health-Token: token' ]] || return 97
              local output=''
              while (($# > 0)); do
                if [[ "$1" == '-o' ]]; then output="$2"; shift 2; else shift; fi
              done
              if [[ "$mode" == 'malformed' ]]; then printf '{' > "$output"; else printf '%s' '{"status":"ok","checks":{"pdf_renderer":{"ok":false}}}' > "$output"; fi
              printf '200'
            }
            if probe_deep_health_contract; then exit 1; fi
            mode=pdf-false
            if probe_deep_health_contract; then exit 1; fi
            printf 'rejected-twice sleeps=%s' "$sleeps"
            BASH
            ,
        );

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertStringContainsString('rejected-twice sleeps=0', $result['stdout']);
        self::assertStringContainsString('deep health response is not valid JSON', $result['stderr']);
        self::assertStringContainsString('deep health contract mismatch', $result['stderr']);
    }

    public function testHealthProbeDryRunShowsLimitsWithoutReadingToken(): void
    {
        $result = $this->runShell(
            <<<'BASH'
            source ./deploy_ea.sh
            DRYRUN=1
            curl() { printf 'curl-called' >&2; return 1; }
            read_healthz_token() { printf 'token-read' >&2; return 1; }
            probe_renderer_health
            probe_deep_health_contract
            BASH
            ,
        );

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertStringContainsString('connect=3s, max=10s', $result['stdout']);
        self::assertStringContainsString('connect=3s, max=30s', $result['stdout']);
        self::assertStringNotContainsString('token-read', $result['stderr']);
        self::assertStringNotContainsString('curl-called', $result['stderr']);
    }

    public function testSuccessfulPostSwitchTailUsesRetainedChecksThenFinalizesWithoutOptionalHttpProbe(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/deploy_ea.sh');
        $boundary = "\nperform_atomic_switch\n";
        $position = strrpos($source, $boundary);
        self::assertNotFalse($position);
        $postSwitch = substr($source, $position + strlen($boundary));
        $script = <<<'BASH'
        source ./deploy_ea.sh
        DRYRUN=0
        MARK_RELEASE=1
        APP=/fixed/active
        ARCHIVE=/fixed/archive.tar.gz
        PREV=/fixed/previous
        LOG=/fixed/deploy.log
        REL=ea_contract
        CURRENT_SCRIPT_PATH=/fixed/deploy_ea.sh
        WEBUSER=www-data
        curl() { printf 'unexpected-curl\n' >&2; return 99; }
        verify_post_switch_runtime_config_contracts() { printf 'config\n'; }
        reload_services() { printf 'reload\n'; }
        probe_renderer_health() { printf 'renderer\n'; }
        probe_deep_health_contract() { printf 'deep\n'; }
        run_zero_surprise_live_canary() { printf 'canary\n'; }
        run_shell() { printf 'release\n'; }
        deploy_result_finalize() { printf 'finalize\n'; return 0; }
        deploy_result_finish() { printf 'finish\n'; return 0; }
        eval "$1"
        BASH;
        $result = $this->runCommand(['bash', '-c', $script, 'bash', $postSwitch]);

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertStringNotContainsString('unexpected-curl', $result['stderr']);
        $previousPosition = -1;
        foreach (['config', 'reload', 'renderer', 'deep', 'canary', 'release', 'finalize', 'finish'] as $step) {
            $position = strpos($result['stdout'], $step . "\n", $previousPosition + 1);
            self::assertNotFalse($position, $step);
            self::assertGreaterThan($previousPosition, $position, $step);
            $previousPosition = $position;
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
