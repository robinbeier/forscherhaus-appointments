#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/../.." && pwd)"
IMPORT_SCRIPT="${IMPORT_SCRIPT_OVERRIDE:-$ROOT_DIR/scripts/import_prod_backup.sh}"
workspace="$(mktemp -d "${TMPDIR:-/tmp}/fh-import-runtime-preflight.XXXXXX")"
trap 'rm -rf -- "$workspace"' EXIT

fail() { printf 'import_prod_backup_runtime_preflight=failed: %s\n' "$*" >&2; exit 1; }

function_body="$(sed -n '/^preflight_local_runtime() {/,/^}$/p' "$IMPORT_SCRIPT")"
[[ -n "$function_body" ]] || fail 'could not extract preflight_local_runtime()'
required_commands_body="$(sed -n '/^require_local_commands() {/,/^}$/p' "$IMPORT_SCRIPT")"
[[ -n "$required_commands_body" ]] || fail 'could not extract require_local_commands()'

docker_mock="$workspace/docker"
cat > "$docker_mock" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "${DOCKER_CALL_LOG:?}"
if [[ "${1:-}" == image && "${2:-}" == inspect && "${MOCK_MISSING_IMAGE:-}" != '' && "$*" == *"${MOCK_MISSING_IMAGE:-}"* ]]; then
    exit 1
fi
if [[ "${1:-}" == inspect && "${2:-}" == --format* ]]; then
    printf 'sha256:0000000000000000000000000000000000000000000000000000000000000000\n'
fi
exit 0
MOCK
chmod 0700 "$docker_mock"

run_case() {
    local name="$1" missing_image="$2" expected_status="$3"
    local runner="$workspace/${name}.sh" calls="$workspace/${name}.calls" ssh_marker="$workspace/${name}.ssh"
    {
        printf '%s\n' '#!/usr/bin/env bash' 'set -euo pipefail'
        printf 'PATH=%q:$PATH\n' "$workspace"
        printf 'export DOCKER_CALL_LOG=%q\n' "$calls"
        printf 'export MOCK_MISSING_IMAGE=%q\n' "$missing_image"
        printf '%s\n' 'START_FULL_STACK=0'
        printf '%s\n' 'log() { :; }' 'die() { exit 1; }'
        printf '%s\n' 'compose() {'
        printf '%s\n' '  case "${1:-}" in'
        printf '%s\n' '    build) [[ "$*" == *"--pull=false"* && "$*" != *"--pull never"* ]] ;;'
        printf '%s\n' '    create) [[ "$*" == *"--pull never"* && "$*" == *"--no-build"* ]] ;;'
        printf '%s\n' '    ps) [[ "$*" == *"--all"* ]] && printf "php-container\n" ;;'
        printf '%s\n' '    *) return 0 ;;'
        printf '%s\n' '  esac'
        printf '%s\n' '}'
        printf '%s\n' "$function_body"
        printf 'preflight_local_runtime && printf done > %q\n' "$ssh_marker"
    } > "$runner"
    chmod 0700 "$runner"
    set +e
    bash "$runner" >/dev/null 2>&1
    local status=$?
    set -e
    [[ "$status" -eq "$expected_status" ]] || fail "$name expected status $expected_status, got $status"
    if [[ "$expected_status" -ne 0 && -e "$ssh_marker" ]]; then
        fail "$name reached the SSH/prod step after preflight failure"
    fi
}

run_case missing_mysql 'mysql:8.4.8@sha256:' 1
run_case missing_nginx 'nginx:1.29.6-alpine@sha256:' 1
run_case approved_images '' 0

# The host-side verifier is a mandatory prerequisite. Keep this check before
# any SSH/SCP call so a missing interpreter cannot download a production dump
# and only then fail during verification.
missing_python_runner="$workspace/missing-python.sh"
missing_python_ssh_marker="$workspace/missing-python.ssh"
missing_python_scp_marker="$workspace/missing-python.scp"
missing_python_output="$workspace/missing-python.output"
for command_name in bash ssh scp tar gunzip curl; do
    ln -s "$(type -P true)" "$workspace/$command_name"
