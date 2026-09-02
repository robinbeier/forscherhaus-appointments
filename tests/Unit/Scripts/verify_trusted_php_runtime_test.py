import contextlib
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import struct
import tarfile
import tempfile
import unittest
from unittest import mock

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

    def write_reviewer_contract(self, **policy):
        document = {"authority": {"reviewer": policy}}
        if os.path.exists(self.contract):
            os.chmod(self.contract, 0o644)
        with open(self.contract, "w", encoding="utf-8") as stream:
            json.dump(document, stream)
        os.chmod(self.contract, 0o444)

    def digest(self):
        return verifier.closure_attestation(self.php, [os.path.realpath(self.php)])

    @contextlib.contextmanager
    def owned_lstat(self, uid):
        original = verifier.os.lstat

        class OwnedStat:
            def __init__(self, metadata):
                self.st_mode = metadata.st_mode
                self.st_uid = uid
                self.st_gid = 0

        verifier.os.lstat = lambda path: OwnedStat(original(path))
        try:
            yield
        finally:
            verifier.os.lstat = original

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
    def static_elf(architecture="x86_64", interpreter=False, needed=False):
        machine = {"x86_64": 62, "aarch64": 183}[architecture]
        extra_segments = int(interpreter) + int(needed)
        program_header_count = 1 + extra_segments
        payload_offset = 64 + program_header_count * 56
        interpreter_payload = b"/lib64/ld-linux-x86-64.so.2\x00" if interpreter else b""
        dynamic_payload = struct.pack("<qQqQ", 1, 0, 0, 0) if needed else b""
        total_size = payload_offset + len(interpreter_payload) + len(dynamic_payload)
        identity = b"\x7fELF" + bytes([2, 1, 1, 0]) + bytes(8)
        header = identity + struct.pack(
            "<HHIQQQIHHHHHH",
            2,
            machine,
            1,
            0,
            64,
            0,
            0,
            64,
            56,
            program_header_count,
            0,
            0,
            0,
        )
        program_headers = [struct.pack("<IIQQQQQQ", 1, 5, 0, 0, 0, total_size, total_size, 4096)]
        next_offset = payload_offset
        if interpreter:
            program_headers.append(
                struct.pack(
                    "<IIQQQQQQ",
                    verifier.ELF_PT_INTERP,
                    4,
                    next_offset,
                    0,
                    0,
                    len(interpreter_payload),
                    len(interpreter_payload),
                    1,
                )
            )
            next_offset += len(interpreter_payload)
        if needed:
            program_headers.append(
                struct.pack(
                    "<IIQQQQQQ",
                    verifier.ELF_PT_DYNAMIC,
                    4,
                    next_offset,
                    0,
                    0,
                    len(dynamic_payload),
                    len(dynamic_payload),
                    8,
                )
            )
        return header + b"".join(program_headers) + interpreter_payload + dynamic_payload

    @staticmethod
    def archive_downloader(source):
        def download(url, target):
            del url
            with open(source, "rb") as input_stream, open(target, "wb") as output_stream:
                output_stream.write(input_stream.read())

        return download

    def test_valid_root_owned_fixed_candidate_prints_canonical_path(self):
        with self.owned_lstat(0):
            self.write_contract(
                candidate_by_platform={"Darwin-arm64": self.php},
                require_exact_closure_sha256=True,
                closure_sha256_by_platform={"Darwin-arm64": self.digest()},
            )
            self.assertEqual(
                os.path.realpath(self.php),
                verifier.attest(self.contract, "Darwin-arm64", self.inspector),
            )

    def test_valid_root_owned_linux_aarch64_fixed_candidate_is_attested(self):
        with self.owned_lstat(0):
            self.write_contract(
                candidate_by_platform={"Linux-aarch64": self.php},
                require_exact_closure_sha256=True,
                closure_sha256_by_platform={"Linux-aarch64": self.digest()},
            )
            self.assertEqual(
                os.path.realpath(self.php),
                verifier.attest(self.contract, "Linux-aarch64", self.inspector),
            )

    def test_user_owned_fixed_candidate_is_rejected_even_when_exactly_pinned(self):
        with self.owned_lstat(501):
            self.write_contract(
                candidate_by_platform={"Darwin-arm64": self.php},
                require_exact_closure_sha256=True,
                closure_sha256_by_platform={"Darwin-arm64": self.digest()},
            )
            with self.assertRaisesRegex(
                verifier.AttestationError,
                "fixed-path runtime closure is not system-owned",
            ):
                verifier.attest(self.contract, "Darwin-arm64", self.inspector)

    def test_digest_mismatch_rejected(self):
        with self.owned_lstat(0):
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

    def test_pinned_archive_download_ignores_malicious_user_curl_configuration(self):
        """The transport must not inherit curlrc, netrc, proxy, or credentials."""
        _, descriptor, _ = self.archive_fixture()
        target = os.path.join(self.root, "download.tar.gz")
        curl_home = os.path.join(self.root, "curl-home")
        unexpected_output = os.path.join(self.root, "unexpected-output")
        os.mkdir(curl_home)
        with open(os.path.join(curl_home, ".curlrc"), "w", encoding="utf-8") as stream:
            stream.write("output %s\nproxy http://attacker.invalid\n" % unexpected_output)
        with open(os.path.join(curl_home, "credentials"), "w", encoding="utf-8") as stream:
            stream.write("machine attacker.invalid login leaked password secret\n")
        with mock.patch.dict(
            os.environ,
            {
                "HOME": curl_home,
                "CURL_HOME": curl_home,
                "NETRC": os.path.join(curl_home, "credentials"),
                "HTTPS_PROXY": "http://attacker.invalid",
                "ALL_PROXY": "http://attacker.invalid",
            },
            clear=False,
        ), mock.patch.object(verifier._RUNTIME_PRIMITIVES.subprocess, "run") as run:
            verifier._download_pinned_archive(descriptor["url"], target)

        primitives = verifier._RUNTIME_PRIMITIVES
        command = run.call_args.args[0]
        self.assertEqual(primitives.CURL_EXECUTABLE, command[0])
        self.assertEqual("--disable", command[1])
        self.assertEqual(
            list(primitives.CURL_SECURITY_OPTIONS), command[1 : 1 + len(primitives.CURL_SECURITY_OPTIONS)]
        )
        self.assertEqual(dict(primitives.SAFE_CURL_ENVIRONMENT), run.call_args.kwargs["env"])
        self.assertNotIn("HOME", run.call_args.kwargs["env"])
        self.assertNotIn("CURL_HOME", run.call_args.kwargs["env"])
        self.assertNotIn("NETRC", run.call_args.kwargs["env"])
        self.assertNotIn("HTTPS_PROXY", run.call_args.kwargs["env"])
        self.assertNotIn("ALL_PROXY", run.call_args.kwargs["env"])
        self.assertFalse(os.path.exists(target))
        self.assertFalse(os.path.exists(unexpected_output))

    def test_linux_x86_64_pinned_static_archive_is_parsed_without_loader_execution(self):
        archive_path, descriptor, closure_sha256 = self.archive_fixture(content=self.static_elf())
        self.write_contract(
            candidate_by_platform={},
            pinned_archive_by_platform={"Linux-x86_64": descriptor},
            require_exact_closure_sha256=True,
            closure_sha256_by_platform={"Linux-x86_64": closure_sha256},
        )

        def forbidden_inspector(_path):
            self.fail("A private Linux archive must not be passed to loader tooling.")

        materialize_root = os.path.join(self.root, "runtime")
        result = verifier.attest(
            self.contract,
            "Linux-x86_64",
            forbidden_inspector,
            materialize_root=materialize_root,
            downloader=self.archive_downloader(archive_path),
        )
        self.assertEqual(os.path.realpath(os.path.join(materialize_root, "php")), result)

    def test_linux_pinned_archive_rejects_wrong_elf_architecture(self):
        archive_path, descriptor, closure_sha256 = self.archive_fixture(
            content=self.static_elf(architecture="aarch64")
        )
        self.write_contract(
            candidate_by_platform={},
            pinned_archive_by_platform={"Linux-x86_64": descriptor},
            require_exact_closure_sha256=True,
            closure_sha256_by_platform={"Linux-x86_64": closure_sha256},
        )
        with self.assertRaisesRegex(verifier.AttestationError, "ELF header is invalid"):
            verifier.attest(
                self.contract,
                "Linux-x86_64",
                materialize_root=os.path.join(self.root, "runtime"),
                downloader=self.archive_downloader(archive_path),
            )

    def test_linux_pinned_archive_rejects_dynamic_interpreter(self):
        archive_path, descriptor, closure_sha256 = self.archive_fixture(
            content=self.static_elf(interpreter=True)
        )
        self.write_contract(
            candidate_by_platform={},
            pinned_archive_by_platform={"Linux-x86_64": descriptor},
            require_exact_closure_sha256=True,
            closure_sha256_by_platform={"Linux-x86_64": closure_sha256},
        )
        with self.assertRaisesRegex(verifier.AttestationError, "dynamic interpreter"):
            verifier.attest(
                self.contract,
                "Linux-x86_64",
                materialize_root=os.path.join(self.root, "runtime"),
                downloader=self.archive_downloader(archive_path),
            )

    def test_linux_pinned_archive_rejects_dynamic_dependency(self):
        archive_path, descriptor, closure_sha256 = self.archive_fixture(content=self.static_elf(needed=True))
        self.write_contract(
            candidate_by_platform={},
            pinned_archive_by_platform={"Linux-x86_64": descriptor},
            require_exact_closure_sha256=True,
            closure_sha256_by_platform={"Linux-x86_64": closure_sha256},
        )
        with self.assertRaisesRegex(verifier.AttestationError, "dynamic dependency"):
            verifier.attest(
                self.contract,
                "Linux-x86_64",
                materialize_root=os.path.join(self.root, "runtime"),
                downloader=self.archive_downloader(archive_path),
            )

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

        with self.owned_lstat(0):
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

    def test_materialized_codex_binary_and_system_closure_are_attested(self):
        os.chmod(self.php, 0o500)
        binary_sha256 = hashlib.sha256(b"php-fixture").hexdigest()
        sealed = ["/usr/lib/libSystem.B.dylib"]
        closure_sha256 = verifier.closure_attestation(
            "codex",
            [os.path.realpath(self.php)],
            sealed,
            path_labels={os.path.realpath(self.php): "codex"},
        )
        self.write_reviewer_contract(
            codex_binary_sha256_by_platform={"Darwin-arm64": binary_sha256},
            codex_closure_sha256_by_platform={"Darwin-arm64": closure_sha256},
            codex_dynamic_dependency_policy="system_sealed_only_non_system_dependency_rejected",
        )

        self.assertEqual(
            os.path.realpath(self.php),
            verifier.attest_codex(
                self.contract,
                "Darwin-arm64",
                self.php,
                lambda _path: "\t/usr/lib/libSystem.B.dylib (compatibility version 1.0.0, current version 1.0.0)\n",
                expected_closure_sha256=closure_sha256,
            ),
        )

    def test_materialized_codex_rejects_explicit_expected_closure_mismatch(self):
        os.chmod(self.php, 0o500)
        binary_sha256 = hashlib.sha256(b"php-fixture").hexdigest()
        closure_sha256 = verifier.closure_attestation(
            "codex",
            [os.path.realpath(self.php)],
            [],
            path_labels={os.path.realpath(self.php): "codex"},
        )
        self.write_reviewer_contract(
            codex_binary_sha256_by_platform={"Darwin-arm64": binary_sha256},
            codex_closure_sha256_by_platform={"Darwin-arm64": closure_sha256},
            codex_dynamic_dependency_policy="system_sealed_only_non_system_dependency_rejected",
        )

        with self.assertRaisesRegex(verifier.AttestationError, "Codex closure policy binding mismatch"):
            verifier.attest_codex(
                self.contract,
                "Darwin-arm64",
                self.php,
                self.inspector,
                expected_closure_sha256="f" * 64,
            )

    def test_materialized_codex_rejects_binary_digest_mismatch(self):
        os.chmod(self.php, 0o500)
        closure_sha256 = verifier.closure_attestation(
            "codex",
            [os.path.realpath(self.php)],
            [],
            path_labels={os.path.realpath(self.php): "codex"},
        )
        self.write_reviewer_contract(
            codex_binary_sha256_by_platform={"Darwin-arm64": "0" * 64},
            codex_closure_sha256_by_platform={"Darwin-arm64": closure_sha256},
            codex_dynamic_dependency_policy="system_sealed_only_non_system_dependency_rejected",
        )

        with self.assertRaisesRegex(verifier.AttestationError, "Codex binary digest mismatch"):
            verifier.attest_codex(
                self.contract,
                "Darwin-arm64",
                self.php,
                self.inspector,
                expected_closure_sha256=closure_sha256,
            )

    def test_materialized_codex_rejects_non_system_dynamic_dependency(self):
        os.chmod(self.php, 0o500)
        dependency = os.path.join(self.root, "libfixture.dylib")
        with open(dependency, "wb") as stream:
            stream.write(b"dependency")
        os.chmod(dependency, 0o500)
        binary_sha256 = hashlib.sha256(b"php-fixture").hexdigest()
        closure_sha256 = verifier.closure_attestation(
            "codex",
            [os.path.realpath(self.php)],
            [],
            path_labels={os.path.realpath(self.php): "codex"},
        )
        self.write_reviewer_contract(
            codex_binary_sha256_by_platform={"Darwin-arm64": binary_sha256},
            codex_closure_sha256_by_platform={"Darwin-arm64": closure_sha256},
            codex_dynamic_dependency_policy="system_sealed_only_non_system_dependency_rejected",
        )

        def inspect(path):
            if path == os.path.realpath(self.php):
                return "\t%s (compatibility version 1.0.0, current version 1.0.0)\n" % dependency
            return ""

        with self.assertRaisesRegex(verifier.AttestationError, "non-system dynamic dependency"):
            verifier.attest_codex(self.contract, "Darwin-arm64", self.php, inspect)

    def test_materialized_codex_requires_private_exact_mode(self):
        os.chmod(self.php, 0o555)
        binary_sha256 = hashlib.sha256(b"php-fixture").hexdigest()
        self.write_reviewer_contract(
            codex_binary_sha256_by_platform={"Darwin-arm64": binary_sha256},
            codex_closure_sha256_by_platform={"Darwin-arm64": "0" * 64},
            codex_dynamic_dependency_policy="system_sealed_only_non_system_dependency_rejected",
        )
        with self.assertRaisesRegex(verifier.AttestationError, "ownership is invalid"):
            verifier.attest_codex(self.contract, "Darwin-arm64", self.php, self.inspector)

    def test_cli_defaults_to_php_attestation_and_prints_result(self):
        with mock.patch.object(verifier, "attest", return_value="/trusted/php") as attest:
            stdout = io.StringIO()
            with contextlib.redirect_stdout(stdout):
                result = verifier.main(
                    [
                        "--contract",
                        self.contract,
                        "--platform",
                        "Darwin-arm64",
                    ]
                )

        self.assertEqual(0, result)
        self.assertEqual("/trusted/php\n", stdout.getvalue())
        attest.assert_called_once_with(
            self.contract,
            "Darwin-arm64",
            materialize_root=None,
        )

    def test_cli_codex_dispatch_requires_path_and_passes_expected_closure(self):
        with mock.patch.object(verifier, "attest_codex", return_value="/trusted/codex") as attest:
            stdout = io.StringIO()
            with contextlib.redirect_stdout(stdout):
                result = verifier.main(
                    [
                        "--runtime",
                        "codex",
                        "--contract",
                        self.contract,
                        "--platform",
                        "Darwin-arm64",
                        "--path",
                        self.php,
                        "--expected-closure-sha256",
                        "a" * 64,
                    ]
                )

        self.assertEqual(0, result)
        self.assertEqual("/trusted/codex\n", stdout.getvalue())
        attest.assert_called_once_with(
            self.contract,
            "Darwin-arm64",
            self.php,
            expected_closure_sha256="a" * 64,
        )

    def test_cli_codex_rejects_missing_expected_closure(self):
        os.chmod(self.php, 0o500)
        binary_sha256 = hashlib.sha256(b"php-fixture").hexdigest()
        closure_sha256 = verifier.closure_attestation(
            "codex",
            [os.path.realpath(self.php)],
            [],
            path_labels={os.path.realpath(self.php): "codex"},
        )
        self.write_reviewer_contract(
            codex_binary_sha256_by_platform={"Darwin-arm64": binary_sha256},
            codex_closure_sha256_by_platform={"Darwin-arm64": closure_sha256},
            codex_dynamic_dependency_policy="system_sealed_only_non_system_dependency_rejected",
        )

        stdout = io.StringIO()
        stderr = io.StringIO()
        with mock.patch.object(verifier, "dependency_closure", return_value=([os.path.realpath(self.php)], [])):
            with contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr):
                result = verifier.main(
                    [
                        "--runtime",
                        "codex",
                        "--contract",
                        self.contract,
                        "--platform",
                        "Darwin-arm64",
                        "--path",
                        self.php,
                    ]
                )

        self.assertEqual(2, result)
        self.assertEqual("", stdout.getvalue())
        self.assertIn("trusted codex runtime rejected:", stderr.getvalue())

    def test_cli_codex_rejects_wrong_expected_closure(self):
        os.chmod(self.php, 0o500)
        binary_sha256 = hashlib.sha256(b"php-fixture").hexdigest()
        closure_sha256 = verifier.closure_attestation(
            "codex",
            [os.path.realpath(self.php)],
            [],
            path_labels={os.path.realpath(self.php): "codex"},
        )
        self.write_reviewer_contract(
            codex_binary_sha256_by_platform={"Darwin-arm64": binary_sha256},
            codex_closure_sha256_by_platform={"Darwin-arm64": closure_sha256},
            codex_dynamic_dependency_policy="system_sealed_only_non_system_dependency_rejected",
        )

        stdout = io.StringIO()
        stderr = io.StringIO()
        with contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr):
            result = verifier.main(
                [
                    "--runtime",
                    "codex",
                    "--contract",
                    self.contract,
                    "--platform",
                    "Darwin-arm64",
                    "--path",
                    self.php,
                    "--expected-closure-sha256",
                    "f" * 64,
                ]
            )

        self.assertEqual(2, result)
        self.assertEqual("", stdout.getvalue())
        self.assertIn("Codex closure policy binding mismatch", stderr.getvalue())

    def test_cli_rejects_php_path_before_attestation(self):
        stderr = io.StringIO()
        with mock.patch.object(verifier, "attest") as attest:
            with contextlib.redirect_stderr(stderr):
                result = verifier.main(
                    [
                        "--contract",
                        self.contract,
                        "--platform",
                        "Darwin-arm64",
                        "--path",
                        self.php,
                    ]
                )

        self.assertEqual(2, result)
        self.assertIn("trusted php runtime rejected: PHP attestation arguments are invalid", stderr.getvalue())
        attest.assert_not_called()


if __name__ == "__main__":
    unittest.main()
