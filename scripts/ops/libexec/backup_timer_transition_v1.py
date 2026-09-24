#!/usr/bin/python3
"""Bounded, operator-only pause/restore for the backup continuity timer."""

import argparse
import errno
import fcntl
import json
import os
import re
import stat
import subprocess
import sys
import time


SCHEMA = 'backup_timer_transition.v1'
ORCHESTRATOR_ROOT = '/var/lib/fh-deploy-orchestrator'
LOCK_PATH = ORCHESTRATOR_ROOT + '/locks/fh-production-change.lock'
STATE_PATH = ORCHESTRATOR_ROOT + '/backup-timer-transition.v1.json'
BACKUP_ROOT = '/root/backups/easyappointments'
CONTINUITY_PATH = BACKUP_ROOT + '/backup_continuity_state.json'
TIMER = 'fh-backup-set-continuity.timer'
SERVICES = ('fh-backup-set-producer.service', 'fh-backup-set-restore-verify.service')
SYSTEMCTL = '/usr/bin/systemctl'
RUN_ID = re.compile(r'\A[0-9a-f]{32}\Z')
MAX_STATE = 4096
PROCESS_PATTERNS = (
    re.compile(r'(^|/)(?:deploy_ea\.sh|deployment_host_runner_v1\.php|zero_surprise_replay\.php)(?:\s|$)'),
    re.compile(r'(^|/)prod_(?:customers|provider)_ui_smoke\.sh(?:\s|$)'),
    re.compile(r'(^|/)(?:mysqldump|mariadb-dump|backup_easyappointments\.sh|backup_ea\.sh|'
               r'ea_restore_verify_latest\.sh|backup_set_producer_v1\.py|'
               r'fh-backup-set-producer-v1|fh-backup-set-producer-supervisor-v1|'
               r'import_prod_backup\.sh)(?:\s|$)'),
    re.compile(r'(^|/)prod_(?:session|build_cache|release_archive_dump)_retention\.sh(?:\s|$)'),
)
RECOVERY_MARKERS = (
    ORCHESTRATOR_ROOT + '/active-run.json',
    ORCHESTRATOR_ROOT + '/csp-report-only-pilot.state.json',
    '/var/lib/fh-defense-ordinary/run.pending',
    '/var/lib/fh-defense-ordinary/request-unconfirmed',
    '/var/lib/fh-defense-ordinary/state.json',
    '/var/lib/fh-defense-ordinary/defense-verification.json',
    '/var/lib/fh-defense-ordinary/defense-verification.json.tmp',
    '/var/lib/fh-defense-ordinary/sessions.json',
    '/var/lib/fh-defense-ordinary/sessions.json.tmp',
)
BEFORE_STATE = {'enabled': 'enabled', 'active': 'active', 'substate': 'waiting'}
PAUSED_STATE = {'enabled': 'disabled', 'active': 'inactive', 'substate': 'dead'}


class TransitionError(Exception):
    def __init__(self, result_class, code=70):
        super().__init__(result_class)
        self.result_class, self.code = result_class, code


def fail(name, code=70):
    raise TransitionError(name, code)


def ident(s):
    return (s.st_dev, s.st_ino, s.st_mode, s.st_uid, s.st_gid, s.st_nlink, s.st_size)


def trusted_dir(path, mode=None):
    try:
        s = os.lstat(path)
    except OSError:
        fail('canonical_path_invalid')
    if (not stat.S_ISDIR(s.st_mode) or s.st_uid != 0 or s.st_gid != 0 or
            stat.S_IMODE(s.st_mode) & 0o022 or (mode is not None and stat.S_IMODE(s.st_mode) != mode)):
        fail('canonical_path_invalid')
    return s