done
{
    printf '%s\n' '#!/usr/bin/env bash' 'set -euo pipefail'
    printf 'PATH=%q\n' "$workspace"
    printf '%s\n' 'die() { printf "%s\\n" "$*" >&2; exit 1; }'
    printf '%s\n' 'require_cmd() { command -v "$1" >/dev/null 2>&1 || die "missing $1"; }'
    printf '%s\n' "$required_commands_body"
    printf 'ssh() { : > %q; }\n' "$missing_python_ssh_marker"
    printf 'scp() { : > %q; }\n' "$missing_python_scp_marker"
    printf '%s\n' 'require_local_commands' 'ssh production' 'scp production dump'
} > "$missing_python_runner"
chmod 0700 "$missing_python_runner"
set +e
bash "$missing_python_runner" >"$missing_python_output" 2>&1
missing_python_status=$?
set -e
[[ "$missing_python_status" -ne 0 ]] || fail 'missing host python was not rejected before remote contact'
rg -q 'missing python3' "$missing_python_output" || fail 'missing host python did not identify python3 as the failing prerequisite'
[[ ! -e "$missing_python_ssh_marker" && ! -e "$missing_python_scp_marker" ]] || fail 'missing host python still reached SSH/SCP'

# Exercise this regression against an isolated copy with the python3 check
# removed. The nested run must fail, proving the test detects that regression
# without modifying the tracked importer.
if [[ "${IMPORT_PREFLIGHT_MUTATION_CHECK:-0}" == '0' ]]; then
    mutation_copy="$workspace/import-without-python.sh"
    sed '/^[[:space:]]*require_cmd python3$/d' "$IMPORT_SCRIPT" > "$mutation_copy"
    set +e
    IMPORT_SCRIPT_OVERRIDE="$mutation_copy" IMPORT_PREFLIGHT_MUTATION_CHECK=1 bash "$0" >/dev/null 2>&1
    mutation_status=$?
    set -e
    [[ "$mutation_status" -ne 0 ]] || fail 'regression test stayed green after removing the python3 prerequisite'
fi

preflight_line="$(rg -n '^    preflight_local_runtime$' "$IMPORT_SCRIPT" | cut -d: -f1)"
required_line="$(rg -n '^    require_local_commands$' "$IMPORT_SCRIPT" | cut -d: -f1)"
remote_line="$(rg -n '^    create_or_select_remote_backup$' "$IMPORT_SCRIPT" | cut -d: -f1)"
up_line="$(rg -n 'compose up -d --pull never --no-build mysql php-fpm nginx' "$IMPORT_SCRIPT" | cut -d: -f1)"
[[ "$required_line" -lt "$remote_line" ]] || fail 'host prerequisites run after remote contact'
[[ "$preflight_line" -lt "$remote_line" ]] || fail 'preflight runs after remote contact'
[[ -n "$up_line" ]] || fail 'local startup does not disable pulling and rebuilding'
rg -q 'compose build --pull=false php-fpm' "$IMPORT_SCRIPT" || fail 'preflight build uses invalid --pull syntax'
rg -q 'compose create --pull never --no-build php-fpm' "$IMPORT_SCRIPT" || fail 'preflight create is not offline'
rg -q 'compose ps --all -q php-fpm' "$IMPORT_SCRIPT" || fail 'preflight inspects only running containers'
rg -q 'openldap_image=' "$IMPORT_SCRIPT" || fail 'full-stack OpenLDAP image is not preflight checked'
rg -q 'php_cli_image=' "$IMPORT_SCRIPT" || fail 'full-stack LDAP initializer image is not preflight checked'
if rg -n 'releases/latest|https?://[^ ]+\|[[:space:]]*(bash|sh|php)' "$ROOT_DIR/docker/php-fpm/Dockerfile" >/dev/null; then
    fail 'php-fpm Dockerfile still contains mutable or piped installer input'
fi
rg -q 'sha256sum -c' "$ROOT_DIR/docker/php-fpm/Dockerfile" || fail 'php-fpm installer checksum verification missing'
compose_config="$(docker compose -f "$ROOT_DIR/docker-compose.yml" config)"
while IFS= read -r image; do
    [[ "$image" == *@sha256:* ]] || fail "runtime image is not digest pinned: $image"
done < <(printf '%s\n' "$compose_config" | sed -n 's/^    image: //p')

printf 'import_prod_backup_runtime_preflight=passed\n'
