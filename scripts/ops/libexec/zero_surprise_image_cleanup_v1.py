#!/usr/bin/python3
"""Bounded, fail-closed cleanup for legacy Zero-Surprise replay images."""

from __future__ import annotations

import fcntl
import json
import os
import re
import selectors
import signal
import stat
import subprocess
import sys
import time
from dataclasses import dataclass
from typing import Callable


SCHEMA = "zero_surprise_image_cleanup.v1"
PROJECT_LABEL = "com.docker.compose.project"
SERVICE_LABEL = "com.docker.compose.service"
ALLOWED_SERVICES = frozenset(("pdf-renderer", "php-fpm"))
PROJECT_RE = re.compile(r"^zs-[a-z0-9][a-z0-9-]{0,58}[a-z0-9]$")
IMAGE_ID_RE = re.compile(r"^sha256:[a-f0-9]{64}$")
CONTAINER_ID_RE = re.compile(r"^[a-f0-9]{64}$")
REPO_DIGEST_RE = re.compile(r"^[a-z0-9][a-z0-9._/-]*@sha256:[a-f0-9]{64}$")
MAX_PROJECTS = 32
MAX_IMAGES = 64
MAX_DISCOVERY_IMAGES = 4096
MAX_CONTAINERS = 4096
MAX_OUTPUT_BYTES = 8 * 1024 * 1024
GLOBAL_LOCK = "/var/lib/fh-deploy-orchestrator/locks/fh-production-change.lock"
DOCKER = "/usr/bin/docker"
REPORT_KEYS = (
    "schema",
    "mode",
    "status",
    "reason",
    "project_count",
    "candidate_count",
    "candidate_virtual_bytes",
    "deleted_count",
    "free_bytes_before",
    "free_bytes_after",
    "freed_bytes",
    "cap_exceeded",
    "mutation_performed",
)
REPORT_REASONS = frozenset(
    (
        "active_production_work",
        "activity_state_unknown",
        "candidate_cap_exceeded",
        "cleanup_internal_error",
        "container_inspect_invalid",
        "container_inventory_invalid",
        "docker_command_failed",
        "docker_command_timeout",
        "docker_executable_unsafe",
        "docker_output_too_large",
        "docker_storage_root_changed",
        "docker_storage_root_invalid",
        "global_lock_busy",
        "global_lock_unsafe",
        "image_delete_unverified",
        "image_has_container_reference",
        "image_inspect_invalid",
        "image_inventory_invalid",
        "image_inventory_too_large",
        "image_snapshot_changed",
        "residual_project_image",
        "unsafe_candidate",
    )
)


class CleanupError(Exception):
    def __init__(self, reason: str, exit_code: int = 70):
        super().__init__(reason)
        self.reason = reason
        self.exit_code = exit_code


@dataclass(frozen=True)
class CommandResult:
    exit_code: int
    stdout: bytes
    stderr: bytes


@dataclass(frozen=True)
class Image:
    image_id: str
    project: str
    service: str
    tags: tuple[str, ...]
    digests: tuple[str, ...]
    size: int


def _trusted_directory(metadata: os.stat_result) -> bool:
    return stat.S_ISDIR(metadata.st_mode) and metadata.st_uid == 0 and metadata.st_gid == 0 and not (
        metadata.st_mode & 0o022
    )


