#!/usr/bin/python3
"""Attest trusted PHP and Codex executables before their first execution.

Contract policy and command dispatch stay here; filesystem, archive, ELF, and
dependency-closure primitives live in the sibling library loaded explicitly
from this materialized exact-base directory.
"""

import argparse
import importlib.util
import json
import os
import stat
import sys

_LIBRARY_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "lib", "trusted_runtime_primitives.py")
_LIBRARY_SPEC = importlib.util.spec_from_file_location("_trusted_runtime_primitives", _LIBRARY_PATH)
if _LIBRARY_SPEC is None or _LIBRARY_SPEC.loader is None:
    raise RuntimeError("trusted runtime primitives are unavailable")
_RUNTIME_PRIMITIVES = importlib.util.module_from_spec(_LIBRARY_SPEC)
_LIBRARY_SPEC.loader.exec_module(_RUNTIME_PRIMITIVES)
for _name in _RUNTIME_PRIMITIVES.__all__:
    globals()[_name] = getattr(_RUNTIME_PRIMITIVES, _name)
del _name

def _load_contract(contract_path):
    if not os.path.isabs(contract_path):
        raise AttestationError("contract path must be absolute")
    _regular_secure(contract_path)
    try:
        with open(contract_path, "r", encoding="utf-8") as stream:
            return json.load(stream)
    except (OSError, ValueError, UnicodeError) as exc:
        raise AttestationError("contract is invalid") from exc


def attest(contract_path, requested_platform, inspector=None, materialize_root=None, downloader=None):
    if not isinstance(requested_platform, str) or not PLATFORM.fullmatch(requested_platform):
        raise AttestationError("invalid platform")
    contract = _load_contract(contract_path)
    try:
        policy = contract["authority"]["interpreter_trust"]["php"]
        candidates = policy["candidate_by_platform"]
        archives = policy["pinned_archive_by_platform"]
        require_exact_pin = policy["require_exact_closure_sha256"]
        pins = policy["closure_sha256_by_platform"]
    except (KeyError, TypeError, AttributeError) as exc:
        raise AttestationError("contract shape is invalid") from exc
    if not isinstance(candidates, dict) or not isinstance(archives, dict) or require_exact_pin is not True:
        raise AttestationError("contract shape is invalid")
    if not isinstance(pins, dict):
        raise AttestationError("contract pins are invalid")
    candidate_platforms = set(candidates)
    archive_platforms = set(archives)
    if (
        candidate_platforms & archive_platforms
        or candidate_platforms | archive_platforms != set(pins)
        or any(not isinstance(value, str) or not value.startswith("/") for value in candidates.values())
        or any(not isinstance(value, dict) for value in archives.values())
        or any(not isinstance(key, str) or PLATFORM.fullmatch(key) is None for key in set(pins))
        or any(not isinstance(value, str) or HEX64.fullmatch(value) is None for value in pins.values())
    ):
        raise AttestationError("contract runtime platform maps are invalid")
    for descriptor in archives.values():
        _validated_pinned_archive_descriptor(descriptor)
    pin = pins.get(requested_platform)
    if not isinstance(pin, str) or not HEX64.fullmatch(pin):
        raise AttestationError("exact platform closure pin is required")
    system = requested_platform.split("-", 1)[0]
    logical = candidates.get(requested_platform)
    archive = archives.get(requested_platform)
    if (logical is None) == (archive is None):
        raise AttestationError("exactly one platform runtime source is required")
    path_labels = None
    if archive is not None:
        if system not in ("Darwin", "Linux") or materialize_root is None:
            raise AttestationError("pinned runtime materialization is unavailable")
        materialized, logical, member = _materialize_pinned_archive(
            archive,
            materialize_root,
            downloader=downloader,
        )
        canonical = _canonical_regular(materialized)
        path_labels = {canonical: member}
    else:
        if not isinstance(logical, str) or not logical.startswith("/"):
            raise AttestationError("contract candidate is invalid")
        canonical = _canonical_regular(logical)
    if archive is not None and system == "Linux":
        architecture = requested_platform.split("-", 1)[1]
        _assert_linux_static_elf(canonical, architecture)
        paths, sealed_dependencies = [canonical], []
    else:
        paths, sealed_dependencies = dependency_closure(canonical, system, inspector=inspector)
    if path_labels is not None and paths != [canonical]:
        raise AttestationError("materialized runtime has a non-system dynamic dependency")
    if path_labels is None and any(os.lstat(path).st_uid != 0 for path in paths):
        raise AttestationError("fixed-path runtime closure is not system-owned")
    digest = closure_attestation(logical, paths, sealed_dependencies, path_labels=path_labels)
    if digest != pin:
        raise AttestationError("runtime closure digest mismatch")
    return canonical


