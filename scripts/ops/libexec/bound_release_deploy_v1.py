#!/usr/bin/python3
"""One admitted invocation of the existing production deploy primitive.

This script is streamed from a reviewed checkout.  The two read-only admission
modules are installed separately and bound to that same checkout by SHA-256.
"""

import argparse
import fcntl
import hashlib
import hmac
import json
import os
import re
import stat
import subprocess
import sys
import types


PAIR_HELPER = '/usr/local/libexec/fh/release_pair_admission_v1.py'
BACKUP_HELPER = '/usr/local/libexec/fh/backup_handoff_admission_v1.py'
DEPLOY = '/root/deploy_ea.sh'
LOCK = '/var/lib/fh-deploy-orchestrator/locks/fh-production-change.lock'
CONTINUITY = '/root/backups/easyappointments/backup_continuity_state.json'
MARKER = '/var/www/html/easyappointments/_RELEASE'
CONFIGS = (
    ('/var/www/html/easyappointments/config.php', 0o440, 0, 33),
    ('/etc/fh/healthz.token', 0o600, 0, 0),
    ('/etc/fh/zero-surprise-predeploy.ini', 0o600, 0, 0),
    ('/etc/fh/zero-surprise-canary.ini', 0o600, 0, 0),
    ('/etc/fh/zero-surprise-incident-webhook.ini', 0o600, 0, 0),
)
RECOVERY = (
    '/var/lib/fh-deploy-orchestrator/active-run.json',
    '/var/lib/fh-deploy-orchestrator/csp-report-only-pilot.state.json',
    '/var/lib/fh-defense-ordinary/run.pending',
    '/var/lib/fh-defense-ordinary/request-unconfirmed',
    '/var/lib/fh-defense-ordinary/state.json',
    '/var/lib/fh-defense-ordinary/defense-verification.json',
    '/var/lib/fh-defense-ordinary/defense-verification.json.tmp',
    '/var/lib/fh-defense-ordinary/sessions.json',
    '/var/lib/fh-defense-ordinary/sessions.json.tmp',
)
HEX64 = re.compile(r'[0-9a-f]{64}\Z')
RUN_ID = re.compile(r'[0-9a-f]{32}\Z')
RELEASE_ID = re.compile(r'ea_[A-Za-z0-9_]+\Z')
RECEIPT_OUTCOMES = {
    'succeeded': 0,
    'failed_pre_switch': 30,
    'internal_rollback_succeeded': 30,
    'rollback_failed_or_unverifiable': 31,
    'switch_recovery_required': 32,
    'interrupted_pre_switch': 143,
}


class AdmissionError(Exception):
    def __init__(self, result_class, code=70):
        super().__init__(result_class)
        self.result_class = result_class
        self.code = code


def fail(result_class, code=70):
    raise AdmissionError(result_class, code)


def identity(value):
    return (value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_gid,
            value.st_nlink, value.st_size, value.st_mtime_ns, value.st_ctime_ns)


def trusted_parent(path, final_mode=None):
    if not path.startswith('/') or '//' in path or '/./' in path or '/../' in path:
        fail('path_unsafe')
    parts = [part for part in path.split('/') if part]
    cursor = ''
    for index, part in enumerate(parts):
        cursor += '/' + part
        value = os.lstat(cursor)
        mode = stat.S_IMODE(value.st_mode)
        if (not stat.S_ISDIR(value.st_mode) or value.st_uid != 0 or value.st_gid != 0 or
                mode & 0o022 or (index == len(parts) - 1 and final_mode is not None and mode != final_mode)):
            fail('path_unsafe')


