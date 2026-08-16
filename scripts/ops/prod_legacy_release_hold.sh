#!/usr/bin/env bash
set -euo pipefail

if [[ "$(uname -s)" == Darwin ]]; then export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"; fi
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/ops/lib/prod_common.sh
source "${SCRIPT_DIR}/lib/prod_common.sh"
SSH_OPTIONS=(-o StrictHostKeyChecking=accept-new)
PROD_SSH_TARGET="$(prod_default_ssh_target)"
PROVISION=0
CONFIRM=''

usage() {
  cat <<'USAGE'
Usage: bash scripts/ops/prod_legacy_release_hold.sh [--provision --confirm-live-write ROB-470-HOLD]
Default mode is read-only aggregate inspection.
USAGE
  prod_usage_common
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --prod-ssh-target) [[ $# -ge 2 ]] || exit 1; PROD_SSH_TARGET="$2"; shift 2;;
    --provision) PROVISION=1; shift;;
    --confirm-live-write) [[ $# -ge 2 ]] || exit 1; CONFIRM="$2"; shift 2;;
    -h|--help) usage; exit 0;;
    *) printf 'ERROR: unknown option: %s\n' "$1" >&2; exit 1;;
  esac
done
if (( PROVISION == 1 )); then
  [[ "$CONFIRM" == ROB-470-HOLD ]] || { printf 'ERROR: --provision requires --confirm-live-write ROB-470-HOLD.\n' >&2; exit 1; }
else
  [[ -z "$CONFIRM" ]] || { printf 'ERROR: --confirm-live-write is valid only with --provision.\n' >&2; exit 1; }
fi
prod_require_cmd ssh
if (( PROVISION )); then prod_print_plan 'prod-legacy-release-hold' "$PROD_SSH_TARGET" 'live-write'; else prod_print_plan 'prod-legacy-release-hold' "$PROD_SSH_TARGET" 'read-only'; fi
remote_command="/usr/bin/python3 -I -B /usr/local/libexec/fh-legacy-release-hold-v1"
if (( PROVISION )); then remote_command+=" provision ROB-470-HOLD"; fi
if ! result="$(ssh "${SSH_OPTIONS[@]}" "$PROD_SSH_TARGET" "$remote_command" 2>/dev/null)"; then
  if (( PROVISION )); then
    printf '%s\n' '{"mode":"provision","mutation_counts":{"hold_published":0,"temp_files_created":0,"temp_files_removed":0},"mutation_outcome":"unknown","reason":"transport_result_unavailable","schema":"legacy_release_hold_result.v1","status":"blocked"}'
  else
    printf '%s\n' '{"attached":false,"mode":"inspect","mutation_counts":{"hold_published":0,"temp_files_created":0,"temp_files_removed":0},"mutation_outcome":"none","pending":true,"reason":"transport_result_unavailable","schema":"legacy_release_hold_result.v1","status":"blocked","targets_preflighted":0}'
  fi
  exit 75
fi
if (( ${#result} > 4096 )); then
  result=''
fi
if ! HOLD_RESULT="$result" HOLD_MODE="$([[ $PROVISION == 1 ]] && echo provision || echo inspect)" /usr/bin/python3 -c 'import json,os,sys
try:
    value=json.loads(os.environ["HOLD_RESULT"])
    mode=os.environ["HOLD_MODE"]
    inspect_keys={"attached","mode","mutation_counts","mutation_outcome","pending","schema","status","targets_preflighted"}
    provision_keys={"mode","mutation_counts","mutation_outcome","schema","status"}
    expected=(inspect_keys if mode == "inspect" else provision_keys)
    if value.get("status") == "blocked": expected=expected|{"reason"}
    if not isinstance(value,dict) or set(value) != expected or value.get("mode") != mode or value.get("schema") != "legacy_release_hold_result.v1" or value.get("status") not in {"pass","blocked"} or value.get("mutation_outcome") not in {"none","known","unknown"} or not isinstance(value.get("mutation_counts"),dict) or set(value["mutation_counts"]) != {"hold_published","temp_files_created","temp_files_removed"} or any(type(n) is not int or n < 0 or n > 32 for n in value["mutation_counts"].values()): raise ValueError
    total=sum(value["mutation_counts"].values())
    if (value["mutation_outcome"] == "none") != (total == 0): raise ValueError
    if value["mutation_outcome"] == "known" and total == 0: raise ValueError
    if value["status"] == "pass" and value["mutation_outcome"] == "unknown": raise ValueError
    if mode == "inspect" and (type(value.get("attached")) is not bool or type(value.get("pending")) is not bool or type(value.get("targets_preflighted")) is not int or value["targets_preflighted"] < 0 or value["targets_preflighted"] > 2 or total != 0 or value["mutation_outcome"] != "none" or (value["status"] == "pass" and value["targets_preflighted"] != 2)): raise ValueError
    if value.get("status") == "blocked" and value.get("reason") not in {"host_contract_invalid","invalid_arguments","invalid_hold","mutation_ledger_invalid","root_required","invalid_release_marker","missing_required_file","unsafe_file","unsafe_tar","unsafe_tar_member","tar_entry_limit","tar_unpacked_limit","duplicate_release_ids","hold_conflict","lock_busy","lock_missing","renameat2_unavailable","foreign_helper_temp","unsafe_helper_temp","helper_temp_limit","temp_changed","preflight_changed","hold_attach_invalid","hold_parent_missing","hold_parent_changed","unsafe_hold_parent","write_failed","archive_identity_mismatch","file_changed","transport_result_unavailable","transport_result_invalid","internal_error"}: raise ValueError
except Exception:
    sys.exit(1)
print(json.dumps(value,sort_keys=True,separators=(",",":")))' <<<"$result"; then
  if (( PROVISION )); then
    printf '%s\n' '{"mode":"provision","mutation_counts":{"hold_published":0,"temp_files_created":0,"temp_files_removed":0},"mutation_outcome":"unknown","reason":"transport_result_invalid","schema":"legacy_release_hold_result.v1","status":"blocked"}'
  else
    printf '%s\n' '{"attached":false,"mode":"inspect","mutation_counts":{"hold_published":0,"temp_files_created":0,"temp_files_removed":0},"mutation_outcome":"none","pending":true,"reason":"transport_result_invalid","schema":"legacy_release_hold_result.v1","status":"blocked","targets_preflighted":0}'
  fi
  exit 75
fi
