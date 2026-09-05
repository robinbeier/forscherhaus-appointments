#!/usr/bin/env python3
"""Derive a safe content-addressed name for a local php-fpm build."""

import argparse
import hashlib
import json
import os
import stat
import sys
from pathlib import Path


SUPPORTED_BUILD_KEYS = {"context", "dockerfile", "args", "target"}


def _unsupported_tree(path: Path) -> bool:
    for root, directories, files in os.walk(path, followlinks=False):
        names = [Path(root, name) for name in directories + files]
        if any(item.is_symlink() or not (item.is_file() or item.is_dir()) for item in names):
            return True
    return False


def _add_file(digest: "hashlib._Hash", label: str, path: Path) -> None:
    mode = stat.S_IMODE(path.stat().st_mode)
    digest.update(b"file\0")
    digest.update(label.encode())
    digest.update(b"\0")
    digest.update(str(mode).encode())
    digest.update(b"\0")
    content = path.read_bytes()
    digest.update(len(content).to_bytes(8, "big"))
    digest.update(content)


def _key(config: dict, platform: str) -> str:
    if not isinstance(config, dict):
        return ""
    services = config.get("services")
    if not isinstance(services, dict):
        return ""
    service = services.get("php-fpm")
    if not isinstance(service, dict):
        return ""
    if service.get("image") is not None:
        return ""
    platform = service.get("platform") or platform
    if not isinstance(platform, str) or not platform:
        return ""
    build = service.get("build")
    if not isinstance(build, dict) or set(build) - SUPPORTED_BUILD_KEYS:
        return ""
    context_value = build.get("context")
    if not isinstance(context_value, str) or not context_value:
        return ""
    context = Path(context_value).resolve()
    if not context.is_dir() or _unsupported_tree(context):
        return ""

    dockerfile_value = build.get("dockerfile", "Dockerfile")
    if not isinstance(dockerfile_value, str) or not dockerfile_value:
        return ""
    dockerfile = (context / dockerfile_value).resolve()
    # An external Dockerfile would make a worktree-independent name unsafe.
    try:
        dockerfile.relative_to(context)
    except ValueError:
        return ""
    if not dockerfile.is_file() or dockerfile.is_symlink():
        return ""

    args = build.get("args", {})
    if args is None or not isinstance(args, dict) or any(value is None for value in args.values()):
        return ""
    if not all(isinstance(name, str) and isinstance(value, (str, int, float, bool)) for name, value in args.items()):
        return ""

    digest = hashlib.sha256()
    digest.update(b"forscherhaus-local-php-fpm-v1\0")
    digest.update(b"platform\0" + platform.encode() + b"\0")
    digest.update(b"target\0" + str(build.get("target", "")).encode() + b"\0")
    digest.update(b"dockerfile\0")
    digest.update(dockerfile.relative_to(context).as_posix().encode() + b"\0")
    for name in sorted(args):
        digest.update(b"arg\0" + name.encode() + b"\0" + str(args[name]).encode() + b"\0")
    for path in sorted(context.rglob("*")):
        if path.is_dir():
            digest.update(b"directory\0" + path.relative_to(context).as_posix().encode() + b"\0")
            digest.update(str(stat.S_IMODE(path.stat().st_mode)).encode() + b"\0")
            continue
        _add_file(digest, path.relative_to(context).as_posix(), path)
    return "forscherhaus-local/php-fpm:" + digest.hexdigest()


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--platform", default="")
    arguments = parser.parse_args()
    try:
        config = json.load(sys.stdin)
        result = _key(config, arguments.platform)
    except (OSError, UnicodeError, ValueError, TypeError):
        result = ""
    print(result)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
