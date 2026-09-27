#!/usr/bin/python3
"""One-pass, release-directory-only production cleanup.

The deliberately inactive general retention service is not involved.  This
operator tool imports only a hash-pinned copy of its audited filesystem and
lock primitives; archives, dumps, failed releases, and stages are never
deletion targets.
"""

import datetime
import fcntl
import hashlib
import json
import os
import pwd
import re
import socket
import stat
import sys
import types


SCHEMA = 'manual_release_cleanup.v1'
HOST = 'booking-server'
WEB_ROOT = '/var/www/html'
RELEASES_ROOT = '/root/releases'
STATE_ROOT = '/var/lib/fh-release-retention'
HELPER_PATH = '/usr/local/libexec/fh-release-archive-dump-retention-v1'
HELPER_SHA256 = 'e5e29a78eee9d7659df36caac587f194af752e83962edb237912e5da37b493ac'
MIN_AGE_SECONDS = 7 * 86400
MAX_PER_PASS = 4
PREVIOUS = re.compile(r'easyappointments_prev_([A-Za-z0-9._-]{1,128})\Z')
SHA256 = re.compile(r'[0-9a-f]{64}\Z')


class CleanupError(Exception):
    def __init__(self, reason, code=70):
        super().__init__(reason)
        self.reason = reason
        self.code = code


def reject(reason, code=70):
    raise CleanupError(reason, code)


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':')).encode() + b'\n'


