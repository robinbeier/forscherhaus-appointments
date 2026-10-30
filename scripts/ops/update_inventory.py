#!/usr/bin/env python3
"""Secret-free, host-local read-only maintenance inventory."""
from __future__ import annotations

import argparse
import datetime as dt
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time
from typing import Callable

PACKAGES = {
    "booking": ["tailscale", "docker.io", "containerd", "runc", "php8.5-fpm", "apache2", "mariadb-server"],
    "learning": ["tailscale", "docker-ce", "docker-ce-cli", "containerd.io", "docker-buildx-plugin", "docker-compose-plugin"],
}
CONTAINERS = {
    "booking": ["fh-pdf-renderer-pdf-renderer-1", "uptime-kuma-uptime-kuma-1"],
    "learning": ["lernplattform-test-db-1", "lernplattform-test-api-1", "lernplattform-test-web-1"],
}
REF_RE = re.compile(r"^[a-z0-9][a-z0-9._/-]*(?::[A-Za-z0-9._-]+)?(?:@sha256:[a-f0-9]{64})?$")
REV_RE = re.compile(r"^[a-f0-9]{40}$")


class InventoryError(RuntimeError):
    pass


def command(argv: list[str], timeout: float = 20, runner: Callable = subprocess.run) -> str:
    try:
        result = runner(argv, check=True, capture_output=True, text=True, timeout=timeout, shell=False)
    except (OSError, subprocess.SubprocessError) as exc:
        raise InventoryError(f"command failed: {argv[0]}") from exc
    if len(result.stdout) > 2_000_000:
        raise InventoryError(f"command output too large: {argv[0]}")
    return result.stdout


class AptAdapter:
    def __init__(self):
        try:
            import apt
            import apt_pkg
            apt_pkg.init_system()
            self.cache = apt.Cache()
            self.compare = apt_pkg.version_compare
        except Exception as exc:
            raise InventoryError("python-apt unavailable") from exc

    def package(self, name):
        return self.cache[name] if name in self.cache else None


def package_inventory(names: list[str], apt: AptAdapter) -> tuple[list[dict], list[str]]:
    result, unknown = [], []
    for name in names:
        package = apt.package(name)
        if package is None:
            result.append({"name": name, "state": "unknown", "reason": "package-absent"})
            unknown.append(name)
            continue
        installed = getattr(getattr(package, "installed", None), "version", None)
        candidate = getattr(getattr(package, "candidate", None), "version", None)
        if not installed:
            state, reason = "not-installed", "not-installed"
            unknown.append(name)
        elif not candidate:
            state, reason = "unknown", "candidate-missing"
            unknown.append(name)
        else:
            try:
                comparison = apt.compare(installed, candidate)
            except Exception as exc:
                raise InventoryError(f"version comparison failed for {name}") from exc
            state, reason = ("update-available", None) if comparison < 0 else ("current", None)
        origins = []
        trusted = True
        if getattr(package, "candidate", None):
            for origin in getattr(package.candidate, "origins", []) or []:
                entry = {key: getattr(origin, key, None) for key in ("origin", "archive", "label", "component", "trusted")}
                if entry["archive"] != "now":
                    trusted = trusted and entry["trusted"] is True
                origins.append(entry)
        trusted = any(o["trusted"] is True for o in origins) and trusted
        if candidate and not trusted:
            reason = "untrusted-or-unknown-origin"
            unknown.append(name)
        item = {"name": name, "installed": installed, "candidate": candidate, "origins": origins, "trusted": trusted, "state": state}
        if reason:
            item["reason"] = reason
        result.append(item)
    return result, sorted(set(unknown))


def apt_cache_freshness(root: Path = Path("/"), now: float | None = None) -> dict:
    now = time.time() if now is None else now
    lists = root / "var/lib/apt/lists"
    files = list(lists.glob("*_InRelease")) + list(lists.glob("*_Release"))
    refresh = root / "var/lib/apt/periodic/update-success-stamp"
    if not files or not refresh.exists():
        return {"state": "unknown", "metadata_age_days": None, "last_refresh_age_days": None, "reason": "release-metadata-or-refresh-stamp-missing"}
    times = [path.stat().st_mtime for path in files]
    latest = max(times)
    oldest = min(times)
    refreshed = refresh.stat().st_mtime
    ages = [(now - latest) / 86400, (now - refreshed) / 86400]
    if any(age < 0 for age in ages):
        state, reason = "unknown", "future-apt-timestamp"
    elif ages[1] > 7:
        state, reason = "unknown", "apt-refresh-older-than-7-days"
    else:
        state, reason = "fresh", None
    iso = lambda stamp: dt.datetime.fromtimestamp(stamp, dt.timezone.utc).isoformat()
    return {"state": state, "metadata_latest": iso(latest), "metadata_oldest": iso(oldest), "metadata_age_is_not_refresh_age": True, "metadata_age_days": round(max(0, ages[0]), 2), "last_refresh": iso(refreshed), "last_refresh_age_days": round(max(0, ages[1]), 2), "reason": reason}