def _walk_trusted_directory(path: str) -> int:
    if not path.startswith("/"):
        raise CleanupError("global_lock_unsafe")
    current = os.open("/", os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
    try:
        if not _trusted_directory(os.fstat(current)):
            raise CleanupError("global_lock_unsafe")
        for component in (part for part in path.split("/") if part):
            child = os.open(
                component,
                os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW,
                dir_fd=current,
            )
            if not _trusted_directory(os.fstat(child)):
                os.close(child)
                raise CleanupError("global_lock_unsafe")
            os.close(current)
            current = child
        return current
    except Exception:
        os.close(current)
        raise


def acquire_global_lock(path: str = GLOBAL_LOCK) -> int:
    try:
        parent = _walk_trusted_directory(os.path.dirname(path))
    except OSError as error:
        raise CleanupError("global_lock_unsafe") from error
    try:
        leaf = os.path.basename(path)
        before = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
        fd = os.open(leaf, os.O_RDWR | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
        opened = os.fstat(fd)
        after = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
        identity = lambda value: (
            value.st_dev,
            value.st_ino,
            value.st_mode,
            value.st_uid,
            value.st_gid,
            value.st_nlink,
        )
        if identity(before) != identity(opened) or identity(opened) != identity(after):
            os.close(fd)
            raise CleanupError("global_lock_unsafe")
        if not (
            stat.S_ISREG(opened.st_mode)
            and stat.S_IMODE(opened.st_mode) == 0o600
            and opened.st_uid == 0
            and opened.st_gid == 0
            and opened.st_nlink == 1
        ):
            os.close(fd)
            raise CleanupError("global_lock_unsafe")
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            os.close(fd)
            raise CleanupError("global_lock_busy", 75)
        return fd
    except (FileNotFoundError, NotADirectoryError, PermissionError, OSError) as error:
        raise CleanupError("global_lock_unsafe") from error
    finally:
        os.close(parent)


def _ensure_private_directory(parent: int, leaf: str) -> tuple[int, bool]:
    created = False
    try:
        before = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
    except FileNotFoundError:
        try:
            os.mkdir(leaf, 0o700, dir_fd=parent)
            created = True
            os.fsync(parent)
        except FileExistsError:
            pass
        before = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
    child = os.open(leaf, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW, dir_fd=parent)
    try:
        opened = os.fstat(child)
        after = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
        identity = lambda value: (
            value.st_dev,
            value.st_ino,
            value.st_mode,
            value.st_uid,
            value.st_gid,
            value.st_nlink,
        )
        if identity(before) != identity(opened) or identity(opened) != identity(after):
            raise CleanupError("global_lock_unsafe")
        if not (
            stat.S_ISDIR(opened.st_mode)
            and stat.S_IMODE(opened.st_mode) == 0o700
            and opened.st_uid == 0
            and opened.st_gid == 0
        ):
            raise CleanupError("global_lock_unsafe")
        return child, created
    except Exception:
        os.close(child)
        raise


def prepare_global_lock(state_root: str = os.path.dirname(os.path.dirname(GLOBAL_LOCK))) -> tuple[dict[str, object], int]:
    report: dict[str, object] = {
        "schema": SCHEMA,
        "mode": "prepare-lock",
        "status": "pass",
        "reason": None,
        "project_count": 0,
        "candidate_count": 0,
        "candidate_virtual_bytes": 0,
        "deleted_count": 0,
        "free_bytes_before": None,
        "free_bytes_after": None,
        "freed_bytes": None,
        "cap_exceeded": False,
        "mutation_performed": False,
    }
    mutation_performed = False
    parent: int | None = None
    root: int | None = None
    locks: int | None = None
    lock_fd: int | None = None
    try:
        parent = _walk_trusted_directory(os.path.dirname(state_root))
        root, created = _ensure_private_directory(parent, os.path.basename(state_root))
        mutation_performed = mutation_performed or created
        locks, created = _ensure_private_directory(root, "locks")
        mutation_performed = mutation_performed or created
        leaf = os.path.basename(GLOBAL_LOCK)
        try:
            before = os.stat(leaf, dir_fd=locks, follow_symlinks=False)
        except FileNotFoundError:
            try:
                lock_fd = os.open(
                    leaf,
                    os.O_RDWR | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK,
                    0o600,
                    dir_fd=locks,
                )
                mutation_performed = True
                os.fchmod(lock_fd, 0o600)
                os.fsync(lock_fd)
                os.fsync(locks)
            except FileExistsError:
                lock_fd = None
            before = os.stat(leaf, dir_fd=locks, follow_symlinks=False)
        if lock_fd is None:
            lock_fd = os.open(
                leaf,
                os.O_RDWR | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK,
                dir_fd=locks,
            )
        opened = os.fstat(lock_fd)
        after = os.stat(leaf, dir_fd=locks, follow_symlinks=False)
        identity = lambda value: (
            value.st_dev,
            value.st_ino,
            value.st_mode,
            value.st_uid,
            value.st_gid,
            value.st_nlink,
        )
        if identity(before) != identity(opened) or identity(opened) != identity(after):
            raise CleanupError("global_lock_unsafe")
        if not (
            stat.S_ISREG(opened.st_mode)
            and stat.S_IMODE(opened.st_mode) == 0o600
            and opened.st_uid == 0
            and opened.st_gid == 0
            and opened.st_nlink == 1
            and opened.st_size == 0
        ):
            raise CleanupError("global_lock_unsafe")
        try:
            fcntl.flock(lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError as error:
            raise CleanupError("global_lock_busy", 75) from error
        os.fsync(lock_fd)
        os.fsync(locks)
        report["mutation_performed"] = mutation_performed
        return report, 0
    except CleanupError as error:
        report["status"] = "partial" if mutation_performed else "blocked"
        report["reason"] = error.reason
        report["mutation_performed"] = mutation_performed
        return report, 75 if mutation_performed else error.exit_code
    except OSError:
        report["status"] = "partial" if mutation_performed else "blocked"
        report["reason"] = "global_lock_unsafe"
        report["mutation_performed"] = mutation_performed
        return report, 75 if mutation_performed else 70
    finally:
        for descriptor in (lock_fd, locks, root, parent):
            if descriptor is not None:
                os.close(descriptor)


ACTIVITY_PATTERNS = (
    re.compile(r"(^|/)(deploy_ea\.sh|deployment_host_runner_v1\.php|zero_surprise_replay\.php)(\s|$)"),
    re.compile(r"(^|/)(deployment_dump_attestation_v1\.py|verify_deployment_dump_v1\.php)(\s|$)"),
    re.compile(r"(^|/)(prod_(build_cache|release_archive_dump|session)_retention\.sh)(\s|$)"),
    re.compile(
        r"(^|/|\s)docker(-compose)?\s+"
        r"(build|compose\b.*\s(build|run|up)|builder\s+prune|buildx\s+(build|bake|prune))(\s|$)"
    ),
    re.compile(r"(^|/)buildctl(\s|$)"),
    re.compile(r"(^|/)(mysqldump|mariadb-dump|backup_easyappointments\.sh)(\s|$)"),
)


def assert_idle(proc_root: str = "/proc") -> None:
    try:
        proc_fd = os.open(proc_root, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW)
    except OSError as error:
        raise CleanupError("activity_state_unknown") from error
    try:
        entries = list(os.scandir(proc_root))
    except OSError as error:
        os.close(proc_fd)
        raise CleanupError("activity_state_unknown") from error
    try:
        for entry in entries:
            if not entry.name.isdigit() or int(entry.name) == os.getpid():
                continue
            try:
                fd = os.open(
                    entry.name + "/cmdline",
                    os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW,
                    dir_fd=proc_fd,
                )
            except FileNotFoundError:
                continue
            except OSError as error:
                try:
                    os.stat(entry.name, dir_fd=proc_fd, follow_symlinks=False)
                except FileNotFoundError:
                    continue
                raise CleanupError("activity_state_unknown") from error
            try:
                raw = os.read(fd, 131073)
            finally:
                os.close(fd)
            if len(raw) > 131072:
                raise CleanupError("activity_state_unknown")
            command = raw.replace(b"\0", b" ").decode("utf-8", "replace").strip()
            if command and any(pattern.search(command) for pattern in ACTIVITY_PATTERNS):
                raise CleanupError("active_production_work", 75)
    finally:
        os.close(proc_fd)


def assert_trusted_docker(path: str = DOCKER) -> None:
    parent = _walk_trusted_directory(os.path.dirname(path))
    try:
        leaf = os.path.basename(path)
        before = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
        fd = os.open(leaf, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
        try:
            opened = os.fstat(fd)
            after = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
        finally:
            os.close(fd)
        identity = lambda value: (
            value.st_dev,
            value.st_ino,
            value.st_mode,
            value.st_uid,
            value.st_gid,
            value.st_nlink,
            value.st_size,
            value.st_mtime_ns,
            value.st_ctime_ns,
        )
        if identity(before) != identity(opened) or identity(opened) != identity(after):
            raise CleanupError("docker_executable_unsafe")
        if not (
            stat.S_ISREG(opened.st_mode)
            and stat.S_IMODE(opened.st_mode) == 0o755
            and opened.st_uid == 0
            and opened.st_gid == 0
            and opened.st_nlink == 1
        ):
            raise CleanupError("docker_executable_unsafe")
    except (FileNotFoundError, NotADirectoryError, PermissionError, OSError) as error:
        raise CleanupError("docker_executable_unsafe") from error
    finally:
        os.close(parent)


def run_command(command: list[str], timeout_seconds: float, accepted: frozenset[int] = frozenset((0,))) -> CommandResult:
    process = subprocess.Popen(
        command,
        stdin=subprocess.DEVNULL,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        close_fds=True,
        start_new_session=True,
        env={"LC_ALL": "C", "PATH": "/usr/bin:/bin"},
    )
    assert process.stdout is not None and process.stderr is not None
    selector = selectors.DefaultSelector()
    selector.register(process.stdout, selectors.EVENT_READ, "stdout")
    selector.register(process.stderr, selectors.EVENT_READ, "stderr")
    buffers = {"stdout": bytearray(), "stderr": bytearray()}
    deadline = time.monotonic() + timeout_seconds
    try:
        while selector.get_map():
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                raise TimeoutError
            events = selector.select(remaining)
            if not events:
                raise TimeoutError
            for key, _ in events:
                chunk = os.read(key.fileobj.fileno(), 65536)
                if not chunk:
                    selector.unregister(key.fileobj)
                    continue
                target = buffers[key.data]
                target.extend(chunk)
                if len(target) > MAX_OUTPUT_BYTES:
                    raise CleanupError("docker_output_too_large")
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            raise TimeoutError
        exit_code = process.wait(timeout=remaining)
    except (TimeoutError, subprocess.TimeoutExpired):
        os.killpg(process.pid, signal.SIGKILL)
        process.wait()
        raise CleanupError("docker_command_timeout")
    except Exception:
        if process.poll() is None:
            os.killpg(process.pid, signal.SIGKILL)
            process.wait()
        raise
    finally:
        selector.close()
    result = CommandResult(exit_code, bytes(buffers["stdout"]), bytes(buffers["stderr"]))
    if exit_code not in accepted:
        raise CleanupError("docker_command_failed")
    return result


class CleanupEngine:
    def __init__(
        self,
        runner: Callable[[list[str], float, frozenset[int]], CommandResult] = run_command,
        idle: Callable[[], None] = assert_idle,
        statvfs: Callable[[str], os.statvfs_result] = os.statvfs,
    ) -> None:
        self.runner = runner
        self.idle = idle
        self.statvfs = statvfs

    def docker(self, arguments: list[str], timeout: float = 60, accepted: frozenset[int] = frozenset((0,))) -> CommandResult:
        return self.runner([DOCKER, *arguments], timeout, accepted)

    def image_ids(self, arguments: list[str]) -> list[str]:
        raw = self.docker(arguments, 30).stdout.decode("ascii", "strict").strip()
        if not raw:
            return []
        ids = sorted(set(line.strip() for line in raw.splitlines()))
        if any(not IMAGE_ID_RE.fullmatch(image_id) for image_id in ids):
            raise CleanupError("image_inventory_invalid")
        if len(ids) > MAX_DISCOVERY_IMAGES:
            raise CleanupError("image_inventory_too_large", 75)
        return ids

    def inspect_images(self, ids: list[str]) -> list[dict[str, object]]:
        records: list[dict[str, object]] = []
        for offset in range(0, len(ids), MAX_IMAGES):
            batch = ids[offset : offset + MAX_IMAGES]
            try:
                decoded = json.loads(self.docker(["image", "inspect", *batch], 60).stdout)
            except (UnicodeDecodeError, json.JSONDecodeError) as error:
                raise CleanupError("image_inspect_invalid") from error
            if not isinstance(decoded, list) or len(decoded) != len(batch) or not all(isinstance(item, dict) for item in decoded):
                raise CleanupError("image_inspect_invalid")
            records.extend(decoded)
        return records

    def snapshot(self) -> dict[str, Image]:
        ids = self.image_ids(["image", "ls", "--filter", f"label={PROJECT_LABEL}", "--quiet", "--no-trunc"])
        records = self.inspect_images(ids)
        seen: set[str] = set()
        candidates: dict[str, Image] = {}
        for record in records:
            image_id = record.get("Id")
            labels = record.get("Config", {}).get("Labels") if isinstance(record.get("Config"), dict) else None
            project = labels.get(PROJECT_LABEL) if isinstance(labels, dict) else None
            if not isinstance(image_id, str) or image_id not in ids or image_id in seen:
                raise CleanupError("image_inspect_invalid")
            if not isinstance(project, str) or not project:
                raise CleanupError("image_inspect_invalid")
            seen.add(image_id)
            if not project.startswith("zs-"):
                continue
            if not PROJECT_RE.fullmatch(project):
                raise CleanupError("unsafe_candidate")
            service = labels.get(SERVICE_LABEL) if isinstance(labels, dict) else None
            tags = record.get("RepoTags")
            digests = record.get("RepoDigests")
            size = record.get("Size")
            if not isinstance(service, str) or service not in ALLOWED_SERVICES:
                raise CleanupError("unsafe_candidate")
            expected_tag = f"{project}-{service}:latest"
            if not isinstance(tags, list) or tags != [expected_tag] or not all(isinstance(value, str) for value in tags):
                raise CleanupError("unsafe_candidate")
            if digests is None:
                digests = []
            if not isinstance(digests, list) or not all(isinstance(value, str) for value in digests):
                raise CleanupError("unsafe_candidate")
            expected_repo = expected_tag.removesuffix(":latest")
            if digests and (
                len(digests) != 1
                or not REPO_DIGEST_RE.fullmatch(digests[0])
                or not digests[0].startswith(expected_repo + "@")
            ):
                raise CleanupError("unsafe_candidate")
            if not isinstance(size, int) or isinstance(size, bool) or size < 0:
                raise CleanupError("unsafe_candidate")
            candidates[image_id] = Image(image_id, project, service, tuple(tags), tuple(digests), size)
        if seen != set(ids):
            raise CleanupError("image_inspect_invalid")
        return dict(sorted(candidates.items()))

    def assert_no_references(self, candidate_ids: set[str]) -> None:
        raw = self.docker(["container", "ls", "--all", "--quiet", "--no-trunc"], 30).stdout.decode("ascii", "strict").strip()
        if not raw:
            return
        container_ids = sorted(set(line.strip() for line in raw.splitlines()))
        if len(container_ids) > MAX_CONTAINERS or any(not CONTAINER_ID_RE.fullmatch(value) for value in container_ids):
            raise CleanupError("container_inventory_invalid")
        for offset in range(0, len(container_ids), MAX_IMAGES):
            batch = container_ids[offset : offset + MAX_IMAGES]
            try:
                records = json.loads(self.docker(["container", "inspect", *batch], 60).stdout)
            except (UnicodeDecodeError, json.JSONDecodeError) as error:
                raise CleanupError("container_inspect_invalid") from error
            if not isinstance(records, list) or len(records) != len(batch):
                raise CleanupError("container_inspect_invalid")
            for record in records:
                image_id = record.get("Image") if isinstance(record, dict) else None
                if not isinstance(image_id, str) or not IMAGE_ID_RE.fullmatch(image_id):
                    raise CleanupError("container_inspect_invalid")
                if image_id in candidate_ids:
                    raise CleanupError("image_has_container_reference")

    def storage(self) -> tuple[str, int]:
        try:
            value = json.loads(self.docker(["info", "--format", "{{json .DockerRootDir}}"], 30).stdout)
        except (UnicodeDecodeError, json.JSONDecodeError) as error:
            raise CleanupError("docker_storage_root_invalid") from error
        if not isinstance(value, str) or not value.startswith("/"):
            raise CleanupError("docker_storage_root_invalid")
        resolved = os.path.realpath(value)
        try:
            metadata = os.stat(resolved)
            filesystem = self.statvfs(resolved)
        except OSError as error:
            raise CleanupError("docker_storage_root_invalid") from error
        if not stat.S_ISDIR(metadata.st_mode):
            raise CleanupError("docker_storage_root_invalid")
        return resolved, filesystem.f_bavail * filesystem.f_frsize

    def run(self, mode: str) -> tuple[dict[str, object], int]:
        report: dict[str, object] = {
            "schema": SCHEMA,
            "mode": mode,
            "status": "pass",
            "reason": None,
            "project_count": 0,
            "candidate_count": 0,
            "candidate_virtual_bytes": 0,
            "deleted_count": 0,
            "free_bytes_before": None,
            "free_bytes_after": None,
            "freed_bytes": None,
            "cap_exceeded": False,
            "mutation_performed": False,
        }
        deleted = 0
        mutation_attempted = False
        try:
            self.idle()
            docker_root, before_free = self.storage()
            first = self.snapshot()
            project_count = len({image.project for image in first.values()})
            report["project_count"] = project_count
            report["candidate_count"] = len(first)
            report["candidate_virtual_bytes"] = sum(image.size for image in first.values())
            report["free_bytes_before"] = before_free
            if project_count > MAX_PROJECTS or len(first) > MAX_IMAGES:
                report["cap_exceeded"] = True
                raise CleanupError("candidate_cap_exceeded", 75)
            self.assert_no_references(set(first))
            second = self.snapshot()
            if first != second:
                raise CleanupError("image_snapshot_changed", 75)
            self.assert_no_references(set(second))
            self.idle()
            if mode == "dry-run":
                after_root, after_free = self.storage()
                if after_root != docker_root:
                    raise CleanupError("docker_storage_root_changed")
                report["free_bytes_after"] = after_free
                report["freed_bytes"] = 0
                return report, 0

            remaining = dict(second)
            for image_id in list(second):
                self.idle()
                fresh = self.snapshot()
                if fresh != remaining:
                    raise CleanupError("image_snapshot_changed", 75)
                self.assert_no_references(set(remaining))
                mutation_attempted = True
                report["mutation_performed"] = True
                self.docker(["image", "rm", image_id], 120)
                verify = self.docker(["image", "inspect", image_id], 30, frozenset((0, 1)))
                if verify.exit_code != 1:
                    raise CleanupError("image_delete_unverified", 75)
                del remaining[image_id]
                deleted += 1
                report["deleted_count"] = deleted
                report["mutation_performed"] = True
            self.idle()
            if self.snapshot():
                raise CleanupError("residual_project_image", 75)
            after_root, after_free = self.storage()
            if after_root != docker_root:
                raise CleanupError("docker_storage_root_changed", 75)
            report["free_bytes_after"] = after_free
            report["freed_bytes"] = max(0, after_free - before_free)
            return report, 0
        except CleanupError as error:
            report["status"] = "partial" if mutation_attempted else "blocked"
            report["reason"] = error.reason
            report["deleted_count"] = deleted
            report["mutation_performed"] = mutation_attempted
            return report, 75 if mutation_attempted else error.exit_code
        except Exception:
            report["status"] = "partial" if mutation_attempted else "blocked"
            report["reason"] = "cleanup_internal_error"
            report["deleted_count"] = deleted
            report["mutation_performed"] = mutation_attempted
            return report, 75 if mutation_attempted else 70


def emit(report: dict[str, object]) -> None:
    print(json.dumps(report, separators=(",", ":"), ensure_ascii=True))


def validate_report(raw: bytes, mode: str, remote_exit: int) -> bool:
    if len(raw) > 4096 or not raw.endswith(b"\n") or raw.endswith(b"\n\n"):
        return False
    try:
        record = json.loads(raw[:-1])
    except (UnicodeDecodeError, json.JSONDecodeError):
        return False
    if not isinstance(record, dict) or tuple(record) != REPORT_KEYS:
        return False
    if (json.dumps(record, separators=(",", ":"), ensure_ascii=True) + "\n").encode() != raw:
        return False
    if record["schema"] != SCHEMA or record["mode"] != mode:
        return False
    integers = ("project_count", "candidate_count", "candidate_virtual_bytes", "deleted_count")
    if any(type(record[key]) is not int or record[key] < 0 for key in integers):
        return False
    nullable_integers = ("free_bytes_before", "free_bytes_after", "freed_bytes")
    if any(record[key] is not None and (type(record[key]) is not int or record[key] < 0) for key in nullable_integers):
        return False
    if type(record["cap_exceeded"]) is not bool or type(record["mutation_performed"]) is not bool:
        return False
    if record["deleted_count"] > record["candidate_count"]:
        return False
    if record["deleted_count"] > 0 and not record["mutation_performed"]:
        return False
    if mode == "prepare-lock" and (
        any(record[key] != 0 for key in integers)
        or any(record[key] is not None for key in nullable_integers)
        or record["cap_exceeded"]
    ):
        return False
    status = record["status"]
    reason = record["reason"]
    if status == "pass":
        if remote_exit != 0 or reason is not None or record["cap_exceeded"]:
            return False
        if mode != "prepare-lock" and any(type(record[key]) is not int for key in nullable_integers):
            return False
        if record["project_count"] > MAX_PROJECTS or record["candidate_count"] > MAX_IMAGES:
            return False
        if mode == "dry-run" and (record["deleted_count"] != 0 or record["freed_bytes"] != 0):
            return False
        if mode == "execute" and record["deleted_count"] != record["candidate_count"]:
            return False
        if mode != "prepare-lock" and record["mutation_performed"] != (record["deleted_count"] > 0):
            return False
    elif status == "blocked":
        if remote_exit not in (70, 75) or record["mutation_performed"] or reason not in REPORT_REASONS:
            return False
    elif status == "partial":
        if mode not in ("execute", "prepare-lock") or remote_exit != 75 or not record["mutation_performed"] or reason not in REPORT_REASONS:
            return False
    else:
        return False
    return record["cap_exceeded"] == (reason == "candidate_cap_exceeded")


def main(argv: list[str]) -> int:
    if len(argv) == 4 and argv[1] == "validate" and argv[2] in ("dry-run", "execute", "prepare-lock"):
        if not re.fullmatch(r"0|64|70|75|255", argv[3]):
            return 1
        raw = sys.stdin.buffer.read(4097)
        return 0 if validate_report(raw, argv[2], int(argv[3])) else 1
    if len(argv) != 2 or argv[1] not in ("dry-run", "execute", "prepare-lock"):
        print("usage: zero_surprise_image_cleanup_v1.py dry-run|execute|prepare-lock", file=sys.stderr)
        return 64
    mode = argv[1]
    if mode == "prepare-lock":
        report, exit_code = prepare_global_lock()
        emit(report)
        return exit_code
    lock_fd: int | None = None
    try:
        lock_fd = acquire_global_lock()
        assert_trusted_docker()
        report, exit_code = CleanupEngine().run(mode)
    except CleanupError as error:
        report = {
            "schema": SCHEMA,
            "mode": mode,
            "status": "blocked",
            "reason": error.reason,
            "project_count": 0,
            "candidate_count": 0,
            "candidate_virtual_bytes": 0,
            "deleted_count": 0,
            "free_bytes_before": None,
            "free_bytes_after": None,
            "freed_bytes": None,
            "cap_exceeded": False,
            "mutation_performed": False,
        }
        exit_code = error.exit_code
    except Exception:
        report = {
            "schema": SCHEMA,
            "mode": mode,
            "status": "blocked",
            "reason": "cleanup_internal_error",
            "project_count": 0,
            "candidate_count": 0,
            "candidate_virtual_bytes": 0,
            "deleted_count": 0,
            "free_bytes_before": None,
            "free_bytes_after": None,
            "freed_bytes": None,
            "cap_exceeded": False,
            "mutation_performed": False,
        }
        exit_code = 70
    finally:
        if lock_fd is not None:
            os.close(lock_fd)
    emit(report)
    return exit_code


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
