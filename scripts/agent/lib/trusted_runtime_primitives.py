#!/usr/bin/python3
"""Fail-closed filesystem, archive, ELF, and dependency-closure primitives."""

import hashlib
import json
import os
import re
import stat
import struct
import subprocess
import tarfile
import time
import urllib.parse


HEX64 = re.compile(r"^[0-9a-f]{64}$")
PLATFORM = re.compile(r"^[A-Za-z0-9_.-]+-[A-Za-z0-9_.-]+$")
SYSTEM_UTILITIES = {"Darwin": "/usr/bin/otool", "Linux": "/usr/bin/ldd"}
CURL_EXECUTABLE = "/usr/bin/curl"
HEAD_EXECUTABLE = "/usr/bin/head"
# Curl reads user configuration before interpreting most command-line options.
# Keep --disable as the first option and pass an explicit allowlist environment;
# this excludes curlrc/netrc/proxy and credential-related ambient variables.
SAFE_CURL_ENVIRONMENT = {"PATH": "/usr/bin:/bin", "LC_ALL": "C"}
CURL_SECURITY_OPTIONS = (
    "--disable",
    "--fail",
    "--silent",
    "--show-error",
    "--location",
    "--proto",
    "=https",
    "--proto-redir",
    "=https",
)
DARWIN_SEALED_PREFIXES = ("/usr/lib/", "/System/Library/")
PINNED_ARCHIVE_MAX_BYTES = 64 * 1024 * 1024
PINNED_ARCHIVE_MEMBER_MAX_BYTES = 128 * 1024 * 1024
CODEX_BINARY_MAX_BYTES = 512 * 1024 * 1024
ELF_MACHINE_BY_ARCHITECTURE = {"x86_64": 62, "aarch64": 183}
ELF_HEADER_64_SIZE = 64
ELF_PROGRAM_HEADER_64_SIZE = 56
ELF_PROGRAM_HEADER_MAX_COUNT = 4096
ELF_PT_DYNAMIC = 2
ELF_PT_INTERP = 3
ELF_DT_NULL = 0
ELF_DT_NEEDED = 1


class AttestationError(Exception):
    """A fail-closed input, trust, or inspection error."""


def _regular_secure(path):
    """Return lstat metadata, rejecting links, writable files, and directories."""
    try:
        value = os.lstat(path)
    except (OSError, ValueError) as exc:
        raise AttestationError("runtime path is unavailable") from exc
    if not stat.S_ISREG(value.st_mode) or stat.S_ISLNK(value.st_mode):
        raise AttestationError("runtime path is not a regular file")
    if stat.S_IMODE(value.st_mode) & 0o022:
        raise AttestationError("runtime path is writable by group or world")
    return value


def _canonical_regular(path):
    if not isinstance(path, str) or not path.startswith("/") or "\x00" in path:
        raise AttestationError("runtime path is not absolute")
    # realpath resolves links but raises no error for a missing leaf; lstat
    # below catches that case.  A repeated canonical path is a cycle in the
    # dependency walk and is handled by the caller.
    canonical = os.path.realpath(path)
    if not canonical.startswith("/"):
        raise AttestationError("runtime path canonicalization failed")
    _regular_secure(canonical)
    return canonical


def _private_materialization_root(path):
    if not isinstance(path, str) or not path.startswith("/") or "\x00" in path:
        raise AttestationError("runtime materialization root is invalid")
    if os.path.lexists(path):
        raise AttestationError("runtime materialization root already exists")
    parent = os.path.realpath(os.path.dirname(path))
    try:
        parent_metadata = os.stat(parent, follow_symlinks=False)
    except (OSError, ValueError) as exc:
        raise AttestationError("runtime materialization parent is unavailable") from exc
    if (
        not stat.S_ISDIR(parent_metadata.st_mode)
        or parent_metadata.st_uid != os.geteuid()
        or stat.S_IMODE(parent_metadata.st_mode) & 0o077
    ):
        raise AttestationError("runtime materialization parent is not private")
    try:
        os.mkdir(path, 0o700)
    except OSError as exc:
        raise AttestationError("runtime materialization root could not be created") from exc
    metadata = os.stat(path, follow_symlinks=False)
    if (
        not stat.S_ISDIR(metadata.st_mode)
        or metadata.st_uid != os.geteuid()
        or stat.S_IMODE(metadata.st_mode) != 0o700
    ):
        raise AttestationError("runtime materialization root is not private")
    return path