def read_bound_file(path, maximum, mode, uid, gid):
    trusted_parent(os.path.dirname(path))
    before = os.lstat(path)
    if (not stat.S_ISREG(before.st_mode) or before.st_uid != uid or before.st_gid != gid or
            stat.S_IMODE(before.st_mode) != mode or before.st_nlink != 1 or
            before.st_size <= 0 or before.st_size > maximum):
        fail('file_unsafe')
    fd = os.open(path, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        opened = os.fstat(fd)
        if identity(before) != identity(opened):
            fail('identity_drift')
        contents = bytearray()
        while len(contents) < opened.st_size:
            chunk = os.read(fd, min(1024 * 1024, opened.st_size - len(contents)))
            if not chunk:
                fail('identity_drift')
            contents.extend(chunk)
        if os.read(fd, 1):
            fail('identity_drift')
        after = os.fstat(fd)
        current = os.lstat(path)
        if identity(opened) != identity(after) or identity(after) != identity(current):
            fail('identity_drift')
        return bytes(contents), identity(opened)
    finally:
        os.close(fd)


def bound_hash(path, maximum, mode, uid, gid, expected):
    data, observed = read_bound_file(path, maximum, mode, uid, gid)
    digest = hashlib.sha256(data).hexdigest()
    if not hmac.compare_digest(digest, expected):
        fail('tool_hash_mismatch')
    return data, observed


def checked_module(name, path, digest):
    source, _ = bound_hash(path, 1024 * 1024, 0o555, 0, 0, digest)
    module = types.ModuleType(name)
    module.__file__ = path
    exec(compile(source, path, 'exec'), module.__dict__)
    return module


def active_release(expected):
    data, observed = read_bound_file(MARKER, 512, 0o644, 0, 0)
    if re.fullmatch(rb'ea_[A-Za-z0-9_]+  20[0-9]{2}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\n', data) is None:
        fail('active_release_invalid')
    if data.split(b'  ', 1)[0] != expected.encode('ascii'):
        fail('active_release_mismatch')
    return observed


def open_lock():
    trusted_parent(os.path.dirname(LOCK), 0o700)
    before = os.lstat(LOCK)
    if (not stat.S_ISREG(before.st_mode) or before.st_uid != 0 or before.st_gid != 0 or
            stat.S_IMODE(before.st_mode) != 0o600 or before.st_nlink != 1 or before.st_size != 0):
        fail('lock_unsafe')
    fd = os.open(LOCK, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW)
    try:
        opened = os.fstat(fd)
        if identity(before) != identity(opened):
            fail('lock_unsafe')
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            fail('lock_busy', 75)
        if identity(opened) != identity(os.lstat(LOCK)):
            fail('lock_unsafe')
        return fd
    except BaseException:
        os.close(fd)
        raise


def no_recovery():
    for path in RECOVERY:
        if os.path.lexists(path):
            fail('recovery_pending')


def config_bindings():
    bindings = []
    for path, mode, uid, gid in CONFIGS:
        data, observed = read_bound_file(path, 1024 * 1024, mode, uid, gid)
        bindings.append((observed, hashlib.sha256(data).digest()))
    return tuple(bindings)


def reserve_intent(path, release, commit, run_id, bindings):
    trusted_parent('/root', 0o700)
    if os.path.lexists(path):
        fail('intent_occupied')
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC | os.O_NOFOLLOW, 0o600)
    try:
        data = json.dumps({'schema': 'bound_release_deploy_intent.v1', 'release': release,
                           'commit': commit, 'run_id': run_id, 'bindings': bindings},
                          sort_keys=True, separators=(',', ':')).encode('ascii') + b'\n'
        view = memoryview(data)
        while view:
            written = os.write(fd, view)
            if written <= 0:
                fail('intent_unknown')
            view = view[written:]
        os.fsync(fd)
    finally:
        os.close(fd)
    directory = os.open('/root', os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW)
    try:
        os.fsync(directory)
    finally:
        os.close(directory)


def checked_receipt(path, observed_exit):
    try:
        data, _ = read_bound_file(path, 256, 0o600, 0, 0)
    except (AdmissionError, OSError):
        fail('result_unknown')
    try:
        value = json.loads(data)
    except json.JSONDecodeError:
        fail('result_unknown')
    if (not isinstance(value, dict) or set(value) != {'schema', 'outcome', 'exit_code'} or
            value.get('schema') != 'deploy_result.v1' or
            value.get('outcome') not in RECEIPT_OUTCOMES or
            type(value.get('exit_code')) is not int or
            value['exit_code'] != RECEIPT_OUTCOMES[value['outcome']] or
            value['exit_code'] != observed_exit):
        fail('result_unknown')
    return value


