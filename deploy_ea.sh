#!/usr/bin/env bash
# v1.2 - Hardened host deployment for Easy!Appointments
# - Mandatory pre-switch pdf-renderer dependency gate
# - Post-switch renderer + deep-health validation
# - Automatic strict rollback on post-switch validation failure

set -Eeuo pipefail
umask 022

SCRIPT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CURRENT_SCRIPT_PATH="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$(basename "${BASH_SOURCE[0]}")"
DEPLOY_CWD="$(pwd -P)"

REL=""
APP="/var/www/html/easyappointments"
SRC="/root/releases"
WEBUSER="www-data"
RELOAD_SERVICES="php8.2-fpm"
RELOAD_SERVICES_EXPLICIT=0
DRYRUN=0
MARK_RELEASE=1

RENDERER_HEALTH_URL="http://127.0.0.1:3003/healthz"
DEEP_HEALTH_URL="http://localhost/index.php/healthz"
HEALTHZ_TOKEN_FILE=""
ZERO_SURPRISE_REPORT=""
ZERO_SURPRISE_DUMP_FILE=""
ZERO_SURPRISE_PREDEPLOY_CREDENTIALS_FILE=""
ZERO_SURPRISE_MAX_AGE_MINUTES=240
REQUIRE_ZERO_SURPRISE=1
ZERO_SURPRISE_EXPECTED_MODE="predeploy"
ZERO_SURPRISE_PROFILE="school-day-default"
ZERO_SURPRISE_BREAKGLASS_FILE=""
ZERO_SURPRISE_BREAKGLASS_USED=0
ZERO_SURPRISE_BREAKGLASS_TICKET=""
ZERO_SURPRISE_BREAKGLASS_REASON=""
ZERO_SURPRISE_CANARY_ENABLED=1
ZERO_SURPRISE_CANARY_TIMEOUT=300
ZERO_SURPRISE_CANARY_CREDENTIALS_FILE=""
ZERO_SURPRISE_CANARY_REPORT=""
ZERO_SURPRISE_INCIDENT_WEBHOOK_FILE=""
ZERO_SURPRISE_INCIDENT_TIMEOUT=10

RENDERER_HEALTH_RETRIES=15
RENDERER_HEALTH_SLEEP_SECONDS=2
DEEP_HEALTH_RETRIES=10
DEEP_HEALTH_SLEEP_SECONDS=2

EXIT_DEPLOY_FAILED=30
EXIT_ROLLBACK_SUCCESS=30
EXIT_ROLLBACK_FAILED=31
EXIT_SWITCH_RECOVERY_REQUIRED=32
EXIT_RESULT_PUBLICATION_FAILED=74

DEPLOY_RESULT_NORMALIZATION_ACTIVE=0
DEPLOY_RESULT_PHASE="before_switch"
DEPLOY_RESULT_ROLLBACK_ACTIVE=0
DEPLOY_RESULT_FINAL_EXIT_CODE=""
DEPLOY_RESULT_RECOVERY_SIGNAL_EXIT_CODE=""
DEPLOY_RESULT_FINAL_OUTCOME=""
DEPLOY_RESULT_ACTION_EXIT_CODE=""
DEPLOY_RESULT_ACTION_OUTCOME=""
DEPLOY_RESULT_RECEIPT_PATH=""
DEPLOY_RESULT_RECEIPT_CHAIN_SHA256=""
DEPLOY_RESULT_RECEIPT_ACTIVE=0
DEPLOY_RESULT_RECEIPT_ATTEMPTED=0
DEPLOY_RESULT_RECEIPT_FINALIZATION_ACTIVE=0
# Intentionally reset: only sourced regression tests may set a crash checkpoint.
DEPLOY_RESULT_RECEIPT_TEST_CRASH_POINT=""
# Intentionally reset: only sourced regression tests may inject storage failures.
DEPLOY_RESULT_RECEIPT_TEST_FAILURE_POINT=""
# Intentionally reset: only sourced regression tests may coordinate publishers.
DEPLOY_RESULT_RECEIPT_TEST_BARRIER_PATH=""

DEPLOY_RESULT_EXIT_FINALIZATION_ACTIVE=0

SYSTEMCTL_BASE=(/bin/systemctl)

deploy_result_set_switch_phase() {
  local phase="$1"

  DEPLOY_RESULT_PHASE="$phase"

}

deploy_result_path_exists() {
  [[ -e "$1" || -L "$1" ]]
}

deploy_result_reconcile_switch_phase() {
  case "${DEPLOY_RESULT_PHASE:-before_switch}" in
    switch_first_move_pending)
      if
        ! deploy_result_path_exists "$APP" &&
          deploy_result_path_exists "$PREV" &&
          deploy_result_path_exists "$STAGE_ROOT"
      then
        deploy_result_set_switch_phase switch_partial
      elif
        deploy_result_path_exists "$APP" &&
          ! deploy_result_path_exists "$PREV" &&
          deploy_result_path_exists "$STAGE_ROOT"
      then
        deploy_result_set_switch_phase before_switch
      else
        deploy_result_set_switch_phase switch_partial
      fi
      ;;
    switch_second_move_pending)
      if
        deploy_result_path_exists "$APP" &&
          deploy_result_path_exists "$PREV"
      then
        deploy_result_set_switch_phase switch_complete
      else
        deploy_result_set_switch_phase switch_partial
      fi
      ;;
  esac
}

deploy_result_receipt_storage() {
  php /dev/fd/3 "$@" 3<<'PHP'
<?php
declare(strict_types=1);

const RESULT_SCHEMA = 'deploy_result.v1';
const RESULT_OUTCOME_EXITS = [
    'succeeded' => 0,
    'failed_pre_switch' => 30,
    'internal_rollback_succeeded' => 30,
    'rollback_failed_or_unverifiable' => 31,
    'switch_recovery_required' => 32,
    'interrupted_pre_switch' => 143,
];

/** @return array<string,int|string> */
function trustedDirectory(string $path): array
{
    clearstatcache(true, $path);
    $before = @lstat($path);
    $canonical = @realpath($path);
    clearstatcache(true, $path);
    $after = @lstat($path);
    if (!is_array($before) || !is_array($after) || $canonical !== $path) {
        throw new RuntimeException('untrusted directory');
    }
    foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'nlink'] as $key) {
        if ($before[$key] !== $after[$key]) {
            throw new RuntimeException('directory identity changed');
        }
    }
    if (($after['mode'] & 0170000) !== 0040000 || $after['uid'] !== 0 || $after['gid'] !== 0) {
        throw new RuntimeException('directory identity is untrusted');
    }
    if (($after['mode'] & 0022) !== 0) {
        throw new RuntimeException('directory is writable by non-root');
    }

    return [
        'path' => $path,
        'dev' => $after['dev'],
        'ino' => $after['ino'],
        'mode' => $after['mode'] & 07777,
        'uid' => $after['uid'],
        'gid' => $after['gid'],
    ];
}

/** @return list<array<string,int|string>> */
function trustedChain(string $target): array
{
    if (
        $target === '' || $target === '/' || $target[0] !== '/' || str_ends_with($target, '/') ||
        str_contains($target, '//') || preg_match('/[\x00-\x1f\x7f]/', $target) === 1
    ) {
        throw new RuntimeException('invalid result path');
    }
    $parts = explode('/', substr($target, 1));
    if ($parts === [] || in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)) {
        throw new RuntimeException('non-canonical result path');
    }

    array_pop($parts);
    $paths = ['/'];
    $current = '';
    foreach ($parts as $part) {
        $current .= '/' . $part;
        $paths[] = $current;
    }
    $chain = [];
    foreach ($paths as $path) {
        $chain[] = trustedDirectory($path);
    }
    $parent = dirname($target);
    $parentEntry = $chain[array_key_last($chain)];
    if ($parentEntry['path'] !== $parent || $parentEntry['mode'] !== 0700) {
        throw new RuntimeException('result parent is not protected');
    }

    return $chain;
}