def container_inventory(names: list[str], run: Callable = command) -> tuple[list[dict], list[str]]:
    result, missing = [], []
    for name in names:
        try:
            item = json.loads(run(["docker", "inspect", name]))[0]
            config, state = item.get("Config", {}), item.get("State", {})
            ref = config.get("Image")
            image_id = item.get("Image")
            if not isinstance(ref, str) or not REF_RE.fullmatch(ref) or not isinstance(image_id, str) or not re.fullmatch(r"sha256:[a-f0-9]{64}", image_id):
                missing.append(name)
                continue
            image = json.loads(run(["docker", "image", "inspect", image_id]))[0]
            labels = image.get("Config", {}).get("Labels") or {}
            revision = labels.get("org.opencontainers.image.revision")
            health = (state.get("Health") or {}).get("Status") or ("not_configured" if state.get("Running") else "not_running")
            result.append({"name": name, "id": item.get("Id"), "running": state.get("Running") is True, "image_ref": ref, "image_id": image_id, "oci_revision": revision if isinstance(revision, str) and REV_RE.fullmatch(revision) else None, "repo_digests": [digest for digest in image.get("RepoDigests", []) if isinstance(digest, str) and REF_RE.fullmatch(digest) and re.search(r"@sha256:[a-f0-9]{64}$", digest)], "health": health})
        except (InventoryError, IndexError, json.JSONDecodeError, TypeError, KeyError):
            missing.append(name)
    return result, missing


def host_metadata(role: str, run: Callable = command, root: Path = Path("/")) -> dict:
    try:
        kernel = run(["uname", "-srvm"]).strip()
    except InventoryError as exc:
        raise InventoryError("kernel inventory failed") from exc
    os_release = {}
    try:
        for line in (root / "etc/os-release").read_text(encoding="utf-8").splitlines():
            key, sep, value = line.partition("=")
            if sep and key in {"ID", "VERSION_ID", "PRETTY_NAME"}:
                os_release[key] = value.strip('"')
    except OSError:
        os_release = {"state": "unknown"}
    result = {"kernel": kernel, "os_release": os_release, "reboot_required": (root / "var/run/reboot-required").exists()}
    if role == "learning":
        try:
            result["node"] = {"version": run(["/usr/local/bin/node", "--version"]).strip(), "realpath": os.path.realpath("/usr/local/bin/node")}
        except InventoryError as exc:
            raise InventoryError("node inventory failed") from exc
    return result


def unit_status(run: Callable = command) -> dict[str, str]:
    result = {}
    for unit in ("apt-daily.timer", "apt-daily-upgrade.timer"):
        try:
            result[unit] = run(["systemctl", "is-active", unit]).strip()
        except InventoryError:
            result[unit] = "unknown"
    return result


def collect(role: str, apt: AptAdapter | None = None, run: Callable = command, root: Path = Path("/")) -> tuple[dict, int]:
    apt = apt or AptAdapter()
    packages, package_unknown = package_inventory(PACKAGES[role], apt)
    containers, container_missing = container_inventory(CONTAINERS[role], run)
    health_unknown = [item["name"] for item in containers if not item["running"] or item["health"] not in {"healthy", "not_configured"}]
    freshness = apt_cache_freshness(root)
    payload = {"format": 1, "captured_at": dt.datetime.now(dt.timezone.utc).isoformat(), "role": role, "packages": packages, "containers": containers, "image_update_availability": "unknown", "apt_cache": freshness, "host": host_metadata(role, run, root), "units": unit_status(run), "unknown": {"packages": package_unknown, "containers": container_missing, "container_health": health_unknown}}
    if container_missing:
        payload["status"] = "failed"
        return payload, 2
    host_unknown = payload["host"]["os_release"].get("state") == "unknown"
    units_unknown = any(value != "active" for value in payload["units"].values())
    if package_unknown or health_unknown or freshness["state"] != "fresh" or host_unknown or units_unknown:
        payload["status"] = "review"
        return payload, 2
    payload["status"] = "review"  # image availability is deliberately unknown; never report green.
    return payload, 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Read-only maintenance inventory")
    parser.add_argument("--role", choices=sorted(PACKAGES), required=True)
    args = parser.parse_args(argv)
    try:
        payload, code = collect(args.role)
    except InventoryError as exc:
        print(json.dumps({"format": 1, "role": args.role, "status": "failed", "error": str(exc)}), flush=True)
        return 2
    print(json.dumps(payload, sort_keys=True), flush=True)
    return code


if __name__ == "__main__":
    sys.exit(main())