def run(args):
    if os.geteuid() != 0 or os.uname().nodename.split('.')[0] != 'booking-server':
        fail('host_invalid')
    if (not RELEASE_ID.fullmatch(args.release) or not RELEASE_ID.fullmatch(args.expected_active_release) or
            not RUN_ID.fullmatch(args.run_id) or not re.fullmatch(r'[0-9a-f]{40}', args.commit) or
            not all(HEX64.fullmatch(value) for value in (args.archive_sha, args.provenance_sha,
                    args.continuity_sha, args.deploy_sha, args.pair_helper_sha, args.backup_helper_sha)) or
            args.archive_size <= 0 or args.provenance_size <= 0):
        fail('input_invalid')
    if args.release == args.expected_active_release:
        fail('same_release_invalid')
    pair = checked_module('release_pair_admission_v1', PAIR_HELPER, args.pair_helper_sha)
    backup = checked_module('backup_handoff_admission_v1', BACKUP_HELPER, args.backup_helper_sha)
    trusted_parent('/root', 0o700)
    bound_hash(DEPLOY, 1024 * 1024, 0o700, 0, 0, args.deploy_sha)
    result_path = '/root/fh-deploy-result-' + args.run_id + '.json'
    # Release IDs are immutable. Keep the reservation across run IDs so an
    # unknown transport result cannot launch the same candidate a second time.
    intent_path = '/root/fh-deploy-intent-' + args.release + '.json'
    lock_fd = open_lock()
    try:
        no_recovery()
        active_release(args.expected_active_release)
        if os.path.lexists(result_path) or os.path.lexists(intent_path):
            fail('receipt_or_intent_occupied')
        before = config_bindings()
        try:
            pair.verify_pair(args.release, args.archive_sha, args.archive_size,
                             args.provenance_sha, args.provenance_size)
        except pair.PairAdmissionError as error:
            fail('pair_' + error.result_class)
        try:
            handoff = backup.admit(args.continuity_sha)
        except backup.AdmissionError as error:
            fail('backup_' + error.result_class)
        if config_bindings() != before:
            fail('config_drift')
        no_recovery()
        active_release(args.expected_active_release)
        bound_hash(DEPLOY, 1024 * 1024, 0o700, 0, 0, args.deploy_sha)
        bindings = {
            'archive_sha256': args.archive_sha,
            'provenance_sha256': args.provenance_sha,
            'continuity_sha256': args.continuity_sha,
            'deploy_sha256': args.deploy_sha,
            'pair_helper_sha256': args.pair_helper_sha,
            'backup_helper_sha256': args.backup_helper_sha,
            'config': [{'path': spec[0], 'identity': list(observed[0]),
                        'sha256': observed[1].hex()} for spec, observed in zip(CONFIGS, before)],
        }
        reserve_intent(intent_path, args.release, args.commit, args.run_id, bindings)
        command = [DEPLOY, '--rel', args.release,
                   '--healthz-token-file', CONFIGS[1][0],
                   '--zero-surprise-dump-file', handoff['dump_path'],
                   '--zero-surprise-predeploy-credentials-file', CONFIGS[2][0],
                   '--zero-surprise-canary-credentials-file', CONFIGS[3][0],
                   '--zero-surprise-incident-webhook-file', CONFIGS[4][0],
                   '--result-file', result_path]
        environment = dict(os.environ)
        environment['ORDINARY_CHANGE_LOCK_FD'] = str(lock_fd)
        child = subprocess.run(command, env=environment, pass_fds=(lock_fd,),
                               stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                               stderr=subprocess.DEVNULL, check=False)
        receipt = checked_receipt(result_path, child.returncode)
        if receipt['exit_code'] == 0:
            active_release(args.release)
            return 'deployed', 0
        if receipt['exit_code'] == 30:
            return 'confirmed_failed', 30
        return 'recovery_required', receipt['exit_code']
    finally:
        os.close(lock_fd)


def main():
    parser = argparse.ArgumentParser(description='Bound one existing production release deployment')
    parser.add_argument('--release', required=True)
    parser.add_argument('--expected-active-release', required=True)
    parser.add_argument('--commit', required=True)
    parser.add_argument('--archive-sha', required=True)
    parser.add_argument('--archive-size', required=True, type=int)
    parser.add_argument('--provenance-sha', required=True)
    parser.add_argument('--provenance-size', required=True, type=int)
    parser.add_argument('--continuity-sha', required=True)
    parser.add_argument('--deploy-sha', required=True)
    parser.add_argument('--pair-helper-sha', required=True)
    parser.add_argument('--backup-helper-sha', required=True)
    parser.add_argument('--run-id', required=True)
    args = parser.parse_args()
    try:
        result_class, code = run(args)
        status = 'passed' if code == 0 else 'failed'
    except AdmissionError as error:
        result_class, code, status = error.result_class, error.code, 'failed'
    except (OSError, ValueError, TypeError, subprocess.SubprocessError):
        result_class, code, status = 'result_unknown', 70, 'failed'
    print(json.dumps({'schema': 'bound_release_deploy.v1', 'status': status,
                      'result_class': result_class}, sort_keys=True, separators=(',', ':')))
    return code


if __name__ == '__main__':
    sys.exit(main())
