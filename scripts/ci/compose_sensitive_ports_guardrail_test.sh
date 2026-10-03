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
non_loopback_json="$workspace/non-loopback-compose.json"
missing_mapping_json="$workspace/missing-mapping-compose.json"
incorrect_mapping_json="$workspace/incorrect-mapping-compose.json"
duplicate_mapping_json="$workspace/duplicate-mapping-compose.json"
python3 - "$compose_json" "$non_loopback_json" "$missing_mapping_json" \
    "$incorrect_mapping_json" "$duplicate_mapping_json" <<'PY'
import copy
import json
import sys

source, non_loopback, missing, incorrect, duplicate = sys.argv[1:]
with open(source, encoding="utf-8") as handle:
    secure = json.load(handle)

variants = []
model = copy.deepcopy(secure)
model["services"]["nginx"]["ports"][0]["host_ip"] = "0.0.0.0"
variants.append((non_loopback, model))

model = copy.deepcopy(secure)
model["services"]["phpmyadmin"]["ports"] = []
variants.append((missing, model))

model = copy.deepcopy(secure)
model["services"]["nginx"]["ports"][0]["target"] = 81
variants.append((incorrect, model))

model = copy.deepcopy(secure)
model["services"]["nginx"]["ports"].append(copy.deepcopy(model["services"]["nginx"]["ports"][0]))
variants.append((duplicate, model))

for path, variant in variants:
    with open(path, "w", encoding="utf-8") as handle:
        json.dump(variant, handle)
        handle.write("\n")
PY

docker_shim="$workspace/docker"
compose_calls="$workspace/compose-calls"
cat > "$docker_shim" <<EOF
#!/usr/bin/env bash
set -euo pipefail
if [[ "\${1:-}" == version ]]; then
    printf '%s\\n' "\${FAKE_DOCKER_VERSION:-29.8.0}"
    exit 0
fi
if [[ "\${1:-}" == compose ]]; then
    if [[ "\${2:-}" != -f || "\${3:-}" != "$ROOT_DIR/docker-compose.yml" ]]; then
        printf 'Docker Compose invocation did not pin the canonical compose file.\\n' >&2
        exit 97
    fi
    if [[ "\${4:-}" == down ]]; then
        printf 'compose-down-called %s\\n' "\$*" >> "$compose_calls"
        exit 42
    fi
    cat "\${FAKE_COMPOSE_JSON:-$compose_json}"
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
run_expect_failure 'compose_sensitive_ports=unsupported_docker_engine' \
    env FAKE_DOCKER_VERSION=28.0.0-rc.1 PATH="$workspace:$PATH" "$SMOKE_SCRIPT"
run_expect_failure 'compose_sensitive_ports=unsupported_docker_engine' \
    env FAKE_DOCKER_VERSION=28.3.2 PATH="$workspace:$PATH" "$SMOKE_SCRIPT"

FAKE_DOCKER_VERSION=28.0.4 PATH="$workspace:$PATH" "$SMOKE_SCRIPT" --config-only 2>"$workspace/config-only-output" |
    grep -Fqx 'compose_sensitive_ports=passed checks=mysql:3306->3306,nginx:80->80,phpmyadmin:80->8080 host_ip=127.0.0.1'
grep -Fqx 'compose_sensitive_ports=config_only' "$workspace/config-only-output"
run_expect_failure 'compose_sensitive_ports=unsupported_docker_engine' \
    env FAKE_DOCKER_VERSION=28.0.4 PATH="$workspace:$PATH" "$SMOKE_SCRIPT"
run_expect_failure 'compose_sensitive_ports=unsupported_docker_engine' \
    env COMPOSE_SENSITIVE_PORTS_CONFIG_ONLY=1 FAKE_DOCKER_VERSION=28.0.4 \
        PATH="$workspace:$PATH" "$SMOKE_SCRIPT"

FAKE_DOCKER_VERSION=29.8.0 PATH="$workspace:$PATH" "$SMOKE_SCRIPT" |
    grep -Fqx 'compose_sensitive_ports=passed checks=mysql:3306->3306,nginx:80->80,phpmyadmin:80->8080 host_ip=127.0.0.1'
FAKE_DOCKER_VERSION=28.3.3 PATH="$workspace:$PATH" "$SMOKE_SCRIPT" |
    grep -Fqx 'compose_sensitive_ports=passed checks=mysql:3306->3306,nginx:80->80,phpmyadmin:80->8080 host_ip=127.0.0.1'

run_compose_fixture_failure() {
    local fixture="$1"
    run_expect_failure 'compose_sensitive_ports=failed' \
        env FAKE_DOCKER_VERSION=29.8.0 \
            FAKE_COMPOSE_JSON="$fixture" \
            PATH="$workspace:$PATH" \
            "$SMOKE_SCRIPT"
}

run_compose_fixture_failure "$non_loopback_json"
run_compose_fixture_failure "$missing_mapping_json"
run_compose_fixture_failure "$incorrect_mapping_json"
run_compose_fixture_failure "$duplicate_mapping_json"

run_expect_failure 'compose_sensitive_ports=failed' \
    env FAKE_DOCKER_VERSION=28.0.4 \
        FAKE_COMPOSE_JSON="$non_loopback_json" \
        PATH="$workspace:$PATH" \
        "$SMOKE_SCRIPT" --config-only

