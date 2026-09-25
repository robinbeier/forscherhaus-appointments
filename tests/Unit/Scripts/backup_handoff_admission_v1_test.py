import hashlib
import json
import os
import tempfile
import unittest
from datetime import datetime, timezone, timedelta
from importlib.util import module_from_spec, spec_from_file_location


ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '../../../'))
SPEC = spec_from_file_location('backup_handoff_admission_v1', os.path.join(ROOT, 'scripts/ops/libexec/backup_handoff_admission_v1.py'))
MODULE = module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class BackupHandoffAdmissionTest(unittest.TestCase):
    def setUp(self):
        if os.geteuid() != 0:
            self.skipTest('root context is required for the production trust-boundary fixture')
        self.tmp = tempfile.TemporaryDirectory(prefix='backup-handoff-admission-', dir='/root')
        self.backups = os.path.join(self.tmp.name, 'backups')
        self.evidence = os.path.join(self.tmp.name, 'evidence')
        os.makedirs(self.backups, mode=0o700)
        os.makedirs(self.evidence, mode=0o700)
        MODULE.BACKUP_ROOT = self.backups
        MODULE.EVIDENCE_ROOT = self.evidence
        self.backup_id = datetime.now(timezone.utc).replace(microsecond=0).strftime('%Y%m%dT%H%M%SZ')
        self.dump = b'dump-bytes'
        self.sha = hashlib.sha256(self.dump).hexdigest()
        self.dump_dir = os.path.join(self.backups, self.backup_id, 'db')
        os.makedirs(self.dump_dir, mode=0o700)
        self.dump_path = os.path.join(self.dump_dir, 'easyappointments.sql.gz')
        self._write(self.dump_path, self.dump)
        stamp = datetime.strptime(self.backup_id, '%Y%m%dT%H%M%SZ').strftime('%Y-%m-%dT%H:%M:%SZ')
        handoff = {'backup_set_id': self.backup_id, 'compressed_size_bytes': len(self.dump), 'dump_sha256': self.sha,
                   'schema': 'production_backup_set_handoff.v1', 'uncompressed_size_bytes': len(self.dump)}
        self._write(os.path.join(self.backups, 'last_backup_set.json'), self._canonical(handoff))
        self._write(os.path.join(self.backups, 'backup_continuity_state.json'), self._canonical({'handoff': handoff, 'schema': 'production_backup_continuity_state.v1', 'status': 'verified'}))
        marker = datetime.strptime(self.backup_id, '%Y%m%dT%H%M%SZ').strftime('%Y-%m-%dT%H:%M:%SZ') + '\n'
        self._write(os.path.join(self.backups, 'last_backup_success.utc'), marker.encode())
        self._write(os.path.join(self.backups, 'last_verify_success.utc'), (stamp + '\n').encode())
        attestation = {'attested_at_utc': stamp, 'dump': {'created_at_utc': marker.strip(), 'sha256': self.sha, 'size_bytes': len(self.dump), 'uncompressed_size_bytes': len(self.dump)}, 'schema': 'deployment_dump_attestation.v1', 'verification': {'gzip_verified': True, 'image': 'mariadb@sha256:2f2b6bbcdbaf88afe53b76cb8d73927b623559180c5ab15db2049736f32ec590', 'method': 'mariadb_10_11_isolated_restore_v1', 'restore_verified': True, 'restored_at_utc': stamp, 'restored_datadir_allocated_bytes': 1, 'restored_datadir_inode_count': 1, 'sha256_verified': True}}
        self._write(os.path.join(self.evidence, self.sha + '.json'), self._canonical(attestation))
        with open(os.path.join(self.backups, 'backup_continuity_state.json'), 'rb') as handle:
            self.continuity_sha = hashlib.sha256(handle.read()).hexdigest()

    def tearDown(self):
        self.tmp.cleanup()

    def _write(self, path, value):
        with open(path, 'wb') as handle:
            handle.write(value)
        os.chmod(path, 0o600)

    def _canonical(self, value):
        return (json.dumps(value, sort_keys=True, separators=(',', ':')) + '\n').encode('ascii')

    def test_admit_returns_private_dump_path(self):
        value = MODULE.admit(self.continuity_sha)
        self.assertEqual(self.dump_path, value['dump_path'])
        self.assertEqual(self.sha, value['dump_sha256'])

    def test_wrong_continuity_hash_is_rejected(self):
        with self.assertRaisesRegex(MODULE.AdmissionError, 'continuity_mismatch'):
            MODULE.admit('0' * 64)

    def test_dump_drift_is_rejected(self):
        self._write(self.dump_path, b'changed')
        with self.assertRaisesRegex(MODULE.AdmissionError, 'dump_mismatch'):
            MODULE.admit(self.continuity_sha)

    def test_symlinked_dump_is_rejected(self):
        real = self.dump_path + '.real'
        os.rename(self.dump_path, real)
        os.symlink(real, self.dump_path)
        with self.assertRaisesRegex(MODULE.AdmissionError, 'dump_mismatch'):
            MODULE.admit(self.continuity_sha)

    def test_stale_attestation_is_rejected(self):
        path = os.path.join(self.evidence, self.sha + '.json')
        with open(path, 'rb') as handle:
            value = json.loads(handle.read())
        old = (datetime.now(timezone.utc) - timedelta(hours=2)).replace(microsecond=0).strftime('%Y-%m-%dT%H:%M:%SZ')
        value['attested_at_utc'] = old
        self._write(path, self._canonical(value))
        with self.assertRaisesRegex(MODULE.AdmissionError, 'stale_restore'):
            MODULE.admit(self.continuity_sha)

    def test_stale_created_at_is_rejected(self):
        path = os.path.join(self.evidence, self.sha + '.json')
        with open(path, 'rb') as handle:
            value = json.loads(handle.read())
        value['dump']['created_at_utc'] = '2020-01-01T00:00:00Z'
        self._write(path, self._canonical(value))
        with self.assertRaisesRegex(MODULE.AdmissionError, 'stale_restore'):
            MODULE.admit(self.continuity_sha)

    def test_wrong_producer_marker_is_rejected(self):
        self._write(os.path.join(self.backups, 'last_backup_success.utc'), b'2020-01-01T00:00:00Z\n')
        with self.assertRaisesRegex(MODULE.AdmissionError, 'marker_mismatch'):
            MODULE.admit(self.continuity_sha)

    def test_noncanonical_attestation_is_rejected(self):
        path = os.path.join(self.evidence, self.sha + '.json')
        with open(path, 'rb') as handle:
            value = json.loads(handle.read())
        self._write(path, json.dumps(value).encode())
        with self.assertRaisesRegex(MODULE.AdmissionError, 'attestation_invalid'):
            MODULE.admit(self.continuity_sha)

    def test_dump_digest_reads_in_bounded_chunks(self):
        calls = []
        original_read = MODULE.os.read

        def bounded_read(fd, size):
            calls.append(size)
            return original_read(fd, size)

        MODULE.os.read = bounded_read
        try:
            MODULE.admit(self.continuity_sha)
        finally:
            MODULE.os.read = original_read
        self.assertTrue(calls)
        self.assertLessEqual(max(calls), MODULE.CHUNK)

    def test_cli_is_secret_free(self):
        self.assertNotIn(self.sha, json.dumps({'schema': 'backup_handoff_admission.v1', 'status': 'failed', 'result_class': 'input_invalid'}))


if __name__ == '__main__':
    unittest.main()
