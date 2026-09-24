#!/usr/bin/env python3
"""Read-only inventory for stale checkouts and Git worktree registrations.

The command reports observed state only. It never fetches, prunes, deletes, or
changes a checkout. Paths are redacted from the human report unless
``--show-paths`` is requested.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shlex
import subprocess
import sys
from typing import Callable

sys.dont_write_bytecode = True
TIMEOUT = 8
OPERATION_MARKERS = (
    "MERGE_HEAD",
    "CHERRY_PICK_HEAD",
    "REVERT_HEAD",
    "rebase-merge",
    "rebase-apply",
    "sequencer",
    "BISECT_START",
    "BISECT_LOG",
    "BISECT_NAMES",
)


def _run_git(repo: Path, arguments: list[str], timeout: int = TIMEOUT) -> tuple[int, bytes, bytes]:
    try:
        child_env = {
            key: value
            for key, value in os.environ.items()
            if not key.startswith("GIT_")
        }
        child_env.update(
            {
                # A remote can select a local helper (for example ext::) even
                # after option parsing ends. Keep transports that do not
                # launch an inherited SSH or git-proxy command.
                "GIT_ALLOW_PROTOCOL": "file:http:https",
                "GIT_NO_LAZY_FETCH": "1",
                "GIT_NO_REPLACE_OBJECTS": "1",
                "GIT_OPTIONAL_LOCKS": "0",
                "PYTHONDONTWRITEBYTECODE": "1",
            }
        )
        git_arguments = list(arguments)
        if "ls-remote" in git_arguments:
            # Remote freshness is a read-only probe. Do not allow an
            # inherited askpass program or credential helper to execute, and
            # never wait for interactive credentials.
            child_env.pop("GIT_ASKPASS", None)
            child_env.pop("SSH_ASKPASS", None)
            child_env["GIT_TERMINAL_PROMPT"] = "0"
            git_arguments = [
                "-c",
                "credential.helper=",
                "-c",
                "core.askPass=",
                *git_arguments,
            ]
        returncode = subprocess.run(
            ["git", "-C", str(repo), *git_arguments],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            timeout=timeout,
            check=False,
            env=child_env,
        )
        return returncode.returncode, returncode.stdout, returncode.stderr
    except (OSError, subprocess.TimeoutExpired) as error:
        return 124, b"", str(error).encode()


def _text(value: bytes) -> str:
    return value.decode("utf-8", errors="replace").strip()


def _configured_filters(
    repo: Path,
    runner: Callable[..., tuple[int, bytes, bytes]],
) -> tuple[bool, list[str]]:
    """Return whether effective clean/process filters are known and configured.

    A status operation can invoke configured clean/process filters, including
    through an initialized submodule.  Names are collected so the caller can
    blank those commands for the one status invocation; a failed config read
    is itself an unknown result.
    """
    code, output, _ = runner(
        repo, ["config", "--get-regexp", r"^filter\..+\.(clean|process)$"],
    )
    if code not in (0, 1):
        return False, []
    if code == 1 and output:
        return False, []
    names = {
        ".".join(parts[1:-1])
        for line in output.decode("utf-8", errors="surrogateescape").splitlines()
        for parts in [line.split(None, 1)[0].split(".")]
        if len(parts) >= 3 and parts[0] == "filter" and parts[-1] in {"clean", "process"}
    }
    return True, sorted(names)


def _submodule_paths(
    repo: Path,
    runner: Callable[..., tuple[int, bytes, bytes]],
) -> tuple[bool, list[tuple[str, Path | None]]]:
    """Read submodule paths from the index without shell hooks."""
    code, output, _ = runner(repo, ["-c", "core.fsmonitor=false", "ls-files", "--stage", "-z"])
    if code != 0:
        return False, []
    paths: list[tuple[str, Path | None]] = []
    for record in output.decode("utf-8", errors="surrogateescape").split("\0"):
        if not record or "\t" not in record:
            continue
        mode, relative = record.split("\t", 1)
        if not mode.startswith("160000 "):
            continue
        relative_path = Path(relative)
        if relative_path.is_absolute() or ".." in relative_path.parts:
            return False, []
        child = repo / relative_path
        paths.append((relative, child if child.is_dir() and not child.is_symlink() else None))
    return True, paths


def _active_filter_state(
    repo: Path,
    configured_filters: list[str],
    runner: Callable[..., tuple[int, bytes, bytes]],
) -> tuple[bool, bool]:
    """Check tracked paths for effective filter attributes without a shell."""
    code, output, _ = runner(repo, ["-c", "core.fsmonitor=false", "ls-files", "-z"])
    if code != 0:
        return False, False
    paths = [path for path in output.decode("utf-8", errors="surrogateescape").split("\0") if path]
    configured = set(configured_filters)
    for offset in range(0, len(paths), 256):
        batch = paths[offset : offset + 256]
        attr_code, attr_output, _ = runner(
            repo, ["-c", "core.fsmonitor=false", "check-attr", "-z", "filter", "--", *batch]
        )
        if attr_code != 0:
            return False, False
        values = attr_output.decode("utf-8", errors="surrogateescape").split("\0")
        if values and values[-1] == "":
            values.pop()
        if len(values) != 3 * len(batch):
            return False, False
        for index, expected_path in enumerate(batch):
            index *= 3
            if values[index] != expected_path or values[index + 1] != "filter":
                return False, False
            value = values[index + 2]
            if value not in {"", "unspecified", "unset"} and value in configured:
                return True, True
    return True, False


def _operation_state(
    repo: Path,
    runner: Callable[..., tuple[int, bytes, bytes]],
) -> tuple[bool, list[str]]:
    state_paths: list[str] = []
    for marker in OPERATION_MARKERS:
        marker_code, marker_path, _ = runner(repo, ["rev-parse", "--git-path", marker])
        if marker_code != 0 or not _text(marker_path):
            return False, []
        resolved_marker = Path(_text(marker_path))
        if not resolved_marker.is_absolute():
            resolved_marker = repo / resolved_marker
        if resolved_marker.exists():
            state_paths.append(marker)
    return True, state_paths


def _repository_guards(
    repo: Path,
    runner: Callable[..., tuple[int, bytes, bytes]],
    seen: set[Path] | None = None,
    depth: int = 0,
) -> tuple[bool, list[str], list[str], bool]:
    """Inspect filters and paused operations recursively before ``status``.

    Returns ``(checked, filter_names, operation_markers, active_filters)``.  The bounded
    recursion and canonical-path set prevent malformed submodule metadata from
    making the read-only inventory walk unbounded.
    """
    if seen is None:
        seen = set()
    if depth > 32:
        return False, [], [], False
    try:
        canonical = repo.resolve(strict=True)
    except (OSError, RuntimeError, ValueError):
        return False, [], [], False
    if canonical in seen:
        return False, [], [], False
    seen.add(canonical)
    filter_checked, filters = _configured_filters(repo, runner)
    operation_checked, operation_state = _operation_state(repo, runner)
    submodules_checked, submodules = _submodule_paths(repo, runner)
    if not (filter_checked and operation_checked and submodules_checked):
        return False, filters, operation_state, False
    attributes_checked, active_filters = _active_filter_state(repo, filters, runner)
    if not attributes_checked:
        return False, filters, operation_state, False
    for relative, child in submodules:
        if child is None:
            return False, filters, operation_state, active_filters
        child_checked, child_filters, child_state, child_active_filters = _repository_guards(
            child, runner, seen, depth + 1
        )
        if child_state:
            operation_state.extend(f"submodule:{relative}:{marker}" for marker in child_state)
        filters.extend(child_filters)
        active_filters = active_filters or child_active_filters
        if not child_checked:
            return False, filters, operation_state, active_filters
    return True, sorted(set(filters)), operation_state, active_filters


def _parse_worktrees(output: str) -> list[dict[str, object]]:
    entries: list[dict[str, object]] = []
    current: dict[str, object] | None = None
    for line in output.split("\0"):
        if line.startswith("worktree "):
            if current:
                entries.append(current)
            current = {"path": line.removeprefix("worktree "), "prunable": False}
            continue
        if current is None or not line:
            if not line and current:
                entries.append(current)
                current = None
            continue
        if line.startswith("HEAD "):
            current["sha"] = line.removeprefix("HEAD ")
        elif line.startswith("branch "):
            branch = line.removeprefix("branch ")
            current["branch"] = branch.removeprefix("refs/heads/")
        elif line == "detached":
            current["detached"] = True
        elif line.startswith("locked"):
            current["locked"] = True
        elif line.startswith("prunable"):
            current["prunable"] = True
            current["prune_reason"] = line.removeprefix("prunable").strip()
    if current:
        entries.append(current)
    for entry in entries:
        entry.setdefault("sha", None)
        entry.setdefault("branch", None)
        entry.setdefault("detached", False)
        entry.setdefault("locked", False)
        entry.setdefault("prune_reason", None)
    return entries


def _role(entry: dict[str, object], index: int) -> str:
    if index == 0:
        return "primary-source"
    branch = str(entry.get("branch") or "").lower()
    path = str(entry.get("path") or "").lower()
    if "release" in branch or "release" in path:
        return "release-candidate"
    if branch.startswith(("codex/", "feature/", "fix/", "hotfix/")):
        return "pull-request-candidate"
    if any(token in branch or token in path for token in ("test", "ci", "review")):
        return "test-candidate"
    return "other"


def _display_path(path: str, label: str, show_paths: bool) -> str:
    if show_paths:
        return path
    return "<" + label + ">"


def _display_remote(remote: str, show_paths: bool) -> str:
    """Keep remote URLs, paths, and embedded credentials out of default output."""
    if "://" in remote or "@" in remote:
        return "<remote>"
    if show_paths or re.fullmatch(r"[A-Za-z0-9._-]+", remote):
        return remote
    return "<remote>"


def _remote_uploadpack_override(
    repo: Path,
    remote: str,
    runner: Callable[..., tuple[int, bytes, bytes]],
) -> tuple[bool, bool]:
    """Check whether a configured remote overrides Git's upload-pack executable."""
    remote_code, remote_output, _ = runner(repo, ["remote"])
    if remote_code != 0:
        return False, True
    configured_remotes = remote_output.decode("utf-8", errors="surrogateescape").splitlines()
    if remote not in configured_remotes:
        return True, False
    code, output, _ = runner(repo, ["config", "--get-all", f"remote.{remote}.uploadpack"])
    if code == 1 and not output:
        return True, False
    if code == 0:
        return True, bool(output.strip())
    return False, True


