#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(git rev-parse --show-toplevel)"
cd "$ROOT_DIR"

LOG_DIR="storage/logs/ci"
mkdir -p "$LOG_DIR"

RAW_REPORT="$LOG_DIR/deptrac-raw-report.json"
CHANGED_GATE_REPORT="$LOG_DIR/deptrac-changed-gate.json"
GITHUB_ACTIONS_LOG="$LOG_DIR/deptrac-github-actions.log"

SCOPE_PREFIXES=(
  "application/controllers/"
  "application/libraries/"
  "application/models/"
  "application/helpers/"
  "application/core/"
)

detect_diff_range() {
  local explicit_range event_name base_ref base_sha before_sha

  explicit_range="${DEPTRAC_DIFF_RANGE:-}"
  if [[ -n "$explicit_range" ]]; then
    echo "$explicit_range"
    return 0
  fi

  event_name="${GITHUB_EVENT_NAME:-local}"

  if [[ "$event_name" == "pull_request" ]]; then
    base_ref="${GITHUB_BASE_REF:-main}"
    git fetch --no-tags origin "$base_ref" >/dev/null 2>&1 || true

    base_sha="$(git merge-base HEAD "origin/$base_ref" 2>/dev/null || true)"
    if [[ -n "$base_sha" ]]; then
      echo "$base_sha...HEAD"
      return 0
    fi

    echo "HEAD~1...HEAD"
    return 0
  fi

  if [[ "$event_name" == "push" ]]; then
    before_sha="${GITHUB_EVENT_BEFORE:-}"
    if [[ -n "$before_sha" && "$before_sha" != "0000000000000000000000000000000000000000" ]]; then
      echo "$before_sha...HEAD"
      return 0
    fi
  fi

  echo "HEAD~1...HEAD"
}

is_in_scope() {
  local file="$1"
  local prefix

  [[ "$file" == *.php ]] || return 1

  for prefix in "${SCOPE_PREFIXES[@]}"; do
    if [[ "$file" == "$prefix"* ]]; then
      return 0
    fi
  done

  return 1
}

DIFF_RANGE="$(detect_diff_range)"
changed_scope_files=()

set +e
changed_files_output="$(git diff --name-only --diff-filter=ACMR "$DIFF_RANGE" 2>/dev/null)"
git_diff_exit=$?
set -e

if [[ "$git_diff_exit" -ne 0 ]]; then
  cat > "$CHANGED_GATE_REPORT" <<JSON
{
  "tool": "deptrac-changed-gate",
  "status": "error",
  "reason": "Failed to compute changed files for diff range.",
  "diff_range": "$DIFF_RANGE",
  "changed_scope_files": []
}
JSON
  : > "$GITHUB_ACTIONS_LOG"
  echo "::error::git diff failed for range '$DIFF_RANGE'."
  exit 1
fi

while IFS= read -r file; do
  [[ -z "$file" ]] && continue
  if is_in_scope "$file"; then
    changed_scope_files+=("$file")
  fi
done <<< "$changed_files_output"

if [[ "${#changed_scope_files[@]}" -eq 0 ]]; then
  cat > "$CHANGED_GATE_REPORT" <<JSON
{
  "tool": "deptrac-changed-gate",
  "status": "skipped",
  "reason": "No changed PHP files in configured Deptrac scope.",
  "diff_range": "$DIFF_RANGE",
  "changed_scope_files": []
}
JSON

  : > "$GITHUB_ACTIONS_LOG"
  echo "No changed PHP files in Deptrac scope; skipping gate."
  exit 0
fi

set +e
./vendor/bin/deptrac analyse --config-file=deptrac.yaml --formatter=json --output="$RAW_REPORT" --no-progress >/dev/null 2>&1
deptrac_json_exit=$?
./vendor/bin/deptrac analyse --config-file=deptrac.yaml --formatter=github-actions --no-progress >"$GITHUB_ACTIONS_LOG" 2>&1
deptrac_actions_exit=$?
set -e