function chainFingerprint(string $target): string
{
    return hash('sha256', json_encode(trustedChain($target), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}

function targetIsAbsent(string $target): bool
{
    clearstatcache(true, $target);
    return @lstat($target) === false;
}

/** @return array<string,int> */
function protectedFile(string $path, int $expectedLinks = 1): array
{
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if (
        !is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || $stat['uid'] !== 0 ||
        $stat['gid'] !== 0 || ($stat['mode'] & 07777) !== 0600 || $stat['nlink'] !== $expectedLinks
    ) {
        throw new RuntimeException('result file is untrusted');
    }

    return ['dev' => $stat['dev'], 'ino' => $stat['ino']];
}

/** @param resource $stream @param array<string,int> $identity */
function assertStreamIdentity($stream, array $identity, int $expectedLinks = 1): void
{
    $stat = fstat($stream);
    if (
        !is_array($stat) || $stat['dev'] !== $identity['dev'] || $stat['ino'] !== $identity['ino'] ||
        ($stat['mode'] & 0170000) !== 0100000 || $stat['uid'] !== 0 || $stat['gid'] !== 0 ||
        ($stat['mode'] & 07777) !== 0600 || $stat['nlink'] !== $expectedLinks
    ) {
        throw new RuntimeException('result stream identity is untrusted');
    }
}

function crashAt(string $selected, string $checkpoint): void
{
    if ($selected !== $checkpoint) {
        return;
    }
    if (!function_exists('posix_kill') || !posix_kill(posix_getpid(), 9)) {
        exit(97);
    }
    usleep(1000000);
    exit(97);
}

function failAt(string $selected, string $checkpoint): void
{
    if ($selected === $checkpoint) {
        throw new RuntimeException('injected result storage failure');
    }
}

function waitAtTestBarrier(string $barrier): void
{
    if ($barrier === '') {
        return;
    }
    $ready = $barrier . '.ready.' . getmypid();
    if (file_put_contents($ready, '') === false || !chmod($ready, 0600)) {
        throw new RuntimeException('result test barrier setup failed');
    }
    try {
        for ($attempt = 0; $attempt < 1000; $attempt++) {
            clearstatcache(true, $barrier);
            if (is_file($barrier)) {
                return;
            }
            usleep(10000);
        }
        throw new RuntimeException('result test barrier timed out');
    } finally {
        @unlink($ready);
    }
}

/** @param array<string,int|string> $expectedParent */
function fsyncDirectory(string $parent, array $expectedParent): void
{
    $directoryStream = @fopen($parent, 'rb');
    if (!is_resource($directoryStream)) {
        throw new RuntimeException('result parent open failed');
    }
    try {
        $directoryStat = fstat($directoryStream);
        $parentEntry = trustedDirectory($parent);
        if (
            !is_array($directoryStat) || $directoryStat['dev'] !== $expectedParent['dev'] ||
            $directoryStat['ino'] !== $expectedParent['ino'] || $parentEntry['dev'] !== $expectedParent['dev'] ||
            $parentEntry['ino'] !== $expectedParent['ino'] || !function_exists('fsync') || !fsync($directoryStream)
        ) {
            throw new RuntimeException('result parent fsync failed');
        }
    } finally {
        fclose($directoryStream);
    }
}

function validateReceipt(string $encoded): void
{
    $receipt = json_decode($encoded, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($receipt) || array_is_list($receipt)) {
        throw new RuntimeException('result receipt is not an object');
    }
    $keys = array_keys($receipt);
    sort($keys);
    if ($keys !== ['exit_code', 'outcome', 'schema'] || $receipt['schema'] !== RESULT_SCHEMA) {
        throw new RuntimeException('result receipt schema is invalid');
    }
    if (
        !is_string($receipt['outcome']) || !array_key_exists($receipt['outcome'], RESULT_OUTCOME_EXITS) ||
        !is_int($receipt['exit_code']) || RESULT_OUTCOME_EXITS[$receipt['outcome']] !== $receipt['exit_code']
    ) {
        throw new RuntimeException('result receipt pair is invalid');
    }
}

/** @param array<string,int> $identity */
function unlinkOwnedProtectedFile(string $path, array $identity): bool
{
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if (!is_array($stat)) {
        return true;
    }
    if (
        $stat['dev'] !== $identity['dev'] || $stat['ino'] !== $identity['ino'] ||
        ($stat['mode'] & 0170000) !== 0100000 || $stat['uid'] !== 0 || $stat['gid'] !== 0 ||
        ($stat['mode'] & 07777) !== 0600 || !in_array($stat['nlink'], [1, 2], true)
    ) {
        return false;
    }

    return @unlink($path);
}

function publish(
    string $target,
    string $expectedChain,
    string $encoded,
    string $crashPoint,
    string $failurePoint,
    string $testBarrier,
): void {
    validateReceipt($encoded);
    $chain = trustedChain($target);
    $chainHash = hash('sha256', json_encode($chain, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    if (!hash_equals($expectedChain, $chainHash) || !targetIsAbsent($target)) {
        throw new RuntimeException('result target changed');
    }

    $parent = dirname($target);
    $parentIdentity = $chain[array_key_last($chain)];
    $temporary = $parent . '/.deploy-result.' . bin2hex(random_bytes(16)) . '.tmp';
    umask(0077);
    $stream = @fopen($temporary, 'x+b');
    if (!is_resource($stream)) {
        throw new RuntimeException('result temporary create failed');
    }
    $temporaryIdentity = null;
    $published = false;
    try {
        if (!chmod($temporary, 0600)) {
            throw new RuntimeException('result chmod failed');
        }
        $temporaryIdentity = protectedFile($temporary);
        assertStreamIdentity($stream, $temporaryIdentity);
        crashAt($crashPoint, 'after_temp_create');

        $offset = 0;
        $length = strlen($encoded);
        while ($offset < $length) {
            $written = fwrite($stream, substr($encoded, $offset));
            if (!is_int($written) || $written < 1) {
                throw new RuntimeException('result write failed');
            }
            $offset += $written;
        }
        if (!fflush($stream)) {
            throw new RuntimeException('result flush failed');
        }
        failAt($failurePoint, 'file_fsync');
        if (!function_exists('fsync') || !fsync($stream)) {
            throw new RuntimeException('result file fsync failed');
        }
        assertStreamIdentity($stream, $temporaryIdentity);
        crashAt($crashPoint, 'after_file_fsync');
        if (
            !hash_equals($expectedChain, chainFingerprint($target)) || !targetIsAbsent($target) ||
            protectedFile($temporary) !== $temporaryIdentity
        ) {
            throw new RuntimeException('result target changed before publish');
        }
        waitAtTestBarrier($testBarrier);
        if (!@link($temporary, $target)) {
            throw new RuntimeException('result no-clobber publish failed');
        }
        $published = true;
        if (
            protectedFile($temporary, 2) !== $temporaryIdentity ||
            protectedFile($target, 2) !== $temporaryIdentity
        ) {
            throw new RuntimeException('published result identity changed');
        }
        assertStreamIdentity($stream, $temporaryIdentity, 2);
        if (!@unlink($temporary)) {
            throw new RuntimeException('result temporary unlink failed');
        }
        if (protectedFile($target) !== $temporaryIdentity) {
            throw new RuntimeException('published result identity changed');
        }
        assertStreamIdentity($stream, $temporaryIdentity);
        crashAt($crashPoint, 'after_publish');

        if ($failurePoint === 'replace_target_identity') {
            if (
                !@unlink($target) || file_put_contents($target, "replacement\n") === false ||
                !chmod($target, 0600)
            ) {
                throw new RuntimeException('injected result target replacement failed');
            }
            throw new RuntimeException('injected result target identity replacement');
        }

        if (in_array($failurePoint, ['parent_fsync', 'parent_fsync_cleanup'], true)) {
            throw new RuntimeException('injected result parent fsync failure');
        }
        fsyncDirectory($parent, $parentIdentity);
        crashAt($crashPoint, 'after_parent_fsync');
        failAt($failurePoint, 'post_publish_identity');
        if (
            !hash_equals($expectedChain, chainFingerprint($target)) ||
            protectedFile($target) !== $temporaryIdentity
        ) {
            throw new RuntimeException('published result changed');
        }
    } catch (Throwable $failure) {
        if (
            is_array($temporaryIdentity) && $published && $failurePoint !== 'parent_fsync_cleanup' &&
            unlinkOwnedProtectedFile($target, $temporaryIdentity)
        ) {
            try {
                fsyncDirectory($parent, $parentIdentity);
            } catch (Throwable) {
                // The caller still receives a hard publication failure.
            }
        }
        throw $failure;
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
        if (is_array($temporaryIdentity)) {
            unlinkOwnedProtectedFile($temporary, $temporaryIdentity);
        }
    }
}

try {
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0 || count($argv) < 3) {
        throw new RuntimeException('result storage requires root');
    }
    $operation = $argv[1];
    $target = $argv[2];
    if ($operation === 'prepare' && count($argv) === 3) {
        if (!targetIsAbsent($target)) {
            throw new RuntimeException('result target already exists');
        }
        echo chainFingerprint($target), "\n";
        exit(0);
    }
    if ($operation === 'publish' && count($argv) === 8) {
        publish($target, $argv[3], $argv[4], $argv[5], $argv[6], $argv[7]);
        exit(0);
    }
    throw new RuntimeException('result storage invocation is invalid');
} catch (Throwable) {
    exit(1);
}
PHP
}

deploy_result_receipt_prepare() {
  local fingerprint

  [[ -n "${DEPLOY_RESULT_RECEIPT_PATH:-}" ]] || return 0
  if ! fingerprint="$(deploy_result_receipt_storage prepare "$DEPLOY_RESULT_RECEIPT_PATH")" \
    || [[ ! "$fingerprint" =~ ^[0-9a-f]{64}$ ]]; then
    echo "[!] Deploy result target trust contract failed." >&2
    return 1
  fi
  DEPLOY_RESULT_RECEIPT_CHAIN_SHA256="$fingerprint"
  DEPLOY_RESULT_RECEIPT_ACTIVE=1
  return 0
}

deploy_result_receipt_outcome() {
  local exit_code="$1"

  case "$exit_code" in
    0)
      printf 'succeeded\n'
      ;;
    30)
      if [[ "${DEPLOY_RESULT_ROLLBACK_ACTIVE:-0}" == "1" ]]; then
        printf 'internal_rollback_succeeded\n'
      else
        printf 'failed_pre_switch\n'
      fi
      ;;
    31)
      printf 'rollback_failed_or_unverifiable\n'
      ;;
    32)
      printf 'switch_recovery_required\n'
      ;;
    143)
      [[ "${DEPLOY_RESULT_PHASE:-before_switch}" == "before_switch" ]] || return 1
      printf 'interrupted_pre_switch\n'
      ;;
    *)
      return 1
      ;;
  esac
}

deploy_result_receipt_publish_once() {
  local outcome="$1"
  local exit_code="$2"
  local receipt

  [[ "${DEPLOY_RESULT_RECEIPT_ACTIVE:-0}" == "1" ]] || return 0
  [[ "${DEPLOY_RESULT_RECEIPT_ATTEMPTED:-0}" == "0" ]] || return 0
  DEPLOY_RESULT_RECEIPT_ATTEMPTED=1
  builtin printf -v receipt '{"schema":"deploy_result.v1","outcome":"%s","exit_code":%d}\n' "$outcome" "$exit_code"
  if ! deploy_result_receipt_storage \
    publish \
    "$DEPLOY_RESULT_RECEIPT_PATH" \
    "$DEPLOY_RESULT_RECEIPT_CHAIN_SHA256" \
    "$receipt" \
    "${DEPLOY_RESULT_RECEIPT_TEST_CRASH_POINT:-}" \
    "${DEPLOY_RESULT_RECEIPT_TEST_FAILURE_POINT:-}" \
    "${DEPLOY_RESULT_RECEIPT_TEST_BARRIER_PATH:-}"
  then
    echo "[!] Deploy result candidate could not be durably published." >&2
    return "$EXIT_RESULT_PUBLICATION_FAILED"
  fi
  return 0
}

deploy_result_finalize() {
  local exit_code="$1"
  local outcome

  if [[ -n "${DEPLOY_RESULT_FINAL_EXIT_CODE:-}" ]]; then
    [[ "$DEPLOY_RESULT_FINAL_EXIT_CODE" == "$exit_code" ]]
    return $?
  fi

  case "$exit_code" in
    0|30|31|32|143)
      outcome="$(deploy_result_receipt_outcome "$exit_code")" || return 1
      DEPLOY_RESULT_RECEIPT_FINALIZATION_ACTIVE=1
      DEPLOY_RESULT_ACTION_EXIT_CODE="$exit_code"
      DEPLOY_RESULT_ACTION_OUTCOME="$outcome"
      DEPLOY_RESULT_FINAL_EXIT_CODE="$exit_code"
      DEPLOY_RESULT_FINAL_OUTCOME="$outcome"
      if ! deploy_result_receipt_publish_once "$outcome" "$exit_code"; then
        DEPLOY_RESULT_FINAL_EXIT_CODE="$EXIT_RESULT_PUBLICATION_FAILED"
        DEPLOY_RESULT_FINAL_OUTCOME=""
        DEPLOY_RESULT_RECEIPT_FINALIZATION_ACTIVE=0
        return "$EXIT_RESULT_PUBLICATION_FAILED"
      fi
      DEPLOY_RESULT_RECEIPT_FINALIZATION_ACTIVE=0
      return 0
      ;;
    *)
      return 1
      ;;
  esac
}

deploy_result_exit() {
  local exit_code="$1"
  local finalize_status

  if deploy_result_finalize "$exit_code"; then
    exit "${DEPLOY_RESULT_FINAL_EXIT_CODE:-$exit_code}"
  else
    finalize_status="$?"
  fi
  if [[ "${DEPLOY_RESULT_FINAL_EXIT_CODE:-}" == "$EXIT_RESULT_PUBLICATION_FAILED" ]]; then
    exit "$EXIT_RESULT_PUBLICATION_FAILED"
  fi
  return "$finalize_status"
}

deploy_result_normalize_exit_code() {
  local exit_code="$1"

  if [[ "$exit_code" == "0" ]]; then
    printf '%s\n' "$exit_code"
    return 0
  fi

  if [[ "${DEPLOY_RESULT_NORMALIZATION_ACTIVE:-0}" != "1" ]]; then
    printf '%s\n' "$exit_code"
    return 0
  fi

  if [[ -n "${DEPLOY_RESULT_FINAL_EXIT_CODE:-}" ]]; then
    printf '%s\n' "$DEPLOY_RESULT_FINAL_EXIT_CODE"
    return 0
  fi

  case "${DEPLOY_RESULT_PHASE:-before_switch}" in
    before_switch)
      if [[ "$exit_code" == "143" ]]; then
        printf '%s\n' "$exit_code"
      else
        printf '%s\n' "$EXIT_DEPLOY_FAILED"
      fi
      ;;
    switch_partial)
      printf '%s\n' "$EXIT_SWITCH_RECOVERY_REQUIRED"
      ;;
    switch_complete)
      printf '%s\n' "$EXIT_ROLLBACK_FAILED"
      ;;
    *)
      printf '%s\n' "$EXIT_ROLLBACK_FAILED"
      ;;
  esac
}

deploy_result_on_signal() {
  local signal_exit_code="${1:-143}"

  if [[ "${DEPLOY_RESULT_EXIT_FINALIZATION_ACTIVE:-0}" == "1" \
    || "${DEPLOY_RESULT_RECEIPT_FINALIZATION_ACTIVE:-0}" == "1" ]]; then
    return 0
  fi
  if [[ "${DEPLOY_RESULT_ROLLBACK_ACTIVE:-0}" != "1" ]]; then
    DEPLOY_RESULT_RECOVERY_SIGNAL_EXIT_CODE=""
  fi
  deploy_result_recovery_signal_traps_install
  deploy_result_reconcile_switch_phase

  if [[ -n "${DEPLOY_RESULT_FINAL_EXIT_CODE:-}" ]]; then
    exit "$DEPLOY_RESULT_FINAL_EXIT_CODE"
  fi

  case "${DEPLOY_RESULT_PHASE:-before_switch}" in
    switch_partial)
      deploy_result_exit "$EXIT_SWITCH_RECOVERY_REQUIRED"
      ;;
    switch_complete)
      if [[ "${DEPLOY_RESULT_ROLLBACK_ACTIVE:-0}" != "1" && "${DRYRUN:-0}" != "1" ]]; then
        DEPLOY_RESULT_ROLLBACK_ACTIVE=1
        rollback_after_failure "post-switch interruption"
        deploy_result_exit "$EXIT_ROLLBACK_FAILED"
      fi
      ;;
  esac

  signal_exit_code="$(deploy_result_normalize_exit_code "$signal_exit_code")"
  exit "$signal_exit_code"
}

