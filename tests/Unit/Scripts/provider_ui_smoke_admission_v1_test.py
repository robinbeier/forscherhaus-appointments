#!/usr/bin/env python3
"""Behavior tests for the provider UI smoke admission coordinator.

These tests exercise the coordinator's proof decisions with synthetic
systemctl, PHP verification, and maintenance-core doubles. They do not touch
production or require the installed root-only core.
"""
from __future__ import annotations

import contextlib
import fcntl
import importlib.util
import io
import json
import os
import stat
import shutil
import subprocess
import sys
import tempfile
import types
import unittest
from pathlib import Path
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[3]
MODULE_PATH = ROOT / "scripts/ops/libexec/provider_ui_smoke_admission_v1.py"
SPEC = importlib.util.spec_from_file_location("provider_ui_smoke_admission_v1", MODULE_PATH)
assert SPEC and SPEC.loader
COORD = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(COORD)


class FakeAdmission:
    def __init__(self, record):
        self.record = record
        self.admitted = False
        self.proof_calls = 0

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        return False

    def admit(self):
        self.admitted = True

    def publish_pending(self, record):
        self.published = record

    def recover_pending(self, record, proof):
        self.proof_calls += 1
        self.asserted = proof(record)
        self.asserted_again = proof(record)
        if not self.asserted or not self.asserted_again:
            return {"status": "unknown"}
        return {"status": "recovered"}


class FakeCore:
    SCHEMA = "maintenance_pending_v1"
    PROTOCOL_EPOCH = 1
    PendingError = type("PendingError", (Exception,), {})

    def __init__(self, record):
        self.record = record
        self.recovery = FakeAdmission(record)

    def current_boot_id(self):
        return "boot-test"

    def make_record(self, operation, run_id, boot_id, resource):
        return {
            "schema": self.SCHEMA, "epoch": self.PROTOCOL_EPOCH,
            "operation": operation, "run_id": run_id,
            "boot_id": boot_id, "resource_identity": resource,
        }

    def read_pending(self, boot_id):
        if getattr(self, 'no_pending', False):
            return None
        return [self.record]

    def maintenance_recovery(self):
        return self.recovery

    def maintenance_admission(self):
        return self.recovery

    def maintenance_recovery_from_lock_fd(self, lock_fd):
        if lock_fd != 3:
            raise self.PendingError("shared_lock_fd_not_held")
        return self.recovery


