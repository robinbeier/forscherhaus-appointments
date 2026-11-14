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

preflight_line="$(rg -n '^    preflight_local_runtime$' "$IMPORT_SCRIPT" | cut -d: -f1)"
remote_line="$(rg -n '^    create_or_select_remote_backup$' "$IMPORT_SCRIPT" | cut -d: -f1)"
up_line="$(rg -n 'compose up -d --pull never --no-build mysql php-fpm nginx' "$IMPORT_SCRIPT" | cut -d: -f1)"
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
