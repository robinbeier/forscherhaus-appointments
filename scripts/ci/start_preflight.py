#!/usr/bin/env python3
"""Bounded, read-only observations before the repository's local workflow."""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import sys

sys.dont_write_bytecode = True
TIMEOUT = 8


def _run(command, stdin=None, timeout=TIMEOUT, env=None):
    try:
        result = subprocess.run(
            command, input=stdin, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            timeout=timeout, check=False,
            env={**os.environ, **(env or {}), "GIT_OPTIONAL_LOCKS": "0", "PYTHONDONTWRITEBYTECODE": "1"},
        )
        return result.returncode, result.stdout, result.stderr
    except (OSError, subprocess.TimeoutExpired):
        return 124, b"", b""


def _json(data):
    try:
        return json.loads(data)
    except (ValueError, UnicodeError):
        return None


def _path(value):
    return Path(value).expanduser().resolve()


def _inside(path, root):
    return path == root or root in path.parents


def _slug(value):
    return re.sub(r"[^a-z0-9]+", "-", value.lower())


def preflight(argv=None, runner=_run):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base-ref", default="origin/main")
    parser.add_argument("--writable-root", action="append", default=[])
    parser.add_argument("--read-only-root", action="append", default=[])
    parser.add_argument("--network-policy", choices=["unknown", "restricted", "allowed"], default="unknown")
    parser.add_argument("--service", action="append")
    parser.add_argument("--probe-github", action="store_true")
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args(argv)
    results, actions = [], set()
    writable = [_path(value) for value in args.writable_root]
    readonly = [_path(value) for value in args.read_only_root]

    def add(category, status, detail, action=None):
        results.append(dict(category=category, status=status, detail=detail))
        if action:
            actions.add(action)

    def probe(command, stdin=None, env=None):
        return runner(command, stdin, TIMEOUT, env)

    def git(*arguments):
        code, out, _ = probe(["git", *arguments])
        return out.decode(errors="replace").strip() if code == 0 else None

    def boundary(category, target, recursive=False):
        if target is None:
            add(category, "unknown", "target could not be resolved", "Git metadata access")
            return
        target = _path(target)
        if any(_inside(target, root) or (recursive and _inside(root, target)) for root in readonly):
            add(category, "blocked", "target overlaps a declared read-only exclusion", "Local/Git write permission")
        elif not writable:
            add(category, "unknown", "writable roots were not declared", "Runtime write-boundary information")
        elif not any(_inside(target, root) for root in writable):
            add(category, "blocked", "target is outside declared writable roots", "Local/Git write permission")
        else:
            ancestor = target
            while not ancestor.exists() and ancestor != ancestor.parent:
                ancestor = ancestor.parent
            if category == "bind-write" and target.is_file():
                accessible = os.access(target, os.W_OK) and os.access(target.parent, os.X_OK)
            else:
                accessible = ancestor.is_dir() and os.access(ancestor, os.W_OK | os.X_OK)
            if accessible:
                add(category, "ready", "inside declared boundary; filesystem access observed, runtime approval unproven")
            else:
                add(category, "blocked", "filesystem write or directory search access is unavailable", "Local/Git write permission")

    repo_value = git("rev-parse", "--show-toplevel")
    git_dir = git("rev-parse", "--absolute-git-dir")
    common = git("rev-parse", "--git-common-dir")
    repo = _path(repo_value) if repo_value else Path.cwd().resolve()
    add("git", "ready" if repo_value and git_dir and common else "blocked",
        "worktree and both Git metadata paths resolved" if repo_value and git_dir and common else "Git repository metadata unavailable")
    boundary("worktree-write", repo if repo_value else None)
    boundary("git-dir-write", git_dir, recursive=True)
    boundary("git-common-write", common, recursive=True)
    branch = git("symbolic-ref", "--quiet", "--short", "HEAD")
    add("branch", "ready" if branch else "unknown", "attached branch" if branch else "detached HEAD or branch unavailable")
    base = git("rev-parse", "--verify", "--end-of-options", args.base_ref + "^{commit}")
    add("base", "ready" if base else "blocked", "base commit exists locally; remote freshness unproven" if base else "base commit unavailable locally", None if base else "Authorized Git fetch/sync")
    hooks = git("rev-parse", "--git-path", "hooks")
    managed = False
    if hooks:
        hook = _path(hooks) / "pre-commit"
        try:
            managed = hook.is_file() and os.access(hook, os.X_OK) and '# managed-by-forscherhaus-precommit' in hook.read_text().splitlines()
        except (OSError, UnicodeError):
            pass
    add("hooks", "ready" if managed else "blocked", "managed executable pre-commit hook observed" if managed else "managed executable hook not confirmed", None if managed else "Managed hook setup")

    project = os.environ.get("CI_DOCKER_COMPOSE_PROJECT_NAME")
    if not project and git_dir:
        code, out, _ = probe(["cksum"], git_dir.encode())
        if code == 0 and re.fullmatch(rb"\d+\s+\d+\s*", out):
            project = f"{_slug(repo.name)}-local-ci-{out.split()[0].decode()}"
    if not project or not re.fullmatch(r"[a-z0-9][a-z0-9_-]*", project):
        add("project", "blocked", "Compose project name unavailable or invalid", "Unique Compose project configuration")
        project = None
    else:
        add("project", "ready", "Compose project: " + project)

    endpoint = os.environ.get("DOCKER_HOST") if not os.environ.get("DOCKER_CONTEXT") else None
    if not endpoint:
        code, raw, _ = probe(["docker", "context", "inspect", "--format", "{{json .Endpoints.docker.Host}}"])
        endpoint = _json(raw) if code == 0 else None
    local_daemon = isinstance(endpoint, str) and endpoint.startswith(("unix://", "npipe://"))
    add("docker-endpoint", "ready" if local_daemon else "blocked",
        "local Docker endpoint selected" if local_daemon else "non-local or unconfirmed Docker endpoint; daemon probes skipped",
        None if local_daemon else "Local Docker context selection")
    info = None
    if local_daemon:
        code, raw, _ = probe(["docker", "info", "--format", "{{json .}}"])
        info = _json(raw) if code == 0 else None
    daemon = isinstance(info, dict)
    add("daemon", "ready" if daemon else "blocked", "Docker daemon responded" if daemon else "Docker access unavailable or timed out", None if daemon else "Docker daemon access")
    code, _, _ = probe(["docker", "compose", "version"])
    compose_available = code == 0
    add("compose", "ready" if compose_available else "blocked", "Compose v2 available" if compose_available else "Compose v2 unavailable; JSON inspection requires v2", None if compose_available else "Docker Compose v2 setup")

    config = None
    portless = os.environ.get("EA_LOCAL_CI_PORTLESS_COMPOSE", "1") == "1"
    override = os.environ.get("EA_LOCAL_CI_COMPOSE_OVERRIDE_PATH", "docker/compose.ci-local.yml")
    ci_override = portless and (repo / override).is_file()
    compose = ["docker", "compose", "--project-directory", str(repo), "-p", project] if project else []
    if ci_override:
        compose += ["-f", str(repo / "docker-compose.yml"), "-f", str(repo / override)]
    runtime_env = {}
    if not ci_override and os.environ.get("COMPOSE_FILE"):
        runtime_env["COMPOSE_FILE"] = os.pathsep.join(str(repo / item) for item in os.environ["COMPOSE_FILE"].split(os.pathsep))
    if project and not os.environ.get("EA_MYSQL_DATA_PATH"):
        runtime_env["EA_MYSQL_DATA_PATH"] = "./docker/.ci-mysql/" + _slug(project)
    if project and compose_available:
        code, raw, _ = probe(compose + ["config", "--format", "json"], env=runtime_env)
        config = _json(raw) if code == 0 else None
    config_resolved = isinstance(config, dict) and isinstance(config.get("services"), dict)
    if not config_resolved:
        add("compose-config", "blocked", "resolved Compose configuration unavailable or invalid", "Compose configuration access")
        config = {"services": {}}
    else:
        add("compose-config", "ready", "resolved configuration inspected without disclosure")

    planned = list(dict.fromkeys(args.service or ["php-fpm", "mysql", "nginx"]))
    services = config["services"]
    selected_services_resolved = config_resolved
    # Include dependencies that Compose starts for the selected services.
    for name in planned:
        service = services.get(name)
        if not isinstance(service, dict):
            selected_services_resolved = False
            add("services", "blocked", "a selected or required service is undefined", "Compose service selection")
            continue
        dependencies = service.get("depends_on", {})
        if isinstance(dependencies, (dict, list)):
            for dependency in dependencies:
                if dependency not in planned:
                    planned.append(dependency)

    if daemon and project:
        code, raw, _ = probe(["docker", "ps", "-a", "--filter", f"label=com.docker.compose.project={project}", "--format", "{{.ID}}"])
        names_code, names_raw, _ = probe(["docker", "ps", "-a", "--format", "{{.Names}}"])
        names = names_raw.decode(errors="replace").splitlines() if names_code == 0 else []
        collision = bool(raw.strip()) if code == 0 else False
        for name in planned:
            service = services.get(name)
            if not isinstance(service, dict):
                continue
            explicit = service.get("container_name")
            if explicit:
                collision = collision or explicit in names
            else:
                # Include both Compose v2 and compatibility separators and any replica.
                pattern = re.compile(re.escape(project) + r"[-_]" + re.escape(name) + r"[-_][1-9][0-9]*$")
                collision = collision or any(pattern.fullmatch(item) for item in names)
        unresolved = code != 0 or names_code != 0 or not selected_services_resolved
        add("project-collision", "blocked" if collision else ("unknown" if unresolved else "ready"),
            "existing project containers or selected container names require ownership checks" if collision else ("project or container-name inspection unavailable" if unresolved else "no existing project containers or selected-name collisions observed"),
            "Existing Compose project ownership" if collision else None)

    missing_pull = missing_build = 0
    resources = set()
    ports = set()
    port_requirements_resolved = True
    shared_php_image = None
    if "php-fpm" in planned and ci_override and daemon:
        platform = os.environ.get("DOCKER_DEFAULT_PLATFORM") or "/".join(str(info.get(key, "")) for key in ["OSType", "Architecture"])
        code, raw, _ = probe([sys.executable, "-B", str(repo / "scripts/ci/local_php_image_key.py"), "--platform", platform], json.dumps(config).encode())
        if code == 0 and re.fullmatch(rb"forscherhaus-local/php-fpm:[a-f0-9]{64}\s*", raw):
            shared_php_image = raw.decode().strip()
    for name in planned:
        service = services.get(name)
        if not isinstance(service, dict):
            continue
        image = service.get("image")
        build = bool(service.get("build"))
        if name == "php-fpm" and shared_php_image:
            image = shared_php_image
        elif not image and build and project:
            image = f"{project}-{name}"
        if image and daemon:
            code, _, _ = probe(["docker", "image", "inspect", image])
            if code:
                if build:
                    missing_build += 1
                else:
                    missing_pull += 1
        elif image or build:
            add("images", "unknown", "image inventory requires Docker daemon access")
        for mount in service.get("volumes", []):
            if not isinstance(mount, dict):
                add("mounts", "unknown", "unsupported mount representation")
            elif mount.get("type") == "bind" and mount.get("source") and not mount.get("read_only"):
                boundary("bind-write", mount["source"], recursive=True)
            elif mount.get("type") == "volume" and mount.get("source"):
                resources.add(("volume", mount["source"]))
        networks = service.get("networks", {"default": {}})
        if isinstance(networks, (dict, list)):
            resources.update(("network", name) for name in networks)
        for port in service.get("ports", []):
            if isinstance(port, dict) and port.get("published"):
                value = str(port["published"])
                if value.isdecimal() and 0 < int(value) < 65536:
                    ports.add((port.get("protocol", "tcp"), int(value)))
                else:
                    port_requirements_resolved = False
                    add("ports", "unknown", "port ranges or dynamic ports need separate inspection")
            else:
                port_requirements_resolved = False
                add("ports", "unknown", "unresolved published-port configuration")
    if missing_pull:
        add("images", "unknown", f"{missing_pull} pull image(s) unavailable", "Docker image pull/network access")
    if missing_build:
        add("images", "unknown", f"{missing_build} build image(s) unavailable", "Docker build/dependency access")
    if not selected_services_resolved:
        add("images", "unknown", "planned image requirements could not be resolved")
    elif daemon and not missing_pull and not missing_build:
        add("images", "ready", "no missing planned images observed")
    for kind, key in sorted(resources):
        definition = config.get(kind + "s", {}).get(key, {})
        definition = definition if isinstance(definition, dict) else {}
        external = bool(definition.get("external"))
        name = definition.get("name") or (key if external else f"{project}_{key}")
        if not daemon:
            add("docker-" + kind, "unknown", "resource inspection requires daemon access")
            continue
        code, raw, _ = probe(["docker", kind, "inspect", name])
        if code:
            add("docker-" + kind, "blocked" if external else "unknown",
                "required external resource unavailable" if external else "internal resource must be created by the normal workflow",
                "External Docker resource preparation" if external else "Local Docker resource creation")
            continue
        inspected = _json(raw)
        resource = inspected[0] if isinstance(inspected, list) and len(inspected) == 1 and isinstance(inspected[0], dict) else None
        if resource is None:
            add("docker-" + kind, "unknown", "resource inspection response could not be resolved")
        elif external:
            add("docker-" + kind, "ready", "declared external resource exists")
        else:
            labels = resource.get("Labels")
            owned = isinstance(labels, dict) and labels.get("com.docker.compose.project") == project and labels.get("com.docker.compose." + kind) == key
            add("docker-" + kind, "ready" if owned else "blocked",
                "resource exists with expected Compose ownership labels" if owned else "existing internal resource has missing or conflicting Compose ownership labels",
                None if owned else "Existing Compose resource ownership")
    if not selected_services_resolved:
        add("ports", "unknown", "planned host-port requirements could not be resolved")
    elif not ports and port_requirements_resolved:
        add("ports", "ready", "selected services have no observed published host ports")
    for protocol in sorted({protocol for protocol, _ in ports}):
        command = ["lsof", "-nP", "-iTCP", "-sTCP:LISTEN", "-F", "n"] if protocol == "tcp" else ["lsof", "-nP", "-iUDP", "-F", "n"]
        code, raw, _ = probe(command)
        if code not in (0, 1) or (code == 1 and raw.strip()):
            add("ports", "unknown", "local listener inventory unavailable")
            continue
        listening = {int(match) for match in re.findall(rb"^n[^\n]*:(\d+)(?:\s|$)", raw, re.MULTILINE)}
        conflict = any(port in listening for proto, port in ports if proto == protocol)
        add("ports", "blocked" if conflict else "unknown", "potential host-port conflict observed" if conflict else "no visible listener conflict; port availability is not reserved or guaranteed", "Local port conflict resolution" if conflict else None)
    add("capacity", "unknown", "read-only inspection cannot prove future subnet, volume, disk or container allocation capacity")
    add("network", "unknown", f"runtime policy declared {args.network_policy}; fetch/push/download approval remains external", "Network/action approval check" if args.network_policy != "allowed" else None)
    if args.probe_github:
        code, _, _ = probe(["gh", "api", "rate_limit"])
        add("github", "ready" if not code else "unknown", "API read succeeded; push/merge rights and approval unproven" if not code else "API read unavailable or timed out", "GitHub CLI/network access" if code else None)
    else:
        add("github", "unknown", "external connectivity not probed; use --probe-github for a read-only check")
    add("authorization", "unknown", "observations never grant Git, Docker, network, push or merge permission")
    status = "blocked" if any(item["status"] == "blocked" for item in results) else "unknown"
    return (1 if status == "blocked" else 0), dict(status=status, project=project, results=results, actions=sorted(actions))


def main():
    try:
        code, report = preflight()
    except Exception:
        message = "inspection failed; raw details withheld"
        print(json.dumps({"status": "blocked", "error": message}) if "--json" in sys.argv else "start preflight: blocked (" + message + ")")
        return 1
    if "--json" in sys.argv:
        print(json.dumps(report, sort_keys=True))
    else:
        print("start preflight: " + report["status"])
        # Aggregate repeated mount/image/resource observations, keeping the worst status.
        grouped = {}
        rank = {"ready": 0, "unknown": 1, "blocked": 2}
        for item in report["results"]:
            previous = grouped.get(item["category"])
            if previous is None or rank[item["status"]] > rank[previous["status"]]:
                grouped[item["category"]] = item
        for item in grouped.values():
            print(f"{item['category']}: {item['status']} — {item['detail']}")
        if report["actions"]:
            print("Prerequisites: " + "; ".join(report["actions"]))
    return code


if __name__ == "__main__":
    raise SystemExit(main())
