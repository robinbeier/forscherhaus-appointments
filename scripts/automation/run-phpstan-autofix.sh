#!/usr/bin/env bash
set -euo pipefail

COMPOSE_PROJECT="${COMPOSE_PROJECT:-fh-phpstan-auto}"
COMPOSE_SERVICE="${COMPOSE_SERVICE:-php-fpm}"
CONTAINER_COMPOSER_CACHE="${CONTAINER_COMPOSER_CACHE:-/tmp/composer-cache}"
RUN_BOOTSTRAP="${RUN_BOOTSTRAP:-1}"
CODEX_BIN="${CODEX_BIN:-codex}"
CODEX_MODEL="${CODEX_MODEL:-}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BOOTSTRAP_SCRIPT="$SCRIPT_DIR/bootstrap-phpstan-autofix.sh"
TMP_DIR=""

readonly STATUS_FIXED_AND_PR="fixed-and-pr"
readonly STATUS_REPORT_ONLY="report-only"
readonly STATUS_SKIPPED_DRAFT="skipped-draft-exists"

print_report() {
    local status="$1"
    local reason="$2"
    shift 2

    printf 'status: %s\n' "$status"
    printf 'reason: %s\n' "$reason"
    printf 'verification:\n'

    if [[ "$#" -eq 0 ]]; then
        printf '  - none\n'
        return 0
    fi

    local cmd
    for cmd in "$@"; do
        printf '  - %s\n' "$cmd"
    done
}

fail_with_report() {
    local reason="$1"
    shift
    print_report "$STATUS_REPORT_ONLY" "$reason" "$@"
    exit 0
}

require_cmd() {
    command -v "$1" >/dev/null 2>&1 || fail_with_report "missing-$1-binary" "command -v $1"
}

require_file() {
    [[ -f "$1" ]] || fail_with_report "missing-required-files" "test -f composer.json && test -f composer.lock && test -f phpstan.neon.dist"
}

cleanup() {
    if [[ -n "${TMP_DIR:-}" && -d "${TMP_DIR:-}" ]]; then
        rm -rf "$TMP_DIR"
    fi
}

run_bootstrap_if_enabled() {
    if [[ "$RUN_BOOTSTRAP" != "1" ]]; then
        return 0
    fi

    if [[ ! -f "$BOOTSTRAP_SCRIPT" ]]; then
        fail_with_report "runner-bootstrap-missing" "test -f $BOOTSTRAP_SCRIPT"
    fi

    if ! COMPOSE_PROJECT="$COMPOSE_PROJECT" \
        COMPOSE_SERVICE="$COMPOSE_SERVICE" \
        CONTAINER_COMPOSER_CACHE="$CONTAINER_COMPOSER_CACHE" \
        bash "$BOOTSTRAP_SCRIPT" >&2; then
        fail_with_report "runner-bootstrap-failed" \
            "COMPOSE_PROJECT=$COMPOSE_PROJECT COMPOSE_SERVICE=$COMPOSE_SERVICE bash $BOOTSTRAP_SCRIPT"
    fi
}

write_schema_file() {
    local path="$1"

    cat >"$path" <<'JSON'
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "type": "object",
  "required": ["status", "reason", "verification"],
  "properties": {
    "status": {
      "type": "string",
      "enum": ["fixed-and-pr", "report-only", "skipped-draft-exists"]
    },
    "reason": {
      "type": "string",
      "minLength": 1
    },
    "verification": {
      "type": "array",
      "items": {
        "type": "string",
        "minLength": 1
      },
      "minItems": 1
    }
  },
  "additionalProperties": false
}
JSON
}

