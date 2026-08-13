#!/usr/bin/python3

from __future__ import annotations

import importlib.util
import json
import os
import sys
import tempfile
import unittest
from types import SimpleNamespace


HELPER = sys.argv[1]
spec = importlib.util.spec_from_file_location("rob458_cleanup", HELPER)
assert spec is not None and spec.loader is not None
module = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = module
spec.loader.exec_module(module)


def image_id(character: str) -> str:
    return "sha256:" + character * 64


def container_id(character: str) -> str:
    return character * 64


class FakeDocker:
    def __init__(self, images: dict[str, dict[str, object]], references: dict[str, str] | None = None):
        self.images = images
        self.references = references or {}
        self.commands: list[list[str]] = []
        self.mutate_after_delete = False

    def __call__(
        self,
        command: list[str],
        timeout: float,
        accepted: frozenset[int],
    ) -> module.CommandResult:
        del timeout, accepted
        args = command[1:]
        self.commands.append(args)
        if args[:2] == ["image", "ls"]:
            return self.result("\n".join(sorted(self.images)) + ("\n" if self.images else ""))
        if args[:2] == ["image", "inspect"]:
            ids = args[2:]
            if len(ids) == 1 and ids[0] not in self.images:
                return module.CommandResult(1, b"", b"not found")
            return self.result(json.dumps([self.images[value] for value in ids]))
        if args[:3] == ["container", "ls", "--all"]:
            return self.result("\n".join(sorted(self.references)) + ("\n" if self.references else ""))
        if args[:2] == ["container", "inspect"]:
            return self.result(json.dumps([{"Image": self.references[value]} for value in args[2:]]))
        if args[:2] == ["info", "--format"]:
            return self.result(json.dumps("/tmp"))
        if args[:2] == ["image", "rm"]:
            removed = args[2]
            del self.images[removed]
            if self.mutate_after_delete and len(self.images) == 1:
                remaining = next(iter(self.images.values()))
                remaining["Size"] = int(remaining["Size"]) + 1
            return self.result(removed)
        raise AssertionError(args)

    @staticmethod
    def result(stdout: str) -> module.CommandResult:
        return module.CommandResult(0, stdout.encode(), b"")


def record(identifier: str, project: str, service: str, size: int = 100) -> dict[str, object]:
    return {
        "Id": identifier,
        "Config": {"Labels": {module.PROJECT_LABEL: project, module.SERVICE_LABEL: service}},
        "RepoTags": [f"{project}-{service}:latest"],
        "RepoDigests": [],
        "Size": size,
    }


def engine(fake: FakeDocker) -> module.CleanupEngine:
    filesystem = SimpleNamespace(f_bavail=10_000, f_frsize=4096)
    return module.CleanupEngine(fake, lambda: None, lambda _path: filesystem)


