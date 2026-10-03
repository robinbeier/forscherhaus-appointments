#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/../.." && pwd)"
cd "$ROOT_DIR"

config_only=0
case "${1:-}" in
    '') ;;
    --config-only)
        config_only=1
        ;;
    *)
        echo "compose_sensitive_ports=invalid_arguments" >&2
        echo "usage: $0 [--config-only]" >&2
        exit 2
        ;;
esac
if (( $# > 1 )); then
    echo "compose_sensitive_ports=invalid_arguments" >&2
    echo "usage: $0 [--config-only]" >&2
    exit 2
fi

# Docker Engine versions before 28 can expose localhost-published ports to
# peers on the same L2 network. Versions 28.2.0 through 28.3.2 can also lose
# their loopback boundary after a firewalld reload (CVE-2025-54388). Fail
# closed before treating loopback bindings as a host-isolation guarantee.
if (( config_only == 0 )); then
    docker_server_version="$(docker version --format '{{.Server.Version}}' 2>/dev/null || true)"
    # Require a complete stable release. Prerelease builds can change the
    # localhost-publishing behavior this guardrail relies on, so fail closed
    # instead of treating e.g. 28.0.0-rc.1 as Docker 28.
    if [[ ! "$docker_server_version" =~ ^([0-9]+)\.[0-9]+\.[0-9]+$ ]]; then
        echo "compose_sensitive_ports=unsupported_docker_engine" >&2
        exit 1
    fi
    docker_major="${BASH_REMATCH[1]}"
    docker_minor="${docker_server_version#*.}"
    docker_minor="${docker_minor%%.*}"
    docker_patch="${docker_server_version##*.}"
    if (( docker_major < 28 || (docker_major == 28 && (docker_minor < 3 || (docker_minor == 3 && docker_patch < 3))) )); then
        echo "compose_sensitive_ports=unsupported_docker_engine" >&2
        exit 1
    fi
else
    # CI's root-deployment job still verifies the canonical bindings on older
    # runner Docker versions. This mode deliberately skips only the runtime
    # safety floor; it never relaxes the resolved Compose binding checks below.
    echo "compose_sensitive_ports=config_only" >&2
fi

# Inspect the canonical, fully resolved Compose model. Explicitly naming the
# repository's default file prevents a caller-provided COMPOSE_FILE or override
# from replacing the configuration under test. This deliberately does not
# start containers: the property under test is the host exposure declared by
# the default configuration.
compose_config="$(docker compose -f "$ROOT_DIR/docker-compose.yml" config --format json)"

COMPOSE_CONFIG_JSON="$compose_config" python3 - <<'PY'
import json
import os
import sys

try:
    model = json.loads(os.environ["COMPOSE_CONFIG_JSON"])
except (KeyError, json.JSONDecodeError) as exc:
    print("compose_sensitive_ports=invalid_effective_config", file=sys.stderr)
    raise SystemExit(f"Could not parse effective Compose JSON: {exc}")

expected = {
    "mysql": (3306, "3306"),
    "nginx": (80, "80"),
    "phpmyadmin": (80, "8080"),
}
services = model.get("services")
if not isinstance(services, dict):
    print("compose_sensitive_ports=invalid_effective_config", file=sys.stderr)
    raise SystemExit("Effective Compose model has no services object.")

failures = []
for service, (target, published) in expected.items():
    ports = services.get(service, {}).get("ports", [])
    if not isinstance(ports, list) or len(ports) != 1:
        failures.append(f"{service}: expected exactly one published TCP binding")
        continue
    port = ports[0]
    if (
        port.get("protocol", "tcp") != "tcp"
        or port.get("target") != target
        or str(port.get("published")) != published
    ):
        failures.append(f"{service}: unexpected published TCP binding")
        continue
    if port.get("host_ip") != "127.0.0.1":
        failures.append(f"{service}: host_ip is not loopback-only")

if failures:
    print("compose_sensitive_ports=failed", file=sys.stderr)
    for failure in failures:
        print(f"check={failure}", file=sys.stderr)
    raise SystemExit(1)

print("compose_sensitive_ports=passed checks=mysql:3306->3306,nginx:80->80,phpmyadmin:80->8080 host_ip=127.0.0.1")
PY