def open_lock():
    # Validate every canonical ancestor before opening the already-created lock.
    trusted_dir('/var')
    trusted_dir('/var/lib')
    trusted_dir(ORCHESTRATOR_ROOT, 0o700)
    trusted_dir(os.path.dirname(LOCK_PATH), 0o700)
    try:
        before = os.lstat(LOCK_PATH)
        if (not stat.S_ISREG(before.st_mode) or before.st_uid != 0 or before.st_gid != 0 or
                stat.S_IMODE(before.st_mode) != 0o600 or before.st_nlink != 1 or before.st_size != 0):
            fail('lock_identity_invalid')
        fd = os.open(LOCK_PATH, os.O_RDWR | os.O_CLOEXEC | os.O_NOFOLLOW)
        opened = os.fstat(fd)
        if ident(before) != ident(opened):
            os.close(fd); fail('lock_identity_invalid')
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError as exc:
            os.close(fd)
            if exc.errno in (errno.EACCES, errno.EAGAIN):
                fail('lock_busy', 75)
            fail('lock_failed')
        current = os.lstat(LOCK_PATH)
        if ident(opened) != ident(current):
            fcntl.flock(fd, fcntl.LOCK_UN); os.close(fd); fail('lock_identity_changed')
        return fd, ident(opened)
    except FileNotFoundError:
        fail('lock_identity_invalid')


def systemctl(*args, allow_interrupt=False):
    try:
        return subprocess.run([SYSTEMCTL, *args], check=False, text=True,
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=90)
    except (OSError, subprocess.TimeoutExpired, KeyboardInterrupt):
        fail('transition_interrupted' if allow_interrupt else 'systemctl_unknown', 75)


def timer_state():
    values = {}
    for action, key in (('is-enabled', 'enabled'), ('is-active', 'active')):
        p = systemctl(action, TIMER)
        value = p.stdout.strip()
        # systemctl uses 1/3 for disabled/inactive; lightweight test doubles may return 0.
        expected_codes = {'enabled': (0,), 'disabled': (0, 1), 'active': (0,), 'inactive': (0, 3)}
        if value not in expected_codes or p.returncode not in expected_codes[value]:
            fail('timer_state_unknown', 75)
        values[key] = value
    p = systemctl('show', TIMER, '--property=SubState', '--value')
    sub = p.stdout.strip()
    if p.returncode != 0 or sub not in ('waiting', 'dead'):
        fail('timer_state_unknown', 75)
    values['substate'] = sub
    return values


def assert_service_quiet():
    for service in SERVICES:
        result = systemctl('show', service, '--property=LoadState,ActiveState,SubState')
        values = dict(line.split('=', 1) for line in result.stdout.splitlines() if '=' in line)
        if (result.returncode != 0 or set(values) != {'LoadState', 'ActiveState', 'SubState'} or
                values['LoadState'] != 'loaded'):
            fail('backup_service_unknown', 75)
        if values['ActiveState'] != 'inactive' or values['SubState'] != 'dead':
            fail('backup_service_active', 75)


def assert_quiet():
    try:
        for marker in RECOVERY_MARKERS:
            if os.path.lexists(marker):
                fail('recovery_marker_present', 75)
    except OSError:
        fail('activity_state_unknown', 75)
    try:
        with os.scandir('/proc') as entries:
            for entry in entries:
                if not entry.name.isdigit() or int(entry.name) == os.getpid():
                    continue
                try:
                    with open(os.path.join('/proc', entry.name, 'status'), 'rb') as handle:
                        status = handle.read(4097)
                    uid_rows = [line.split() for line in status.splitlines() if line.startswith(b'Uid:')]
                    if len(status) > 4096 or len(uid_rows) != 1 or len(uid_rows[0]) != 5:
                        fail('activity_state_unknown', 75)
                    if not all(uid == b'0' for uid in uid_rows[0][1:]):
                        continue
                    with open(os.path.join('/proc', entry.name, 'cmdline'), 'rb') as handle:
                        raw = handle.read(131073)
                except (FileNotFoundError, ProcessLookupError):
                    continue
                except OSError:
                    fail('activity_state_unknown', 75)
                if len(raw) > 131072:
                    fail('activity_state_unknown', 75)
                command = raw.replace(b'\0', b' ').decode('utf-8', 'replace').strip()
                if command and any(pattern.search(command) for pattern in PROCESS_PATTERNS):
                    fail('activity_present', 75)
    except OSError:
        fail('activity_state_unknown', 75)