def load_pinned_helper(path=HELPER_PATH):
    fd = os.open(path, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        before = os.fstat(fd)
        if (not stat.S_ISREG(before.st_mode) or before.st_uid != 0 or before.st_gid != 0
                or stat.S_IMODE(before.st_mode) != 0o555 or before.st_nlink != 1
                or before.st_size < 1 or before.st_size > 1_048_576):
            reject('helper_identity_invalid')
        source = bytearray()
        while len(source) <= 1_048_576:
            part = os.read(fd, 65_536)
            if not part:
                break
            source.extend(part)
        after = os.fstat(fd)
        if (len(source) != before.st_size or before.st_dev != after.st_dev
                or before.st_ino != after.st_ino or before.st_mtime_ns != after.st_mtime_ns
                or before.st_ctime_ns != after.st_ctime_ns
                or hashlib.sha256(source).hexdigest() != HELPER_SHA256):
            reject('helper_hash_mismatch')
    finally:
        os.close(fd)
    helper = types.ModuleType('pinned_release_retention')
    helper.__file__ = path
    exec(compile(bytes(source), path, 'exec'), helper.__dict__)
    return helper


def archive_identity(helper, releases, release_id):
    leaf = release_id + '.tar.gz'
    try:
        before = os.stat(leaf, dir_fd=releases, follow_symlinks=False)
    except FileNotFoundError:
        reject('candidate_archive_missing')
    if (not stat.S_ISREG(before.st_mode) or before.st_uid != 0 or before.st_gid != 0
            or stat.S_IMODE(before.st_mode) != 0o600 or before.st_nlink != 1
            or before.st_size < 1):
        reject('candidate_archive_invalid')
    return helper.file_identity(before)


def candidate_record(helper, web, releases, name, web_uid, device, now_ns):
    tree = helper.validate_candidate_tree(web, name, {0, web_uid}, device)
    age_ns = now_ns - tree['mtime_ns']
    if age_ns < MIN_AGE_SECONDS * 1_000_000_000:
        return None
    candidate = helper.open_child_directory(web, name)
    try:
        contained_release = helper.read_release_marker(candidate)
    finally:
        os.close(candidate)
    archive = archive_identity(helper, releases, contained_release)
    return {
        'name': name,
        'contained_release': contained_release,
        'age_days': age_ns // (86400 * 1_000_000_000),
        'tree': tree,
        'archive_identity': archive,
    }


def collect(helper, web, releases, state, current, rollback, web_uid):
    device = os.fstat(web).st_dev
    if os.fstat(state).st_dev != device:
        reject('filesystem_mismatch')
    if any(name.startswith('.pending-') for name in os.listdir(state)):
        reject('pending_cleanup_unresolved')
    now_ns = int(datetime.datetime.now(datetime.timezone.utc).timestamp() * 1_000_000_000)
    protected = 'easyappointments_prev_' + current
    records = []
    names = os.listdir(web)
    if protected not in names:
        reject('rollback_missing')
    for name in names:
        if name == protected:
            continue
        if name.startswith('easyappointments_prev_'):
            if PREVIOUS.fullmatch(name) is None:
                reject('foreign_previous_entry')
            record = candidate_record(helper, web, releases, name, web_uid, device, now_ns)
            if record is not None:
                records.append(record)
    records.sort(key=lambda item: (item['tree']['mtime_ns'], item['name']))
    selected = records[:MAX_PER_PASS]
    if helper.open_file_identities([item['tree'] for item in selected]):
        reject('candidate_open', 75)
    public = {
        'schema': SCHEMA,
        'active_release': current,
        'rollback_release': rollback,
        'eligible_count': len(records),
        'selected': [
            {
                'name': item['name'],
                'contained_release': item['contained_release'],
                'age_days': item['age_days'],
                'directory_identity': item['tree']['identity'],
                'mtime_ns': item['tree']['mtime_ns'],
                'allocated_bytes': item['tree']['allocated'],
                'inode_count': item['tree']['inodes'],
                'archive_identity': item['archive_identity'],
            }
            for item in selected
        ],
    }
    return public, selected


def protected_state_unchanged(helper, web, current, rollback_fd, rollback_name,
                              current_id, rollback_id, expected_identities):
    actual = (
        helper.directory_identity(os.stat('easyappointments', dir_fd=web, follow_symlinks=False)),
        helper.directory_identity(os.stat(rollback_name, dir_fd=web, follow_symlinks=False)),
    )
    opened = (helper.directory_identity(os.fstat(current)),
              helper.directory_identity(os.fstat(rollback_fd)))
    return (actual == expected_identities == opened
            and helper.read_release_marker(current) == current_id
            and helper.read_release_marker(rollback_fd) == rollback_id)


def run(mode, expected_plan_sha=None, helper=None):
    if os.geteuid() != 0 or socket.gethostname().split('.')[0] != HOST:
        reject('wrong_runtime')
    helper = helper or load_pinned_helper()
    global_lock = helper.open_global_lock()
    web = current = rollback_fd = releases = state = orchestrator = None
    try:
        if helper.activity_count() != 0:
            reject('active_production_work', 75)
        helper.assert_no_nonterminal_runs()
        web = helper.open_absolute_directory(WEB_ROOT)
        current = helper.open_child_directory(web, 'easyappointments')
        current_id = helper.read_release_marker(current)
        rollback_name = 'easyappointments_prev_' + current_id
        rollback_fd = helper.open_child_directory(web, rollback_name)
        rollback_id = helper.read_release_marker(rollback_fd)
        protected_identity = (helper.directory_identity(os.fstat(current)),
                              helper.directory_identity(os.fstat(rollback_fd)))
        releases = helper.open_absolute_directory(RELEASES_ROOT, exact_mode=0o700)
        state = helper.open_absolute_directory(STATE_ROOT, exact_mode=0o700)
        orchestrator = helper.open_absolute_directory(helper.ORCHESTRATOR_ROOT, exact_mode=0o700)
        helper.assert_no_nested_mounts(os.listdir(web), orchestrator)
        try:
            fcntl.flock(state, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            reject('cleanup_lock_busy', 75)
        plan, selected = collect(helper, web, releases, state, current_id, rollback_id,
                                 pwd.getpwnam('www-data').pw_uid)
        digest = hashlib.sha256(canonical(plan)).hexdigest()
        if mode == 'plan':
            return {'status': 'pass', 'plan_sha256': digest, **plan,
                    'deletion_performed': False}
        if digest != expected_plan_sha:
            reject('plan_identity_changed', 75)
        if not selected:
            reject('no_eligible_candidates')
        if helper.activity_count() != 0:
            reject('active_production_work', 75)
        helper.assert_no_nonterminal_runs()
        deleted = 0
        for item in selected:
            if not protected_state_unchanged(helper, web, current, rollback_fd,
                                             rollback_name, current_id, rollback_id,
                                             protected_identity):
                reject('protected_release_changed', 75)
            current_candidate = os.stat(item['name'], dir_fd=web, follow_symlinks=False)
            if (helper.directory_identity(current_candidate) != item['tree']['identity']
                    or current_candidate.st_mtime_ns != item['tree']['mtime_ns']):
                reject('candidate_changed', 75)
            if archive_identity(helper, releases, item['contained_release']) != item['archive_identity']:
                reject('candidate_archive_changed', 75)
            if helper.open_file_identities([item['tree']]):
                reject('candidate_open', 75)
            if helper.activity_count() != 0:
                reject('active_production_work', 75)
            helper.detach_tree(web, state, {'leaf': item['name'],
                                             'identity': item['tree']['identity']},
                               'release', {0, pwd.getpwnam('www-data').pw_uid},
                               helper.MUTATIONS)
            deleted += 1
        os.fsync(web)
        if not protected_state_unchanged(helper, web, current, rollback_fd,
                                         rollback_name, current_id, rollback_id,
                                         protected_identity):
            reject('protected_release_changed', 75)
        return {'status': 'pass', 'schema': SCHEMA, 'plan_sha256': digest,
                'deleted_release_dirs': deleted, **helper.MUTATIONS.fields()}
    finally:
        for fd in (orchestrator, state, releases, rollback_fd, current, web, global_lock):
            if fd is not None:
                os.close(fd)


def main(argv):
    helper = None
    try:
        if argv == ['plan']:
            mode, expected = 'plan', None
        elif len(argv) == 2 and argv[0] == 'execute' and SHA256.fullmatch(argv[1]):
            mode, expected = 'execute', argv[1]
        else:
            reject('invalid_arguments')
        if os.geteuid() != 0 or socket.gethostname().split('.')[0] != HOST:
            reject('wrong_runtime')
        helper = load_pinned_helper()
        result = run(mode, expected, helper)
        print(canonical(result).decode(), end='')
    except Exception as error:
        known = isinstance(error, CleanupError) or (helper is not None and isinstance(error, helper.RetentionError))
        reason = error.reason if known else 'internal_rejection'
        payload = {'schema': SCHEMA, 'status': 'blocked', 'reason': reason}
        if helper is not None:
            payload.update(helper.MUTATIONS.fields())
        print(canonical(payload).decode(), end='')
        raise SystemExit(error.code if known else 70)


if __name__ == '__main__':
    main(sys.argv[1:])
