#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/../.." && pwd)"
SMOKE_SCRIPT="$ROOT_DIR/scripts/ci/compose_sensitive_ports_smoke.sh"
IMPORT_SCRIPT="$ROOT_DIR/scripts/import_prod_backup.sh"
workspace="$(mktemp -d "${TMPDIR:-/tmp}/fh-compose-guardrail.XXXXXX")"
trap 'rm -rf -- "$workspace"' EXIT

real_docker="$(command -v docker)"
compose_json="$workspace/secure-compose.json"
printf '%s\n' '{"services":{"mysql":{"ports":[{"protocol":"tcp","host_ip":"127.0.0.1","target":3306,"published":"3306"}]},"nginx":{"ports":[{"protocol":"tcp","host_ip":"127.0.0.1","target":80,"published":"80"}]},"phpmyadmin":{"ports":[{"protocol":"tcp","host_ip":"127.0.0.1","target":80,"published":"8080"}]}}}' > "$compose_json"

docker_shim="$workspace/docker"
cat > "$docker_shim" <<EOF
#!/usr/bin/env bash
set -euo pipefail
if [[ "\${1:-}" == version ]]; then
    printf '%s\\n' "\${FAKE_DOCKER_VERSION:-29.8.0}"
    exit 0
fi
if [[ "\${1:-}" == compose ]]; then
    cat "$compose_json"
    exit 0
fi
exec "$real_docker" "\$@"
EOF
chmod 0700 "$docker_shim"

run_expect_failure() {
    local expected_marker="$1"
    shift
    local output status
    set +e
    output="$("$@" 2>&1)"
    status=$?
    set -e
    [[ "$status" -ne 0 ]] || {
        printf 'Expected failure did not occur: %s\n' "$expected_marker" >&2
        return 1
    }
    grep -Fqx "$expected_marker" <<<"$output"
}

run_expect_failure 'compose_sensitive_ports=unsupported_docker_engine' \
    env FAKE_DOCKER_VERSION=27.3.1 PATH="$workspace:$PATH" "$SMOKE_SCRIPT"
run_expect_failure 'compose_sensitive_ports=unsupported_docker_engine' \
    env FAKE_DOCKER_VERSION=unparseable PATH="$workspace:$PATH" "$SMOKE_SCRIPT"

FAKE_DOCKER_VERSION=29.8.0 PATH="$workspace:$PATH" "$SMOKE_SCRIPT" |
    grep -Fqx 'compose_sensitive_ports=passed checks=mysql:3306->3306,nginx:80->80,phpmyadmin:80->8080 host_ip=127.0.0.1'

ssh_calls="$workspace/ssh-calls"
scp_calls="$workspace/scp-calls"
cat > "$workspace/ssh" <<EOF
#!/usr/bin/env bash
printf 'ssh-called\\n' >> "$ssh_calls"
exit 0
EOF
cat > "$workspace/scp" <<EOF
#!/usr/bin/env bash
printf 'scp-called\\n' >> "$scp_calls"
exit 0
EOF
chmod 0700 "$workspace/ssh" "$workspace/scp"

run_expect_failure 'compose_sensitive_ports=unsupported_docker_engine' \
    env FAKE_DOCKER_VERSION=27.3.1 \
        PATH="$workspace:$PATH" \
        PROD_SSH_TARGET='synthetic@invalid' \
        LOCAL_DOWNLOAD_ROOT="$workspace/downloads" \
        bash "$IMPORT_SCRIPT" --core-services-only

[[ ! -e "$ssh_calls" ]] || { echo 'Import contacted ssh before the preflight.' >&2; exit 1; }
[[ ! -e "$scp_calls" ]] || { echo 'Import contacted scp before the preflight.' >&2; exit 1; }
[[ ! -e "$workspace/downloads" ]] || { echo 'Import created local download state before the preflight.' >&2; exit 1; }

printf 'compose_sensitive_ports_guardrail=passed\n'