def _assign_labels(entries: list[dict[str, object]]) -> None:
    """Assign stable labels without revealing checkout paths."""
    collisions: dict[str, int] = {}
    for entry in entries:
        raw_path = Path(str(entry["path"])).expanduser()
        try:
            canonical = str(raw_path.resolve(strict=False))
        except (OSError, RuntimeError, ValueError):
            canonical = str(raw_path.absolute())
        digest = hashlib.sha256(canonical.encode("utf-8")).hexdigest()[:12]
        collisions[digest] = collisions.get(digest, 0) + 1
        suffix = "" if collisions[digest] == 1 else f"-{collisions[digest]}"
        entry["label"] = f"worktree-{digest}{suffix}"


def _registered_git_admin(common_dir: Path, worktree_path: Path) -> Path | None:
    """Return the Git admin directory registered for a canonical worktree."""
    try:
        canonical_path = worktree_path.resolve(strict=False)
        if canonical_path == common_dir.parent.resolve(strict=False):
            return common_dir
        worktrees_dir = common_dir / "worktrees"
        if not worktrees_dir.is_dir():
            return None
        admin_dirs = list(worktrees_dir.iterdir())
    except (OSError, RuntimeError, UnicodeError, ValueError):
        return None
    matches: list[Path] = []
    metadata_error = False
    for admin_dir in admin_dirs:
        gitdir_file = admin_dir / "gitdir"
        try:
            if not gitdir_file.is_file():
                continue
            registered_gitdir = Path(gitdir_file.read_text(encoding="utf-8").strip()).expanduser()
            if not registered_gitdir.is_absolute():
                registered_gitdir = (admin_dir / registered_gitdir).resolve(strict=False)
            if registered_gitdir.name == ".git":
                registered_gitdir = registered_gitdir.parent
            if registered_gitdir.resolve(strict=False) == canonical_path:
                matches.append(admin_dir.resolve(strict=False))
        except (OSError, RuntimeError, UnicodeError, ValueError):
            metadata_error = True
    if metadata_error:
        return None
    return matches[0] if len(matches) == 1 else None


