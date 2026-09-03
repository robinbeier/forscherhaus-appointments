#!/usr/bin/python3
"""Parse and validate the trusted-base bootstrap contract.

The shell launcher and shared runtime execute this exact-base blob. Keeping
the contract semantics here avoids two subtly different policy parsers while
leaving both attestation points intact.
"""
import json
import sys


def main() -> int:
    if len(sys.argv) != 2:
        return 1
    try:
        contract = json.load(sys.stdin)
        bootstrap = contract["trusted_base_bootstrap"]
        launcher = bootstrap["launcher"]
        parser = bootstrap["contract_parser"]
        runtime = bootstrap["shared_runtime"]
        payloads = bootstrap["payloads"]
        payload = payloads[sys.argv[1]]
    except (KeyError, TypeError, ValueError, UnicodeError):
        return 1
    if not all(isinstance(value, dict) for value in (bootstrap, launcher, parser, runtime, payloads, payload)):
        return 1
    if set(bootstrap) != {"schema_version", "contract_path", "launcher", "contract_parser", "shared_runtime", "payloads"}:
        return 1
    if bootstrap["schema_version"] != 2 or bootstrap["contract_path"] != ".codex/contracts/agent-workflow.json":
        return 1
    if set(payloads) != {"reviewer", "parallel"}:
        return 1
    if set(launcher) != {"path", "mode"} or set(runtime) != {"path", "mode"}:
        return 1
    if set(payload) != {"path", "mode", "environment_profile"}:
        return 1
    if launcher != {"path": "scripts/agent/trusted_base_launcher.sh", "mode": "0500"}:
        return 1
    if parser != {"path": "scripts/agent/lib/trusted_base_bootstrap_contract.py", "mode": "0400"}:
        return 1
    if runtime != {"path": "scripts/agent/lib/trusted_base_payload_runtime.sh", "mode": "0400"}:
        return 1
    if payloads != {
        "reviewer": {
            "path": "scripts/agent/run_readonly_reviewer.sh",
            "mode": "0500",
            "environment_profile": "reviewer",
        },
        "parallel": {
            "path": "scripts/agent/check_parallel_work_contract.sh",
            "mode": "0500",
            "environment_profile": "parallel",
        },
    }:
        return 1
    if payload["mode"] != "0500" or payload["environment_profile"] != sys.argv[1]:
        return 1
    sys.stdout.write("\n".join((
        bootstrap["contract_path"], launcher["path"], launcher["mode"], parser["path"], parser["mode"],
        runtime["path"], runtime["mode"],
        payload["path"], payload["mode"], payload["environment_profile"],
    )))
    return 0


raise SystemExit(main())