if [[ ! -f "$RAW_REPORT" ]]; then
  cat > "$CHANGED_GATE_REPORT" <<JSON
{
  "tool": "deptrac-changed-gate",
  "status": "error",
  "reason": "Deptrac JSON report was not created.",
  "diff_range": "$DIFF_RANGE",
  "changed_scope_files": [$(printf '"%s",' "${changed_scope_files[@]}" | sed 's/,$//')],
  "deptrac_json_exit_code": $deptrac_json_exit,
  "deptrac_github_actions_exit_code": $deptrac_actions_exit
}
JSON
  echo "::error::Deptrac JSON report missing: $RAW_REPORT"
  exit 1
fi

changed_scope_tmp="$(mktemp)"
printf '%s\n' "${changed_scope_files[@]}" > "$changed_scope_tmp"

read -r changed_violation_count deptrac_report_error_count < <(
  python3 - "$RAW_REPORT" "$changed_scope_tmp" "$CHANGED_GATE_REPORT" "$ROOT_DIR" "$DIFF_RANGE" "$deptrac_json_exit" "$deptrac_actions_exit" <<'PY'
import json
import sys
from pathlib import Path

raw_report = Path(sys.argv[1])
changed_file_list = Path(sys.argv[2])
out_report = Path(sys.argv[3])
root_dir = Path(sys.argv[4]).resolve()
diff_range = sys.argv[5]
json_exit = int(sys.argv[6])
actions_exit = int(sys.argv[7])

def normalize(path: str) -> str:
    text = path.replace('\\', '/').strip()
    if text.startswith('./'):
        text = text[2:]
    return text

changed_files = {
    normalize(line)
    for line in changed_file_list.read_text(encoding='utf-8').splitlines()
    if line.strip()
}

payload = json.loads(raw_report.read_text(encoding='utf-8'))
report = payload.get('Report', {}) if isinstance(payload.get('Report'), dict) else {}
files = payload.get('files', {}) if isinstance(payload.get('files'), dict) else {}

report_errors = int(report.get('Errors', 0) or 0)
changed_violations: list[dict[str, object]] = []

def to_repo_relative(raw_path: str) -> str:
    candidate = Path(raw_path)
    if candidate.is_absolute():
        try:
            return normalize(str(candidate.resolve().relative_to(root_dir)))
        except Exception:  # noqa: BLE001
            return normalize(str(candidate))
    return normalize(str(candidate))

for file_path, file_payload in files.items():
    if not isinstance(file_payload, dict):
        continue

    repo_file = to_repo_relative(file_path)
    if repo_file not in changed_files:
        continue

    messages = file_payload.get('messages', [])
    if not isinstance(messages, list):
        continue

    for message in messages:
        if not isinstance(message, dict):
            continue
        if message.get('type') != 'error':
            continue

        changed_violations.append(
            {
                'file': repo_file,
                'line': int(message.get('line', 0) or 0),
                'message': str(message.get('message', '')),
            }
        )

status = 'passed'
if report_errors > 0 or changed_violations:
    status = 'failed'

result = {
    'tool': 'deptrac-changed-gate',
    'status': status,
    'diff_range': diff_range,
    'changed_scope_files': sorted(changed_files),
    'deptrac': {
        'json_exit_code': json_exit,
        'github_actions_exit_code': actions_exit,
        'report': {
            'violations': int(report.get('Violations', 0) or 0),
            'skipped_violations': int(report.get('Skipped violations', 0) or 0),
            'uncovered': int(report.get('Uncovered', 0) or 0),
            'allowed': int(report.get('Allowed', 0) or 0),
            'warnings': int(report.get('Warnings', 0) or 0),
            'errors': report_errors,
        },
    },
    'changed_file_violation_count': len(changed_violations),
    'changed_file_violations': changed_violations,
}

out_report.write_text(json.dumps(result, indent=2, sort_keys=True) + '\n', encoding='utf-8')
print(f"{len(changed_violations)} {report_errors}")
PY
)

rm -f "$changed_scope_tmp"

if [[ "$deptrac_report_error_count" -gt 0 ]]; then
  echo "::error::Deptrac reported parsing/runtime errors ($deptrac_report_error_count)."
  cat "$CHANGED_GATE_REPORT"
  exit 1
fi

if [[ "$changed_violation_count" -gt 0 ]]; then
  echo "::error::Deptrac changed-file gate found $changed_violation_count violation(s)."
  cat "$CHANGED_GATE_REPORT"
  exit 1
fi

echo "Deptrac changed-file gate passed (no violations in changed scoped PHP files)."
exit 0