def inventory(
    argv: list[str] | None = None,
    runner: Callable[..., tuple[int, bytes, bytes]] = _run_git,
) -> tuple[int, dict[str, object]]:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo", default=".", help="checkout whose worktree registrations are inspected")
    parser.add_argument("--remote", default="origin")
    parser.add_argument("--branch", default="main")
    parser.add_argument("--json", action="store_true")
    parser.add_argument("--show-paths", action="store_true", help="include absolute checkout paths in output")
    args = parser.parse_args(argv)
    repo = Path(args.repo).expanduser().resolve()
    remote_display = _display_remote(args.remote, args.show_paths)
    observations: list[str] = []

    def git(*command: str) -> tuple[int, str]:
        code, output, _ = runner(repo, list(command))
        return code, _text(output)

    code, raw_worktree_bytes, _ = runner(repo, ["worktree", "list", "--porcelain", "-z"])
    if code != 0:
        report = {"status": "unknown", "repository": str(repo.name), "error": "worktree inventory unavailable"}
        return 1, report
    try:
        raw_worktree_text = raw_worktree_bytes.decode("utf-8")
    except UnicodeDecodeError:
        report = {"status": "unknown", "repository": str(repo.name), "error": "worktree inventory encoding unavailable"}
        return 1, report
    entries = _parse_worktrees(raw_worktree_text)
    if not entries:
        report = {"status": "unknown", "repository": str(repo.name), "error": "no worktree registered"}
        return 1, report

    common_code, raw_common_dir = git("rev-parse", "--path-format=absolute", "--git-common-dir")
    if common_code != 0 or not raw_common_dir:
        report = {"status": "unknown", "repository": str(repo.name), "error": "worktree identity unavailable"}
        return 1, report
    common_dir = Path(raw_common_dir).resolve(strict=False)

    _assign_labels(entries)
    for index, entry in enumerate(entries):
        path = Path(str(entry["path"]))
        exists = path.is_dir()
        entry["exists"] = exists
        entry["role"] = _role(entry, index)
        entry["dirty"] = None
        entry["operation_state_checked"] = False
        entry["operation_state"] = []
        entry["filter_state_checked"] = False
        entry["configured_filters"] = False
        entry["active_filters"] = False
        entry["git_admin_verified"] = False
        entry["index_state_checked"] = False
        entry["index_hidden_state"] = False
        entry["index_hidden_count"] = 0
        entry["identity_verified"] = False
        if exists:
            # A registered path replaced by a symlink can expose another
            # clean worktree with the same HEAD and common Git directory.
            # Require the registered path itself to remain canonical before
            # trusting any repository identity obtained through it.
            try:
                path_is_canonical = not path.is_symlink() and path.resolve(strict=False) == path.absolute()
            except (OSError, RuntimeError, ValueError):
                path_is_canonical = False
            common_status, path_common_dir, _ = runner(path, ["rev-parse", "--path-format=absolute", "--git-common-dir"])
            admin_status, path_admin_dir, _ = runner(path, ["rev-parse", "--path-format=absolute", "--absolute-git-dir"])
            head_status, path_head, _ = runner(path, ["rev-parse", "HEAD"])
            branch_status, path_branch, _ = runner(path, ["symbolic-ref", "-q", "HEAD"])
            registered_branch = entry.get("branch")
            branch_matches = (
                branch_status == 0 and _text(path_branch) == f"refs/heads/{registered_branch}"
                if registered_branch
                else branch_status == 1 and entry.get("detached") is True
            )
            entry["identity_verified"] = (
                path_is_canonical
                and common_status == 0
                and bool(path_common_dir)
                and Path(_text(path_common_dir)).resolve(strict=False) == common_dir
                and head_status == 0
                and _text(path_head) == entry.get("sha")
                and branch_matches
            )
            registered_admin = _registered_git_admin(common_dir, path) if path_is_canonical else None
            admin_path_matches = False
            if admin_status == 0 and path_admin_dir and registered_admin is not None:
                try:
                    admin_path_matches = Path(_text(path_admin_dir)).resolve(strict=False) == registered_admin
                except (OSError, RuntimeError, ValueError):
                    admin_path_matches = False
            entry["git_admin_verified"] = admin_path_matches
            entry["identity_verified"] = entry["identity_verified"] and entry["git_admin_verified"]
            if entry["identity_verified"]:
                guards_checked, configured_filters, state_paths, active_filters = _repository_guards(path, runner)
                entry["filter_state_checked"] = guards_checked
                entry["configured_filters"] = bool(configured_filters)
                entry["active_filters"] = active_filters
                entry["operation_state_checked"] = guards_checked
                entry["operation_state"] = state_paths
                # Blank every effective clean/process command on the status
                # invocation. Git propagates command-line config to status
                # calls it starts for initialized submodules, so configured
                # filters are observed without executing their commands.
                if guards_checked and not active_filters:
                    status_arguments = ["-c", "core.fsmonitor=false"]
                    for filter_name in configured_filters:
                        status_arguments.extend(
                            [
                                "-c",
                                f"filter.{filter_name}.clean=",
                                "-c",
                                f"filter.{filter_name}.process=",
                                "-c",
                                f"filter.{filter_name}.required=false",
                            ]
                        )
                    status_arguments.extend(
                        [
                            "status",
                            "--porcelain=v1",
                            "--untracked-files=all",
                            "--ignore-submodules=none",
                        ]
                    )
                    status_code, status, _ = runner(
                        path,
                        status_arguments,
                    )
                    if status_code == 0:
                        entry["dirty"] = bool(status.strip())
                index_code, index_output, _ = runner(
                    path,
                    ["-c", "core.fsmonitor=false", "ls-files", "-v", "-z", "--recurse-submodules"],
                )
                if index_code == 0:
                    hidden_flags = {"h", "s", "S"}
                    hidden_count = sum(
                        1
                        for record in index_output.decode("utf-8", errors="replace").split("\0")
                        if record and record[0] in hidden_flags
                    )
                    entry["index_state_checked"] = True
                    entry["index_hidden_count"] = hidden_count
                    entry["index_hidden_state"] = hidden_count > 0
        entry["display_path"] = _display_path(str(path), str(entry["label"]), args.show_paths)

    primary = entries[0]
    # Remote freshness belongs to the registered primary checkout, even when
    # the command was invoked from a linked worktree. With worktree-specific
    # Git configuration enabled, a linked checkout can override ``origin``;
    # using that context would compare the primary HEAD with the wrong remote.
    primary_repo = Path(str(primary["path"])).expanduser().resolve(strict=False)

    def primary_git(*command: str) -> tuple[int, str]:
        code, output, _ = runner(primary_repo, list(command))
        return code, _text(output)

    primary_branch = primary.get("branch")
    local_main = None
    if primary_branch == args.branch and primary.get("sha"):
        local_main = str(primary["sha"])
    remote_config_checked, remote_uploadpack_override = _remote_uploadpack_override(
        primary_repo, args.remote, runner
    )
    if remote_uploadpack_override:
        observations.append("remote upload-pack override is configured; freshness probe was skipped")
        remote_code, remote_main = 1, ""
    elif not remote_config_checked:
        observations.append("remote upload-pack configuration could not be verified")
        remote_code, remote_main = 1, ""
    else:
        remote_code, remote_main = primary_git(
            "ls-remote", "--", args.remote, f"refs/heads/{args.branch}"
        )
    remote_fields = remote_main.split()
    remote_sha = (
        remote_fields[0]
        if remote_code == 0
        and len(remote_fields) == 2
        and remote_fields[1] == f"refs/heads/{args.branch}"
        and re.fullmatch(r"(?:[0-9a-f]{40}|[0-9a-f]{64})", remote_fields[0])
        else None
    )
    freshness = "unknown"
    if local_main and remote_sha and primary.get("identity_verified") is True:
        if local_main == remote_sha:
            freshness = "current"
        else:
            shallow_code, shallow = primary_git("rev-parse", "--is-shallow-repository")
            # ``cat-file -e`` may satisfy a missing promisor object by
            # contacting the remote. ``rev-list --missing=print`` performs a
            # local object walk instead; together with GIT_NO_LAZY_FETCH this
            # keeps freshness inspection read-only even for partial clones.
            object_code, object_output = primary_git("rev-list", "--max-count=1", "--missing=print", f"{remote_sha}^{{commit}}")
            object_available = object_code == 0 and remote_sha in object_output.split() and not any(
                line.startswith("?") for line in object_output.splitlines()
            )
            if shallow_code == 0 and shallow == "false" and object_available:
                local_ancestor_code, _ = primary_git("merge-base", "--is-ancestor", local_main, remote_sha)
                remote_ancestor_code, _ = primary_git("merge-base", "--is-ancestor", remote_sha, local_main)
                if local_ancestor_code == 0 and remote_ancestor_code == 1:
                    freshness = "stale"
                elif local_ancestor_code == 1 and remote_ancestor_code == 0:
                    freshness = "ahead"
                elif local_ancestor_code == 1 and remote_ancestor_code == 1:
                    freshness = "diverged"
    stale = freshness == "stale"
    primary["freshness"] = freshness
    primary["remote_main_sha"] = remote_sha

    prune_code, prune_output = git("worktree", "prune", "--dry-run", "-v")
    prunable = [entry for entry in entries if entry.get("prunable")]
    if prune_code != 0:
        observations.append("prunable registration check unavailable")
    elif prunable:
        observations.append(f"{len(prunable)} registration(s) would be prunable; no prune was run")

    dirty = [entry for entry in entries if entry.get("dirty") is True]
    active = [entry for entry in entries if entry.get("exists") is True]
    blocked_refresh = (
        primary.get("dirty") is not False
        or primary.get("identity_verified") is not True
        or primary.get("operation_state_checked") is not True
        or bool(primary.get("operation_state"))
        or primary.get("git_admin_verified") is not True
        or primary.get("index_state_checked") is not True
        or primary.get("index_hidden_state") is True
        or primary_branch != args.branch
        or not remote_sha
    )
    remote_actionable = remote_display == args.remote
    refresh = {
        "safe_ff_only": remote_actionable and not blocked_refresh and stale,
        "commands": [],
        "reason": None,
    }
    if stale and not blocked_refresh and remote_actionable:
        primary_path = str(primary["path"])
        if args.show_paths:
            refresh["commands"] = [
                f"git -C {shlex.quote(primary_path)} fetch -- {shlex.quote(remote_display)} {shlex.quote(f'refs/heads/{args.branch}')}",
                f"git -C {shlex.quote(primary_path)} merge --ff-only {remote_sha}",
            ]
        else:
            refresh["commands"] = [
                f"From <{primary['label']}>: git fetch -- {shlex.quote(remote_display)} {shlex.quote(f'refs/heads/{args.branch}')}",
                f"From <{primary['label']}>: git merge --ff-only {remote_sha}",
            ]
    elif freshness == "current":
        refresh["reason"] = "primary is current"
    elif freshness == "ahead":
        refresh["reason"] = "local main is ahead of remote main"
    elif freshness == "diverged":
        refresh["reason"] = "local and remote main have diverged"
    elif freshness == "unknown":
        refresh["reason"] = "remote ancestry is unavailable; fetch the branch and rerun the inventory"
    elif stale and not remote_actionable:
        refresh["reason"] = "remote address is redacted; rerun with an executable remote name or explicit path"
    else:
        refresh["reason"] = "primary must be clean, on main, and remote main must be readable"

    def eligible_candidate(item: dict[str, object]) -> bool:
        return (
            item.get("identity_verified") is True
            and item.get("dirty") is False
            and item.get("operation_state_checked") is True
            and not item.get("operation_state")
            and item.get("git_admin_verified") is True
            and item.get("index_state_checked") is True
            and item.get("index_hidden_state") is not True
            and not item.get("prunable")
        )

    authority: dict[str, object] = {
        "source": primary["display_path"] if not remote_uploadpack_override and remote_config_checked and prune_code == 0 and primary_branch == args.branch and primary.get("identity_verified") is True and primary.get("dirty") is False and primary.get("operation_state_checked") is True and not primary.get("operation_state") and primary.get("git_admin_verified") is True and primary.get("index_state_checked") is True and primary.get("index_hidden_state") is not True and primary.get("freshness") == "current" else None,
        "test": next((item["display_path"] for item in entries if item["role"] == "test-candidate" and eligible_candidate(item)), None),
        "pull_request": next((item["display_path"] for item in entries if item["role"] == "pull-request-candidate" and eligible_candidate(item)), None),
        "release": next((item["display_path"] for item in entries if item["role"] == "release-candidate" and eligible_candidate(item)), None),
        "note": "Roles are local candidates; PR/release authority still requires current external evidence.",
    }
    in_progress = [entry for entry in entries if entry.get("operation_state")]
    state_unknown = any(entry.get("operation_state_checked") is not True for entry in entries)
    admin_unknown = any(
        entry.get("exists") is True and entry.get("git_admin_verified") is not True
        for entry in entries
    )
    hidden_index = [entry for entry in entries if entry.get("index_hidden_state") is True]
    index_unknown = any(entry.get("index_state_checked") is not True for entry in entries if entry.get("identity_verified") is True)
    status = "blocked" if dirty or prunable or in_progress or hidden_index or freshness in {"stale", "ahead", "diverged"} else "ready"
    if admin_unknown:
        status = "unknown"
    primary_not_authoritative = primary_branch != args.branch or primary.get("freshness") != "current"
    if primary_not_authoritative or any(item.get("dirty") is None for item in entries) or state_unknown or index_unknown or remote_sha is None or prune_code != 0:
        status = "unknown" if status == "ready" else status
    result_entries = []
    for entry in entries:
        result_entries.append({key: value for key, value in entry.items() if key != "path" or args.show_paths})
    report = {
        "status": status,
        "repository": str(repo.name),
        "remote": remote_display,
        "branch": args.branch,
        "primary": {key: value for key, value in primary.items() if key != "path" or args.show_paths},
        "worktrees": result_entries,
        "counts": {"registered": len(entries), "existing": len(active), "dirty": len(dirty), "prunable": len(prunable), "in_progress": len(in_progress)},
        "prunable_suggestions": [
            {"display_path": entry["display_path"], "reason": entry.get("prune_reason"), "action": "review; no automatic prune; run git worktree prune manually only when ownership is clear"}
            for entry in prunable
        ],
        "authority": authority,
        "safe_primary_refresh": refresh,
        "observations": observations,
        "prune_dry_run": prune_output.splitlines() if args.show_paths else bool(prunable),
    }
    return (0 if status == "ready" else 1), report