write_prompt_file() {
    local path="$1"

    cat >"$path" <<EOF
You are the PHPStan autofix automation. Follow this deterministic flow and do not deviate.

Execution environment contract:
- For PHP/composer/phpstan commands, execute in Docker:
  docker compose -p ${COMPOSE_PROJECT} exec -T ${COMPOSE_SERVICE} sh -lc '<command>'
- For git/gh commands, execute on the host shell.
- Record verification commands exactly as executed, in order.

1) Preflight
- Run \`test -f composer.json && test -f composer.lock && test -f phpstan.neon.dist\`.
- If that fails, output \`status: report-only\`, \`reason: missing-required-files\`, include \`verification:\` commands, and stop.
- If \`vendor/bin/phpstan\` is missing, run \`docker compose -p ${COMPOSE_PROJECT} exec -T ${COMPOSE_SERVICE} sh -lc 'COMPOSER_CACHE_DIR=${CONTAINER_COMPOSER_CACHE} composer install --no-interaction --prefer-dist --no-progress'\`.
- If install fails, output \`status: report-only\`, \`reason: composer-install-failed\`, include \`verification:\` commands, and stop.
- Run \`docker compose -p ${COMPOSE_PROJECT} exec -T ${COMPOSE_SERVICE} sh -lc 'test -x vendor/bin/phpstan'\`.
- If that fails, output \`status: report-only\`, \`reason: missing-phpstan-binary\`, include \`verification:\` commands, and stop.

2) Initial scan and issue selection
- Run \`docker compose -p ${COMPOSE_PROJECT} exec -T ${COMPOSE_SERVICE} sh -lc 'composer phpstan:application'\`.
- If exit code is 0, output \`status: report-only\`, \`reason: no-actionable-issue\`, include \`verification:\` commands, and stop.
- Select exactly one high-confidence PHPStan type issue under \`application/\` not already covered by the current baseline.
- Edit exactly one file per run and only within \`application/\`.
- Never change \`system/\`, \`build/\`, \`docker/\`, \`.github/\`, \`composer.json\`, \`composer.lock\`, \`package.json\`, \`package-lock.json\`, \`phpstan.neon.dist\`, or \`phpstan-baseline.neon\`.
- Do not add ignores, do not relax rules, do not change dependencies.
- Apply the smallest behavior-preserving fix. If ambiguous, output \`status: report-only\`, \`reason: ambiguous-fix\`, include \`verification:\` commands, and stop.

3) Verification
- Run \`docker compose -p ${COMPOSE_PROJECT} exec -T ${COMPOSE_SERVICE} sh -lc 'php -l <changed_php_file>'\`.
- If lint fails, output \`status: report-only\`, \`reason: php-lint-failed\`, include \`verification:\` commands, and stop.
- Rerun \`docker compose -p ${COMPOSE_PROJECT} exec -T ${COMPOSE_SERVICE} sh -lc 'composer phpstan:application'\`.
- If rerun fails, output \`status: report-only\`, \`reason: post-fix-phpstan-failed\`, include \`verification:\` commands, and stop.

4) PR gate and creation
- Before creating branch/PR, run exactly:
  \`gh pr list --state open --search "is:draft head:codex/phpstan-auto-" --json number,headRefName,title\`
- If a matching open draft PR exists, output \`status: skipped-draft-exists\`, \`reason: existing-auto-draft-pr\`, include \`verification:\` commands, and stop.
- If all checks are green, create exactly one draft PR on branch prefix \`codex/phpstan-auto-\`.
- Use concise PR body sections: \`issue\`, \`fix\`, \`risk\`, \`verification\`.
- Add label \`codex\` always.
- Add label \`codex-automation\` only if the label exists; if missing, continue without failing.
- If PR creation or PR lookup fails, output \`status: report-only\`, \`reason: pr-gate-failed\`, include \`verification:\` commands, and stop.
- On success, output \`status: fixed-and-pr\`, \`reason: issue-fixed-and-draft-opened\`, include \`verification:\` commands.

5) Reporting format
- Return ONLY one JSON object matching this schema:
  - \`status\`: one of \`fixed-and-pr\`, \`report-only\`, \`skipped-draft-exists\`
  - \`reason\`: short machine-friendly reason
  - \`verification\`: list of commands executed in order
EOF
}

validate_json_output() {
    local path="$1"
    jq -e '
        (.status == "fixed-and-pr" or .status == "report-only" or .status == "skipped-draft-exists")
        and (.reason | type == "string" and length > 0)
        and (.verification | type == "array" and length > 0)
        and all(.verification[]; type == "string" and length > 0)
    ' "$path" >/dev/null
}

print_yaml_report_from_json() {
    local path="$1"
    local status
    local reason
    status="$(jq -r '.status' "$path")"
    reason="$(jq -r '.reason' "$path")"

    printf 'status: %s\n' "$status"
    printf 'reason: %s\n' "$reason"
    printf 'verification:\n'
    jq -r '.verification[] | "  - " + .' "$path"
}

main() {
    trap cleanup EXIT

    require_cmd "$CODEX_BIN"
    require_cmd jq
    require_cmd docker
    require_cmd gh
    require_file composer.json
    require_file composer.lock
    require_file phpstan.neon.dist

    run_bootstrap_if_enabled

    local schema_file prompt_file result_file log_file
    TMP_DIR="$(mktemp -d)"
    schema_file="$TMP_DIR/report-schema.json"
    prompt_file="$TMP_DIR/automation-prompt.txt"
    result_file="$TMP_DIR/report.json"
    log_file="$TMP_DIR/codex-exec.log"

    write_schema_file "$schema_file"
    write_prompt_file "$prompt_file"

    local -a codex_cmd
    codex_cmd=(
        "$CODEX_BIN" exec
        --dangerously-bypass-approvals-and-sandbox
        --output-schema "$schema_file"
        -o "$result_file"
        -
    )

    if [[ -n "$CODEX_MODEL" ]]; then
        codex_cmd=(
            "$CODEX_BIN" exec
            -m "$CODEX_MODEL"
            --dangerously-bypass-approvals-and-sandbox
            --output-schema "$schema_file"
            -o "$result_file"
            -
        )
    fi

    if ! "${codex_cmd[@]}" <"$prompt_file" >"$log_file" 2>&1; then
        fail_with_report "runner-codex-exec-failed" \
            "COMPOSE_PROJECT=$COMPOSE_PROJECT COMPOSE_SERVICE=$COMPOSE_SERVICE $CODEX_BIN exec --dangerously-bypass-approvals-and-sandbox --output-schema <schema> -o <result> -" \
            "tail -n 80 $log_file"
    fi

    if [[ ! -s "$result_file" ]]; then
        fail_with_report "runner-empty-codex-output" \
            "test -s $result_file" \
            "tail -n 80 $log_file"
    fi

    if ! validate_json_output "$result_file"; then
        fail_with_report "runner-invalid-codex-output" \
            "jq -e '.status and .reason and .verification' $result_file" \
            "cat $result_file"
    fi

    print_yaml_report_from_json "$result_file"
}

main "$@"
