#!/usr/bin/env python3
"""Build only the existing PHP recipe; remote layers are an optional accelerator."""
from __future__ import annotations

import argparse
import json
import os
import re
import signal
import subprocess
import sys
import time
from pathlib import Path

import local_php_image_key

# Backend limits also apply to individual cache operations. The outer deadlines
# include lazy layer downloads, image loading and exporter transfer overhead.
CACHE_BUILD_SECONDS = 45
CACHE_EXPORT_SECONDS = 20
CACHE_TIMEOUT = "15s"


def run(command, timeout=None):
    started = time.monotonic()
    process = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                               text=True, start_new_session=True)
    timed_out = False
    try:
        stdout, stderr = process.communicate(timeout=timeout)
    except subprocess.TimeoutExpired:
        timed_out = True
        os.killpg(process.pid, signal.SIGTERM)
        try:
            stdout, stderr = process.communicate(timeout=2)
        except subprocess.TimeoutExpired:
            os.killpg(process.pid, signal.SIGKILL)
            stdout, stderr = process.communicate()
    return {"seconds": round(time.monotonic() - started, 3),
            "exit_code": process.returncode, "timed_out": timed_out}, stdout + stderr


def vertices(output):
    result = {}
    for line in output.splitlines():
        try:
            item = json.loads(line)
        except ValueError:
            continue
        if not isinstance(item, dict):
            continue
        # Buildx versions emit either individual vertices or SolveStatus
        # envelopes. Preserve updates by identity in both supported formats.
        candidates = item.get("vertexes", [item])
        if not isinstance(candidates, list):
            continue
        for vertex in candidates:
            if not isinstance(vertex, dict):
                continue
            identity = vertex.get("digest") or vertex.get("id")
            if isinstance(identity, str):
                result.setdefault(identity, {}).update(vertex)
    return list(result.values())


def cache_import_failure(output):
    errors = [item for item in vertices(output) if item.get("error")]
    # A successful import elsewhere in the output never excuses a RUN failure.
    return bool(errors) and all(
        str(item.get("name", "")).startswith("importing cache manifest from gha")
        for item in errors
    )


def cache_state(output):
    executed = [item for item in vertices(output)
                if re.search(r"\] (RUN|COPY|ADD)\b", str(item.get("name", ""))) and item.get("completed")]
    if not executed:
        return "unknown"
    return "hit" if all(item.get("cached") is True for item in executed) else "miss"


def build_command(config, platform):
    service = config["services"]["php-fpm"]
    platform = service.get("platform") or platform
    key = local_php_image_key._key(config, platform)
    if not key:
        raise ValueError("Unsupported PHP build recipe")
    build = service["build"]
    context = Path(build["context"]).resolve()
    # This helper is intentionally limited to the existing PHP build directory.
    if context != (Path.cwd() / "docker/php-fpm").resolve():
        raise ValueError("PHP cache context must be docker/php-fpm")
    command = ["docker", "buildx", "build", "--progress=rawjson", "--platform", platform,
               "--tag", key, "--file", str(context / build.get("dockerfile", "Dockerfile"))]
    if build.get("target"):
        command += ["--target", build["target"]]
    for name, value in sorted(build.get("args", {}).items()):
        command += ["--build-arg", f"{name}={value}"]
    return key, command, str(context)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--platform", default=os.environ.get("DOCKER_DEFAULT_PLATFORM", ""))
    parser.add_argument("--resolve-compose", action="store_true")
    parser.add_argument("--report")
    args = parser.parse_args()
    started = time.monotonic()
    report = {"schema": "defense_php_cache.v1", "status": "build_failed", "phases": []}

    def phase(name, command, timeout=None):
        timing, output = run(command, timeout)
        report["phases"].append({"phase": name, **timing})
        # BuildKit output describes the recipe, never print runtime environment.
        print(output, file=sys.stderr, end="", flush=True)
        return timing, output

    try:
        platform = args.platform
        if not platform:
            platform = subprocess.check_output(
                ["docker", "info", "--format", "{{.OSType}}/{{.Architecture}}"], text=True).strip()
        if args.resolve_compose:
            config = json.loads(subprocess.check_output(
                ["docker", "compose", "-f", "docker-compose.yml", "-f", "docker/compose.ci-local.yml",
                 "config", "--format", "json"], text=True))
        else:
            config = json.load(sys.stdin)
        key, command, context = build_command(config, platform)
        report["image"] = key
        scope = "defense-php-" + key.rsplit(":", 1)[1]
        cache = bool(os.environ.get("ACTIONS_RUNTIME_TOKEN") and os.environ.get("ACTIONS_RESULTS_URL"))
        report["cache"] = "unavailable"
        needs_build = True
        if cache:
            report["cache"] = "unknown"
            timing, output = phase("cache_build", command + ["--load", "--cache-from",
                f"type=gha,version=2,scope={scope},timeout={CACHE_TIMEOUT}", context], CACHE_BUILD_SECONDS)
            if timing["timed_out"]:
                report["cache"] = "timeout"
            elif timing["exit_code"] == 0:
                needs_build = False
                report["cache"] = cache_state(output)
            elif cache_import_failure(output):
                report["cache"] = "import_error"
            else:
                return timing["exit_code"] or 1
        if needs_build:
            timing, _ = phase("build", command + ["--load", context])
            if timing["exit_code"] != 0:
                return timing["exit_code"] or 1
        image_id = subprocess.check_output(
            ["docker", "image", "inspect", "--format", "{{.Id}}", key], text=True).strip()
        if not re.fullmatch(r"sha256:[0-9a-f]{64}", image_id):
            raise ValueError("Loaded PHP image identity is unavailable")
        report["image_id"] = image_id
        report["status"] = "built"
        if cache and report["cache"] != "hit":
            # The image has already been built and loaded successfully. Only
            # this optional export may fail; it cannot mask a build/test error.
            phase("cache_export", command + ["--output=type=cacheonly", "--cache-to",
                f"type=gha,version=2,scope={scope},mode=min,ignore-error=true,timeout={CACHE_TIMEOUT}",
                context], CACHE_EXPORT_SECONDS)
        return 0
    except (OSError, ValueError, KeyError, TypeError, subprocess.CalledProcessError) as exc:
        print(f"PHP build preparation failed: {type(exc).__name__}", file=sys.stderr)
        return 1
    finally:
        report["total_seconds"] = round(time.monotonic() - started, 3)
        content = json.dumps(report, sort_keys=True) + "\n"
        print(content, end="")
        if args.report:
            path = Path(args.report)
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(content)


if __name__ == "__main__":
    raise SystemExit(main())