def _download_pinned_archive(url, target):
    curl = CURL_EXECUTABLE
    limiter = HEAD_EXECUTABLE
    try:
        curl_metadata = _regular_secure(os.path.realpath(curl))
        limiter_metadata = _regular_secure(os.path.realpath(limiter))
    except AttestationError as exc:
        raise AttestationError("safe runtime archive transport is unavailable") from exc
    if (
        curl_metadata.st_uid != 0
        or limiter_metadata.st_uid != 0
        or not os.access(curl, os.X_OK)
        or not os.access(limiter, os.X_OK)
    ):
        raise AttestationError("safe runtime archive transport is unavailable")
    curl_process = None
    limiter_process = None
    try:
        with open(target, "xb") as output:
            curl_process = subprocess.Popen(
                [
                    curl,
                    *CURL_SECURITY_OPTIONS,
                    "--max-filesize",
                    str(PINNED_ARCHIVE_MAX_BYTES),
                    url,
                ],
                stdout=subprocess.PIPE,
                stderr=subprocess.DEVNULL,
                env=dict(SAFE_CURL_ENVIRONMENT),
            )
            if curl_process.stdout is None:
                raise OSError("runtime archive transport pipe is unavailable")
            limiter_process = subprocess.Popen(
                [limiter, "-c", str(PINNED_ARCHIVE_MAX_BYTES + 1)],
                stdin=curl_process.stdout,
                stdout=output,
                stderr=subprocess.DEVNULL,
                env=dict(SAFE_CURL_ENVIRONMENT),
            )
            curl_process.stdout.close()
            deadline = time.monotonic() + 180
            limiter_status = limiter_process.wait(timeout=max(0.1, deadline - time.monotonic()))
            curl_status = curl_process.wait(timeout=max(0.1, deadline - time.monotonic()))
        if (
            limiter_status != 0
            or curl_status != 0
            or os.path.getsize(target) > PINNED_ARCHIVE_MAX_BYTES
        ):
            raise subprocess.SubprocessError("runtime archive transport exceeded its boundary")
    except (OSError, subprocess.SubprocessError) as exc:
        for process in (limiter_process, curl_process):
            if process is not None and process.poll() is None:
                process.kill()
                process.wait()
        try:
            os.unlink(target)
        except FileNotFoundError:
            pass
        except OSError:
            pass
        raise AttestationError("pinned runtime archive download failed") from exc


def _sha256_file(path, maximum_bytes=None):
    digest = hashlib.sha256()
    total = 0
    try:
        with open(path, "rb") as stream:
            while True:
                chunk = stream.read(1024 * 1024)
                if not chunk:
                    break
                total += len(chunk)
                if maximum_bytes is not None and total > maximum_bytes:
                    raise AttestationError("pinned runtime artifact exceeds its size bound")
                digest.update(chunk)
    except OSError as exc:
        raise AttestationError("pinned runtime artifact is unavailable") from exc
    return digest.hexdigest()


def _validated_pinned_archive_descriptor(descriptor):
    if not isinstance(descriptor, dict) or set(descriptor) != {
        "url",
        "archive_sha256",
        "member",
        "member_sha256",
    }:
        raise AttestationError("pinned runtime archive policy is invalid")
    url = descriptor["url"]
    archive_sha256 = descriptor["archive_sha256"]
    member = descriptor["member"]
    member_sha256 = descriptor["member_sha256"]
    try:
        parsed_url = urllib.parse.urlsplit(url) if isinstance(url, str) else None
        parsed_port = parsed_url.port if parsed_url is not None else None
    except ValueError as exc:
        raise AttestationError("pinned runtime archive policy is invalid") from exc
    if (
        not isinstance(url, str)
        or parsed_url is None
        or parsed_url.scheme != "https"
        or not parsed_url.hostname
        or parsed_url.username is not None
        or parsed_url.password is not None
        or parsed_port not in (None, 443)
        or bool(parsed_url.query)
        or bool(parsed_url.fragment)
        or "\x00" in url
        or not isinstance(archive_sha256, str)
        or HEX64.fullmatch(archive_sha256) is None
        or not isinstance(member_sha256, str)
        or HEX64.fullmatch(member_sha256) is None
        or not isinstance(member, str)
        or not re.fullmatch(r"[A-Za-z0-9_.-]+", member)
    ):
        raise AttestationError("pinned runtime archive policy is invalid")
    return url, archive_sha256, member, member_sha256