def assert_backup_continuity_verified():
    trusted_dir('/root', 0o700)
    trusted_dir(os.path.dirname(BACKUP_ROOT), 0o700)
    trusted_dir(BACKUP_ROOT, 0o700)
    try:
        if any(name.startswith('.backup_continuity_state.json.tmp-')
               for name in os.listdir(BACKUP_ROOT)):
            fail('backup_continuity_unresolved', 75)
        before = os.lstat(CONTINUITY_PATH)
        if (not stat.S_ISREG(before.st_mode) or before.st_uid != 0 or before.st_gid != 0 or
                stat.S_IMODE(before.st_mode) != 0o600 or before.st_nlink != 1 or
                before.st_size == 0 or before.st_size > 8192):
            fail('backup_continuity_invalid', 75)
        fd = os.open(CONTINUITY_PATH, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW)
        try:
            if ident(before) != ident(os.fstat(fd)):
                fail('backup_continuity_invalid', 75)
            raw = os.read(fd, 8193)
        finally:
            os.close(fd)
        if ident(before) != ident(os.lstat(CONTINUITY_PATH)):
            fail('backup_continuity_invalid', 75)
        value = json.loads(raw.decode('ascii'))
    except (OSError, ValueError, UnicodeError):
        fail('backup_continuity_invalid', 75)
    if not isinstance(value, dict) or set(value) != {'handoff', 'schema', 'status'}:
        fail('backup_continuity_invalid', 75)
    handoff = value['handoff']
    if (value['schema'] != 'production_backup_continuity_state.v1' or
            value['status'] not in ('pending', 'verified') or
            not isinstance(handoff, dict) or
            set(handoff) != {'backup_set_id', 'compressed_size_bytes', 'dump_sha256',
                             'schema', 'uncompressed_size_bytes'} or
            handoff['schema'] != 'production_backup_set_handoff.v1' or
            not isinstance(handoff['backup_set_id'], str) or
            re.fullmatch(r'20[0-9]{6}T[0-9]{6}Z', handoff['backup_set_id']) is None or
            not isinstance(handoff['dump_sha256'], str) or
            re.fullmatch(r'[0-9a-f]{64}', handoff['dump_sha256']) is None or
            type(handoff['compressed_size_bytes']) is not int or
            not (0 < handoff['compressed_size_bytes'] <= 16 * 1024 * 1024 * 1024) or
            type(handoff['uncompressed_size_bytes']) is not int or
            not (0 < handoff['uncompressed_size_bytes'] <= 64 * 1024 * 1024 * 1024) or
            (json.dumps(value, sort_keys=True, separators=(',', ':')) + '\n').encode('ascii') != raw):
        fail('backup_continuity_invalid', 75)
    if value['status'] != 'verified':
        fail('backup_continuity_pending', 75)


def read_state():
    try:
        before = os.lstat(STATE_PATH)
        if (not stat.S_ISREG(before.st_mode) or before.st_uid != 0 or before.st_gid != 0 or
                stat.S_IMODE(before.st_mode) != 0o600 or before.st_nlink != 1 or
                before.st_size == 0 or before.st_size > MAX_STATE):
            fail('state_invalid')
        fd = os.open(STATE_PATH, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW)
        try:
            if ident(before) != ident(os.fstat(fd)):
                fail('state_invalid')
            data = os.read(fd, MAX_STATE + 1)
        finally:
            os.close(fd)
        if ident(before) != ident(os.lstat(STATE_PATH)):
            fail('state_invalid')
        value = json.loads(data.decode('ascii'))
        if (not isinstance(value, dict) or
                set(value) != {'schema', 'run_id', 'phase', 'lock_dev', 'lock_ino', 'timer_before', 'created_at'} or
                value['schema'] != SCHEMA or not isinstance(value['run_id'], str) or
                not RUN_ID.fullmatch(value['run_id']) or value['phase'] != 'paused' or
                type(value['lock_dev']) is not int or type(value['lock_ino']) is not int or
                type(value['created_at']) is not int or value['created_at'] < 0 or
                value['timer_before'] != BEFORE_STATE or
                (json.dumps(value, sort_keys=True, separators=(',', ':')) + '\n').encode('ascii') != data):
            fail('state_invalid')
        return value
    except FileNotFoundError:
        return None
    except (OSError, ValueError, UnicodeError):
        fail('state_invalid')


