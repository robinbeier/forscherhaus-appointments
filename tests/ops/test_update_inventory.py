import json
import os
import sys
import tempfile
import time
import unittest
from pathlib import Path
from types import SimpleNamespace

sys.path.insert(0, str(Path(__file__).parents[2] / "scripts" / "ops"))
import update_inventory as inventory


class FakeApt:
    def __init__(self, packages, comparison=1):
        self.packages = packages
        self.comparison = comparison

    def package(self, name):
        return self.packages.get(name)

    def compare(self, installed, candidate):
        return self.comparison


def package(installed="1.0", candidate="2.0", trusted=True):
    origin = SimpleNamespace(origin="Ubuntu", archive="noble", label="Ubuntu", component="main", trusted=trusted)
    candidate_obj = SimpleNamespace(version=candidate, origins=[origin]) if candidate else None
    return SimpleNamespace(installed=SimpleNamespace(version=installed) if installed else None, candidate=candidate_obj)


class UpdateInventoryTests(unittest.TestCase):
    def test_apt_adapter_marks_updates_untrusted_origins_and_absent_packages(self):
        apt = FakeApt({"demo": package(), "bad": package(trusted=False), "absent": None}, comparison=-1)
        values, unknown = inventory.package_inventory(["demo", "bad", "absent"], apt)
        self.assertEqual(values[0]["state"], "update-available")
        self.assertEqual(values[1]["reason"], "untrusted-or-unknown-origin")
        self.assertEqual(values[2]["reason"], "package-absent")
        self.assertEqual(unknown, ["absent", "bad"])
        self.assertNotIn("https://", json.dumps(values))

    def test_apt_version_compare_failure_is_fail_closed(self):
        class Broken(FakeApt):
            def compare(self, *_):
                raise ValueError("bad version")
        with self.assertRaises(inventory.InventoryError):
            inventory.package_inventory(["demo"], Broken({"demo": package()}))

    def test_flat_apt_metadata_and_refresh_ages_are_separate(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            lists = root / "var/lib/apt/lists"
            periodic = root / "var/lib/apt/periodic"
            lists.mkdir(parents=True)
            periodic.mkdir(parents=True)
            old = lists / "repo_InRelease"
            old.write_text("metadata")
            stamp = periodic / "update-success-stamp"
            stamp.write_text("ok")
            now = time.time()
            os.utime(old, (now - 8 * 86400, now - 8 * 86400))
            os.utime(stamp, (now - 1 * 86400, now - 1 * 86400))
            result = inventory.apt_cache_freshness(root, now)
            self.assertEqual(result["state"], "fresh")
            os.utime(stamp, (now - 8 * 86400, now - 8 * 86400))
            self.assertEqual(inventory.apt_cache_freshness(root, now)["state"], "unknown")
            os.utime(stamp, (now + 60, now + 60))
            self.assertEqual(inventory.apt_cache_freshness(root, now)["state"], "unknown")
            self.assertGreater(result["metadata_age_days"], 7)
            self.assertLess(result["last_refresh_age_days"], 2)

    def test_container_and_image_inspection_are_separate_and_sanitized(self):
        container = {"Id": "container-id", "Image": "sha256:" + "d" * 64, "Config": {"Image": "registry.example/app@sha256:" + "a" * 64, "Labels": {"SECRET": "no"}}, "State": {"Running": True, "Health": {"Status": "not_configured"}}}
        image = {"Id": "sha256:" + "d" * 64, "Config": {"Labels": {"org.opencontainers.image.revision": "b" * 40, "SECRET": "no"}}, "RepoDigests": ["registry.example/app@sha256:" + "c" * 64]}
        def run(argv):
            return json.dumps([container if argv[1] == "inspect" else image])
        values, missing = inventory.container_inventory(["demo"], run)
        self.assertFalse(missing)
        self.assertEqual(values[0]["health"], "not_configured")
        self.assertEqual(values[0]["oci_revision"], "b" * 40)
        self.assertNotIn("SECRET", json.dumps(values))

    def test_missing_or_not_running_container_fails_closed(self):
        def run(argv):
            raise inventory.InventoryError("missing")
        values, missing = inventory.container_inventory(["demo"], run)
        self.assertEqual(values, [])
        self.assertEqual(missing, ["demo"])

    def test_learning_node_failure_is_not_silently_healthy(self):
        def run(argv):
            if argv[0] == "uname":
                return "Linux test"
            if argv[0] == "docker":
                raise inventory.InventoryError("missing")
            if argv[0] == "/usr/local/bin/node":
                raise inventory.InventoryError("node missing")
            raise inventory.InventoryError("unit unavailable")
        with self.assertRaises(inventory.InventoryError):
            inventory.collect("learning", FakeApt({name: package() for name in inventory.PACKAGES["learning"]}), run, Path("/tmp"))


if __name__ == "__main__":
    unittest.main()
