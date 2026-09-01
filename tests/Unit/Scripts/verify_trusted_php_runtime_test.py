import importlib.util
import hashlib
import io
import json
import os
from pathlib import Path
import stat
import tarfile
import tempfile
import unittest

MODULE_PATH = Path(__file__).resolve().parents[3] / "scripts/agent/verify_trusted_php_runtime.py"
MODULE_SPEC = importlib.util.spec_from_file_location("verify_trusted_php_runtime", MODULE_PATH)
if MODULE_SPEC is None or MODULE_SPEC.loader is None:
    raise RuntimeError("trusted PHP runtime verifier could not be loaded")
verifier = importlib.util.module_from_spec(MODULE_SPEC)
MODULE_SPEC.loader.exec_module(verifier)


class TrustedPhpRuntimeTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = self.temp.name
        self.php = os.path.join(self.root, "php")
        with open(self.php, "wb") as stream:
            stream.write(b"php-fixture")
        os.chmod(self.php, 0o555)
        self.contract = os.path.join(self.root, "contract.json")
        self.inspector = lambda path: ""

    def tearDown(self):
        self.temp.cleanup()

    def write_contract(self, **policy):
        policy.setdefault("pinned_archive_by_platform", {})
        document = {"authority": {"interpreter_trust": {"php": policy}}}
        if os.path.exists(self.contract):
            os.chmod(self.contract, 0o644)
        with open(self.contract, "w", encoding="utf-8") as stream:
            json.dump(document, stream)
        os.chmod(self.contract, 0o444)

    def digest(self):
        return verifier.closure_attestation(self.php, [os.path.realpath(self.php)])

    def archive_fixture(self, content=b"static-php-fixture", extra_member=False):
        archive_path = os.path.join(self.root, "fixture-runtime.tar.gz")
        with tarfile.open(archive_path, "w:gz") as archive:
            member = tarfile.TarInfo("php")
            member.size = len(content)
            member.mode = 0o755
            member.mtime = 0
            archive.addfile(member, io.BytesIO(content))
            if extra_member:
                extra = tarfile.TarInfo("unexpected")
                extra.size = 1
                extra.mode = 0o644
                extra.mtime = 0
                archive.addfile(extra, io.BytesIO(b"x"))
        with open(archive_path, "rb") as stream:
            archive_sha256 = hashlib.sha256(stream.read()).hexdigest()
        member_sha256 = hashlib.sha256(content).hexdigest()
        descriptor = {
            "url": "https://artifacts.example.invalid/php-runtime.tar.gz",
            "archive_sha256": archive_sha256,
            "member": "php",
            "member_sha256": member_sha256,
        }
        payload = {
            "logical": descriptor["url"] + "#php",
            "paths": [{"canonical": "php", "sha256": member_sha256, "mode": 0o500}],
            "sealed_system_dependencies": [],
        }
        closure_sha256 = hashlib.sha256(
            json.dumps(payload, sort_keys=True, separators=(",", ":")).encode("utf-8")
        ).hexdigest()
        return archive_path, descriptor, closure_sha256

    @staticmethod
    def archive_downloader(source):
        def download(url, target):
            del url
            with open(source, "rb") as input_stream, open(target, "wb") as output_stream:
                output_stream.write(input_stream.read())

        return download

    def test_valid_pinned_candidate_prints_canonical_path(self):
        self.write_contract(
            candidate_by_platform={"Darwin-arm64": self.php},
            require_exact_closure_sha256=True,
            closure_sha256_by_platform={"Darwin-arm64": self.digest()},
        )
        self.assertEqual(
            os.path.realpath(self.php),
            verifier.attest(self.contract, "Darwin-arm64", self.inspector),
        )

    def test_digest_mismatch_rejected(self):
        self.write_contract(
            candidate_by_platform={"Darwin-arm64": self.php},
            require_exact_closure_sha256=True,
            closure_sha256_by_platform={"Darwin-arm64": "0" * 64},
        )
        with self.assertRaises(verifier.AttestationError):
            verifier.attest(self.contract, "Darwin-arm64", self.inspector)

    def test_valid_pinned_archive_is_materialized_privately_and_attested(self):
        archive_path, descriptor, closure_sha256 = self.archive_fixture()
        self.write_contract(
            candidate_by_platform={},
            pinned_archive_by_platform={"Darwin-x86_64": descriptor},
            require_exact_closure_sha256=True,
            closure_sha256_by_platform={"Darwin-x86_64": closure_sha256},
        )
        materialize_root = os.path.join(self.root, "runtime")
        result = verifier.attest(
            self.contract,
            "Darwin-x86_64",
            self.inspector,
            materialize_root=materialize_root,
            downloader=self.archive_downloader(archive_path),
        )
        self.assertEqual(os.path.realpath(os.path.join(materialize_root, "php")), result)
        self.assertEqual(0o500, stat.S_IMODE(os.stat(result).st_mode))
        self.assertFalse(os.path.exists(os.path.join(materialize_root, "runtime.tar.gz")))

    def test_pinned_archive_requires_materialization_root(self):
        _, descriptor, closure_sha256 = self.archive_fixture()
        self.write_contract(
            candidate_by_platform={},
            pinned_archive_by_platform={"Darwin-x86_64": descriptor},
            require_exact_closure_sha256=True,
            closure_sha256_by_platform={"Darwin-x86_64": closure_sha256},
        )
        with self.assertRaises(verifier.AttestationError):
            verifier.attest(self.contract, "Darwin-x86_64", self.inspector)

    def test_pinned_archive_digest_mismatch_is_rejected_before_extraction(self):
        archive_path, descriptor, closure_sha256 = self.archive_fixture()
        descriptor["archive_sha256"] = "0" * 64
        self.write_contract(
            candidate_by_platform={},
            pinned_archive_by_platform={"Darwin-x86_64": descriptor},
            require_exact_closure_sha256=True,
            closure_sha256_by_platform={"Darwin-x86_64": closure_sha256},
        )
        materialize_root = os.path.join(self.root, "runtime")
        with self.assertRaises(verifier.AttestationError):
            verifier.attest(
                self.contract,
                "Darwin-x86_64",
                self.inspector,
                materialize_root=materialize_root,
                downloader=self.archive_downloader(archive_path),
            )
        self.assertFalse(os.path.exists(os.path.join(materialize_root, "php")))

    def test_pinned_archive_member_digest_mismatch_is_rejected(self):
        archive_path, descriptor, closure_sha256 = self.archive_fixture()
        descriptor["member_sha256"] = "0" * 64
        self.write_contract(
            candidate_by_platform={},
            pinned_archive_by_platform={"Darwin-x86_64": descriptor},
            require_exact_closure_sha256=True,
            closure_sha256_by_platform={"Darwin-x86_64": closure_sha256},
        )
        with self.assertRaises(verifier.AttestationError):
            verifier.attest(
                self.contract,
                "Darwin-x86_64",
                self.inspector,
                materialize_root=os.path.join(self.root, "runtime"),
                downloader=self.archive_downloader(archive_path),
            )

    def test_pinned_archive_rejects_unexpected_members(self):
        archive_path, descriptor, closure_sha256 = self.archive_fixture(extra_member=True)
        self.write_contract(
            candidate_by_platform={},
            pinned_archive_by_platform={"Darwin-x86_64": descriptor},
            require_exact_closure_sha256=True,
            closure_sha256_by_platform={"Darwin-x86_64": closure_sha256},
        )
        with self.assertRaises(verifier.AttestationError):
            verifier.attest(
                self.contract,
                "Darwin-x86_64",
                self.inspector,
                materialize_root=os.path.join(self.root, "runtime"),
                downloader=self.archive_downloader(archive_path),
            )

    def test_pinned_archive_rejects_non_system_dynamic_dependency(self):
        archive_path, descriptor, closure_sha256 = self.archive_fixture()
        dependency = os.path.join(self.root, "libfixture.dylib")
        with open(dependency, "wb") as stream:
            stream.write(b"dependency")
        os.chmod(dependency, 0o555)
        self.write_contract(
            candidate_by_platform={},
            pinned_archive_by_platform={"Darwin-x86_64": descriptor},
            require_exact_closure_sha256=True,
            closure_sha256_by_platform={"Darwin-x86_64": closure_sha256},
        )

        def inspect(path):
            if path.endswith("/php"):
                return "\t%s (compatibility version 1.0.0, current version 1.0.0)\n" % dependency
            return ""

        with self.assertRaises(verifier.AttestationError):
            verifier.attest(
                self.contract,
                "Darwin-x86_64",
                inspect,
                materialize_root=os.path.join(self.root, "runtime"),
                downloader=self.archive_downloader(archive_path),
            )

    def test_platform_cannot_have_both_fixed_and_archive_runtime_sources(self):
        archive_path, descriptor, closure_sha256 = self.archive_fixture()
        self.write_contract(
            candidate_by_platform={"Darwin-x86_64": self.php},
            pinned_archive_by_platform={"Darwin-x86_64": descriptor},
            require_exact_closure_sha256=True,
            closure_sha256_by_platform={"Darwin-x86_64": closure_sha256},
        )
        with self.assertRaises(verifier.AttestationError):
            verifier.attest(
                self.contract,
                "Darwin-x86_64",
                self.inspector,
                materialize_root=os.path.join(self.root, "runtime"),
                downloader=self.archive_downloader(archive_path),
            )

    def test_every_runtime_source_requires_an_exact_platform_pin(self):
        _, descriptor, _ = self.archive_fixture()
        self.write_contract(
            candidate_by_platform={"Darwin-arm64": self.php},
            pinned_archive_by_platform={"Darwin-x86_64": descriptor},
            require_exact_closure_sha256=True,
            closure_sha256_by_platform={"Darwin-arm64": self.digest()},
        )
        with self.assertRaises(verifier.AttestationError):
            verifier.attest(self.contract, "Darwin-arm64", self.inspector)

    def test_pinned_dependency_drift_is_rejected(self):
        dependency = os.path.join(self.root, "libfixture.dylib")
        with open(dependency, "wb") as stream:
            stream.write(b"trusted-dependency")
        os.chmod(dependency, 0o555)

        def inspect(path):
            if path == os.path.realpath(self.php):
                return (
                    "\t%s (compatibility version 1.0.0, current version 1.0.0)\n"
                    % dependency
                )
            return ""

        paths, sealed = verifier.dependency_closure(self.php, "Darwin", inspect)
        digest = verifier.closure_attestation(self.php, paths, sealed)
        self.write_contract(
            candidate_by_platform={"Darwin-arm64": self.php},
            require_exact_closure_sha256=True,
            closure_sha256_by_platform={"Darwin-arm64": digest},
        )
        os.chmod(dependency, 0o755)
        with open(dependency, "wb") as stream:
            stream.write(b"drifted-dependency")
        os.chmod(dependency, 0o555)

        with self.assertRaises(verifier.AttestationError):
            verifier.attest(self.contract, "Darwin-arm64", inspect)

    def test_writable_candidate_rejected(self):
        os.chmod(self.php, 0o775)
        self.write_contract(
            candidate_by_platform={"Darwin-arm64": self.php},
            require_exact_closure_sha256=True,
            closure_sha256_by_platform={"Darwin-arm64": "0" * 64},
        )
        with self.assertRaises(verifier.AttestationError):
            verifier.attest(self.contract, "Darwin-arm64", self.inspector)

    def test_malformed_contract_rejected(self):
        with open(self.contract, "w", encoding="utf-8") as stream:
            stream.write("{}")
        os.chmod(self.contract, 0o444)
        with self.assertRaises(verifier.AttestationError):
            verifier.attest(self.contract, "Darwin-arm64", self.inspector)

    def test_exact_platform_pin_is_required_even_for_root_owned_closure(self):
        original = verifier.os.lstat

        class RootStat:
            st_mode = stat.S_IFREG | 0o555
            st_uid = 0
            st_gid = 0

        verifier.os.lstat = lambda path: RootStat()
        try:
            self.write_contract(
                candidate_by_platform={"Linux-x86_64": self.php},
                require_exact_closure_sha256=True,
                closure_sha256_by_platform={},
            )
            with self.assertRaises(verifier.AttestationError):
                verifier.attest(self.contract, "Linux-x86_64", self.inspector)
            self.write_contract(
                candidate_by_platform={"Linux-x86_64": self.php},
                require_exact_closure_sha256=True,
                closure_sha256_by_platform={"Linux-x86_64": self.digest()},
            )
            self.assertEqual(
                os.path.realpath(self.php),
                verifier.attest(self.contract, "Linux-x86_64", self.inspector),
            )
        finally:
            verifier.os.lstat = original

    def test_linux_closure_must_remain_system_owned_even_when_pinned(self):
        original = verifier.os.lstat

        class UserStat:
            st_mode = stat.S_IFREG | 0o555
            st_uid = 501
            st_gid = 20

        verifier.os.lstat = lambda path: UserStat()
        try:
            self.write_contract(
                candidate_by_platform={"Linux-x86_64": self.php},
                require_exact_closure_sha256=True,
                closure_sha256_by_platform={"Linux-x86_64": self.digest()},
            )
            with self.assertRaises(verifier.AttestationError):
                verifier.attest(self.contract, "Linux-x86_64", self.inspector)
        finally:
            verifier.os.lstat = original


if __name__ == "__main__":
    unittest.main()
