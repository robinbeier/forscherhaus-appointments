#!/usr/bin/python3
"""Synthetic regression coverage for the retention service mount boundary."""

from __future__ import annotations

import copy
import importlib.machinery
import importlib.util
import os
import pathlib
import tempfile
import unittest
from unittest import mock


HELPER_PATH = pathlib.Path(__file__).parents[3] / "scripts" / "ops" / "libexec" / "release_archive_dump_retention_v1.py"
loader = importlib.machinery.SourceFileLoader("rob493_retention_helper", str(HELPER_PATH))
spec = importlib.util.spec_from_loader(loader.name, loader)
assert spec is not None
helper = importlib.util.module_from_spec(spec)
loader.exec_module(helper)


LOCK_POINT = "/var/lib/fh-deploy-orchestrator/locks/fh-production-change.lock"
PARENT_POINT = "/"
LOCK_DEVICE = os.makedev(8, 1)
SERVICE_CGROUP = "0::/system.slice/fh-release-archive-dump-retention.service\n"
INVOCATION_ID = "0123456789abcdef0123456789abcdef"


def mount_line(
    mount_id: int,
    parent_id: int,
    root: str,
    mount_point: str,
    options: str,
    *,
    device: str = "8:1",
    filesystem: str = "ext4",
    source: str = "/dev/vda1",
    super_options: str = "rw",
    optional: str = ""
) -> str:
    # mountinfo escapes spaces, tabs and backslashes in the root/point fields.
    optional_fields = f" {optional}" if optional else ""
    return f"{mount_id} {parent_id} {device} {root} {mount_point} {options}{optional_fields} - {filesystem} {source} {super_options}"


def trusted_lines() -> list[str]:
    return [
        mount_line(41, 30, "/", PARENT_POINT, "ro,relatime"),
        mount_line(42, 41, LOCK_POINT, LOCK_POINT, "rw,relatime"),
    ]


def trusted_records() -> list[dict[str, object]]:
    return helper.parse_mountinfo(trusted_lines())