class CleanupEngineTest(unittest.TestCase):
    def candidates(self) -> dict[str, dict[str, object]]:
        first = image_id("a")
        second = image_id("b")
        return {
            first: record(first, "zs-old-aa", "php-fpm", 125),
            second: record(second, "zs-old-bb", "pdf-renderer", 250),
        }

    def test_dry_run_is_aggregate_and_never_removes(self) -> None:
        fake = FakeDocker(self.candidates())
        report, exit_code = engine(fake).run("dry-run")
        self.assertEqual(0, exit_code)
        self.assertEqual("pass", report["status"])
        self.assertEqual(2, report["project_count"])
        self.assertEqual(2, report["candidate_count"])
        self.assertEqual(375, report["candidate_virtual_bytes"])
        self.assertFalse(report["mutation_performed"])
        self.assertFalse(any(command[:2] == ["image", "rm"] for command in fake.commands))
        encoded = json.dumps(report, separators=(",", ":"))
        self.assertNotIn("zs-old", encoded)
        self.assertNotIn("sha256", encoded)

    def test_execute_removes_only_full_ids_without_force_or_prune(self) -> None:
        fake = FakeDocker(self.candidates())
        report, exit_code = engine(fake).run("execute")
        self.assertEqual(0, exit_code)
        self.assertEqual("pass", report["status"])
        self.assertEqual(2, report["deleted_count"])
        self.assertTrue(report["mutation_performed"])
        self.assertEqual({}, fake.images)
        removals = [command for command in fake.commands if command[:2] == ["image", "rm"]]
        self.assertEqual([["image", "rm", image_id("a")], ["image", "rm", image_id("b")]], removals)
        flattened = " ".join(" ".join(command) for command in fake.commands)
        self.assertNotIn("--force", flattened)
        self.assertNotIn("prune", flattened)

    def test_foreign_compose_image_is_ignored(self) -> None:
        images = self.candidates()
        foreign = image_id("c")
        images[foreign] = record(foreign, "production", "php-fpm")
        fake = FakeDocker(images)
        report, exit_code = engine(fake).run("execute")
        self.assertEqual(0, exit_code)
        self.assertEqual("pass", report["status"])
        self.assertEqual({foreign}, set(fake.images))

    def test_reference_blocks_before_first_removal(self) -> None:
        images = self.candidates()
        fake = FakeDocker(images, {container_id("c"): image_id("a")})
        report, exit_code = engine(fake).run("execute")
        self.assertEqual(70, exit_code)
        self.assertEqual("blocked", report["status"])
        self.assertEqual("image_has_container_reference", report["reason"])
        self.assertEqual(2, len(fake.images))

    def test_ambiguous_tag_blocks_whole_preflight(self) -> None:
        images = self.candidates()
        images[image_id("b")]["RepoTags"] = ["unexpected:latest"]
        fake = FakeDocker(images)
        report, exit_code = engine(fake).run("execute")
        self.assertEqual(70, exit_code)
        self.assertEqual("unsafe_candidate", report["reason"])
        self.assertFalse(any(command[:2] == ["image", "rm"] for command in fake.commands))

    def test_malformed_zero_surprise_identity_is_not_treated_as_foreign(self) -> None:
        images = self.candidates()
        malformed = image_id("d")
        images[malformed] = record(malformed, "zs-unsafe!", "php-fpm")
        fake = FakeDocker(images)
        report, exit_code = engine(fake).run("execute")
        self.assertEqual(70, exit_code)
        self.assertEqual("unsafe_candidate", report["reason"])
        self.assertFalse(any(command[:2] == ["image", "rm"] for command in fake.commands))

    def test_candidate_cap_blocks_without_removal(self) -> None:
        images = {}
        for index in range(module.MAX_IMAGES + 1):
            identifier = "sha256:" + f"{index:064x}"
            images[identifier] = record(identifier, "zs-cap-aa", "php-fpm")
        fake = FakeDocker(images)
        report, exit_code = engine(fake).run("execute")
        self.assertEqual(75, exit_code)
        self.assertTrue(report["cap_exceeded"])
        self.assertEqual("candidate_cap_exceeded", report["reason"])
        self.assertFalse(any(command[:2] == ["image", "rm"] for command in fake.commands))

    def test_race_after_first_delete_stops_partial(self) -> None:
        fake = FakeDocker(self.candidates())
        fake.mutate_after_delete = True
        report, exit_code = engine(fake).run("execute")
        self.assertEqual(75, exit_code)
        self.assertEqual("partial", report["status"])
        self.assertEqual("image_snapshot_changed", report["reason"])
        self.assertEqual(1, report["deleted_count"])
        self.assertEqual(1, len(fake.images))

    def test_activity_scan_blocks_buildkit_commands(self) -> None:
        for command in (b"/usr/bin/docker\0buildx\0bake\0", b"/usr/bin/buildctl\0build\0"):
            with self.subTest(command=command), tempfile.TemporaryDirectory() as proc_root:
                process = os.path.join(proc_root, "424242")
                os.mkdir(process)
                with open(os.path.join(process, "cmdline"), "wb") as handle:
                    handle.write(command)
                with self.assertRaisesRegex(module.CleanupError, "active_production_work"):
                    module.assert_idle(proc_root)


if __name__ == "__main__":
    unittest.main(argv=[sys.argv[0]])