def _materialize_pinned_archive(descriptor, materialize_root, downloader=None):
    url, archive_sha256, member, member_sha256 = _validated_pinned_archive_descriptor(descriptor)
    root = _private_materialization_root(materialize_root)
    archive_path = os.path.join(root, "runtime.tar.gz")
    if downloader is None:
        _download_pinned_archive(url, archive_path)
    else:
        downloader(url, archive_path)
    _regular_secure(archive_path)
    if _sha256_file(archive_path, PINNED_ARCHIVE_MAX_BYTES) != archive_sha256:
        raise AttestationError("pinned runtime archive digest mismatch")
    try:
        with tarfile.open(archive_path, mode="r:gz") as archive:
            members = archive.getmembers()
            if len(members) != 1:
                raise AttestationError("pinned runtime archive has unexpected contents")
            archive_member = members[0]
            if (
                archive_member.name != member
                or not archive_member.isreg()
                or archive_member.size < 1
                or archive_member.size > PINNED_ARCHIVE_MEMBER_MAX_BYTES
            ):
                raise AttestationError("pinned runtime archive member is invalid")
            source = archive.extractfile(archive_member)
            if source is None:
                raise AttestationError("pinned runtime archive member is unavailable")
            target = os.path.join(root, member)
            flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL
            if hasattr(os, "O_NOFOLLOW"):
                flags |= os.O_NOFOLLOW
            descriptor_fd = os.open(target, flags, 0o500)
            try:
                with os.fdopen(descriptor_fd, "wb", closefd=True) as output:
                    copied = 0
                    while True:
                        chunk = source.read(1024 * 1024)
                        if not chunk:
                            break
                        copied += len(chunk)
                        if copied > PINNED_ARCHIVE_MEMBER_MAX_BYTES:
                            raise AttestationError("pinned runtime archive member exceeds its size bound")
                        output.write(chunk)
                    output.flush()
                    os.fsync(output.fileno())
            finally:
                source.close()
    except (OSError, tarfile.TarError) as exc:
        raise AttestationError("pinned runtime archive could not be extracted safely") from exc
    os.chmod(target, 0o500)
    metadata = _regular_secure(target)
    if metadata.st_uid != os.geteuid() or stat.S_IMODE(metadata.st_mode) != 0o500:
        raise AttestationError("materialized runtime ownership is invalid")
    if _sha256_file(target, PINNED_ARCHIVE_MEMBER_MAX_BYTES) != member_sha256:
        raise AttestationError("pinned runtime member digest mismatch")
    try:
        os.unlink(archive_path)
    except OSError as exc:
        raise AttestationError("pinned runtime archive cleanup failed") from exc
    return target, "%s#%s" % (url, member), member


def _assert_linux_static_elf(path, architecture):
    expected_machine = ELF_MACHINE_BY_ARCHITECTURE.get(architecture)
    if expected_machine is None:
        raise AttestationError("pinned Linux runtime architecture is unsupported")
    try:
        with open(path, "rb") as stream:
            payload = stream.read(PINNED_ARCHIVE_MEMBER_MAX_BYTES + 1)
    except OSError as exc:
        raise AttestationError("pinned Linux runtime ELF is unavailable") from exc
    if len(payload) > PINNED_ARCHIVE_MEMBER_MAX_BYTES:
        raise AttestationError("pinned runtime artifact exceeds its size bound")
    if (
        len(payload) < ELF_HEADER_64_SIZE
        or payload[:4] != b"\x7fELF"
        or payload[4] != 2
        or payload[5] != 1
        or payload[6] != 1
    ):
        raise AttestationError("pinned Linux runtime is not a supported ELF64 executable")
    try:
        (
            elf_type,
            machine,
            version,
            _entry,
            program_header_offset,
            _section_header_offset,
            _flags,
            elf_header_size,
            program_header_size,
            program_header_count,
            _section_header_size,
            _section_header_count,
            _section_name_index,
        ) = struct.unpack_from("<HHIQQQIHHHHHH", payload, 16)
    except struct.error as exc:
        raise AttestationError("pinned Linux runtime ELF header is invalid") from exc
    if (
        elf_type not in (2, 3)
        or machine != expected_machine
        or version != 1
        or elf_header_size != ELF_HEADER_64_SIZE
        or program_header_size != ELF_PROGRAM_HEADER_64_SIZE
        or program_header_count < 1
        or program_header_count > ELF_PROGRAM_HEADER_MAX_COUNT
        or program_header_offset < ELF_HEADER_64_SIZE
        or program_header_offset + program_header_size * program_header_count > len(payload)
    ):
        raise AttestationError("pinned Linux runtime ELF header is invalid")

    dynamic_segments = []
    for index in range(program_header_count):
        offset = program_header_offset + index * program_header_size
        try:
            (
                segment_type,
                _segment_flags,
                segment_offset,
                _virtual_address,
                _physical_address,
                segment_file_size,
                _segment_memory_size,
                _alignment,
            ) = struct.unpack_from("<IIQQQQQQ", payload, offset)
        except struct.error as exc:
            raise AttestationError("pinned Linux runtime program header is invalid") from exc
        if segment_offset + segment_file_size > len(payload):
            raise AttestationError("pinned Linux runtime segment is invalid")
        if segment_type == ELF_PT_INTERP:
            raise AttestationError("pinned Linux runtime requires a dynamic interpreter")
        if segment_type == ELF_PT_DYNAMIC:
            dynamic_segments.append((segment_offset, segment_file_size))

    for segment_offset, segment_file_size in dynamic_segments:
        if segment_file_size % 16 != 0:
            raise AttestationError("pinned Linux runtime dynamic table is invalid")
        terminated = False
        for offset in range(segment_offset, segment_offset + segment_file_size, 16):
            try:
                tag, _value = struct.unpack_from("<qQ", payload, offset)
            except struct.error as exc:
                raise AttestationError("pinned Linux runtime dynamic table is invalid") from exc
            if tag == ELF_DT_NEEDED:
                raise AttestationError("pinned Linux runtime has a dynamic dependency")
            if tag == ELF_DT_NULL:
                terminated = True
                break
        if not terminated:
            raise AttestationError("pinned Linux runtime dynamic table is invalid")