class RetentionMountInfoTest(unittest.TestCase):
    def parameters(self, **changes: object) -> dict[str, object]:
        parameters: dict[str, object] = {
            "web_names": ["easyappointments_20260830"],
            "lock_device": LOCK_DEVICE,
            "cgroup_text": SERVICE_CGROUP,
            "invocation_id": INVOCATION_ID,
            "self_mount_namespace": "mnt:[4026531841]",
            "pid1_mount_namespace": "mnt:[4026531840]",
        }
        parameters.update(changes)
        return parameters

    def assert_reason(self, reason: str, records: list[dict[str, object]], **kwargs: object) -> None:
        with self.assertRaises(helper.RetentionError) as caught:
            helper.validate_nested_mount_records(records=records, **self.parameters(**kwargs))
        self.assertEqual(caught.exception.reason, reason)

    def validate(self, records: list[dict[str, object]] | None = None, **kwargs: object) -> None:
        helper.validate_nested_mount_records(records=records or trusted_records(), **self.parameters(**kwargs))

    @staticmethod
    def proc_snapshot(*, changed: bool = False) -> dict[str, object]:
        return {
            "cgroup_after": SERVICE_CGROUP,
            "cgroup_before": SERVICE_CGROUP,
            "lock_device": LOCK_DEVICE,
            "mountinfo_after": "after" if changed else "stable",
            "mountinfo_before": "before" if changed else "stable",
            "pid1_namespace_after": "mnt:[4026531840]",
            "pid1_namespace_before": "mnt:[4026531840]",
            "self_namespace_after": "mnt:[4026531841]",
            "self_namespace_before": "mnt:[4026531841]",
        }

    def test_direct_context_without_protected_nested_mount_passes(self) -> None:
        helper.validate_nested_mount_records(
            helper.parse_mountinfo([mount_line(41, 30, "/", "/", "rw,relatime", super_options="rw")]),
            ["easyappointments_20260830"], LOCK_DEVICE, "", "", "mnt:[4026531840]", "mnt:[4026531840]",
        )

    def test_exact_service_context_requires_lock_boundary(self) -> None:
        records = helper.parse_mountinfo([mount_line(41, 30, "/", "/", "rw,relatime", super_options="rw")])
        self.assert_reason("nested_mount_boundary", records)

    def test_exact_lock_bind_passes(self) -> None:
        self.validate()

    def test_proc_snapshot_retries_a_changed_pair_but_accepts_only_a_stable_pair(self) -> None:
        with mock.patch.object(
            helper,
            "capture_proc_mount_snapshot",
            side_effect=[self.proc_snapshot(changed=True), self.proc_snapshot()],
        ) as capture:
            self.assertEqual(helper.stable_proc_mount_snapshot(object()), self.proc_snapshot())
        self.assertEqual(capture.call_count, 2)

    def test_proc_snapshot_rejects_when_every_bounded_pair_changes(self) -> None:
        with mock.patch.object(
            helper,
            "capture_proc_mount_snapshot",
            side_effect=[self.proc_snapshot(changed=True)] * helper.PROC_SNAPSHOT_ATTEMPTS,
        ) as capture, self.assertRaises(helper.RetentionError) as caught:
            helper.stable_proc_mount_snapshot(object())
        self.assertEqual(caught.exception.reason, "mount_state_unknown")
        self.assertEqual(capture.call_count, helper.PROC_SNAPSHOT_ATTEMPTS)

    def test_proc_snapshot_retries_and_rejects_cgroup_or_namespace_drift(self) -> None:
        for name, field, changed_value in (
            ("cgroup", "cgroup_after", "/system.slice/other.service\n"),
            ("self namespace", "self_namespace_after", "mnt:[4026531842]"),
            ("pid1 namespace", "pid1_namespace_after", "mnt:[4026531842]"),
        ):
            changed = self.proc_snapshot()
            changed[field] = changed_value
            with self.subTest(name=name, outcome="retry"), mock.patch.object(
                helper,
                "capture_proc_mount_snapshot",
                side_effect=[changed, self.proc_snapshot()],
            ) as capture:
                self.assertEqual(helper.stable_proc_mount_snapshot(object()), self.proc_snapshot())
                self.assertEqual(capture.call_count, 2)

            with self.subTest(name=name, outcome="reject"), mock.patch.object(
                helper,
                "capture_proc_mount_snapshot",
                side_effect=[changed] * helper.PROC_SNAPSHOT_ATTEMPTS,
            ) as capture, self.assertRaises(helper.RetentionError) as caught:
                helper.stable_proc_mount_snapshot(object())
            self.assertEqual(caught.exception.reason, "mount_state_unknown")
            self.assertEqual(capture.call_count, helper.PROC_SNAPSHOT_ATTEMPTS)

    def test_proc_snapshot_rejects_malformed_capture_state(self) -> None:
        with mock.patch.object(helper, "capture_proc_mount_snapshot", return_value={}):
            with self.assertRaises(helper.RetentionError) as caught:
                helper.stable_proc_mount_snapshot(object())
        self.assertEqual(caught.exception.reason, "mount_state_unknown")

    def test_exact_lock_bind_requires_expected_context(self) -> None:
        cases = {
            "missing cgroup": {"cgroup_text": ""},
            "wrong cgroup": {"cgroup_text": "/system.slice/other.service\n"},
            "missing invocation": {"invocation_id": ""},
            "invalid invocation": {"invocation_id": "G" * 32},
            "same namespace": {"self_mount_namespace": "mnt:[4026531840]"},
            "invalid namespace": {"pid1_mount_namespace": ""},
        }
        for name, changes in cases.items():
            with self.subTest(name=name):
                self.assert_reason("nested_mount_boundary", trusted_records(), **changes)

    def test_rejects_duplicate_or_extra_or_foreign_nested_mounts(self) -> None:
        records = trusted_records()
        for name, extra in {
            "duplicate exact lock": {**records[1], "mount_id": 43},
            "extra release mount": helper.parse_mountinfo([mount_line(43, 41, "/", "/var/www/html/easyappointments_20260830", "rw", super_options="rw")])[0],
            "foreign orchestrator mount": helper.parse_mountinfo([mount_line(43, 41, "/other", "/var/lib/fh-deploy-orchestrator/other", "rw")])[0],
        }.items():
            with self.subTest(name=name):
                candidate = records + [copy.deepcopy(extra)]
                self.assert_reason("nested_mount_boundary", candidate)

    def test_rejects_lock_boundary_metadata_drift(self) -> None:
        fields = {
            "mount_point": "/var/lib/fh-deploy-orchestrator/locks/other.lock",
            "root": "/locks/other.lock",
            "parent_id": 30,
            "major_minor": "9:9",
            "filesystem_type": "tmpfs",
            "mount_source": "/dev/vdb1",
            "super_options": "ro",
            "mount_options": "ro",
        }
        for name, value in fields.items():
            with self.subTest(name=name):
                candidate = trusted_records()
                candidate[1][name] = value
                self.assert_reason("nested_mount_boundary", candidate)

    def test_rejects_wrong_parent_or_computed_root(self) -> None:
        for name, root, parent in (("wrong parent", LOCK_POINT, 30), ("wrong root", "/", 41)):
            with self.subTest(name=name):
                candidate = trusted_records()
                candidate[1]["root"] = root
                candidate[1]["parent_id"] = parent
                self.assert_reason("nested_mount_boundary", candidate)

    def test_rejects_extra_mount_at_protected_orchestrator_root(self) -> None:
        candidate = trusted_records()
        candidate.insert(
            1,
            helper.parse_mountinfo([
                mount_line(
                    43,
                    41,
                    "/var/lib/fh-deploy-orchestrator",
                    "/var/lib/fh-deploy-orchestrator",
                    "ro,relatime",
                )
            ])[0],
        )
        candidate[2]["parent_id"] = 43
        self.assert_reason("nested_mount_boundary", candidate)

    def test_rejects_read_only_child_or_writable_parent(self) -> None:
        for name, index, options in (
            ("child ro", 1, frozenset({"ro", "relatime"})),
            ("parent rw", 0, frozenset({"rw", "relatime"})),
        ):
            with self.subTest(name=name):
                candidate = trusted_records()
                candidate[index]["mount_options"] = options
                self.assert_reason("nested_mount_boundary", candidate)

    def test_rejects_lock_device_mismatch(self) -> None:
        candidate = trusted_records()
        candidate[1]["major_minor"] = "8:2"
        self.assert_reason("nested_mount_boundary", candidate)

    def test_release_protection_and_unrelated_mount_non_regression(self) -> None:
        records = helper.parse_mountinfo([
            mount_line(50, 30, "/", "/var/www/html/easyappointments_20260830", "rw", device="8:1"),
            mount_line(51, 30, "/", "/mnt/unrelated", "rw", device="8:2"),
        ])
        self.assert_reason("nested_mount_boundary", records)
        self.validate([records[1]], cgroup_text="")

    def test_parser_decodes_escaped_fields(self) -> None:
        records = helper.parse_mountinfo([mount_line(41, 30, "/mnt/my\\040root", "/mnt/my\\040root\\011tab\\134slash", "ro")])
        self.assertEqual(records[0]["root"], "/mnt/my root")
        self.assertEqual(records[0]["mount_point"], "/mnt/my root\ttab\\slash")

    def test_parser_accepts_only_kernel_network_namespace_roots_without_a_slash(self) -> None:
        record = helper.parse_mountinfo([
            mount_line(
                41,
                30,
                "net:[4026531841]",
                "/run/docker/netns/0123456789ab",
                "rw,nosuid,nodev,noexec,relatime",
                filesystem="nsfs",
                source="nsfs",
            )
        ])[0]
        self.assertEqual(record["root"], "net:[4026531841]")
        self.assertEqual(record["filesystem_type"], "nsfs")

        for name, root, filesystem, source in (
            ("wrong filesystem", "net:[4026531841]", "tmpfs", "nsfs"),
            ("wrong source", "net:[4026531841]", "nsfs", "none"),
            ("zero inode", "net:[0]", "nsfs", "nsfs"),
            ("wrong namespace", "mnt:[4026531841]", "nsfs", "nsfs"),
            ("malformed handle", "net:4026531841", "nsfs", "nsfs"),
        ):
            with self.subTest(name=name), self.assertRaises(helper.RetentionError) as caught:
                helper.parse_mountinfo([
                    mount_line(
                        41,
                        30,
                        root,
                        "/run/docker/netns/0123456789ab",
                        "rw,nosuid,nodev,noexec,relatime",
                        filesystem=filesystem,
                        source=source,
                    )
                ])
            self.assertEqual(caught.exception.reason, "mount_state_unknown")

    def test_parser_accepts_kernel_mountinfo_lines_larger_than_legacy_ci_bound(self) -> None:
        long_super_options = "rw,lowerdir=" + ":".join(
            f"/var/lib/runner/snapshots/{index:04d}/fs" for index in range(512)
        )
        line = mount_line(
            41,
            30,
            "/",
            "/",
            "rw,relatime",
            filesystem="overlay",
            source="overlay",
            super_options=long_super_options,
        )
        self.assertGreater(len(line.encode("utf-8")), 16_384)
        records = helper.parse_mountinfo([line])
        self.assertEqual(records[0]["mount_point"], "/")
        self.assertEqual(records[0]["filesystem_type"], "overlay")

    def test_mountinfo_snapshot_has_a_separate_bounded_capacity(self) -> None:
        value = "x" * (helper.PROC_STATE_MAX_BYTES + 1)
        with tempfile.NamedTemporaryFile(mode="w", encoding="utf-8") as handle:
            handle.write(value)
            handle.flush()
            self.assertEqual(
                helper.read_proc_text(handle.name, helper.MOUNTINFO_MAX_BYTES),
                value,
            )
            with self.assertRaises(helper.RetentionError) as caught:
                helper.read_proc_text(handle.name)
        self.assertEqual(caught.exception.reason, "mount_state_unknown")

    def test_parser_rejects_malformed_state_fail_closed(self) -> None:
        for name, line in {
            "missing separator": "41 30 8:1 / / ro,relatime",
            "bad numeric id": "x 30 8:1 / / ro - ext4 /dev/vda1 rw",
            "bad separator": "41 30 8:1 / / ro optional ext4 /dev/vda1 rw",
            "malformed escape": mount_line(41, 30, "/mnt/bad\\12", "/", "ro"),
            "embedded NUL": mount_line(41, 30, "/mnt/bad\x00root", "/", "ro"),
        }.items():
            with self.subTest(name=name), self.assertRaises(helper.RetentionError) as caught:
                helper.parse_mountinfo([line])
            self.assertEqual(caught.exception.reason, "mount_state_unknown")

    def test_parser_rejects_duplicate_mount_ids_fail_closed(self) -> None:
        with self.assertRaises(helper.RetentionError) as caught:
            helper.parse_mountinfo(trusted_lines() + [mount_line(41, 31, "/", "/mnt/duplicate", "rw")])
        self.assertEqual(caught.exception.reason, "mount_state_unknown")


if __name__ == "__main__":
    unittest.main()
