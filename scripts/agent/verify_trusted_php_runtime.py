#!/usr/bin/python3
"""Attest a PHP interpreter and its dynamic dependency closure.

This module is deliberately runnable by the system Python bootstrap. It never
launches user-owned PHP: Darwin uses non-executing ``otool`` inspection, while
Linux permits ``ldd`` only after the candidate is proved root-owned.
"""

import argparse
import hashlib
import json
import os
import re
import stat
import subprocess
import sys


HEX64 = re.compile(r"^[0-9a-f]{64}$")
PLATFORM = re.compile(r"^[A-Za-z0-9_.-]+-[A-Za-z0-9_.-]+$")
SYSTEM_UTILITIES = {"Darwin": "/usr/bin/otool", "Linux": "/usr/bin/ldd"}
DARWIN_SEALED_PREFIXES = ("/usr/lib/", "/System/Library/")


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
        return dependency
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
    """Return canonical files and OS-sealed dependencies without executing PHP."""
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


def closure_attestation(logical, paths, sealed_dependencies=()):
    records = []
    for path in sorted(paths):
        metadata = _regular_secure(path)
        with open(path, "rb") as stream:
            digest = hashlib.sha256(stream.read()).hexdigest()
        records.append({
            "canonical": path,
            "sha256": digest,
            "uid": metadata.st_uid,
            "gid": metadata.st_gid,
            "mode": stat.S_IMODE(metadata.st_mode),
        })
    payload = {
        "logical": logical,
        "paths": records,
        "sealed_system_dependencies": sorted(sealed_dependencies),
    }
    encoded = json.dumps(payload, sort_keys=True, separators=(",", ":")).encode("utf-8")
    return hashlib.sha256(encoded).hexdigest()


def attest(contract_path, requested_platform, inspector=None):
    if not isinstance(requested_platform, str) or not PLATFORM.fullmatch(requested_platform):
        raise AttestationError("invalid platform")
    if not os.path.isabs(contract_path):
        raise AttestationError("contract path must be absolute")
    _regular_secure(contract_path)
    try:
        with open(contract_path, "r", encoding="utf-8") as stream:
            contract = json.load(stream)
    except (OSError, ValueError, UnicodeError) as exc:
        raise AttestationError("contract is invalid") from exc
    try:
        policy = contract["authority"]["interpreter_trust"]["php"]
        logical = policy["candidate_by_platform"][requested_platform]
        require_exact_pin = policy["require_exact_closure_sha256"]
        pins = policy["closure_sha256_by_platform"]
    except (KeyError, TypeError, AttributeError) as exc:
        raise AttestationError("contract shape is invalid") from exc
    if not isinstance(logical, str) or require_exact_pin is not True:
        raise AttestationError("contract shape is invalid")
    if not logical.startswith("/"):
        raise AttestationError("contract candidate is invalid")
    if not isinstance(pins, dict):
        raise AttestationError("contract pins are invalid")
    pin = pins.get(requested_platform)
    if not isinstance(pin, str) or not HEX64.fullmatch(pin):
        raise AttestationError("exact platform closure pin is required")
    system = requested_platform.split("-", 1)[0]
    canonical = _canonical_regular(logical)
    paths, sealed_dependencies = dependency_closure(canonical, system, inspector=inspector)
    if system == "Linux" and any(os.lstat(path).st_uid != 0 for path in paths):
        raise AttestationError("Linux runtime closure is not system-owned")
    digest = closure_attestation(logical, paths, sealed_dependencies)
    if digest != pin:
        raise AttestationError("runtime closure digest mismatch")
    return canonical


def main(argv=None):
    parser = argparse.ArgumentParser(add_help=False)
    parser.add_argument("--contract", required=True)
    parser.add_argument("--platform", required=True)
    args = parser.parse_args(argv)
    try:
        print(attest(args.contract, args.platform))
        return 0
    except (AttestationError, OSError, ValueError) as exc:
        print("trusted PHP runtime rejected: %s" % exc, file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())