def attest_codex(contract_path, requested_platform, executable, inspector=None, expected_closure_sha256=None):
    if not isinstance(requested_platform, str) or not PLATFORM.fullmatch(requested_platform):
        raise AttestationError("invalid platform")
    system = requested_platform.split("-", 1)[0]
    if system != "Darwin":
        raise AttestationError("Codex dependency attestation is unavailable on this platform")
    contract = _load_contract(contract_path)
    try:
        policy = contract["authority"]["reviewer"]
        binary_pins = policy["codex_binary_sha256_by_platform"]
        closure_pins = policy["codex_closure_sha256_by_platform"]
        dependency_policy = policy["codex_dynamic_dependency_policy"]
    except (KeyError, TypeError, AttributeError) as exc:
        raise AttestationError("contract shape is invalid") from exc
    if (
        not isinstance(binary_pins, dict)
        or not isinstance(closure_pins, dict)
        or set(binary_pins) != set(closure_pins)
        or dependency_policy != "system_sealed_only_non_system_dependency_rejected"
    ):
        raise AttestationError("Codex dependency policy is invalid")
    binary_pin = binary_pins.get(requested_platform)
    closure_pin = closure_pins.get(requested_platform)
    if (
        not isinstance(binary_pin, str)
        or HEX64.fullmatch(binary_pin) is None
        or not isinstance(closure_pin, str)
        or HEX64.fullmatch(closure_pin) is None
    ):
        raise AttestationError("exact Codex platform pins are required")
    if expected_closure_sha256 is not None and expected_closure_sha256 != closure_pin:
        raise AttestationError("Codex closure policy binding mismatch")

    canonical = _canonical_regular(executable)
    metadata = _regular_secure(canonical)
    if metadata.st_uid != os.geteuid() or stat.S_IMODE(metadata.st_mode) != 0o500:
        raise AttestationError("materialized Codex ownership is invalid")
    if _sha256_file(canonical, CODEX_BINARY_MAX_BYTES) != binary_pin:
        raise AttestationError("materialized Codex binary digest mismatch")
    paths, sealed_dependencies = dependency_closure(canonical, system, inspector=inspector)
    if paths != [canonical]:
        raise AttestationError("materialized Codex has a non-system dynamic dependency")
    digest = closure_attestation(
        "codex",
        paths,
        sealed_dependencies,
        path_labels={canonical: "codex"},
    )
    if digest != closure_pin:
        raise AttestationError("Codex dependency closure digest mismatch")
    return canonical


def main(argv=None):
    parser = argparse.ArgumentParser(add_help=False)
    parser.add_argument("--runtime", choices=("php", "codex"), default="php")
    parser.add_argument("--contract", required=True)
    parser.add_argument("--platform", required=True)
    parser.add_argument("--materialize-root")
    parser.add_argument("--path")
    parser.add_argument("--expected-closure-sha256")
    args = parser.parse_args(argv)
    try:
        if args.runtime == "codex":
            if (
                args.materialize_root is not None
                or args.path is None
                or args.expected_closure_sha256 is None
            ):
                raise AttestationError("Codex attestation arguments are invalid")
            print(
                attest_codex(
                    args.contract,
                    args.platform,
                    args.path,
                    expected_closure_sha256=args.expected_closure_sha256,
                )
            )
        else:
            if args.path is not None or args.expected_closure_sha256 is not None:
                raise AttestationError("PHP attestation arguments are invalid")
            print(attest(args.contract, args.platform, materialize_root=args.materialize_root))
        return 0
    except (AttestationError, OSError, ValueError) as exc:
        print("trusted %s runtime rejected: %s" % (args.runtime, exc), file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())
