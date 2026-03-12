#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(git rev-parse --show-toplevel)"
cd "$ROOT_DIR"

RUN_FULL_GATE=0

usage() {
    cat <<'USAGE'
Usage: bash ./scripts/ci/run_symphony_pilot_checks.sh [--with-full-gate]

Runs deterministic local baseline checks for Symphony pilot issues.

Options:
  --with-full-gate   Also run PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh
  -h, --help         Show this help text
USAGE
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --with-full-gate)
            RUN_FULL_GATE=1
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "[symphony-pilot-checks] Unknown option: $1" >&2
            usage >&2
            exit 1
            ;;
    esac
done

echo_section() {
    echo
    echo "== $*"
}

SOAK_OUTPUT_JSON="$(mktemp -t symphony-soak-gate-baseline)"

echo_section "Symphony pilot baseline checks"
echo "[symphony-pilot-checks] deterministic order: build -> conformance -> workflow check -> sample soak gate -> optional full pre-pr gate"
echo "[symphony-pilot-checks] note: this baseline proves Symphony pilot readiness only; start_pilot bootability and repo-wide PHPUnit remain separate signals."

echo_section "Build Symphony"
npm --prefix tools/symphony run build

echo_section "Symphony conformance"
npm --prefix tools/symphony run test:conformance

echo_section "Workflow preflight (pilot-safe env)"
SYMPHONY_LINEAR_API_KEY=fake \
SYMPHONY_LINEAR_PROJECT_SLUG=fake \
SYMPHONY_CODEX_COMMAND='codex app-server' \
SYMPHONY_REPO_ROOT="$ROOT_DIR" \
    npm --prefix tools/symphony run dev -- --check --workflow "$ROOT_DIR/WORKFLOW.md"

echo_section "State snapshot sample soak gate"
python3 ./scripts/symphony/run_soak_gate.py \
    --sample-file ./tools/symphony/fixtures/state-snapshot.sample.json \
    --duration-seconds 1 \
    --poll-seconds 1 \
    --output-json "$SOAK_OUTPUT_JSON"

if [[ "$RUN_FULL_GATE" -eq 1 ]]; then
    echo_section "Full pre-PR gate (coverage enabled)"
    PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh
else
    echo
    echo "[symphony-pilot-checks] Skipping full pre-pr gate. Use --with-full-gate to include it."
fi

echo
echo "[symphony-pilot-checks] All requested checks passed."