def _run_inspector(executable, system, runner=None):
    inspector = SYSTEM_UTILITIES.get(system)
    if inspector is None or not os.path.isfile(inspector) or not os.access(inspector, os.X_OK):
        raise AttestationError("safe dependency inspection is unavailable")
    if runner is None:
        runner = subprocess.run
    inspector_metadata = _regular_secure(os.path.realpath(inspector))
    if inspector_metadata.st_uid != 0:
        raise AttestationError("dependency inspector is not system-owned")
    if system == "Linux" and _regular_secure(executable).st_uid != 0:
        raise AttestationError("Linux dependency inspection requires a system-owned executable")
    args = [inspector, "-L", executable] if system == "Darwin" else [inspector, executable]
    try:
        result = runner(
            args,
            check=True,
            capture_output=True,
            text=True,
            env={"PATH": "/usr/bin:/bin", "LC_ALL": "C"},
        )
    except (OSError, subprocess.SubprocessError) as exc:
        raise AttestationError("dependency inspection failed") from exc
    return result.stdout


def _parse_dependencies(output, system):
    dependencies = []
    for line in output.splitlines():
        if system == "Darwin":
            match = re.match(r"\s*((?:/|@)[^ ]+)\s+\(", line)
            if match:
                dependencies.append(match.group(1))
            elif line.startswith("\t"):
                raise AttestationError("Darwin dependency output is invalid")
        else:
            stripped = line.strip()
            if not stripped or stripped.startswith("linux-vdso"):
                continue
            if "=> not found" in stripped:
                raise AttestationError("Linux dependency is unavailable")
            match = re.search(r"=>\s+(/\S+)\s+\(", stripped)
            if match is None:
                match = re.match(r"(/\S+)\s+\(", stripped)
            if match is None:
                raise AttestationError("Linux dependency output is invalid")
            dependencies.append(match.group(1))
    if system == "Darwin" and output and not dependencies:
        raise AttestationError("dependency output has no resolvable paths")
    return dependencies


def _darwin_rpaths(executable, runner=None):
    if runner is None:
        runner = subprocess.run
    try:
        result = runner(
            ["/usr/bin/otool", "-l", executable],
            check=True,
            capture_output=True,
            text=True,
            env={"PATH": "/usr/bin:/bin", "LC_ALL": "C"},
        )
    except (OSError, subprocess.SubprocessError) as exc:
        raise AttestationError("Darwin rpath inspection failed") from exc
    lines = result.stdout.splitlines()
    rpaths = []
    for index, line in enumerate(lines):
        if line.strip() != "cmd LC_RPATH":
            continue
        for candidate_line in lines[index + 1:index + 5]:
            match = re.match(r"\s*path\s+(\S+)\s+\(offset\s+\d+\)$", candidate_line)
            if match:
                rpaths.append(match.group(1))
                break
    return rpaths