def write_state(run_id, before, lock_identity):
    payload = {'schema': SCHEMA, 'run_id': run_id, 'phase': 'paused',
               'lock_dev': lock_identity[0], 'lock_ino': lock_identity[1],
               'timer_before': before, 'created_at': int(time.time())}
    encoded = (json.dumps(payload, sort_keys=True, separators=(',', ':')) + '\n').encode('ascii')
    try:
        fd = os.open(STATE_PATH, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC | os.O_NOFOLLOW, 0o600)
        try:
            if os.write(fd, encoded) != len(encoded):
                fail('state_write_failed')
            os.fchown(fd, 0, 0)
            os.fchmod(fd, 0o600)
            os.fsync(fd)
        finally:
            os.close(fd)
        fsync_state_directory()
    except FileExistsError:
        fail('transition_unresolved', 75)
    except OSError:
        fail('state_write_failed')


def settle_state():
    try:
        os.unlink(STATE_PATH)
        fsync_state_directory()
    except FileNotFoundError:
        fail('state_invalid')
    except OSError:
        fail('state_settle_failed')


def fsync_state_directory():
    fd = os.open(ORCHESTRATOR_ROOT, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def transition(action, run_id):
    if not RUN_ID.fullmatch(run_id):
        fail('run_id_invalid')
    fd, lock_identity = open_lock()
    try:
        # The lock is deliberately acquired anew for each action invocation.
        assert_quiet()
        assert_backup_continuity_verified()
        assert_service_quiet()
        current = timer_state()
        state = read_state()
        if action == 'pause':
            if state is not None:
                fail('transition_unresolved', 75)
            if current != BEFORE_STATE:
                fail('timer_state_unexpected', 75)
            write_state(run_id, current, lock_identity)
            result = systemctl('disable', '--now', TIMER, allow_interrupt=True)
            after = timer_state()
            if result.returncode != 0 or after != PAUSED_STATE:
                fail('transition_unknown', 75)
            assert_service_quiet()
            return 'paused'
        if state is None or state['run_id'] != run_id:
            fail('restore_not_authorized', 75)
        if state.get('lock_dev') != lock_identity[0] or state.get('lock_ino') != lock_identity[1]:
            fail('lock_identity_changed', 75)
        if current != PAUSED_STATE:
            fail('timer_state_unexpected', 75)
        result = systemctl('enable', '--now', TIMER, allow_interrupt=True)
        after = timer_state()
        if result.returncode != 0 or after != state['timer_before']:
            fail('transition_unknown', 75)
        assert_service_quiet()
        settle_state()
        return 'restored'
    finally:
        fcntl.flock(fd, fcntl.LOCK_UN)
        os.close(fd)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('action', choices=('pause', 'restore'))
    parser.add_argument('run_id')
    args = parser.parse_args()
    try:
        outcome = transition(args.action, args.run_id)
        receipt = {'schema': SCHEMA, 'status': 'passed', 'result_class': outcome, 'action': args.action,
                   'run_id': args.run_id}
        print(json.dumps(receipt, sort_keys=True, separators=(',', ':')))
        return 0
    except TransitionError as exc:
        print(json.dumps({'schema': SCHEMA, 'status': 'failed', 'result_class': exc.result_class,
                          'action': args.action, 'run_id': args.run_id}, sort_keys=True, separators=(',', ':')))
        return exc.code
    except Exception:
        print(json.dumps({'schema': SCHEMA, 'status': 'failed', 'result_class': 'internal_unknown',
                          'action': args.action, 'run_id': args.run_id}, sort_keys=True, separators=(',', ':')))
        return 70


if __name__ == '__main__':
    sys.exit(main())
