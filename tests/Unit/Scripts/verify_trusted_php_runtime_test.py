import importlib.util
import json
import os
from pathlib import Path
import stat
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
        document = {"authority": {"interpreter_trust": {"php": policy}}}
        if os.path.exists(self.contract):
            os.chmod(self.contract, 0o644)
        with open(self.contract, "w", encoding="utf-8") as stream:
            json.dump(document, stream)
        os.chmod(self.contract, 0o444)

    def digest(self):
        return verifier.closure_attestation(self.php, [os.path.realpath(self.php)])

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