def _expand_darwin_path(value, current, root_executable):
    substitutions = {
        "@loader_path": os.path.dirname(current),
        "@executable_path": os.path.dirname(root_executable),
    }
    for marker, directory in substitutions.items():
        if value == marker:
            return directory
        if value.startswith(marker + "/"):
            return os.path.normpath(os.path.join(directory, value[len(marker) + 1:]))
    if value.startswith("/"):
        return os.path.normpath(value)
    raise AttestationError("relative Darwin runtime path cannot be resolved safely")


def _resolve_darwin_dependency(dependency, current, root_executable):
    if dependency.startswith("/"):
        # Collapse traversal before dependency_closure classifies sealed paths.
        return os.path.normpath(dependency)
    if dependency.startswith("@loader_path") or dependency.startswith("@executable_path"):
        return _expand_darwin_path(dependency, current, root_executable)
    if not dependency.startswith("@rpath/"):
        raise AttestationError("relative Darwin dependency cannot be resolved safely")
    suffix = dependency[len("@rpath/"):]
    rpaths = _darwin_rpaths(current)
    if current != root_executable:
        rpaths.extend(_darwin_rpaths(root_executable))
    for rpath in rpaths:
        directory = _expand_darwin_path(rpath, current, root_executable)
        candidate = os.path.normpath(os.path.join(directory, suffix))
        if os.path.exists(candidate):
            return candidate
    raise AttestationError("Darwin rpath dependency is unavailable")


def dependency_closure(executable, system, inspector=None):
    """Return canonical files and OS-sealed dependencies without executing the target."""
    root_executable = _canonical_regular(executable)
    if system == "Linux":
        output = (
            inspector(root_executable)
            if inspector is not None
            else _run_inspector(root_executable, system)
        )
        dependencies = [
            _canonical_regular(path)
            for path in _parse_dependencies(output, system)
        ]
        return sorted(set([root_executable, *dependencies])), []
    pending = [root_executable]
    seen = set()
    sealed = set()
    while pending:
        current = pending.pop(0)
        if current in seen:
            continue
        seen.add(current)
        output = inspector(current) if inspector is not None else _run_inspector(current, system)
        for dependency in _parse_dependencies(output, system):
            if system == "Darwin":
                dependency = _resolve_darwin_dependency(dependency, current, root_executable)
            if system == "Darwin" and dependency.startswith(DARWIN_SEALED_PREFIXES):
                sealed.add(dependency)
                continue
            canonical = _canonical_regular(dependency)
            if canonical not in seen and canonical not in pending:
                pending.append(canonical)
    return sorted(seen), sorted(sealed)


def closure_attestation(logical, paths, sealed_dependencies=(), path_labels=None):
    records = []
    for path in sorted(paths):
        metadata = _regular_secure(path)
        digest = _sha256_file(path)
        if path_labels is None:
            records.append({
                "canonical": path,
                "sha256": digest,
                "uid": metadata.st_uid,
                "gid": metadata.st_gid,
                "mode": stat.S_IMODE(metadata.st_mode),
            })
        else:
            label = path_labels.get(path)
            if not isinstance(label, str) or not label:
                raise AttestationError("portable runtime closure path is unlabelled")
            records.append({
                "canonical": label,
                "sha256": digest,
                "mode": stat.S_IMODE(metadata.st_mode),
            })
    payload = {
        "logical": logical,
        "paths": records,
        "sealed_system_dependencies": sorted(sealed_dependencies),
    }
    encoded = json.dumps(payload, sort_keys=True, separators=(",", ":")).encode("utf-8")
    return hashlib.sha256(encoded).hexdigest()



__all__ = [
    "AttestationError", "HEX64", "PLATFORM", "SYSTEM_UTILITIES",
    "DARWIN_SEALED_PREFIXES", "PINNED_ARCHIVE_MAX_BYTES",
    "PINNED_ARCHIVE_MEMBER_MAX_BYTES", "CODEX_BINARY_MAX_BYTES",
    "ELF_MACHINE_BY_ARCHITECTURE", "ELF_HEADER_64_SIZE",
    "ELF_PROGRAM_HEADER_64_SIZE", "ELF_PROGRAM_HEADER_MAX_COUNT",
    "ELF_PT_DYNAMIC", "ELF_PT_INTERP", "ELF_DT_NULL", "ELF_DT_NEEDED",
    "_regular_secure", "_canonical_regular", "_private_materialization_root",
    "_download_pinned_archive", "_sha256_file",
    "_validated_pinned_archive_descriptor", "_materialize_pinned_archive",
    "_assert_linux_static_elf", "_run_inspector", "_parse_dependencies",
    "_darwin_rpaths", "_expand_darwin_path", "_resolve_darwin_dependency",
    "dependency_closure", "closure_attestation",
]