deploy_result_on_recovery_signal() {
  DEPLOY_RESULT_RECOVERY_SIGNAL_EXIT_CODE="${1:-143}"
  return 0
}

deploy_result_recovery_signal_traps_install() {
  trap 'deploy_result_on_recovery_signal 129' HUP
  trap 'deploy_result_on_recovery_signal 130' INT
  trap 'deploy_result_on_recovery_signal 131' QUIT
  trap 'deploy_result_on_recovery_signal 143' TERM
}

deploy_result_trap_install() {
  DEPLOY_RESULT_NORMALIZATION_ACTIVE=1
  DEPLOY_RESULT_FINAL_EXIT_CODE=""
  DEPLOY_RESULT_FINAL_OUTCOME=""
  DEPLOY_RESULT_ACTION_EXIT_CODE=""
  DEPLOY_RESULT_ACTION_OUTCOME=""
  trap deploy_result_on_exit EXIT
  trap 'deploy_result_on_signal 129' HUP
  trap 'deploy_result_on_signal 130' INT
  trap 'deploy_result_on_signal 131' QUIT
  trap 'deploy_result_on_signal 143' TERM
}

deploy_result_after_finalize() {
  return 0
}

deploy_result_finish() {
  local exit_code="$1"

  if ! deploy_result_finalize "$exit_code"; then
    if [[ "${DEPLOY_RESULT_FINAL_EXIT_CODE:-}" == "$EXIT_RESULT_PUBLICATION_FAILED" ]]; then
      deploy_result_exit "$EXIT_RESULT_PUBLICATION_FAILED"
    fi
    return 1
  fi
  trap - EXIT
  deploy_result_after_finalize || true
  deploy_result_exit "$exit_code"
}

deploy_result_on_exit() {
  local raw_exit_code="$?"
  local exit_code

  DEPLOY_RESULT_EXIT_FINALIZATION_ACTIVE=1
  deploy_result_reconcile_switch_phase
  if [[
    "$raw_exit_code" != "0" &&
    -z "${DEPLOY_RESULT_FINAL_EXIT_CODE:-}" &&
    "${DEPLOY_RESULT_PHASE:-before_switch}" == "switch_complete" &&
    "${DEPLOY_RESULT_ROLLBACK_ACTIVE:-0}" != "1" &&
    "${DRYRUN:-0}" != "1"
  ]]; then
    DEPLOY_RESULT_ROLLBACK_ACTIVE=1
    trap deploy_result_on_exit EXIT
    rollback_after_failure "unhandled post-switch failure"
    deploy_result_exit "$EXIT_ROLLBACK_FAILED"
  fi

  exit_code="$(deploy_result_normalize_exit_code "$raw_exit_code")"
  if ! deploy_result_finalize "$exit_code"; then
    exit_code="$(deploy_result_normalize_exit_code "$exit_code")"
  fi
  trap - EXIT
  exit "$exit_code"
}

sync_storage_payload() {
  local source_root="${1%/}"
  local target_root="${2%/}"
  local exit_code

  if [[ "${DRYRUN:-0}" -eq 1 ]]; then
    echo "[DRY-RUN] rsync -a -- '$source_root/' '$target_root/'"
    return 0
  fi

  if rsync -a -- "$source_root/" "$target_root/" >/dev/null 2>&1; then
    exit_code=0
  else
    exit_code="$?"
  fi
  if [[ "$exit_code" -ne 0 ]]; then
    return "$exit_code"
  fi

  return 0
}

sync_live_storage_to_stage() {
  sync_storage_payload "$APP/storage" "$STAGE_ROOT/storage"
}

prepare_predeploy_stage_permissions() {
  prepare_zero_surprise_stage_runtime || return $?
  run_shell "chown -R '$WEBUSER':'$WEBUSER' '$STAGE_ROOT'" || return $?
  run_shell "find '$STAGE_ROOT' -type d -exec chmod 755 {} +" || return $?
  run_shell "find -P '$STAGE_ROOT' -path '$STAGE_ROOT/storage/sessions' -prune -o -type f -exec chmod 644 {} +" || return $?
  restore_runtime_script_permissions || return $?
  if [[ "$REQUIRE_ZERO_SURPRISE" -eq 1 ]]; then
    harden_and_verify_runtime_config "$STAGE_ROOT" || return $?
  fi
  return 0
}

run_zero_surprise_predeploy_gate() {
  run_zero_surprise_predeploy_replay || return $?
  validate_zero_surprise_report || return $?
  return 0
}

normalize_stage_permissions() {
  run_shell "chown -R '$WEBUSER':'$WEBUSER' '$STAGE_ROOT'" || return $?
  run_shell "find '$STAGE_ROOT' -type d -exec chmod 755 {} +" || return $?
  run_shell "find -P '$STAGE_ROOT' -path '$STAGE_ROOT/storage/sessions' -prune -o -type f -exec chmod 644 {} +" || return $?
  restore_runtime_script_permissions || return $?
  return 0
}

verify_pre_switch_runtime_config_contracts() {
  harden_and_verify_runtime_config "$STAGE_ROOT" || return $?
  harden_and_verify_runtime_config "$APP" || return $?
  return 0
}

usage() {
  cat <<'USAGE'
Usage: deploy_ea.sh --rel REL [options]

Trusted maintenance modes:
  deploy_ea.sh --runtime-config-permissions harden|verify --app-root PATH --runtime-user USER
  deploy_ea.sh --runtime-config-rollback --active PATH --previous PATH --failed PATH --runtime-user USER

Required:
  --rel REL                    Release-ID (e.g. ea_20251005_2000)

Core options:
  --app PATH                   Live app path                     [default: /var/www/html/easyappointments]
  --src DIR                    Directory with release archive     [default: /root/releases]
  --user WEBUSER               Web user for ownership/actions     [default: www-data]
  --reload LIST                Services to reload (CSV)           [default: <detected php-fpm>]
  --result-file PATH           Publish durable deploy_result.v1 candidate
  --dry-run                    Print actions only
  --no-mark                    Skip writing _RELEASE marker

Renderer / health gate options:
  --renderer-health-url URL    Renderer health endpoint           [default: http://127.0.0.1:3003/healthz]
  --deep-health-url URL         App deep health endpoint           [default: http://localhost/index.php/healthz]
  --healthz-token-file PATH     File containing deep-health token  [required for non-dry deploy]
  --zero-surprise-report PATH   Output path for generated predeploy report
  --zero-surprise-dump-file PATH
                                Restore dump for predeploy replay   [required when gate is enabled]
  --zero-surprise-predeploy-credentials-file PATH
                                INI file for predeploy replay       [required when gate is enabled]
  --zero-surprise-profile NAME  Named zero-surprise profile         [default: school-day-default]
  --zero-surprise-max-age-minutes N
                                Max age for report in minutes      [default: 240]
  --require-zero-surprise 0|1   Enforce zero-surprise hard-fail    [default: 1]
  --zero-surprise-breakglass-file PATH
                                Expiring ack JSON required for any bypass
  --zero-surprise-canary-enabled 0|1
                                Run post-switch live canary        [default: 1]
  --zero-surprise-canary-timeout N
                                Live canary timeout in seconds      [default: 300]
  --zero-surprise-canary-credentials-file PATH
                                INI file for live canary creds      [required when canary is enabled]
  --zero-surprise-incident-webhook-file PATH
                                INI file for zero-surprise incident webhook
  --zero-surprise-incident-timeout N
                                Incident webhook timeout seconds    [default: 10]

Exit codes:
  0   Success
  30  Deploy failed before switch, or automatic rollback succeeded
  31  Deploy failed, rollback failed or unverifiable
  32  Switch is partial and requires recovery

Example:
  /root/deploy_ea.sh --rel ea_20251005_2000 --healthz-token-file /etc/fh/healthz.token
USAGE
}

die() {
  echo "$1"
  exit 1
}

run_shell() {
  local cmd="$1"
  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] $cmd"
  else
    bash -lc "$cmd"
  fi
}

require_command() {
  local cmd="$1"
  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] prerequisite check: command '$cmd' exists"
    return 0
  fi
  command -v "$cmd" >/dev/null 2>&1 || die "[!] Required command missing: $cmd"
}

require_docker_compose() {
  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] prerequisite check: docker compose version"
    return 0
  fi

  docker compose version >/dev/null 2>&1 || die "[!] Required command missing: docker compose"
}