def main() -> int:
    code, report = inventory()
    if "--json" in sys.argv:
        print(json.dumps(report, indent=2, sort_keys=True))
    else:
        print(f"status: {report.get('status', 'unknown')}")
        print(f"repository: {report.get('repository', '<unknown>')}")
        if "error" in report:
            print(f"error: {report['error']}")
            return code
        counts = report.get("counts", {})
        print("worktrees: registered={registered} existing={existing} dirty={dirty} prunable={prunable} in_progress={in_progress}".format(**counts))
        primary = report.get("primary", {})
        print(f"primary: {primary.get('display_path', '<unknown>')} {primary.get('freshness', 'unknown')}")
        print("authority: " + json.dumps(report.get("authority", {}), ensure_ascii=False, sort_keys=True))
        refresh = report.get("safe_primary_refresh", {})
        if refresh.get("commands"):
            print("safe ff-only refresh:")
            for command in refresh["commands"]:
                print(f"  {command}")
        elif refresh.get("reason"):
            print(f"safe ff-only refresh: unavailable ({refresh['reason']})")
        for suggestion in report.get("prunable_suggestions", []):
            print(f"suggestion: review {suggestion['display_path']} ({suggestion['reason']}); no automatic prune")
    return code


if __name__ == "__main__":
    raise SystemExit(main())
