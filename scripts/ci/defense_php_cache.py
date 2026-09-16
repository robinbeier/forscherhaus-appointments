#!/usr/bin/env python3
"""Build only the existing PHP recipe with an optional local OCI cache."""
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

# Outer deadlines include local layer reads, image loading and export.
# Archive upload/download are bounded separately by the workflow.
CACHE_BUILD_SECONDS = 45
CACHE_EXPORT_SECONDS = 20


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
    entries = vertices(output)
    errors = [item for item in entries if item.get("error")]
    # A successful import elsewhere in the output never excuses a RUN failure.
    if not errors:
        return False
    if all(
        str(item.get("name", "")).startswith("importing cache manifest from local")
        for item in errors
    ):
        return True
    imported = any(str(item.get("name", "")).startswith("importing cache manifest from local")
                   and item.get("completed") and not item.get("error") for item in entries)
    if not imported or cache_state(output) != "hit":
        return False
    # Lazy cache reads occur in the Docker exporter. These guards permit a
    # recovery build, not a provenance claim. Generic load/disk/daemon errors
    # and errors on Dockerfile vertices remain fatal.
    export_names = {"exporting to docker image format", "exporting to image", "sending tarball"}
    return all(item.get("name") in export_names and cache_read_error(str(item["error"]))
               for item in errors)


def cache_read_error(error):
    if re.search(r"\bblob sha256:[0-9a-f]{64}: not found\b", error):
        return True
    return False


def cache_state(output):
    executed = [item for item in vertices(output)
                if re.search(r"\] (RUN|COPY|ADD)\b", str(item.get("name", "")))]
    if not executed or not all(item.get("completed") for item in executed):
        return "unknown"
    return "hit" if all(item.get("cached") is True for item in executed) else "miss"


def valid_cache_index(path):
    try:
        return isinstance(json.loads(path.read_text()), dict)
    except (OSError, ValueError, TypeError):
        return False


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
    parser.add_argument("--cache-dir", type=Path)
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
        if args.cache_dir and "," in str(args.cache_dir):
            raise ValueError("cache directory must not contain commas")
        cache_dir = args.cache_dir
        import_dir = cache_dir / "import" if cache_dir else None
        export_dir = cache_dir / "export" if cache_dir else None
        cache = bool(import_dir and valid_cache_index(import_dir / "index.json"))
        report["cache"] = "cold" if cache_dir else "unavailable"
        needs_build = True
        if cache:
            report["cache"] = "unknown"
            timing, output = phase("cache_build", command + ["--load", "--cache-from",
                f"type=local,src={import_dir}", context], CACHE_BUILD_SECONDS)
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
            # Do not reuse records that the failed importer may have installed
            # in this builder. A cache-free recovery must execute the recipe.
            recovery = ["--no-cache"] if cache else []
            timing, _ = phase("build", command + recovery + ["--load", context])
            if timing["exit_code"] != 0:
                return timing["exit_code"] or 1
        image_id = subprocess.check_output(
            ["docker", "image", "inspect", "--format", "{{.Id}}", key], text=True).strip()
        if not re.fullmatch(r"sha256:[0-9a-f]{64}", image_id):
            raise ValueError("Loaded PHP image identity is unavailable")
        report["image_id"] = image_id
        report["status"] = "built"
        if cache_dir and report["cache"] != "hit":
            # The image has already been built and loaded successfully. Only
            # this optional export may fail; it cannot mask a build/test error.
            report["cache_export_ready"] = False
            try:
                export_dir.mkdir(parents=True, exist_ok=True)
                # A failed attempt must not leave an older index looking fresh
                # to the workflow's archive step.
                (export_dir / "index.json").unlink(missing_ok=True)
                timing, _ = phase("cache_export", command + ["--output=type=cacheonly", "--cache-to",
                    f"type=local,dest={export_dir},mode=min", context], CACHE_EXPORT_SECONDS)
                report["cache_export_ready"] = bool(
                    timing["exit_code"] == 0 and (export_dir / "index.json").is_file()
                )
            except OSError:
                print("PHP cache export unavailable", file=sys.stderr)
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