absolutize_path() {
  local value="$1"

  case "$value" in
    "~")
      value="$HOME"
      ;;
    "~/"*)
      value="$HOME/${value#~/}"
      ;;
  esac

  if [[ "$value" != /* ]]; then
    value="${DEPLOY_CWD}/${value}"
  fi

  printf '%s\n' "$value"
}

absolutize_path_var() {
  local var_name="$1"
  local value="${!var_name:-}"

  [[ -n "$value" ]] || return 0
  printf -v "$var_name" '%s' "$(absolutize_path "$value")"
}

initialize_service_control() {
  if [[ "$EUID" -eq 0 ]]; then
    SYSTEMCTL_BASE=(/bin/systemctl)
  else
    SYSTEMCTL_BASE=(sudo -n /bin/systemctl)
  fi
}

validate_stage_release_artifact() {
  local validator_script="$STAGE_ROOT/scripts/release-gate/validate_release_artifact.php"

  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] validate release artifact at '$STAGE_ROOT' with '$validator_script'"
    return 0
  fi

  [[ -f "$validator_script" && ! -L "$validator_script" ]] \
    || die "[!] Release artifact validator must be a regular non-symlink file: $validator_script"

  php "$validator_script" --root="$STAGE_ROOT" \
    || die "[!] Extracted stage is missing required release artifacts."
}

validate_deploy_script_drift() {
  local stage_deploy_script="$STAGE_ROOT/deploy_ea.sh"

  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] compare running deploy script '$CURRENT_SCRIPT_PATH' with staged '$stage_deploy_script'"
    return 0
  fi

  [[ -f "$stage_deploy_script" && ! -L "$stage_deploy_script" ]] \
    || die "[!] Staged deploy script must be a regular non-symlink file: $stage_deploy_script"

  cmp -s "$CURRENT_SCRIPT_PATH" "$stage_deploy_script" \
    || die "[!] Host deploy script drift detected: '$CURRENT_SCRIPT_PATH' does not match '$stage_deploy_script'. Sync the host deploy script from the merged repo before deploying."
}

detect_php_fpm_reload_service() {
  local unit
  local state

  while read -r unit _ state _; do
    [[ "$state" == active ]] || continue
    [[ "$unit" =~ ^php[0-9.]+-fpm\.service$ ]] || continue
    printf '%s\n' "${unit%.service}"
    return 0
  done < <("${SYSTEMCTL_BASE[@]}" list-units --type=service --state=active 'php*-fpm.service' --no-legend 2>/dev/null || true)

  return 1
}

resolve_reload_services() {
  local detected_php_fpm

  [[ "$RELOAD_SERVICES_EXPLICIT" -eq 0 ]] || return 0

  detected_php_fpm="$(detect_php_fpm_reload_service || true)"
  [[ -n "$detected_php_fpm" ]] || return 0

  RELOAD_SERVICES="${detected_php_fpm}"
}

reload_services() {
  local s
  local s_trim
  local reload_status=0

  [[ -n "${RELOAD_SERVICES//[[:space:],]/}" ]] || return 0
  IFS=',' read -ra SVCS <<< "$RELOAD_SERVICES"
  for s in "${SVCS[@]}"; do
    s_trim="$(echo "$s" | xargs)"
    [[ -n "$s_trim" ]] || continue
    if [[ "$DRYRUN" -eq 1 ]]; then
      echo "[DRY-RUN] ${SYSTEMCTL_BASE[*]} reload '$s_trim' 2>/dev/null"
    else
      if ! "${SYSTEMCTL_BASE[@]}" reload "$s_trim" 2>/dev/null; then
        reload_status=1
      fi
    fi
  done

  return "$reload_status"
}

restore_runtime_script_permissions() {
  local ops_dir="${STAGE_ROOT}/scripts/ops"

  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] restore executable bits for '$ops_dir' shell scripts when present"
    return 0
  fi

  [[ -d "$ops_dir" ]] || return 0

  find "$ops_dir" -type f -name '*.sh' -exec chmod 755 {} +
}

runtime_config_path_identity() {
  stat -c '%d:%i' -- "$1"
}

runtime_config_path_matches() {
  local path="$1"
  local expected_identity="$2"
  local expected_type="$3"

  case "$expected_type" in
    directory)
      [[ -d "$path" && ! -L "$path" ]] || return 1
      ;;
    file)
      [[ -f "$path" && ! -L "$path" ]] || return 1
      ;;
    *)
      return 1
      ;;
  esac

  [[ "$(runtime_config_path_identity "$path" 2>/dev/null || true)" == "$expected_identity" ]]
}

runtime_user_can_write_path() {
  local runtime_user="$1"
  local path="$2"

  runuser -u "$runtime_user" -- php -r 'exit(is_writable($argv[1]) ? 0 : 1);' "$path"
}

validate_root_controlled_ancestors() {
  local target_path="$1"
  local runtime_user="$2"
  local cursor
  local owner
  local mode
  local index
  local -a ancestors=()

  cursor="$(dirname "$target_path")"
  while true; do
    ancestors+=("$cursor")
    [[ "$cursor" == "/" ]] && break
    cursor="$(dirname "$cursor")"
  done

  for ((index = ${#ancestors[@]} - 1; index >= 0; index--)); do
    cursor="${ancestors[$index]}"
    [[ -d "$cursor" && ! -L "$cursor" ]] || {
      echo "[!] Runtime config permission contract failed: ancestor must be a non-symlink directory: $cursor" >&2
      return 1
    }

    owner="$(stat -c '%u' -- "$cursor")" || {
      echo "[!] Runtime config permission contract failed: could not read ancestor owner: $cursor" >&2
      return 1
    }
    mode="$(stat -c '%a' -- "$cursor")" || {
      echo "[!] Runtime config permission contract failed: could not read ancestor mode: $cursor" >&2
      return 1
    }

    [[ "$owner" == "0" ]] || {
      echo "[!] Runtime config permission contract failed: ancestor must be root-owned: $cursor" >&2
      return 1
    }
    (( (8#$mode & 0022) == 0 )) || {
      echo "[!] Runtime config permission contract failed: ancestor must not be group/world-writable: $cursor" >&2
      return 1
    }
    if runtime_user_can_write_path "$runtime_user" "$cursor"; then
      echo "[!] Runtime config permission contract failed: ancestor is writable by runtime user: $cursor" >&2
      return 1
    fi
  done
}

validate_trusted_deploy_script() {
  local script_path="$1"
  local runtime_user="$2"
  local canonical_path
  local owner
  local mode

  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] verify root-owned non-symlink deploy script and ancestors: '$script_path'"
    return 0
  fi

  [[ "$EUID" -eq 0 ]] || {
    echo "[!] Trusted deploy script validation requires root privileges." >&2
    return 1
  }
  id -u "$runtime_user" >/dev/null 2>&1 || {
    echo "[!] Runtime user does not exist: $runtime_user" >&2
    return 1
  }
  [[ -f "$script_path" && ! -L "$script_path" ]] || {
    echo "[!] Trusted deploy script must be a regular non-symlink file: $script_path" >&2
    return 1
  }

  canonical_path="$(realpath -e -- "$script_path")" || {
    echo "[!] Could not canonicalize trusted deploy script: $script_path" >&2
    return 1
  }
  [[ "$canonical_path" == "$script_path" ]] || {
    echo "[!] Trusted deploy script path must be canonical and symlink-free: $script_path" >&2
    return 1
  }

  owner="$(stat -c '%u' -- "$script_path")" || return 1
  mode="$(stat -c '%a' -- "$script_path")" || return 1
  [[ "$owner" == "0" ]] || {
    echo "[!] Trusted deploy script must be root-owned: $script_path" >&2
    return 1
  }
  (( (8#$mode & 0022) == 0 )) || {
    echo "[!] Trusted deploy script must not be group/world-writable: $script_path" >&2
    return 1
  }
  if runtime_user_can_write_path "$runtime_user" "$script_path"; then
    echo "[!] Trusted deploy script is writable by runtime user: $script_path" >&2
    return 1
  fi

  validate_root_controlled_ancestors "$script_path" "$runtime_user"
}

restore_runtime_config_transaction() {
  local exit_code="$?"
  local restore_ok=1

  trap - EXIT

  if [[ "${RUNTIME_CONFIG_TX_ACTIVE:-0}" -eq 1 ]]; then
    if [[ "${RUNTIME_CONFIG_TX_CONFIG_MUTATED:-0}" -eq 1 ]]; then
      if runtime_config_path_matches \
        "$RUNTIME_CONFIG_TX_CONFIG_PATH" \
        "$RUNTIME_CONFIG_TX_CONFIG_IDENTITY" \
        file; then
        chown --no-dereference "$RUNTIME_CONFIG_TX_CONFIG_OWNER" -- "$RUNTIME_CONFIG_TX_CONFIG_PATH" \
          || restore_ok=0
        chmod "$RUNTIME_CONFIG_TX_CONFIG_MODE" -- "$RUNTIME_CONFIG_TX_CONFIG_PATH" \
          || restore_ok=0
        runtime_config_path_matches \
          "$RUNTIME_CONFIG_TX_CONFIG_PATH" \
          "$RUNTIME_CONFIG_TX_CONFIG_IDENTITY" \
          file \
          || restore_ok=0
      else
        echo "[!] Refusing to restore replaced config.php path; manual intervention required." >&2
        restore_ok=0
      fi
    fi

    if [[ "$restore_ok" -eq 1 && "${RUNTIME_CONFIG_TX_APP_MUTATED:-0}" -eq 1 ]]; then
      if runtime_config_path_matches \
        "$RUNTIME_CONFIG_TX_APP_PATH" \
        "$RUNTIME_CONFIG_TX_APP_IDENTITY" \
        directory; then
        chown --no-dereference "$RUNTIME_CONFIG_TX_APP_OWNER" -- "$RUNTIME_CONFIG_TX_APP_PATH" \
          || restore_ok=0
        chmod "$RUNTIME_CONFIG_TX_APP_MODE" -- "$RUNTIME_CONFIG_TX_APP_PATH" \
          || restore_ok=0
        runtime_config_path_matches \
          "$RUNTIME_CONFIG_TX_APP_PATH" \
          "$RUNTIME_CONFIG_TX_APP_IDENTITY" \
          directory \
          || restore_ok=0
      else
        echo "[!] Refusing to restore replaced app root path; manual intervention required." >&2
        restore_ok=0
      fi
    fi

    if [[ "$restore_ok" -eq 1 ]]; then
      echo "[i] Restored prior runtime config permission metadata after failed hardening." >&2
    else
      echo "[!] Failed to restore pinned runtime config metadata; manual intervention required." >&2
      exit 2
    fi
  fi

  exit "$exit_code"
}

runtime_config_permissions_contract() {
  local action="$1"
  local app_root="$2"
  local runtime_user="$3"
  local canonical_root
  local root_uid
  local root_gid
  local runtime_uid
  local runtime_gid
  local app_owner
  local app_mode
  local config_path
  local config_owner
  local config_mode
  local config_links

  while [[ "$app_root" != "/" && "$app_root" == */ ]]; do
    app_root="${app_root%/}"
  done

  [[ "$app_root" == /* && "$app_root" != "/" ]] || {
    echo "[!] Runtime config permission contract failed: app root must be an absolute non-root path." >&2
    return 1
  }
  [[ -d "$app_root" && ! -L "$app_root" ]] || {
    echo "[!] Runtime config permission contract failed: app root must be a non-symlink directory: $app_root" >&2
    return 1
  }
  canonical_root="$(realpath -e -- "$app_root")" || {
    echo "[!] Runtime config permission contract failed: could not canonicalize app root: $app_root" >&2
    return 1
  }
  [[ "$canonical_root" == "$app_root" ]] || {
    echo "[!] Runtime config permission contract failed: app root path must be canonical and symlink-free: $app_root" >&2
    return 1
  }

  root_uid="$(id -u root)" || return 1
  root_gid="$(id -g root)" || return 1
  runtime_uid="$(id -u "$runtime_user")" || {
    echo "[!] Runtime config permission contract failed: runtime user does not exist: $runtime_user" >&2
    return 1
  }
  runtime_gid="$(id -g "$runtime_user")" || return 1
  [[ "$runtime_uid" != "$root_uid" ]] || {
    echo "[!] Runtime config permission contract failed: runtime user must not be root." >&2
    return 1
  }

  validate_root_controlled_ancestors "$app_root" "$runtime_user" || return 1

  RUNTIME_CONFIG_TX_ACTIVE=0
  RUNTIME_CONFIG_TX_APP_MUTATED=0
  RUNTIME_CONFIG_TX_CONFIG_MUTATED=0
  RUNTIME_CONFIG_TX_APP_PATH="$app_root"
  RUNTIME_CONFIG_TX_APP_IDENTITY="$(runtime_config_path_identity "$app_root")" || return 1
  RUNTIME_CONFIG_TX_APP_OWNER="$(stat -c '%u:%g' -- "$app_root")" || return 1
  RUNTIME_CONFIG_TX_APP_MODE="$(stat -c '%a' -- "$app_root")" || return 1
  RUNTIME_CONFIG_TX_CONFIG_PATH=""
  RUNTIME_CONFIG_TX_CONFIG_IDENTITY=""
  RUNTIME_CONFIG_TX_CONFIG_OWNER=""
  RUNTIME_CONFIG_TX_CONFIG_MODE=""

  if [[ "$action" == "harden" ]]; then
    RUNTIME_CONFIG_TX_ACTIVE=1
    RUNTIME_CONFIG_TX_APP_MUTATED=1
    trap restore_runtime_config_transaction EXIT

    chmod 0555 -- "$app_root" || return 1
    runtime_config_path_matches "$app_root" "$RUNTIME_CONFIG_TX_APP_IDENTITY" directory || return 1
    chown --no-dereference "$root_uid:$root_gid" -- "$app_root" || return 1
    runtime_config_path_matches "$app_root" "$RUNTIME_CONFIG_TX_APP_IDENTITY" directory || return 1
    chmod 0755 -- "$app_root" || return 1
    runtime_config_path_matches "$app_root" "$RUNTIME_CONFIG_TX_APP_IDENTITY" directory || return 1
  fi

  config_path="$app_root/config.php"
  [[ -f "$config_path" && ! -L "$config_path" ]] || {
    echo "[!] Runtime config permission contract failed: config.php must be a regular non-symlink file: $config_path" >&2
    return 1
  }

  RUNTIME_CONFIG_TX_CONFIG_PATH="$config_path"
  RUNTIME_CONFIG_TX_CONFIG_IDENTITY="$(runtime_config_path_identity "$config_path")" || return 1
  RUNTIME_CONFIG_TX_CONFIG_OWNER="$(stat -c '%u:%g' -- "$config_path")" || return 1
  RUNTIME_CONFIG_TX_CONFIG_MODE="$(stat -c '%a' -- "$config_path")" || return 1
  config_links="$(stat -c '%h' -- "$config_path")" || return 1
  [[ "$config_links" == "1" ]] || {
    echo "[!] Runtime config permission contract failed: config.php must have exactly one hardlink (observed: $config_links)" >&2
    return 1
  }

  if [[ "$action" == "harden" ]]; then
    RUNTIME_CONFIG_TX_CONFIG_MUTATED=1
    chmod 0400 -- "$config_path" || return 1
    runtime_config_path_matches "$config_path" "$RUNTIME_CONFIG_TX_CONFIG_IDENTITY" file || return 1
    chown --no-dereference "$root_uid:$runtime_gid" -- "$config_path" || return 1
    runtime_config_path_matches "$config_path" "$RUNTIME_CONFIG_TX_CONFIG_IDENTITY" file || return 1
    chmod 0440 -- "$config_path" || return 1
    runtime_config_path_matches "$config_path" "$RUNTIME_CONFIG_TX_CONFIG_IDENTITY" file || return 1
  fi

  app_owner="$(stat -c '%u:%g' -- "$app_root")" || return 1
  app_mode="$(stat -c '%a' -- "$app_root")" || return 1
  config_owner="$(stat -c '%u:%g' -- "$config_path")" || return 1
  config_mode="$(stat -c '%a' -- "$config_path")" || return 1
  config_links="$(stat -c '%h' -- "$config_path")" || return 1

  [[ "$app_owner" == "$root_uid:$root_gid" ]] || {
    echo "[!] Runtime config permission contract failed: app root owner must be $root_uid:$root_gid (observed: $app_owner)" >&2
    return 1
  }
  [[ "$app_mode" == "755" ]] || {
    echo "[!] Runtime config permission contract failed: app root mode must be 755 (observed: $app_mode)" >&2
    return 1
  }
  [[ "$config_owner" == "$root_uid:$runtime_gid" ]] || {
    echo "[!] Runtime config permission contract failed: config.php owner must be $root_uid:$runtime_gid (observed: $config_owner)" >&2
    return 1
  }
  [[ "$config_mode" == "440" ]] || {
    echo "[!] Runtime config permission contract failed: config.php mode must be 440 (observed: $config_mode)" >&2
    return 1
  }
  [[ "$config_links" == "1" ]] || {
    echo "[!] Runtime config permission contract failed: config.php must have exactly one hardlink (observed: $config_links)" >&2
    return 1
  }

  if ! runuser -u "$runtime_user" -- php -r 'exit(is_readable($argv[1]) ? 0 : 1);' "$config_path"; then
    echo "[!] Runtime config permission contract failed: config.php is not readable by runtime user: $runtime_user" >&2
    return 1
  fi
  if runtime_user_can_write_path "$runtime_user" "$config_path"; then
    echo "[!] Runtime config permission contract failed: config.php is writable by runtime user: $runtime_user" >&2
    return 1
  fi
  if runtime_user_can_write_path "$runtime_user" "$app_root"; then
    echo "[!] Runtime config permission contract failed: app root is writable by runtime user: $runtime_user" >&2
    return 1
  fi

  runtime_config_path_matches "$app_root" "$RUNTIME_CONFIG_TX_APP_IDENTITY" directory || return 1
  runtime_config_path_matches "$config_path" "$RUNTIME_CONFIG_TX_CONFIG_IDENTITY" file || return 1
  validate_root_controlled_ancestors "$app_root" "$runtime_user" || return 1

  RUNTIME_CONFIG_TX_ACTIVE=0
  trap - EXIT

  echo "[OK] Runtime config permission contract verified: app_owner=$app_owner app_mode=$app_mode config_owner=$config_owner config_mode=$config_mode config_links=$config_links runtime_user=$runtime_user readable=yes writable=no replaceable=no"
}

runtime_config_permissions_cli() {
  local action="${1:-}"
  local app_root=""
  local runtime_user=""

  [[ -n "$action" ]] && shift
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --app-root)
        [[ $# -ge 2 ]] || return 1
        app_root="$2"
        shift 2
        ;;
      --runtime-user)
        [[ $# -ge 2 ]] || return 1
        runtime_user="$2"
        shift 2
        ;;
      *)
        echo "[!] Unknown runtime config permission option: $1" >&2
        return 1
        ;;
    esac
  done

  [[ "$action" == "harden" || "$action" == "verify" ]] || {
    echo "[!] Runtime config permission action must be harden or verify." >&2
    return 1
  }
  [[ -n "$app_root" && -n "$runtime_user" ]] || {
    echo "[!] Runtime config permission mode requires --app-root and --runtime-user." >&2
    return 1
  }
  [[ "$EUID" -eq 0 ]] || {
    echo "[!] Runtime config permission mode requires root privileges." >&2
    return 1
  }

  for command_name in chmod chown id php realpath runuser stat; do
    command -v "$command_name" >/dev/null 2>&1 || {
      echo "[!] Required command missing: $command_name" >&2
      return 1
    }
  done

  validate_trusted_deploy_script "$CURRENT_SCRIPT_PATH" "$runtime_user" || return 1
  runtime_config_permissions_contract "$action" "$app_root" "$runtime_user"
}

apply_runtime_config_permissions() {
  local action="$1"
  local app_root="$2"

  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] bash '$CURRENT_SCRIPT_PATH' --runtime-config-permissions '$action' --app-root '$app_root' --runtime-user '$WEBUSER'"
    return 0
  fi

  bash "$CURRENT_SCRIPT_PATH" \
    --runtime-config-permissions "$action" \
    --app-root "$app_root" \
    --runtime-user "$WEBUSER"
}

harden_and_verify_runtime_config() {
  local app_root="$1"

  apply_runtime_config_permissions harden "$app_root" \
    && apply_runtime_config_permissions verify "$app_root"
}

read_healthz_token() {
  local token

  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "__DRY_RUN_TOKEN__"
    return 0
  fi

  [[ -r "$HEALTHZ_TOKEN_FILE" ]] || {
    echo "[!] Token file unreadable: $HEALTHZ_TOKEN_FILE"
    return 1
  }

  token="$(tr -d '\r\n' < "$HEALTHZ_TOKEN_FILE")"
  [[ -n "$token" ]] || {
    echo "[!] Token file is empty: $HEALTHZ_TOKEN_FILE"
    return 1
  }

  printf '%s' "$token"
}

probe_renderer_health() {
  local attempt
  local code

  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] renderer health probe: $RENDERER_HEALTH_URL (${RENDERER_HEALTH_RETRIES}x, ${RENDERER_HEALTH_SLEEP_SECONDS}s)"
    return 0
  fi

  for ((attempt = 1; attempt <= RENDERER_HEALTH_RETRIES; attempt++)); do
    code="$(curl -sS -o /dev/null -w '%{http_code}' "$RENDERER_HEALTH_URL" || echo 000)"
    if [[ "$code" == "200" ]]; then
      echo "[OK] Renderer health is up: HTTP 200 (attempt $attempt/$RENDERER_HEALTH_RETRIES)"
      return 0
    fi
    echo "[i] Renderer health pending: HTTP $code (attempt $attempt/$RENDERER_HEALTH_RETRIES)"
    sleep "$RENDERER_HEALTH_SLEEP_SECONDS"
  done

  echo "[!] Renderer health failed after $RENDERER_HEALTH_RETRIES attempts: $RENDERER_HEALTH_URL"
  return 1
}

probe_deep_health_contract() {
  local token
  local body_file
  local http_code
  local attempt

  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] deep health probe: $DEEP_HEALTH_URL with header X-Health-Token:<redacted> and contract status=ok + checks.pdf_renderer.ok=true (${DEEP_HEALTH_RETRIES}x, ${DEEP_HEALTH_SLEEP_SECONDS}s)"
    return 0
  fi

  token="$(read_healthz_token)" || return 1

  for ((attempt = 1; attempt <= DEEP_HEALTH_RETRIES; attempt++)); do
    body_file="$(mktemp)"
    http_code="$(curl -sS -o "$body_file" -w '%{http_code}' -H "X-Health-Token: $token" "$DEEP_HEALTH_URL" || echo 000)"

    if [[ "$http_code" != "200" ]]; then
      echo "[i] Deep health pending: HTTP $http_code from $DEEP_HEALTH_URL (attempt $attempt/$DEEP_HEALTH_RETRIES)"
      rm -f "$body_file"
      sleep "$DEEP_HEALTH_SLEEP_SECONDS"
      continue
    fi

    if php -r '
      $raw = @file_get_contents($argv[1]);
      if ($raw === false) {
          fwrite(STDERR, "deep health body read failed" . PHP_EOL);
          exit(2);
      }
      $json = json_decode($raw, true);
      if (!is_array($json)) {
          fwrite(STDERR, "deep health response is not valid JSON" . PHP_EOL);
          exit(3);
      }
      $status = $json["status"] ?? null;
      $pdfOk = $json["checks"]["pdf_renderer"]["ok"] ?? null;
      if ($status === "ok" && $pdfOk === true) {
          exit(0);
      }
      fwrite(STDERR, "deep health contract mismatch: status=" . var_export($status, true) . ", checks.pdf_renderer.ok=" . var_export($pdfOk, true) . PHP_EOL);
      exit(4);
    ' "$body_file"; then
      echo "[OK] Deep health contract passed: status=ok and checks.pdf_renderer.ok=true (attempt $attempt/$DEEP_HEALTH_RETRIES)"
      rm -f "$body_file"
      return 0
    fi

    echo "[i] Deep health contract pending: $DEEP_HEALTH_URL (attempt $attempt/$DEEP_HEALTH_RETRIES)"
    rm -f "$body_file"
    sleep "$DEEP_HEALTH_SLEEP_SECONDS"
  done

  echo "[!] Deep health contract validation failed after $DEEP_HEALTH_RETRIES attempts: $DEEP_HEALTH_URL"
  return 1
}

perform_atomic_switch() {
  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] mv '$APP' '$PREV'"
    echo "[DRY-RUN] mv '$STAGE_ROOT' '$APP'"

    return 0
  fi

  deploy_result_set_switch_phase switch_first_move_pending
  mv "$APP" "$PREV"
  deploy_result_set_switch_phase switch_partial
  deploy_result_set_switch_phase switch_second_move_pending
  mv "$STAGE_ROOT" "$APP"
  deploy_result_set_switch_phase switch_complete
}

is_positive_integer() {
  [[ "$1" =~ ^[1-9][0-9]*$ ]]
}

extract_base64_field() {
  local payload="$1"
  local field="$2"

  php -r '
    $payload = stream_get_contents(STDIN);
    $field = (string) ($argv[1] ?? "");
    if ($payload === false || $field === "") {
        exit(1);
    }
    $value = null;
    foreach (preg_split("/\\r?\\n/", trim($payload)) as $line) {
        if (!is_string($line) || $line === "") {
            continue;
        }
        [$key, $encoded] = array_pad(explode("=", $line, 2), 2, "");
        if ($key !== $field) {
            continue;
        }
        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            exit(1);
        }
        $value = $decoded;
        break;
    }
    if ($value === null) {
        exit(1);
    }
    echo $value;
  ' "$field" <<<"$payload"
}

emit_zero_surprise_incident() {
  local event="$1"
  local severity="$2"
  local reason="$3"
  local rollback_result="${4:-}"
  local report_path="${5:-}"
  local report_root="${6:-$APP}"

  if [[ -z "$ZERO_SURPRISE_INCIDENT_WEBHOOK_FILE" ]]; then
    echo "[i] Zero-surprise incident webhook not configured; skipping incident notification."
    return 0
  fi

  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] notify zero-surprise incident event='$event' severity='$severity' reason='$reason' rollback='$rollback_result' report='${report_path:-<none>}'"
    return 0
  fi

  local notify_script="${SCRIPT_ROOT}/scripts/release-gate/zero_surprise_incident_notify.php"
  [[ -r "$notify_script" ]] || {
    echo "[!] Zero-surprise incident notifier script missing or unreadable: $notify_script"
    return 0
  }

  local command=(
    php
    "$notify_script"
    "--webhook-file=$ZERO_SURPRISE_INCIDENT_WEBHOOK_FILE"
    "--event=$event"
    "--severity=$severity"
    "--release-id=$REL"
    "--reason=$reason"
    "--log-path=$LOG"
    "--breakglass-used=$ZERO_SURPRISE_BREAKGLASS_USED"
    "--timeout-seconds=$ZERO_SURPRISE_INCIDENT_TIMEOUT"
  )

  if [[ -n "$rollback_result" ]]; then
    command+=("--rollback-result=$rollback_result")
  fi
  if [[ -n "$report_path" ]]; then
    command+=("--report-path=$report_path" "--report-root=$report_root")
  fi
  if [[ -n "$ZERO_SURPRISE_BREAKGLASS_TICKET" ]]; then
    command+=("--ticket=$ZERO_SURPRISE_BREAKGLASS_TICKET")
  fi

  if ! "${command[@]}"; then
    echo "[!] Zero-surprise incident notification failed, continuing without blocking deploy/rollback."
  fi

  return 0
}

validate_breakglass_policy() {
  local disable_predeploy=0
  local disable_canary=0
  local validator_file
  local validator_runtime_code
  local validator_output

  [[ "$REQUIRE_ZERO_SURPRISE" -eq 1 ]] || disable_predeploy=1
  [[ "$ZERO_SURPRISE_CANARY_ENABLED" -eq 1 ]] || disable_canary=1

  if [[ "$disable_predeploy" -ne 1 && "$disable_canary" -ne 1 ]]; then
    return 0
  fi

  [[ -n "$ZERO_SURPRISE_BREAKGLASS_FILE" ]] || {
    echo "[!] Zero-surprise bypass requested, but --zero-surprise-breakglass-file is missing."
    return 1
  }

  ZERO_SURPRISE_BREAKGLASS_USED=1

  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] validate breakglass file '$ZERO_SURPRISE_BREAKGLASS_FILE' for release '$REL' (disable_predeploy=$disable_predeploy disable_canary=$disable_canary)"
    return 0
  fi

  validator_file="${SCRIPT_ROOT}/scripts/release-gate/lib/ZeroSurpriseBreakglassValidator.php"
  [[ -r "$validator_file" ]] || {
    echo "[!] Zero-surprise breakglass validator missing or unreadable: $validator_file"
    return 1
  }

  read -r -d '' validator_runtime_code <<'PHP' || true
require_once $argv[1];

$validator = new \ReleaseGate\ZeroSurpriseBreakglassValidator();
$result = $validator->validateFile(
    (string) ($argv[2] ?? ''),
    (string) ($argv[3] ?? ''),
    ($argv[4] ?? '0') === '1',
    ($argv[5] ?? '0') === '1',
);

if (($result['ok'] ?? false) !== true) {
    foreach (($result['errors'] ?? []) as $error) {
        fwrite(STDERR, "    - " . (string) $error . PHP_EOL);
    }
    exit(1);
}

$normalized = is_array($result['normalized'] ?? null) ? $result['normalized'] : [];
foreach (['ticket', 'reason', 'approved_by', 'expires_at_utc'] as $field) {
    $value = is_scalar($normalized[$field] ?? null) ? (string) $normalized[$field] : '';
    fwrite(STDOUT, $field . '=' . base64_encode($value) . PHP_EOL);
}
exit(0);
PHP

  echo "[i] Validating zero-surprise breakglass ack: $ZERO_SURPRISE_BREAKGLASS_FILE"
  if ! validator_output="$(php -r "$validator_runtime_code" \
    "$validator_file" \
    "$ZERO_SURPRISE_BREAKGLASS_FILE" \
    "$REL" \
    "$disable_predeploy" \
    "$disable_canary" 2> >(cat >&2))"; then
    echo "[!] Zero-surprise breakglass validation failed."
    return 1
  fi

  ZERO_SURPRISE_BREAKGLASS_TICKET="$(extract_base64_field "$validator_output" ticket || true)"
  ZERO_SURPRISE_BREAKGLASS_REASON="$(extract_base64_field "$validator_output" reason || true)"
  local breakglass_approved_by
  local breakglass_expires_at
  breakglass_approved_by="$(extract_base64_field "$validator_output" approved_by || true)"
  breakglass_expires_at="$(extract_base64_field "$validator_output" expires_at_utc || true)"

  echo "[i] Breakglass ack accepted"
  echo "    Ticket            : ${ZERO_SURPRISE_BREAKGLASS_TICKET:-<missing>}"
  echo "    Approved by       : ${breakglass_approved_by:-<missing>}"
  echo "    Expires at (UTC)  : ${breakglass_expires_at:-<missing>}"
  echo "    Disable predeploy : $disable_predeploy"
  echo "    Disable canary    : $disable_canary"

  emit_zero_surprise_incident \
    "zero_surprise_breakglass_used" \
    "warning" \
    "${ZERO_SURPRISE_BREAKGLASS_REASON:-zero-surprise breakglass used}" \
    "not_applicable"

  return 0
}

read_zero_surprise_predeploy_base_url() {
  php -r '
    $path = (string) ($argv[1] ?? "");
    if ($path === "" || !is_file($path) || !is_readable($path)) {
        exit(1);
    }
    $ini = parse_ini_file($path, false, INI_SCANNER_RAW);
    if (!is_array($ini)) {
        exit(2);
    }
    $baseUrl = trim((string) ($ini["base_url"] ?? ""));
    if ($baseUrl === "") {
        exit(3);
    }
    echo $baseUrl;
  ' "$ZERO_SURPRISE_PREDEPLOY_CREDENTIALS_FILE"
}

prepare_zero_surprise_stage_runtime() {
  local stage_config
  local stage_sample
  local base_url

  stage_config="$STAGE_ROOT/config.php"
  stage_sample="$STAGE_ROOT/config-sample.php"

  if [[ "$REQUIRE_ZERO_SURPRISE" -ne 1 ]]; then
    return 0
  fi

  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] would generate zero-surprise stage config from '$stage_sample' -> '$stage_config'"
    echo "[DRY-RUN] would ensure '$STAGE_ROOT/storage/logs/release-gate' exists for replay reports"
    return 0
  fi

  [[ -f "$stage_sample" ]] || return 1

  base_url="$(read_zero_surprise_predeploy_base_url)" \
    || return $?

  cp "$stage_sample" "$stage_config" >/dev/null 2>&1 || return $?
  mkdir -p "$STAGE_ROOT/storage/logs/release-gate" >/dev/null 2>&1 || return $?

  php "$STAGE_ROOT/scripts/release-gate/prepare_zero_surprise_stage_config.php" \
    --config="$stage_config" \
    --base-url="$base_url" \
    >/dev/null 2>&1 \
    || return $?
  return 0
}

run_zero_surprise_predeploy_replay() {
  local replay_script

  if [[ "$REQUIRE_ZERO_SURPRISE" -ne 1 ]]; then
    echo "[i] Zero-surprise predeploy replay disabled (--require-zero-surprise=0)."
    return 0
  fi

  ZERO_SURPRISE_REPORT="${ZERO_SURPRISE_REPORT:-$STAGE_ROOT/storage/logs/release-gate/zero-surprise-predeploy-${REL}-$(date -u +%Y%m%dT%H%M%SZ).json}"

  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] run zero-surprise predeploy replay from '$STAGE_ROOT' with dump '$ZERO_SURPRISE_DUMP_FILE', credentials '$ZERO_SURPRISE_PREDEPLOY_CREDENTIALS_FILE', profile '$ZERO_SURPRISE_PROFILE', report '$ZERO_SURPRISE_REPORT'"
    return 0
  fi

  replay_script="$STAGE_ROOT/scripts/release-gate/zero_surprise_replay.php"
  [[ -r "$replay_script" ]] || {
    echo "[!] Zero-surprise replay script missing or unreadable: $replay_script"
    return 1
  }

  echo "[i] Running zero-surprise predeploy replay"
  echo "    Dump file         : $ZERO_SURPRISE_DUMP_FILE"
  echo "    Credentials file  : $ZERO_SURPRISE_PREDEPLOY_CREDENTIALS_FILE"
  echo "    Profile           : $ZERO_SURPRISE_PROFILE"
  echo "    Report            : $ZERO_SURPRISE_REPORT"

  if ! (
    cd "$STAGE_ROOT" && php scripts/release-gate/zero_surprise_replay.php \
      --release-id="$REL" \
      --dump-file="$ZERO_SURPRISE_DUMP_FILE" \
      --credentials-file="$ZERO_SURPRISE_PREDEPLOY_CREDENTIALS_FILE" \
      --profile="$ZERO_SURPRISE_PROFILE" \
      --output-json="$ZERO_SURPRISE_REPORT"
  ); then
    echo "[!] Zero-surprise predeploy replay failed."
    return 1
  fi

  echo "[OK] Zero-surprise predeploy replay passed."
  return 0
}

validate_zero_surprise_report() {
  local validator_file
  local validator_runtime_code

  if [[ "$REQUIRE_ZERO_SURPRISE" -ne 1 ]]; then
    echo "[i] Zero-surprise hard-fail gate disabled (--require-zero-surprise=0)."
    return 0
  fi

  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] validate zero-surprise report '$ZERO_SURPRISE_REPORT' for release '$REL' (mode=$ZERO_SURPRISE_EXPECTED_MODE, max-age=${ZERO_SURPRISE_MAX_AGE_MINUTES}m)"
    return 0
  fi

  validator_file="$STAGE_ROOT/scripts/release-gate/lib/ZeroSurpriseReportValidator.php"
  [[ -r "$validator_file" ]] || {
    echo "[!] Zero-surprise validator missing or unreadable: $validator_file"
    return 1
  }

  read -r -d '' validator_runtime_code <<'PHP' || true
require_once $argv[1];

$validator = new \ReleaseGate\ZeroSurpriseReportValidator();
$result = $validator->validateFile(
    (string) ($argv[2] ?? ''),
    (string) ($argv[3] ?? ''),
    (string) ($argv[4] ?? ''),
    (int) ($argv[5] ?? 0),
);

if (($result['ok'] ?? false) === true) {
    exit(0);
}

$errors = $result['errors'] ?? [];
if (!is_array($errors) || $errors === []) {
    fwrite(STDERR, "    - Zero-surprise report validation failed with unknown errors." . PHP_EOL);
    exit(1);
}

foreach ($errors as $error) {
    fwrite(STDERR, "    - " . (string) $error . PHP_EOL);
}

exit(1);
PHP

  echo "[i] Validating zero-surprise report: $ZERO_SURPRISE_REPORT"
  if ! php -r "$validator_runtime_code" \
    "$validator_file" \
    "$ZERO_SURPRISE_REPORT" \
    "$REL" \
    "$ZERO_SURPRISE_EXPECTED_MODE" \
    "$ZERO_SURPRISE_MAX_AGE_MINUTES"; then
    echo "[!] Zero-surprise report validation failed."
    return 1
  fi

  echo "[OK] Zero-surprise report validation passed."
  return 0
}

run_zero_surprise_live_canary() {
  local canary_script

  if [[ "$ZERO_SURPRISE_CANARY_ENABLED" -ne 1 ]]; then
    echo "[i] Zero-surprise post-deploy canary disabled (--zero-surprise-canary-enabled=0)."
    return 0
  fi

  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] run zero-surprise live canary with credentials '$ZERO_SURPRISE_CANARY_CREDENTIALS_FILE' (timeout=${ZERO_SURPRISE_CANARY_TIMEOUT}s)"
    return 0
  fi

  canary_script="$APP/scripts/release-gate/zero_surprise_live_canary.php"
  [[ -r "$canary_script" ]] || {
    echo "[!] Zero-surprise live canary script missing or unreadable: $canary_script"
    return 1
  }

  ZERO_SURPRISE_CANARY_REPORT="$APP/storage/logs/release-gate/zero-surprise-live-canary-${REL}-$(date -u +%Y%m%dT%H%M%SZ).json"

  echo "[i] Running zero-surprise post-deploy canary"
  echo "    Credentials file : $ZERO_SURPRISE_CANARY_CREDENTIALS_FILE"
  echo "    Timeout (seconds): $ZERO_SURPRISE_CANARY_TIMEOUT"
  echo "    Profile          : $ZERO_SURPRISE_PROFILE"
  echo "    Report           : $ZERO_SURPRISE_CANARY_REPORT"

  if ! php "$canary_script" \
    --release-id="$REL" \
    --credentials-file="$ZERO_SURPRISE_CANARY_CREDENTIALS_FILE" \
    --profile="$ZERO_SURPRISE_PROFILE" \
    --timeout-seconds="$ZERO_SURPRISE_CANARY_TIMEOUT" \
    --output-json="$ZERO_SURPRISE_CANARY_REPORT"; then
    echo "[!] Zero-surprise post-deploy canary failed."
    return 1
  fi

  echo "[OK] Zero-surprise post-deploy canary passed."
  return 0
}

runtime_config_rollback_contract() {
  local active_path="$1"
  local previous_path="$2"
  local failed_path="$3"
  local runtime_user="$4"
  local active_identity
  local previous_identity
  local common_parent
  local canonical_path
  local permission_ok=1

  for path_name in active_path previous_path failed_path; do
    while [[ "${!path_name}" != "/" && "${!path_name}" == */ ]]; do
      printf -v "$path_name" '%s' "${!path_name%/}"
    done
    [[ "${!path_name}" == /* && "${!path_name}" != "/" ]] || {
      echo "[!] Runtime config rollback failed: release paths must be absolute non-root paths: ${!path_name}" >&2
      return 1
    }
  done

  [[ "$active_path" != "$previous_path" && "$active_path" != "$failed_path" && "$previous_path" != "$failed_path" ]] \
    || {
      echo "[!] Runtime config rollback failed: active, previous, and failed paths must be distinct." >&2
      return 1
    }

  for existing_path in "$active_path" "$previous_path"; do
    [[ -d "$existing_path" && ! -L "$existing_path" ]] || {
      echo "[!] Runtime config rollback failed: release must be a non-symlink directory: $existing_path" >&2
      return 1
    }
    canonical_path="$(realpath -e -- "$existing_path")" || return 1
    [[ "$canonical_path" == "$existing_path" ]] || {
      echo "[!] Runtime config rollback failed: release path must be canonical and symlink-free: $existing_path" >&2
      return 1
    }
    validate_root_controlled_ancestors "$existing_path" "$runtime_user" || return 1
  done

  [[ ! -e "$failed_path" && ! -L "$failed_path" ]] || {
    echo "[!] Runtime config rollback failed: failed release target already exists: $failed_path" >&2
    return 1
  }

  common_parent="$(dirname "$active_path")"
  [[ "$(dirname "$previous_path")" == "$common_parent" && "$(dirname "$failed_path")" == "$common_parent" ]] \
    || {
      echo "[!] Runtime config rollback failed: release paths must share one trusted parent." >&2
      return 1
    }
  canonical_path="$(realpath -e -- "$common_parent")" || return 1
  [[ "$canonical_path" == "$common_parent" ]] || {
    echo "[!] Runtime config rollback failed: release parent must be canonical and symlink-free: $common_parent" >&2
    return 1
  }

  active_identity="$(runtime_config_path_identity "$active_path")" || return 1
  previous_identity="$(runtime_config_path_identity "$previous_path")" || return 1

  mv -- "$active_path" "$failed_path" || {
    echo "[!] Runtime config rollback failed: could not move active release to failed path." >&2
    return 1
  }
  runtime_config_path_matches "$failed_path" "$active_identity" directory || {
    echo "[!] Runtime config rollback failed: failed path identity does not match prior active release." >&2
    return 1
  }

  if ! mv -- "$previous_path" "$active_path"; then
    echo "[!] Runtime config rollback failed: could not restore previous release." >&2
    if [[ ! -e "$active_path" ]] \
      && runtime_config_path_matches "$failed_path" "$active_identity" directory \
      && mv -- "$failed_path" "$active_path"; then
      echo "[i] Restored the original active release after rollback switch failure." >&2
    else
      echo "[!] Could not restore the original active release; manual intervention required." >&2
    fi
    return 1
  fi

  runtime_config_path_matches "$active_path" "$previous_identity" directory || {
    echo "[!] Runtime config rollback failed: restored app identity does not match previous release." >&2
    return 1
  }
  runtime_config_path_matches "$failed_path" "$active_identity" directory || {
    echo "[!] Runtime config rollback failed: failed release identity changed after switch." >&2
    return 1
  }

  if ! bash "$CURRENT_SCRIPT_PATH" \
    --runtime-config-permissions harden \
    --app-root "$active_path" \
    --runtime-user "$runtime_user" \
    || ! bash "$CURRENT_SCRIPT_PATH" \
      --runtime-config-permissions verify \
      --app-root "$active_path" \
      --runtime-user "$runtime_user"; then
    echo "[!] Restored release runtime config permissions are unverifiable." >&2
    permission_ok=0
  fi

  if ! bash "$CURRENT_SCRIPT_PATH" \
    --runtime-config-permissions harden \
    --app-root "$failed_path" \
    --runtime-user "$runtime_user" \
    || ! bash "$CURRENT_SCRIPT_PATH" \
      --runtime-config-permissions verify \
      --app-root "$failed_path" \
      --runtime-user "$runtime_user"; then
    echo "[!] Failed release runtime config permissions are unverifiable." >&2
    permission_ok=0
  fi

  [[ "$permission_ok" -eq 1 ]] || return 1

  echo "[OK] Runtime config rollback switch and permission contracts verified."
}

runtime_config_rollback_cli() {
  local active_path=""
  local previous_path=""
  local failed_path=""
  local runtime_user=""

  while [[ $# -gt 0 ]]; do
    case "$1" in
      --active)
        [[ $# -ge 2 ]] || return 1
        active_path="$2"
        shift 2
        ;;
      --previous)
        [[ $# -ge 2 ]] || return 1
        previous_path="$2"
        shift 2
        ;;
      --failed)
        [[ $# -ge 2 ]] || return 1
        failed_path="$2"
        shift 2
        ;;
      --runtime-user)
        [[ $# -ge 2 ]] || return 1
        runtime_user="$2"
        shift 2
        ;;
      *)
        echo "[!] Unknown runtime config rollback option: $1" >&2
        return 1
        ;;
    esac
  done

  [[ -n "$active_path" && -n "$previous_path" && -n "$failed_path" && -n "$runtime_user" ]] || {
    echo "[!] Runtime config rollback mode requires --active, --previous, --failed, and --runtime-user." >&2
    return 1
  }
  [[ "$EUID" -eq 0 ]] || {
    echo "[!] Runtime config rollback mode requires root privileges." >&2
    return 1
  }

  for command_name in chmod chown id mv php realpath runuser stat; do
    command -v "$command_name" >/dev/null 2>&1 || {
      echo "[!] Required command missing: $command_name" >&2
      return 1
    }
  done

  validate_trusted_deploy_script "$CURRENT_SCRIPT_PATH" "$runtime_user" || return 1
  runtime_config_rollback_contract "$active_path" "$previous_path" "$failed_path" "$runtime_user"
}

rollback_after_failure() {
  if [[ "${DEPLOY_RESULT_ROLLBACK_ACTIVE:-0}" != "1" ]]; then
    DEPLOY_RESULT_RECOVERY_SIGNAL_EXIT_CODE=""
  fi
  DEPLOY_RESULT_ROLLBACK_ACTIVE=1
  deploy_result_recovery_signal_traps_install
  local reason="$1"
  local failed_base="${APP}_failed_${REL}"
  local failed_path="$failed_base"
  local renderer_result="skipped"
  local deep_result="skipped"
  local config_result="skipped"
  local rollback_ok=1
  local rollback_result="rollback_failed_or_unverifiable"
  local incident_event="deploy_rollback"
  local incident_report=""
  local incident_report_root="$APP"


  if [[ -e "$failed_path" ]]; then
    failed_path="${failed_base}_$(date -u +%Y%m%d_%H%M%S)"
  fi

  {
    echo "[!] Post-switch validation failed: $reason"
    echo "[!] Starting automatic rollback"
    echo "    Failed path target : $failed_path"
    echo "    Restore source     : $PREV"
  } || true

  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] bash '$CURRENT_SCRIPT_PATH' --runtime-config-rollback --active '$APP' --previous '$PREV' --failed '$failed_path' --runtime-user '$WEBUSER'"
    echo "[DRY-RUN] validate renderer/deep health"
    deploy_result_finish "$EXIT_ROLLBACK_SUCCESS"
  fi

  config_result="ok"
  if ! bash "$CURRENT_SCRIPT_PATH" \
    --runtime-config-rollback \
    --active "$APP" \
    --previous "$PREV" \
    --failed "$failed_path" \
    --runtime-user "$WEBUSER"; then
    echo "[!] Rollback failed: release switch or runtime config permissions are unverifiable."
    config_result="failed"
    rollback_ok=0
  fi

  if [[ "$rollback_ok" -eq 1 ]]; then
    if ! reload_services; then
      echo "[!] Rollback failed: service reload failed."
      rollback_ok=0
    fi
  fi

  if [[ "$rollback_ok" -eq 1 ]]; then
    if probe_renderer_health; then
      renderer_result="ok"
    else
      renderer_result="failed"
      rollback_ok=0
    fi
  fi

  if [[ "$rollback_ok" -eq 1 ]]; then
    if probe_deep_health_contract; then
      deep_result="ok"
    else
      deep_result="failed"
      rollback_ok=0
    fi
  fi

  if [[ -n "${DEPLOY_RESULT_RECOVERY_SIGNAL_EXIT_CODE:-}" ]]; then
    rollback_ok=0
  fi

  {
    echo "[!] Deployment failed; rollback result summary"
    echo "    Failure reason      : $reason"
    echo "    Failed release path : $failed_path"
    echo "    Restored app path   : $APP"
    echo "    Config permission   : $config_result"
    echo "    Renderer check      : $renderer_result"
    echo "    Deep health check   : $deep_result"
  } || true

  if [[ "$rollback_ok" -eq 1 ]]; then
    rollback_result="rollback_succeeded"
    if [[ "$reason" == "zero-surprise canary failed" ]]; then
      incident_event="zero_surprise_canary_failed"
      incident_report="${ZERO_SURPRISE_CANARY_REPORT/$APP/$failed_path}"
      incident_report_root="$failed_path"
    fi
    emit_zero_surprise_incident \
      "$incident_event" \
      "critical" \
      "$reason" \
      "$rollback_result" \
      "$incident_report" \
      "$incident_report_root" \
      || true
    echo "[!] Rollback succeeded, deployment remains failed." || true
    deploy_result_finish "$EXIT_ROLLBACK_SUCCESS"
  fi

  if [[ "$reason" == "zero-surprise canary failed" ]]; then
    incident_event="zero_surprise_canary_failed"
    incident_report="$ZERO_SURPRISE_CANARY_REPORT"
  fi
  emit_zero_surprise_incident \
    "$incident_event" \
    "critical" \
    "$reason" \
    "$rollback_result" \
    "$incident_report" \
    "$incident_report_root" \
    || true
  echo "[!] Rollback failed or unverifiable. Manual intervention required." || true
  deploy_result_finish "$EXIT_ROLLBACK_FAILED"
}

verify_post_switch_runtime_config_contracts() {
  if ! apply_runtime_config_permissions verify "$APP"; then
    rollback_after_failure "active runtime config permission contract failed after atomic switch"
  fi
  if ! apply_runtime_config_permissions verify "$PREV"; then
    rollback_after_failure "previous runtime config permission contract failed after atomic switch"
  fi
}

if [[ "${BASH_SOURCE[0]}" != "$0" ]]; then
  return 0
fi

case "${1:-}" in
  --runtime-config-permissions)
    shift
    runtime_config_permissions_cli "$@"
    exit $?
    ;;
  --runtime-config-rollback)
    shift
    runtime_config_rollback_cli "$@" || {
      rollback_exit_code="$?"

      exit "$rollback_exit_code"
    }

    exit 0
    ;;
esac

deploy_result_trap_install

while [[ $# -gt 0 ]]; do
  case "$1" in
    --rel) REL="$2"; shift 2;;
    --app) APP="$2"; shift 2;;
    --src) SRC="$2"; shift 2;;
    --user) WEBUSER="$2"; shift 2;;
    --reload) RELOAD_SERVICES="$2"; RELOAD_SERVICES_EXPLICIT=1; shift 2;;
    --result-file) DEPLOY_RESULT_RECEIPT_PATH="$2"; shift 2;;
    --dry-run) DRYRUN=1; shift 1;;
    --no-mark) MARK_RELEASE=0; shift 1;;
    --renderer-health-url) RENDERER_HEALTH_URL="$2"; shift 2;;
    --deep-health-url) DEEP_HEALTH_URL="$2"; shift 2;;
    --healthz-token-file) HEALTHZ_TOKEN_FILE="$2"; shift 2;;
    --zero-surprise-report) ZERO_SURPRISE_REPORT="$2"; shift 2;;
    --zero-surprise-dump-file) ZERO_SURPRISE_DUMP_FILE="$2"; shift 2;;
    --zero-surprise-predeploy-credentials-file) ZERO_SURPRISE_PREDEPLOY_CREDENTIALS_FILE="$2"; shift 2;;
    --zero-surprise-profile) ZERO_SURPRISE_PROFILE="$2"; shift 2;;
    --zero-surprise-max-age-minutes) ZERO_SURPRISE_MAX_AGE_MINUTES="$2"; shift 2;;
    --require-zero-surprise) REQUIRE_ZERO_SURPRISE="$2"; shift 2;;
    --zero-surprise-breakglass-file) ZERO_SURPRISE_BREAKGLASS_FILE="$2"; shift 2;;
    --zero-surprise-canary-enabled) ZERO_SURPRISE_CANARY_ENABLED="$2"; shift 2;;
    --zero-surprise-canary-timeout) ZERO_SURPRISE_CANARY_TIMEOUT="$2"; shift 2;;
    --zero-surprise-canary-credentials-file) ZERO_SURPRISE_CANARY_CREDENTIALS_FILE="$2"; shift 2;;
    --zero-surprise-incident-webhook-file) ZERO_SURPRISE_INCIDENT_WEBHOOK_FILE="$2"; shift 2;;
    --zero-surprise-incident-timeout) ZERO_SURPRISE_INCIDENT_TIMEOUT="$2"; shift 2;;
    -h|--help) usage; exit 0;;
    *) die "[!] Unknown option: $1";;
  esac
done

if [[ -n "$DEPLOY_RESULT_RECEIPT_PATH" && "$DRYRUN" -eq 1 ]]; then
  die "[!] --result-file cannot be used with --dry-run."
fi
deploy_result_receipt_prepare || die "[!] Refusing unsafe deploy result target."

[[ -n "$REL" ]] || die "[!] --rel is required."
[[ "$REQUIRE_ZERO_SURPRISE" == "0" || "$REQUIRE_ZERO_SURPRISE" == "1" ]] \
  || die "[!] --require-zero-surprise must be 0 or 1."
[[ "$ZERO_SURPRISE_CANARY_ENABLED" == "0" || "$ZERO_SURPRISE_CANARY_ENABLED" == "1" ]] \
  || die "[!] --zero-surprise-canary-enabled must be 0 or 1."
is_positive_integer "$ZERO_SURPRISE_MAX_AGE_MINUTES" \
  || die "[!] --zero-surprise-max-age-minutes must be a positive integer."
is_positive_integer "$ZERO_SURPRISE_CANARY_TIMEOUT" \
  || die "[!] --zero-surprise-canary-timeout must be a positive integer."
is_positive_integer "$ZERO_SURPRISE_INCIDENT_TIMEOUT" \
  || die "[!] --zero-surprise-incident-timeout must be a positive integer."
if [[ "$REQUIRE_ZERO_SURPRISE" -eq 1 ]]; then
  [[ -n "$ZERO_SURPRISE_DUMP_FILE" ]] || die "[!] --zero-surprise-dump-file is required when --require-zero-surprise=1."
  [[ -n "$ZERO_SURPRISE_PREDEPLOY_CREDENTIALS_FILE" ]] \
    || die "[!] --zero-surprise-predeploy-credentials-file is required when --require-zero-surprise=1."
fi
if [[ "$ZERO_SURPRISE_CANARY_ENABLED" -eq 1 ]]; then
  [[ -n "$ZERO_SURPRISE_CANARY_CREDENTIALS_FILE" ]] \
    || die "[!] --zero-surprise-canary-credentials-file is required when --zero-surprise-canary-enabled=1."
fi
if [[ "$REQUIRE_ZERO_SURPRISE" -eq 0 || "$ZERO_SURPRISE_CANARY_ENABLED" -eq 0 ]]; then
  [[ -n "$ZERO_SURPRISE_BREAKGLASS_FILE" ]] \
    || die "[!] --zero-surprise-breakglass-file is required when any zero-surprise bypass is requested."
fi
if [[ -z "$ZERO_SURPRISE_PROFILE" ]]; then
  die "[!] --zero-surprise-profile must not be empty."
fi
absolutize_path_var HEALTHZ_TOKEN_FILE
absolutize_path_var ZERO_SURPRISE_REPORT
absolutize_path_var ZERO_SURPRISE_DUMP_FILE
absolutize_path_var ZERO_SURPRISE_PREDEPLOY_CREDENTIALS_FILE
absolutize_path_var ZERO_SURPRISE_BREAKGLASS_FILE
absolutize_path_var ZERO_SURPRISE_CANARY_CREDENTIALS_FILE
absolutize_path_var ZERO_SURPRISE_INCIDENT_WEBHOOK_FILE
resolve_reload_services
validate_trusted_deploy_script "$CURRENT_SCRIPT_PATH" "$WEBUSER" \
  || die "[!] Host deploy script trust contract failed."

if [[ "$DRYRUN" -eq 0 ]]; then
  [[ -n "$HEALTHZ_TOKEN_FILE" ]] || die "[!] --healthz-token-file is required for non-dry deployments."
  [[ -r "$HEALTHZ_TOKEN_FILE" ]] || die "[!] Token file is not readable: $HEALTHZ_TOKEN_FILE"
  if [[ "$REQUIRE_ZERO_SURPRISE" -eq 1 ]]; then
    [[ -r "$ZERO_SURPRISE_DUMP_FILE" ]] \
      || die "[!] Predeploy dump file is not readable: $ZERO_SURPRISE_DUMP_FILE"
    [[ -r "$ZERO_SURPRISE_PREDEPLOY_CREDENTIALS_FILE" ]] \
      || die "[!] Predeploy credentials file is not readable: $ZERO_SURPRISE_PREDEPLOY_CREDENTIALS_FILE"
  fi
  if [[ "$ZERO_SURPRISE_CANARY_ENABLED" -eq 1 ]]; then
    [[ -r "$ZERO_SURPRISE_CANARY_CREDENTIALS_FILE" ]] \
      || die "[!] Canary credentials file is not readable: $ZERO_SURPRISE_CANARY_CREDENTIALS_FILE"
  fi
  [[ -n "$ZERO_SURPRISE_INCIDENT_WEBHOOK_FILE" ]] \
    || die "[!] --zero-surprise-incident-webhook-file is required for non-dry deployments."
  [[ -r "$ZERO_SURPRISE_INCIDENT_WEBHOOK_FILE" ]] \
    || die "[!] Incident webhook file is not readable: $ZERO_SURPRISE_INCIDENT_WEBHOOK_FILE"
  if [[ -n "$ZERO_SURPRISE_BREAKGLASS_FILE" ]]; then
    [[ -r "$ZERO_SURPRISE_BREAKGLASS_FILE" ]] \
      || die "[!] Breakglass file is not readable: $ZERO_SURPRISE_BREAKGLASS_FILE"
  fi
fi

ARCHIVE="${SRC}/${REL}.tar.gz"
STAGE="${APP}_${REL}_stage"
PREV="${APP}_prev_${REL}"
LOG="/var/log/deploy_ea_${REL}.log"

mkdir -p "$(dirname "$LOG")"
if [[ "$DRYRUN" -eq 1 && ! -w "$(dirname "$LOG")" ]]; then
  echo "[i] Dry-run log path is not writable, streaming output without tee: $LOG"
else
  exec > >(tee -a "$LOG") 2>&1
fi


echo "[i] Deploy Easy!Appointments"
echo "    Release              : $REL"
echo "    Archive              : $ARCHIVE"
echo "    Live                 : $APP"
echo "    Stage                : $STAGE"
echo "    Prev                 : $PREV"
echo "    Web user             : $WEBUSER"
echo "    Reload services      : $RELOAD_SERVICES"
echo "    Renderer health URL  : $RENDERER_HEALTH_URL"
echo "    Deep health URL      : $DEEP_HEALTH_URL"
echo "    Token file           : ${HEALTHZ_TOKEN_FILE:-<not-set-dry-run>}"
echo "    Zero-surprise gate   : $REQUIRE_ZERO_SURPRISE"
echo "    Zero-surprise dump   : ${ZERO_SURPRISE_DUMP_FILE:-<not-set>}"
echo "    Zero-surprise creds  : ${ZERO_SURPRISE_PREDEPLOY_CREDENTIALS_FILE:-<not-set>}"
echo "    Zero-surprise report : ${ZERO_SURPRISE_REPORT:-<auto>}"
echo "    Zero-surprise profile: ${ZERO_SURPRISE_PROFILE}"
echo "    Zero-surprise max age: ${ZERO_SURPRISE_MAX_AGE_MINUTES}m"
echo "    Breakglass file      : ${ZERO_SURPRISE_BREAKGLASS_FILE:-<not-set>}"
echo "    Canary enabled       : $ZERO_SURPRISE_CANARY_ENABLED"
echo "    Canary timeout       : ${ZERO_SURPRISE_CANARY_TIMEOUT}s"
echo "    Canary credentials   : ${ZERO_SURPRISE_CANARY_CREDENTIALS_FILE:-<not-set>}"
echo "    Incident webhook     : ${ZERO_SURPRISE_INCIDENT_WEBHOOK_FILE:-<not-set>}"
echo "    Incident timeout     : ${ZERO_SURPRISE_INCIDENT_TIMEOUT}s"
echo "    Logfile              : $LOG"

[[ -f "$ARCHIVE" ]] || die "[!] Archive not found: $ARCHIVE"
[[ -d "$APP" ]] || die "[!] Live directory missing: $APP"
[[ -f "$APP/config.php" ]] || die "[!] Missing root config in live app: $APP/config.php"
if [[ -e "$PREV" ]]; then
  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] would fail because PREV already exists: $PREV"
  else
    die "[!] Previous release path already exists (cleanup required): $PREV"
  fi
fi

# Pre-switch mandatory gates: runtime tool checks.
require_command curl
require_command php
require_command runuser
require_command rsync
if [[ "$REQUIRE_ZERO_SURPRISE" -eq 1 ]]; then
  require_command docker
  require_docker_compose
fi
initialize_service_control
if [[ -e "$STAGE" ]]; then
  run_shell "rm -rf '$STAGE'"
fi
run_shell "mkdir -p '$STAGE'"
run_shell "tar -xzf '$ARCHIVE' -C '$STAGE'"

if [[ "$DRYRUN" -eq 0 ]]; then
  CAND="$(find "$STAGE" -type f -path '*/application/config/config.php' | head -n 1 || true)"
  [[ -n "$CAND" ]] || die "[!] No CI config found in extracted stage. Aborting."
  STAGE_ROOT="${CAND%/application/config/config.php}"
  echo "[i] STAGE_ROOT = $STAGE_ROOT"
else
  echo "[DRY-RUN] would detect STAGE_ROOT by find(*/application/config/config.php)"
  STAGE_ROOT="$STAGE"
fi

validate_stage_release_artifact
validate_deploy_script_drift
validate_breakglass_policy || die "[!] Zero-surprise breakglass policy validation failed."
prepare_predeploy_stage_permissions \
  || die "[!] Predeploy stage permissions failed. Aborting before atomic switch."
run_zero_surprise_predeploy_gate \
  || die "[!] Zero-surprise pre-deploy gate failed. Aborting before atomic switch."


run_shell "cp '$APP/config.php' '$STAGE_ROOT/config.php'"
run_shell "mkdir -p '$STAGE_ROOT/storage'"
sync_live_storage_to_stage \
  || die "[!] Live storage transfer failed. Aborting before atomic switch."

normalize_stage_permissions \
  || die "[!] Final staged permission normalization failed. Aborting before atomic switch."

verify_pre_switch_runtime_config_contracts \
  || die "[!] Runtime config permission contract failed. Aborting before atomic switch."

perform_atomic_switch

verify_post_switch_runtime_config_contracts

if ! reload_services; then
  rollback_after_failure "service reload failed"
fi

if ! probe_renderer_health; then
  rollback_after_failure "renderer health check failed ($RENDERER_HEALTH_URL)"
fi

if ! probe_deep_health_contract; then
  rollback_after_failure "deep health contract failed ($DEEP_HEALTH_URL)"
fi

if ! run_zero_surprise_live_canary; then
  rollback_after_failure "zero-surprise canary failed"
fi

if [[ "$MARK_RELEASE" -eq 1 ]]; then
  run_shell "bash -lc 'echo \"$REL  \$(date -u +%FT%TZ)\" > \"$APP/_RELEASE\"'"
fi

if command -v curl >/dev/null 2>&1; then
  if [[ "$DRYRUN" -eq 1 ]]; then
    echo "[DRY-RUN] curl -fsS http://localhost/ >/dev/null && echo '[OK] HTTP-Check localhost/' || echo '[i] HTTP-Check skipped/failed (non-critical)'"
  else
    if curl -fsS http://localhost/ >/dev/null; then
      echo "[OK] HTTP-Check localhost/"
    else
      echo "[i] HTTP-Check skipped/failed (non-critical)"
    fi
  fi
fi

deploy_result_finalize 0
echo "[✓] Deployment completed: $APP"
echo "    Archive        : $ARCHIVE"
echo "    Previous       : $PREV"
echo "    Log            : $LOG"

echo
echo "Rollback (manual fallback):"
MANUAL_FAILED_PATH="${APP}_failed_${REL}"
echo "  bash '$CURRENT_SCRIPT_PATH' --runtime-config-rollback --active '$APP' --previous '$PREV' --failed '$MANUAL_FAILED_PATH' --runtime-user '$WEBUSER'"

deploy_result_finish 0