class ProviderUiSmokeAdmissionBehaviorTest(unittest.TestCase):
    def resource(self, operation="smoke"):
        return {
            "app_root": "/srv/app",
            "credentials_file": "/etc/fh/provider.env",
            "state_file": "/var/lib/fh-provider/active.json",
            "cleanup_unit": "fh-provider-ui-smoke-cleanup",
            "lifecycle": operation,
        }

    def args(self, action, operation="smoke", run_id="a" * 32):
        return types.SimpleNamespace(
            action=action,
            operation=operation,
            run_id=run_id,
            app_root="/srv/app",
            credentials_file="/etc/fh/provider.env",
            state_file="/var/lib/fh-provider/active.json",
            cleanup_unit="fh-provider-ui-smoke-cleanup",
            lock_fd=3 if action == "assert-bound-fd" else None,
            lifecycle_action="deactivate" if action == "assert-bound-fd" else None,
        )

    def test_unit_terminal_rejects_unknown_unit_identity(self):
        result = types.SimpleNamespace(returncode=0, stdout="Id=other.service\nLoadState=loaded\n", stderr="")
        with patch.object(COORD.subprocess, "run", return_value=result):
            with self.assertRaises(SystemExit) as raised:
                COORD.unit_terminal("fh-provider-ui-smoke-cleanup.service", self.resource(), "a" * 32)
        self.assertEqual(raised.exception.code, 75)

    def test_unit_terminal_rejects_live_cleanup_service(self):
        unit = "fh-provider-ui-smoke-cleanup.service"
        resource = self.resource()
        command = COORD._expected_cleanup_argv(resource, "a" * 32)
        result = types.SimpleNamespace(
            returncode=0,
            stdout=(
                f"Id={unit}\nLoadState=loaded\nTransient=yes\n"
                "ActiveState=active\nSubState=running\n"
                f"ExecStart={{ path=/bin/bash ; argv[]={command} ; ignore_errors=no }}\n"
            ),
            stderr="",
        )
        with patch.object(COORD.subprocess, "run", return_value=result):
            with self.assertRaises(SystemExit) as raised:
                COORD.unit_terminal(unit, resource, "a" * 32)
        self.assertEqual(raised.exception.code, 75)

    def test_unit_armed_rejects_elapsed_one_shot_timer(self):
        unit = "fh-provider-ui-smoke-cleanup.timer"
        result = types.SimpleNamespace(
            returncode=0,
            stdout=(f"Id={unit}\nLoadState=loaded\nTransient=yes\n"
                    "ActiveState=active\nSubState=elapsed\n"
                    "Triggers=fh-provider-ui-smoke-cleanup.service\n"),
            stderr="",
        )
        with patch.object(COORD.subprocess, "run", return_value=result):
            with self.assertRaises(SystemExit) as raised:
                COORD.unit_armed(unit, self.resource(), "a" * 32)
        self.assertEqual(raised.exception.code, 75)

    def test_cleanup_service_rejects_wrong_command_even_with_correct_run_id(self):
        unit = "fh-provider-ui-smoke-cleanup.service"
        resource = self.resource()
        command = COORD._expected_cleanup_argv(resource, "a" * 32)
        wrong_command = command.replace("--credentials-file /etc/fh/provider.env",
                                        "--credentials-file /etc/fh/other.env")
        result = types.SimpleNamespace(
            returncode=0,
            stdout=(f"Id={unit}\nLoadState=loaded\nTransient=yes\n"
                    "ActiveState=inactive\nSubState=dead\n"
                    f"ExecStart={{ path=/bin/bash ; argv[]={wrong_command} ; ignore_errors=no }}\n"),
            stderr="",
        )
        with patch.object(COORD.subprocess, "run", return_value=result):
            with self.assertRaises(SystemExit) as raised:
                COORD.unit_terminal(unit, resource, "a" * 32)
        self.assertEqual(raised.exception.code, 75)

    def test_cleanup_service_accepts_only_exact_bound_command(self):
        unit = "fh-provider-ui-smoke-cleanup.service"
        resource = self.resource()
        command = COORD._expected_cleanup_argv(resource, "a" * 32)
        result = types.SimpleNamespace(
            returncode=0,
            stdout=(f"Id={unit}\nLoadState=loaded\nTransient=yes\n"
                    "ActiveState=inactive\nSubState=dead\n"
                    f"ExecStart={{ path=/bin/bash ; argv[]={command} ; ignore_errors=no }}\n"),
            stderr="",
        )
        with patch.object(COORD.subprocess, "run", return_value=result):
            self.assertTrue(COORD.unit_terminal(unit, resource, "a" * 32))

    def test_dormant_clean_accepts_removed_state_only_for_remove(self):
        with tempfile.TemporaryDirectory() as directory:
            app = Path(directory)
            (app / "index.php").write_text("<?php\n")
            state = app / "active.json"
            resource = {
                "app_root": str(app),
                "credentials_file": str(app / "credentials.env"),
                "state_file": str(state),
                "cleanup_unit": "cleanup",
                "lifecycle": "remove",
            }
            result = types.SimpleNamespace(returncode=0, stdout="provider_ui_smoke action=verify state=removed result=ok\n", stderr="")
            with patch.object(COORD.subprocess, "run", return_value=result):
                self.assertTrue(COORD.dormant_clean(resource, "removed"))
                with self.assertRaises(SystemExit):
                    COORD.dormant_clean(resource, "dormant")

    def test_settle_repeats_terminal_proof_while_recovery_lock_is_held(self):
        resource = self.resource("smoke")
        record = {
            "schema": "maintenance_pending_v1",
            "epoch": 1,
            "operation": "provider_ui_smoke",
            "run_id": "a" * 32,
            "boot_id": "boot-test",
            "resource_identity": resource,
        }
        core = FakeCore(record)
        args = self.args("settle")
        with tempfile.TemporaryDirectory() as directory:
            lock = Path(directory) / "fh-provider-ui-smoke.lock"
            lock.touch(mode=0o600)
            with patch.object(COORD, "PRIVATE_CLEANUP_LOCK_PATH", str(lock)), \
                 patch.object(COORD, "_private_lock_identity", return_value=(1, 2, 3, 0, 0, 1, 0)), \
                 patch.object(COORD, "load_core", return_value=core), \
                 patch.object(COORD, "_read_arm_ack", return_value=COORD._ack_record(record)), \
                 patch.object(COORD, "_remove_arm_ack") as remove_ack, \
                 patch.object(COORD, "unit_terminal", return_value=True), \
                 patch.object(COORD, "dormant_clean", return_value=True), \
                 contextlib.redirect_stdout(io.StringIO()):
                COORD.settle(args)
        self.assertEqual(core.recovery.proof_calls, 1)
        self.assertTrue(core.recovery.asserted)
        self.assertTrue(core.recovery.asserted_again)
        remove_ack.assert_called_once_with(resource)

    def test_settle_refuses_missing_arm_ack_even_when_units_are_not_found(self):
        resource = self.resource("smoke")
        record = {
            "schema": "maintenance_pending_v1", "epoch": 1,
            "operation": "provider_ui_smoke", "run_id": "a" * 32,
            "boot_id": "boot-test", "resource_identity": resource,
        }
        core = FakeCore(record)
        with patch.object(COORD, "private_cleanup_lock", lambda: contextlib.nullcontext()), \
             patch.object(COORD, "load_core", return_value=core), \
             patch.object(COORD, "_read_arm_ack", return_value=None), \
             patch.object(COORD, "unit_terminal", return_value=True), \
             patch.object(COORD, "dormant_clean", return_value=True):
            with self.assertRaises(SystemExit) as raised:
                COORD.settle(self.args("settle"))
        self.assertEqual(raised.exception.code, 75)
        self.assertEqual(core.recovery.proof_calls, 0)

    def test_settle_accepts_exact_old_boot_record_with_matching_ack(self):
        resource = self.resource("smoke")
        record = {
            "schema": "maintenance_pending_v1", "epoch": 1,
            "operation": "provider_ui_smoke", "run_id": "a" * 32,
            "boot_id": "old-boot", "resource_identity": resource,
        }
        core = FakeCore(record)
        read_boots = []
        core.read_pending = lambda boot_id: (
            read_boots.append(boot_id) or [record] if boot_id is None else None
        )
        with patch.object(COORD, "private_cleanup_lock", lambda: contextlib.nullcontext()), \
             patch.object(COORD, "load_core", return_value=core), \
             patch.object(COORD, "_read_arm_ack", return_value=COORD._ack_record(record)), \
             patch.object(COORD, "_remove_arm_ack"), \
             patch.object(COORD, "unit_terminal", return_value=True), \
             patch.object(COORD, "dormant_clean", return_value=True), \
             contextlib.redirect_stdout(io.StringIO()):
            COORD.settle(self.args("settle"))
        self.assertIn(None, read_boots)
        self.assertEqual(core.recovery.proof_calls, 1)

    def test_recover_unarmed_requires_terminal_and_dormant_proof(self):
        resource = self.resource("smoke")
        record = {
            "schema": "maintenance_pending_v1", "epoch": 1,
            "operation": "provider_ui_smoke", "run_id": "a" * 32,
            "boot_id": "old-boot", "resource_identity": resource,
        }
        core = FakeCore(record)
        with patch.object(COORD, "private_cleanup_lock", lambda: contextlib.nullcontext()), \
             patch.object(COORD, "load_core", return_value=core), \
             patch.object(COORD, "_ensure_arm_ack_absent") as absent, \
             patch.object(COORD, "unit_terminal", return_value=True) as units, \
             patch.object(COORD, "dormant_clean", return_value=True) as fixture, \
             contextlib.redirect_stdout(io.StringIO()):
            COORD.recover_unarmed(self.args("recover-unarmed"))
        self.assertEqual(core.recovery.proof_calls, 1)
        absent.assert_called_once_with(resource)
        self.assertEqual(units.call_count, 6)
        self.assertEqual(fixture.call_count, 3)
        fixture.assert_called_with(resource, "dormant")

    def test_recover_unarmed_refuses_acknowledged_run(self):
        resource = self.resource("smoke")
        record = {
            "schema": "maintenance_pending_v1", "epoch": 1,
            "operation": "provider_ui_smoke", "run_id": "a" * 32,
            "boot_id": "boot-test", "resource_identity": resource,
        }
        core = FakeCore(record)
        with patch.object(COORD, "private_cleanup_lock", lambda: contextlib.nullcontext()), \
             patch.object(COORD, "load_core", return_value=core), \
             patch.object(COORD, "_ensure_arm_ack_absent", side_effect=SystemExit(75)) as absent, \
             patch.object(COORD, "unit_terminal") as units, \
             patch.object(COORD, "dormant_clean") as fixture:
            with self.assertRaises(SystemExit) as raised:
                COORD.recover_unarmed(self.args("recover-unarmed"))
        self.assertEqual(raised.exception.code, 75)
        absent.assert_called_once_with(resource)
        units.assert_not_called()
        fixture.assert_not_called()

    def test_recover_unarmed_retains_pending_for_interrupted_ack_publication(self):
        with tempfile.TemporaryDirectory() as directory:
            args = self.args("recover-unarmed")
            args.state_file = str(Path(directory) / "active.json")
            resource = self.resource("smoke")
            resource["state_file"] = args.state_file
            record = FakeCore(None).make_record(
                COORD.OPERATION, args.run_id, "boot-test", resource)
            core = FakeCore(record)
            interrupted = Path(directory) / f".{COORD.ARM_ACK_LEAF}.{args.run_id}.tmp"
            interrupted.write_text("partial\n")
            with patch.object(COORD, "private_cleanup_lock", lambda: contextlib.nullcontext()), \
                 patch.object(COORD, "load_core", return_value=core), \
                 patch.object(COORD, "_state_dir_identity", return_value=(1, 2, 3, 0, 0, 2)), \
                 patch.object(COORD, "unit_terminal") as units:
                with self.assertRaises(SystemExit) as raised:
                    COORD.recover_unarmed(args)
            self.assertEqual(raised.exception.code, 75)
            self.assertEqual(core.recovery.proof_calls, 0)
            self.assertTrue(interrupted.is_file())
            units.assert_not_called()

    def test_recover_unarmed_retains_pending_when_unit_is_not_terminal(self):
        resource = self.resource("smoke")
        record = {
            "schema": "maintenance_pending_v1", "epoch": 1,
            "operation": "provider_ui_smoke", "run_id": "a" * 32,
            "boot_id": "boot-test", "resource_identity": resource,
        }
        core = FakeCore(record)
        with patch.object(COORD, "private_cleanup_lock", lambda: contextlib.nullcontext()), \
             patch.object(COORD, "load_core", return_value=core), \
             patch.object(COORD, "_read_arm_ack", return_value=None), \
             patch.object(COORD, "unit_terminal", side_effect=SystemExit(75)), \
             patch.object(COORD, "dormant_clean", return_value=True):
            with self.assertRaises(SystemExit) as raised:
                COORD.recover_unarmed(self.args("recover-unarmed"))
        self.assertEqual(raised.exception.code, 75)
        self.assertEqual(core.recovery.proof_calls, 0)

    def test_recover_unarmed_retains_pending_when_dormant_proof_fails(self):
        resource = self.resource("smoke")
        record = {
            "schema": "maintenance_pending_v1", "epoch": 1,
            "operation": "provider_ui_smoke", "run_id": "a" * 32,
            "boot_id": "boot-test", "resource_identity": resource,
        }
        core = FakeCore(record)
        with patch.object(COORD, "private_cleanup_lock", lambda: contextlib.nullcontext()), \
             patch.object(COORD, "load_core", return_value=core), \
             patch.object(COORD, "_read_arm_ack", return_value=None), \
             patch.object(COORD, "unit_terminal", return_value=True), \
             patch.object(COORD, "dormant_clean", side_effect=SystemExit(75)):
            with self.assertRaises(SystemExit) as raised:
                COORD.recover_unarmed(self.args("recover-unarmed"))
        self.assertEqual(raised.exception.code, 75)
        self.assertEqual(core.recovery.proof_calls, 0)

    def test_stale_arm_ack_refuses_settlement(self):
        resource = self.resource("smoke")
        record = {
            "schema": "maintenance_pending_v1", "epoch": 1,
            "operation": "provider_ui_smoke", "run_id": "a" * 32,
            "boot_id": "boot-test", "resource_identity": resource,
        }
        stale = dict(COORD._ack_record(record), run_id="b" * 32)
        core = FakeCore(record)
        with patch.object(COORD, "private_cleanup_lock", lambda: contextlib.nullcontext()), \
             patch.object(COORD, "load_core", return_value=core), \
             patch.object(COORD, "_read_arm_ack", return_value=stale):
            with self.assertRaises(SystemExit) as raised:
                COORD.settle(self.args("settle"))
        self.assertEqual(raised.exception.code, 75)
        self.assertEqual(core.recovery.proof_calls, 0)

    def test_orphan_arm_ack_requires_fresh_terminal_proof_before_removal(self):
        resource = self.resource('smoke')
        record = {
            'run_id': 'a' * 32, 'boot_id': 'boot-test',
            'operation': 'provider_ui_smoke', 'resource_identity': resource,
        }
        core = FakeCore(record)
        core.no_pending = True
        with patch.object(COORD, 'private_cleanup_lock', lambda: contextlib.nullcontext()), \
             patch.object(COORD, 'load_core', return_value=core), \
             patch.object(core, 'current_boot_id', return_value='next-boot'), \
             patch.object(COORD, '_read_arm_ack', return_value=COORD._ack_record(record)), \
             patch.object(COORD, 'unit_terminal', return_value=True) as units, \
             patch.object(COORD, 'dormant_clean', return_value=True) as fixture, \
             patch.object(COORD, '_remove_arm_ack') as remove, \
             contextlib.redirect_stdout(io.StringIO()):
            COORD.recover_arm_ack(self.args('recover-arm-ack'))
        self.assertEqual(units.call_count, 2)
        fixture.assert_called_once_with(resource, 'dormant')
        remove.assert_called_once_with(resource)

    def test_orphan_arm_ack_recovery_rejects_invalid_stored_boot_id(self):
        resource = self.resource('smoke')
        core = FakeCore({'resource_identity': resource})
        core.no_pending = True
        malformed = COORD._ack_record({
            'run_id': 'a' * 32, 'boot_id': 42,
            'operation': 'provider_ui_smoke', 'resource_identity': resource,
        })
        with patch.object(COORD, 'private_cleanup_lock', lambda: contextlib.nullcontext()), \
             patch.object(COORD, 'load_core', return_value=core), \
             patch.object(COORD, '_read_arm_ack', return_value=malformed), \
             patch.object(COORD, '_remove_arm_ack') as remove:
            with self.assertRaises(SystemExit) as raised:
                COORD.recover_arm_ack(self.args('recover-arm-ack'))
        self.assertEqual(raised.exception.code, 75)
        remove.assert_not_called()

    def test_orphan_arm_ack_recovery_refuses_while_pending_exists(self):
        resource = self.resource('smoke')
        core = FakeCore({'resource_identity': resource})
        with patch.object(COORD, 'private_cleanup_lock', lambda: contextlib.nullcontext()), \
             patch.object(COORD, 'load_core', return_value=core), \
             patch.object(COORD, '_remove_arm_ack') as remove:
            with self.assertRaises(SystemExit) as raised:
                COORD.recover_arm_ack(self.args('recover-arm-ack'))
        self.assertEqual(raised.exception.code, 75)
        remove.assert_not_called()

    def test_ack_arm_persists_exact_positive_run_and_resource_binding(self):
        resource = self.resource("smoke")
        record = {
            "schema": "maintenance_pending_v1", "epoch": 1,
            "operation": "provider_ui_smoke", "run_id": "a" * 32,
            "boot_id": "boot-test", "resource_identity": resource,
        }
        core = FakeCore(record)
        args = self.args("ack-arm")
        with tempfile.TemporaryDirectory() as directory:
            state = Path(directory) / "active.json"
            resource["state_file"] = str(state)
            args.state_file = str(state)
            with patch.object(COORD, "private_cleanup_lock", lambda: contextlib.nullcontext()), \
                 patch.object(COORD, "load_core", return_value=core), \
                 patch.object(COORD, "unit_armed", return_value=True), \
                 patch.object(COORD, "_state_dir_identity", return_value=(1, 2, 3, 0, 0, 2)), \
                 patch.object(COORD, "_ack_file_identity", return_value=(1, 2, 3, 0, 0, 1, 1)), \
                 contextlib.redirect_stdout(io.StringIO()):
                COORD.ack_arm(args)
            ack = Path(directory) / COORD.ARM_ACK_LEAF
            self.assertEqual(json.loads(ack.read_text()), COORD._ack_record(record))

    def test_ack_arm_rejects_old_boot_pending_record(self):
        resource = self.resource("smoke")
        record = {
            "schema": "maintenance_pending_v1", "epoch": 1,
            "operation": "provider_ui_smoke", "run_id": "a" * 32,
            "boot_id": "old-boot", "resource_identity": resource,
        }
        core = FakeCore(record)
        core.read_pending = lambda boot_id: [record] if boot_id is None else None
        with patch.object(COORD, "private_cleanup_lock", lambda: contextlib.nullcontext()), \
             patch.object(COORD, "load_core", return_value=core), \
             patch.object(COORD, "unit_armed") as armed:
            with self.assertRaises(SystemExit) as raised:
                COORD.ack_arm(self.args("ack-arm"))
        self.assertEqual(raised.exception.code, 75)
        armed.assert_not_called()

    def test_ack_arm_write_failure_does_not_settle_pending(self):
        resource = self.resource("smoke")
        record = {
            "schema": "maintenance_pending_v1", "epoch": 1,
            "operation": "provider_ui_smoke", "run_id": "a" * 32,
            "boot_id": "boot-test", "resource_identity": resource,
        }
        core = FakeCore(record)
        with patch.object(COORD, "private_cleanup_lock", lambda: contextlib.nullcontext()), \
             patch.object(COORD, "load_core", return_value=core), \
             patch.object(COORD, "unit_armed", return_value=True), \
             patch.object(COORD, "_write_arm_ack", side_effect=SystemExit(75)):
            with self.assertRaises(SystemExit):
                COORD.ack_arm(self.args("ack-arm"))
        self.assertFalse(hasattr(core.recovery, "asserted"))

    def test_private_cleanup_lock_is_created_when_absent(self):
        with tempfile.TemporaryDirectory() as directory:
            lock = Path(directory) / "fh-provider-ui-smoke.lock"
            with patch.object(COORD, "PRIVATE_CLEANUP_LOCK_PATH", str(lock)), \
                 patch.object(COORD, "_private_lock_identity", return_value=(1, 2, 3, 0, 0, 1, 0)):
                with COORD.private_cleanup_lock():
                    self.assertTrue(lock.is_file())
            self.assertEqual(lock.stat().st_mode & 0o777, 0o600)

    def test_begin_refuses_existing_arm_ack_before_publishing_pending(self):
        with tempfile.TemporaryDirectory() as directory:
            state = Path(directory) / "active.json"
            ack = Path(directory) / COORD.ARM_ACK_LEAF
            ack.write_text("stale\n")
            resource = self.resource("install")
            resource["state_file"] = str(state)
            record = {
                "schema": "maintenance_pending_v1", "epoch": 1,
                "operation": "provider_ui_smoke", "run_id": "a" * 32,
                "boot_id": "boot-test", "resource_identity": resource,
            }
            core = FakeCore(record)
            args = self.args("begin", operation="install")
            args.state_file = str(state)
            with patch.object(COORD, "private_cleanup_lock", lambda: contextlib.nullcontext()), \
                 patch.object(COORD, "load_core", return_value=core), \
                 patch.object(COORD, "_state_dir_identity", return_value=(1, 2, 3, 0, 0, 2)):
                with self.assertRaises(SystemExit) as raised:
                    COORD.begin(args)
            self.assertEqual(raised.exception.code, 75)
            self.assertFalse(hasattr(core.recovery, "published"))
            self.assertEqual(ack.read_text(), "stale\n")

    def test_begin_refuses_interrupted_arm_ack_temp(self):
        with tempfile.TemporaryDirectory() as directory:
            state = Path(directory) / "active.json"
            temporary = Path(directory) / f".{COORD.ARM_ACK_LEAF}.interrupted.tmp"
            temporary.write_text("partial\n")
            resource = self.resource("install")
            resource["state_file"] = str(state)
            core = FakeCore({})
            args = self.args("begin", operation="install")
            args.state_file = str(state)
            with patch.object(COORD, "private_cleanup_lock", lambda: contextlib.nullcontext()), \
                 patch.object(COORD, "load_core", return_value=core), \
                 patch.object(COORD, "_state_dir_identity", return_value=(1, 2, 3, 0, 0, 2)):
                with self.assertRaises(SystemExit) as raised:
                    COORD.begin(args)
            self.assertEqual(raised.exception.code, 75)
            self.assertFalse(hasattr(core.recovery, "published"))

    def test_begin_smoke_proves_dormant_before_publishing_pending(self):
        with tempfile.TemporaryDirectory() as directory:
            app = Path(directory) / "app"
            app.mkdir()
            (app / "index.php").write_text("<?php\n")
            state_dir = Path(directory) / "state"
            state_dir.mkdir()
            state = state_dir / "active.json"
            args = self.args("begin", operation="smoke")
            args.app_root = str(app)
            args.state_file = str(state)
            args.credentials_file = str(Path(directory) / "credentials.env")
            core = FakeCore({})
            calls = []

            def dormant(resource, expected):
                calls.append("dormant")
                self.assertEqual(expected, "dormant")
                return True

            def publish(record):
                calls.append("publish")
                core.recovery.published = record

            core.recovery.publish_pending = publish
            with patch.object(COORD, "private_cleanup_lock", lambda: contextlib.nullcontext()), \
                 patch.object(COORD, "load_core", return_value=core), \
                 patch.object(COORD, "_state_dir_identity", return_value=(1, 2, 3, 0, 0, 2)), \
                 patch.object(COORD, "dormant_clean", side_effect=dormant), \
                 contextlib.redirect_stdout(io.StringIO()):
                COORD.begin(args)
            self.assertEqual(calls, ["dormant", "publish"])

    def test_begin_smoke_dormant_failure_does_not_publish_pending(self):
        with tempfile.TemporaryDirectory() as directory:
            app = Path(directory) / "app"
            app.mkdir()
            (app / "index.php").write_text("<?php\n")
            state_dir = Path(directory) / "state"
            state_dir.mkdir()
            args = self.args("begin", operation="smoke")
            args.app_root = str(app)
            args.state_file = str(state_dir / "active.json")
            args.credentials_file = str(Path(directory) / "credentials.env")
            core = FakeCore({})
            with patch.object(COORD, "private_cleanup_lock", lambda: contextlib.nullcontext()), \
                 patch.object(COORD, "load_core", return_value=core), \
                 patch.object(COORD, "_state_dir_identity", return_value=(1, 2, 3, 0, 0, 2)), \
                 patch.object(COORD, "dormant_clean", side_effect=SystemExit(75)):
                with self.assertRaises(SystemExit) as raised:
                    COORD.begin(args)
            self.assertEqual(raised.exception.code, 75)
            self.assertFalse(hasattr(core.recovery, "published"))

    def test_first_install_allows_absent_state_directory_without_creating_it(self):
        with tempfile.TemporaryDirectory() as directory:
            state_directory = Path(directory) / "new-state"
            state = state_directory / "active.json"
            resource = self.resource("install")
            resource["state_file"] = str(state)
            core = FakeCore({})
            args = self.args("begin", operation="install")
            args.state_file = str(state)
            with patch.object(COORD, "private_cleanup_lock", lambda: contextlib.nullcontext()), \
                 patch.object(COORD, "load_core", return_value=core), \
                 contextlib.redirect_stdout(io.StringIO()):
                COORD.begin(args)
            self.assertTrue(hasattr(core.recovery, "published"))
            self.assertFalse(state_directory.exists())

    def test_first_install_rejects_state_directory_symlink(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / "target"
            target.mkdir()
            state_directory = Path(directory) / "new-state"
            state_directory.symlink_to(target, target_is_directory=True)
            resource = self.resource("install")
            resource["state_file"] = str(state_directory / "active.json")
            core = FakeCore({})
            args = self.args("begin", operation="install")
            args.state_file = resource["state_file"]
            with patch.object(COORD, "private_cleanup_lock", lambda: contextlib.nullcontext()), \
                 patch.object(COORD, "load_core", return_value=core):
                with self.assertRaises(SystemExit) as raised:
                    COORD.begin(args)
            self.assertEqual(raised.exception.code, 75)
            self.assertFalse(hasattr(core.recovery, "published"))

    def test_ack_publication_is_no_clobber(self):
        with tempfile.TemporaryDirectory() as directory:
            state = Path(directory) / "active.json"
            ack = Path(directory) / COORD.ARM_ACK_LEAF
            ack.write_text("existing\n")
            resource = self.resource("smoke")
            resource["state_file"] = str(state)
            record = {
                "schema": "maintenance_pending_v1", "epoch": 1,
                "operation": "provider_ui_smoke", "run_id": "a" * 32,
                "boot_id": "boot-test", "resource_identity": resource,
            }
            with patch.object(COORD, "_state_dir_identity", return_value=(1, 2, 3, 0, 0, 2)), \
                 patch.object(COORD, "_ack_file_identity", return_value=(1, 2, 3, 0, 0, 1, 1)):
                with self.assertRaises(SystemExit) as raised:
                    COORD._write_arm_ack(resource, record)
            self.assertEqual(raised.exception.code, 75)
            self.assertEqual(ack.read_text(), "existing\n")

    def test_ack_publication_collision_after_preflight_preserves_target(self):
        with tempfile.TemporaryDirectory() as directory:
            state = Path(directory) / "active.json"
            ack = Path(directory) / COORD.ARM_ACK_LEAF
            resource = self.resource("smoke")
            resource["state_file"] = str(state)
            record = {
                "schema": "maintenance_pending_v1", "epoch": 1,
                "operation": "provider_ui_smoke", "run_id": "a" * 32,
                "boot_id": "boot-test", "resource_identity": resource,
            }
            def collide(source, target, **kwargs):
                ack.write_text("racing-target\n")
                raise FileExistsError(target)

            with patch.object(COORD, "_state_dir_identity", return_value=(1, 2, 3, 0, 0, 2)), \
                 patch.object(COORD, "_ack_file_identity", return_value=None), \
                 patch.object(COORD.os, "link", side_effect=collide):
                with self.assertRaises(SystemExit) as raised:
                    COORD._write_arm_ack(resource, record)
            self.assertEqual(raised.exception.code, 75)
            self.assertEqual(ack.read_text(), "racing-target\n")

    def test_failed_ack_removal_leaves_begin_blocked(self):
        with tempfile.TemporaryDirectory() as directory:
            state = Path(directory) / "active.json"
            ack = Path(directory) / COORD.ARM_ACK_LEAF
            ack.write_text("existing\n")
            resource = self.resource("smoke")
            resource["state_file"] = str(state)
            with patch.object(COORD, "_state_dir_identity", return_value=(1, 2, 3, 0, 0, 2)), \
                 patch.object(COORD, "_ack_file_identity", return_value=(1, 2, 3, 0, 0, 1, 1)), \
                 patch.object(COORD.os, "unlink", side_effect=OSError("interrupted")):
                with self.assertRaises(SystemExit):
                    COORD._remove_arm_ack(resource)
                with self.assertRaises(SystemExit) as raised:
                    COORD._ensure_arm_ack_absent(resource)
            self.assertEqual(raised.exception.code, 75)
            self.assertEqual(ack.read_text(), "existing\n")

    def test_settle_refuses_while_private_cleanup_lock_is_held(self):
        with tempfile.TemporaryDirectory() as directory:
            lock = Path(directory) / "fh-provider-ui-smoke.lock"
            lock.touch(mode=0o600)
            holder = os.open(lock, os.O_RDONLY)
            try:
                fcntl.flock(holder, fcntl.LOCK_EX | fcntl.LOCK_NB)
                with patch.object(COORD, "PRIVATE_CLEANUP_LOCK_PATH", str(lock)), \
                     patch.object(COORD, "_private_lock_identity", return_value=(1, 2, 3, 0, 0, 1, 0)), \
                     patch.object(COORD.sys, "stderr", new_callable=io.StringIO) as errors:
                    with self.assertRaises(SystemExit) as raised:
                        with COORD.private_cleanup_lock():
                            self.fail("busy private lock was acquired")
                self.assertEqual(raised.exception.code, 75)
                self.assertIn('reason=private_lock_busy', errors.getvalue())
            finally:
                fcntl.flock(holder, fcntl.LOCK_UN)
                os.close(holder)

    def test_private_cleanup_lock_available_is_held_for_settle(self):
        with tempfile.TemporaryDirectory() as directory:
            lock = Path(directory) / "fh-provider-ui-smoke.lock"
            lock.touch(mode=0o600)
            competitor = os.open(lock, os.O_RDONLY)
            try:
                with patch.object(COORD, "PRIVATE_CLEANUP_LOCK_PATH", str(lock)), \
                     patch.object(COORD, "_private_lock_identity", return_value=(1, 2, 3, 0, 0, 1, 0)):
                    with COORD.private_cleanup_lock():
                        with self.assertRaises(OSError):
                            fcntl.flock(competitor, fcntl.LOCK_EX | fcntl.LOCK_NB)
            finally:
                os.close(competitor)

    def test_private_cleanup_lock_rejects_non_root_identity(self):
        metadata = os.stat(__file__, follow_symlinks=False)
        self.assertIsNone(COORD._private_lock_identity(metadata))

    def test_private_cleanup_lock_accepts_only_exact_root_legacy_mode_for_migration(self):
        metadata = types.SimpleNamespace(
            st_mode=stat.S_IFREG | 0o644, st_uid=0, st_gid=0,
            st_nlink=1, st_size=0, st_dev=1, st_ino=2,
        )
        self.assertIsNone(COORD._private_lock_identity(metadata))
        self.assertIsNotNone(COORD._private_lock_identity(metadata, allow_legacy=True))
        metadata.st_mode = stat.S_IFREG | 0o666
        self.assertIsNone(COORD._private_lock_identity(metadata, allow_legacy=True))

    def test_private_cleanup_lock_migrates_legacy_inode_while_held(self):
        with tempfile.TemporaryDirectory() as directory:
            lock = Path(directory) / 'fh-provider-ui-smoke.lock'
            lock.touch()
            lock.chmod(0o644)
            original_inode = lock.stat().st_ino

            def identity(metadata, allow_legacy=False):
                mode = stat.S_IMODE(metadata.st_mode)
                if mode not in ((0o600, 0o644) if allow_legacy else (0o600,)):
                    return None
                return (metadata.st_dev, metadata.st_ino, metadata.st_mode)

            with patch.object(COORD, 'PRIVATE_CLEANUP_LOCK_PATH', str(lock)), \
                 patch.object(COORD, '_private_lock_identity', side_effect=identity):
                with COORD.private_cleanup_lock():
                    self.assertEqual(stat.S_IMODE(lock.stat().st_mode), 0o600)
                    self.assertEqual(lock.stat().st_ino, original_inode)

    def test_unit_terminal_accepts_not_found_after_private_lock_fence(self):
        result = types.SimpleNamespace(
            returncode=0,
            stdout=("Id=fh-provider-ui-smoke-cleanup.service\n"
                     "LoadState=not-found\n"),
            stderr="",
        )
        with patch.object(COORD.subprocess, "run", return_value=result):
            self.assertTrue(COORD.unit_terminal("fh-provider-ui-smoke-cleanup.service", self.resource(), "a" * 32))

    def test_assert_bound_fd_rejects_wrong_run_id(self):
        resource = self.resource("smoke")
        record = {
            "schema": "maintenance_pending_v1",
            "epoch": 1,
            "operation": "provider_ui_smoke",
            "run_id": "a" * 32,
            "boot_id": "boot-test",
            "resource_identity": resource,
        }
        core = FakeCore(record)
        with patch.object(COORD, "load_core", return_value=core):
            with self.assertRaises(SystemExit) as raised:
                COORD.assert_bound_fd(self.args("assert-bound-fd", run_id="b" * 32))
        self.assertEqual(raised.exception.code, 75)
        self.assertFalse(core.recovery.admitted)

    def test_assert_bound_fd_requires_inherited_canonical_descriptor(self):
        resource = self.resource("smoke")
        record = {
            "schema": "maintenance_pending_v1",
            "epoch": 1,
            "operation": "provider_ui_smoke",
            "run_id": "a" * 32,
            "boot_id": "boot-test",
            "resource_identity": resource,
        }
        core = FakeCore(record)
        args = self.args("assert-bound-fd")
        args.lock_fd = 9
        with patch.object(COORD, "load_core", return_value=core):
            with self.assertRaises(SystemExit) as raised:
                COORD.assert_bound_fd(args)
        self.assertEqual(raised.exception.code, 64)
        self.assertFalse(core.recovery.admitted)

    def test_activate_refuses_timer_that_expired_after_arm_ack(self):
        resource = self.resource()
        record = FakeCore(None).make_record(
            COORD.OPERATION, "a" * 32, "boot-test", resource)
        core = FakeCore(record)
        args = self.args("assert-bound-fd")
        args.lifecycle_action = "activate"
        with patch.object(COORD, "load_core", return_value=core), \
             patch.object(COORD, "_read_arm_ack", return_value=COORD._ack_record(record)), \
             patch.object(COORD, "unit_armed", side_effect=SystemExit(75)) as armed:
            with self.assertRaises(SystemExit) as raised:
                COORD.assert_bound_fd(args)
        self.assertEqual(raised.exception.code, 75)
        armed.assert_called_once_with(
            "fh-provider-ui-smoke-cleanup.timer", resource, "a" * 32)
        self.assertFalse(core.recovery.admitted)

    def test_activate_requires_current_ack_and_live_timer_before_admission(self):
        resource = self.resource()
        record = FakeCore(None).make_record(
            COORD.OPERATION, "a" * 32, "boot-test", resource)
        core = FakeCore(record)
        args = self.args("assert-bound-fd")
        args.lifecycle_action = "activate"
        with patch.object(COORD, "load_core", return_value=core), \
             patch.object(COORD, "_read_arm_ack", return_value=COORD._ack_record(record)), \
             patch.object(COORD, "unit_armed", return_value=True) as armed:
            COORD.assert_bound_fd(args)
        self.assertTrue(core.recovery.admitted)
        self.assertEqual(armed.call_count, 2)
        self.assertEqual(
            armed.call_args_list[0].args[0], "fh-provider-ui-smoke-cleanup.timer")

    def test_activate_rejects_old_boot_pending_record(self):
        resource = self.resource("smoke")
        record = FakeCore(None).make_record(
            COORD.OPERATION, "a" * 32, "old-boot", resource)
        core = FakeCore(record)
        core.read_pending = lambda boot_id: [record] if boot_id is None else None
        args = self.args("assert-bound-fd")
        args.lifecycle_action = "activate"
        with patch.object(COORD, "load_core", return_value=core), \
             patch.object(COORD, "_read_arm_ack") as ack, \
             patch.object(COORD, "unit_armed") as armed:
            with self.assertRaises(SystemExit) as raised:
                COORD.assert_bound_fd(args)
        self.assertEqual(raised.exception.code, 75)
        ack.assert_not_called()
        armed.assert_not_called()

    def test_activate_refuses_missing_arm_ack_before_admission(self):
        resource = self.resource()
        record = FakeCore(None).make_record(
            COORD.OPERATION, "a" * 32, "boot-test", resource)
        core = FakeCore(record)
        args = self.args("assert-bound-fd")
        args.lifecycle_action = "activate"
        with patch.object(COORD, "load_core", return_value=core), \
             patch.object(COORD, "_read_arm_ack", return_value=None), \
             patch.object(COORD, "unit_armed") as armed:
            with self.assertRaises(SystemExit) as raised:
                COORD.assert_bound_fd(args)
        self.assertEqual(raised.exception.code, 75)
        self.assertFalse(core.recovery.admitted)
        armed.assert_not_called()

    def test_deactivate_remains_admissible_after_timer_has_fired(self):
        resource = self.resource()
        record = FakeCore(None).make_record(
            COORD.OPERATION, "a" * 32, "boot-test", resource)
        core = FakeCore(record)
        with patch.object(COORD, "load_core", return_value=core), \
             patch.object(COORD, "unit_armed") as armed:
            COORD.assert_bound_fd(self.args("assert-bound-fd"))
        self.assertTrue(core.recovery.admitted)
        armed.assert_not_called()

    def test_deactivate_accepts_exact_old_boot_pending_record(self):
        resource = self.resource()
        record = FakeCore(None).make_record(
            COORD.OPERATION, "a" * 32, "old-boot", resource)
        core = FakeCore(record)
        core.read_pending = lambda boot_id: [record] if boot_id is None else None
        args = self.args("assert-bound-fd")
        args.lifecycle_action = "deactivate"
        with patch.object(COORD, "load_core", return_value=core):
            COORD.assert_bound_fd(args)
        self.assertTrue(core.recovery.admitted)

    def test_late_php_child_cannot_start_after_pending_was_settled(self):
        resource = self.resource("smoke")
        record = {
            "schema": "maintenance_pending_v1",
            "epoch": 1,
            "operation": "provider_ui_smoke",
            "run_id": "a" * 32,
            "boot_id": "boot-test",
            "resource_identity": resource,
        }
        core = FakeCore(record)
        with patch.object(COORD, "load_core", return_value=core), \
             patch.object(core, "read_pending", return_value=None):
            with self.assertRaises(SystemExit) as raised:
                COORD.assert_bound_fd(self.args("assert-bound-fd"))
        self.assertEqual(raised.exception.code, 75)
        self.assertFalse(core.recovery.admitted)

    @unittest.skipUnless(sys.platform.startswith("linux") and shutil.which("php"), "Linux PHP runtime required")
    def test_php_proc_open_passes_held_flock_as_child_descriptor_three(self):
        php = r'''<?php
$path = tempnam(sys_get_temp_dir(), 'fh-provider-fd-');
if ($path === false) { exit(1); }
chmod($path, 0600);
$handle = fopen($path, 'r+b');
if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) { exit(2); }
$child = [
    'python3', '-c',
    'import os,re,sys; info=open("/proc/self/fdinfo/3").read(); '
    . 'sys.exit(0 if os.fstat(3).st_ino == int(sys.argv[1]) '
    . 'and re.search(r"FLOCK\s+ADVISORY\s+WRITE", info) else 1)',
    (string) fstat($handle)['ino'],
];
$process = proc_open($child, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'],
    2 => ['pipe', 'w'], 3 => $handle], $pipes);
if (!is_resource($process)) { exit(3); }
stream_get_contents($pipes[1]);
stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$status = proc_close($process);
flock($handle, LOCK_UN);
fclose($handle);
unlink($path);
exit($status);
'''
        result = subprocess.run(
            ["php"], input=php, text=True, capture_output=True, timeout=15, check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr)


if __name__ == "__main__":
    unittest.main()
