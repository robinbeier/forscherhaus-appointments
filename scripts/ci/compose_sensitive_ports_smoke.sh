#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/../.." && pwd)"
cd "$ROOT_DIR"

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