COMPOSE_FILE="$workspace/hostile-compose.yml" \
    FAKE_DOCKER_VERSION=29.8.0 PATH="$workspace:$PATH" "$SMOKE_SCRIPT" |
    grep -Fqx 'compose_sensitive_ports=passed checks=mysql:3306->3306,nginx:80->80,phpmyadmin:80->8080 host_ip=127.0.0.1'

import_ssh_calls="$workspace/import-ssh-calls"
import_scp_calls="$workspace/import-scp-calls"
import_find_calls="$workspace/import-find-calls"
import_repo="$workspace/import-mini-repo"
mkdir -p "$import_repo/scripts/ci" "$import_repo/docker/mysql"
import_repo="$(cd -- "$import_repo" && pwd)"
cp "$IMPORT_SCRIPT" "$import_repo/scripts/import_prod_backup.sh"
cp "$SMOKE_SCRIPT" "$import_repo/scripts/ci/compose_sensitive_ports_smoke.sh"
chmod 0700 "$import_repo/scripts/import_prod_backup.sh" "$import_repo/scripts/ci/compose_sensitive_ports_smoke.sh"
printf '%s\n' '<?php return [];' > "$import_repo/config.php"
: > "$import_repo/docker-compose.yml"
guardrail_bash_env="$workspace/guardrail-bash-env"
cat > "$guardrail_bash_env" <<EOF
docker() {
    if [[ "\${1:-}" == version ]]; then
        printf '%s\\n' "\${FAKE_DOCKER_VERSION:-29.8.0}"
        return 0
    fi
    if [[ "\${1:-}" == compose ]]; then
        if [[ "\${2:-}" != -f || "\${3:-}" != "$import_repo/docker-compose.yml" ]]; then
            printf 'Docker Compose invocation did not pin the canonical compose file.\\n' >&2
            return 97
        fi
        if [[ "\${4:-}" == down ]]; then
            printf 'compose-down-called %s\\n' "\$*" >> "\$COMPOSE_CALLS"
            return 42
        fi
        cat "\${FAKE_COMPOSE_JSON:-$compose_json}"
        return 0
    fi
    return 97
}

ssh() {
    printf 'ssh-called %s\\n' "\$*" >> "\$IMPORT_SSH_CALLS"
    return 0
}

scp() {
    local argument destination=''
    for argument; do
        destination="\$argument"
    done
    printf 'scp-called %s\\n' "\$*" >> "\$IMPORT_SCP_CALLS"
    mkdir -p "\$(dirname -- "\$destination")"
    if [[ "\$destination" == *.sql.gz ]]; then
        printf 'synthetic dump\\n' | gzip > "\$destination"
    else
        printf 'synthetic metadata\\n' > "\$destination"
    fi
}

find() {
    printf 'find-called %s\\n' "\$*" >> "\$IMPORT_FIND_CALLS"
    return 99
}
EOF
chmod 0600 "$guardrail_bash_env"

set +e
import_output="$(
    COMPOSE_FILE="$workspace/hostile-compose.yml" \
        BASH_ENV="$guardrail_bash_env" \
        FAKE_DOCKER_VERSION=29.8.0 \
        COMPOSE_CALLS="$compose_calls" \
        IMPORT_SSH_CALLS="$import_ssh_calls" \
        IMPORT_SCP_CALLS="$import_scp_calls" \
        IMPORT_FIND_CALLS="$import_find_calls" \
        PROD_SSH_TARGET='synthetic@invalid' \
        REMOTE_BACKUP_DIR='/root/backups/easyappointments/synthetic' \
        REMOTE_BACKUP_ROOT='/root/backups/easyappointments' \
        LOCAL_DOWNLOAD_ROOT="$workspace/import-downloads" \
        bash "$import_repo/scripts/import_prod_backup.sh" --core-services-only
)"
import_status=$?
set -e
[[ "$import_status" -ne 0 ]] || { echo 'Import unexpectedly continued after synthetic compose down failure.' >&2; exit 1; }
grep -Fqx '[import-prod-backup] Stopping local Docker stack' <<<"$import_output"
grep -Fqx "compose-down-called compose -f $import_repo/docker-compose.yml down" "$compose_calls"
[[ "$(wc -l < "$compose_calls" | tr -d ' ')" == 1 ]] || {
    echo 'Import invoked more than the expected first compose command.' >&2
    exit 1
}
[[ ! -e "$import_find_calls" ]] || { echo 'Import reached filesystem cleanup after compose down.' >&2; exit 1; }
[[ -s "$import_ssh_calls" && -s "$import_scp_calls" ]] || {
    echo 'Synthetic import setup did not exercise SSH/SCP preparation.' >&2
    exit 1
}

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
        bash "$import_repo/scripts/import_prod_backup.sh" --core-services-only

[[ ! -e "$ssh_calls" ]] || { echo 'Import contacted ssh before the preflight.' >&2; exit 1; }
[[ ! -e "$scp_calls" ]] || { echo 'Import contacted scp before the preflight.' >&2; exit 1; }
[[ ! -e "$workspace/downloads" ]] || { echo 'Import created local download state before the preflight.' >&2; exit 1; }

printf 'compose_sensitive_ports_guardrail=passed\n'
