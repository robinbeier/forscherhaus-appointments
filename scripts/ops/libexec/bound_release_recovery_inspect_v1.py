#!/usr/bin/python3
"""Read one bound deployment's retained state without changing it.

The operator wrapper streams this reviewed source over SSH. This program has
no deploy, acknowledgement, upload, unlink, or file-creation path. The shared
production lock excludes a still-running compliant deployment while evidence
is read.
"""

import argparse
import fcntl
import json
import os
import re
import stat
import sys


LOCK = '/var/lib/fh-deploy-orchestrator/locks/fh-production-change.lock'
GUARD = '/root/fh-deploy-recovery-pending.v1.json'
MARKER = '/var/www/html/easyappointments/_RELEASE'
HEX64 = re.compile(r'[0-9a-f]{64}\Z')
RUN_ID = re.compile(r'[0-9a-f]{32}\Z')
COMMIT = re.compile(r'[0-9a-f]{40}\Z')
RELEASE = re.compile(r'ea_[A-Za-z0-9_]+\Z')
MARKER_BYTES = re.compile(rb'(ea_[A-Za-z0-9_]+)  20[0-9]{2}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\n\Z')
RECEIPT_EXITS = {
    'succeeded': 0,
    'failed_pre_switch': 30,
    'internal_rollback_succeeded': 30,
    'rollback_failed_or_unverifiable': 31,
    'switch_recovery_required': 32,
    'interrupted_pre_switch': 143,
}
BOUND_HASH_FIELDS = (
    'archive_sha256', 'provenance_sha256', 'continuity_sha256',
    'deploy_sha256', 'pair_helper_sha256', 'backup_helper_sha256',
)
CONFIG_PATHS = (
    '/var/www/html/easyappointments/config.php',
    '/etc/fh/healthz.token',
    '/etc/fh/zero-surprise-predeploy.ini',
    '/etc/fh/zero-surprise-canary.ini',
    '/etc/fh/zero-surprise-incident-webhook.ini',
)


class InspectionError(Exception):
    def __init__(self, result_class, code=70):
        super().__init__(result_class)
        self.result_class = result_class
        self.code = code


def fail(result_class, code=70):
    raise InspectionError(result_class, code)


def identity(value):
    return (value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_gid,
            value.st_nlink, value.st_size, value.st_mtime_ns, value.st_ctime_ns)


def trusted_parent(path, final_mode=None):
    if not path.startswith('/') or '//' in path or '/./' in path or '/../' in path:
        fail('path_unsafe')
    cursor = ''
    parts = [part for part in path.split('/') if part]
    for index, part in enumerate(parts):
        cursor += '/' + part
        try:
            value = os.lstat(cursor)
        except OSError:
            fail('path_unsafe')
        mode = stat.S_IMODE(value.st_mode)
        if (not stat.S_ISDIR(value.st_mode) or value.st_uid != 0 or value.st_gid != 0 or
                mode & 0o022 or (index == len(parts) - 1 and final_mode is not None and mode != final_mode)):
            fail('path_unsafe')


def read_bound_file(path, maximum, mode, label):
    trusted_parent(os.path.dirname(path))
    try:
        before = os.lstat(path)
    except FileNotFoundError:
        fail(label + '_missing')
    except OSError:
        fail(label + '_unknown')
    if (not stat.S_ISREG(before.st_mode) or before.st_uid != 0 or before.st_gid != 0 or
            stat.S_IMODE(before.st_mode) != mode or before.st_nlink != 1 or
            before.st_size <= 0 or before.st_size > maximum):
        fail(label + '_unsafe')
    try:
        fd = os.open(path, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK)
    except OSError:
        fail(label + '_changed')
    try:
        opened = os.fstat(fd)
        if identity(before) != identity(opened):
            fail(label + '_changed')
        data = bytearray()
        while len(data) < opened.st_size:
            chunk = os.read(fd, opened.st_size - len(data))
            if not chunk:
                fail(label + '_changed')
            data.extend(chunk)
        if os.read(fd, 1):
            fail(label + '_changed')
        if identity(opened) != identity(os.fstat(fd)) or identity(opened) != identity(os.lstat(path)):
            fail(label + '_changed')
        return bytes(data), identity(opened)
    except OSError:
        fail(label + '_changed')
    finally:
        os.close(fd)


def still_same(path, observed, label):
    try:
        if identity(os.lstat(path)) != observed:
            fail(label + '_changed')
    except OSError:
        fail(label + '_changed')


def open_lock():
    trusted_parent(os.path.dirname(LOCK), 0o700)
    try:
        before = os.lstat(LOCK)
    except OSError:
        fail('lock_unsafe')
    if (not stat.S_ISREG(before.st_mode) or before.st_uid != 0 or before.st_gid != 0 or
            stat.S_IMODE(before.st_mode) != 0o600 or before.st_nlink != 1 or before.st_size != 0):
        fail('lock_unsafe')
    try:
        fd = os.open(LOCK, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW)
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
        if 'fd' in locals():
            os.close(fd)
        raise


def marker_release():
    raw, observed = read_bound_file(MARKER, 512, 0o644, 'marker')
    match = MARKER_BYTES.fullmatch(raw)
    if match is None:
        fail('marker_invalid')
    return match.group(1).decode('ascii'), observed


def guard_absent():
    try:
        os.lstat(GUARD)
    except FileNotFoundError:
        return True
    except OSError:
        fail('guard_unknown')
    return False


def read_json(path, maximum, label):
    raw, observed = read_bound_file(path, maximum, 0o600, label)
    try:
        value = json.loads(raw, object_pairs_hook=unique_pairs)
    except (UnicodeDecodeError, json.JSONDecodeError, ValueError):
        fail(label + '_invalid')
    if not isinstance(value, dict):
        fail(label + '_invalid')
    return value, observed


def unique_pairs(pairs):
    value = {}
    for key, item in pairs:
        if key in value:
            raise ValueError('duplicate JSON key')
        value[key] = item
    return value


def expected_hashes(args):
    return {key: getattr(args, key) for key in BOUND_HASH_FIELDS}


def validate_args(args):
    if not RELEASE.fullmatch(args.expected_active_release):
        fail('input_invalid')
    if args.mode == 'no-guard':
        if any(getattr(args, key) is not None for key in (
                'release', 'commit', 'run_id', *BOUND_HASH_FIELDS)):
            fail('input_invalid')
        return
    if (not args.release or not RELEASE.fullmatch(args.release) or
            args.release == args.expected_active_release or
            not args.commit or not COMMIT.fullmatch(args.commit) or
            not args.run_id or not RUN_ID.fullmatch(args.run_id) or
            any(value is None or not HEX64.fullmatch(value) for value in expected_hashes(args).values())):
        fail('input_invalid')


def validate_guard(value, args, intent_path, result_path):
    expected = {
        'schema': 'bound_release_deploy_recovery_guard.v1',
        'release': args.release,
        'expected_active_release': args.expected_active_release,
        'run_id': args.run_id,
        'intent_path': intent_path,
        'result_path': result_path,
    }
    if value != expected:
        fail('guard_mismatch')


def validate_intent(value, args):
    if (set(value) != {'schema', 'release', 'commit', 'run_id', 'bindings'} or
            value['schema'] != 'bound_release_deploy_intent.v1' or
            value['release'] != args.release or value['commit'] != args.commit or
            value['run_id'] != args.run_id or not isinstance(value['bindings'], dict)):
        fail('intent_mismatch')
    bindings = value['bindings']
    if set(bindings) != set(BOUND_HASH_FIELDS) | {'config'}:
        fail('intent_mismatch')
    for key, expected in expected_hashes(args).items():
        if bindings[key] != expected:
            fail('binding_mismatch')
    configs = bindings['config']
    if not isinstance(configs, list) or len(configs) != len(CONFIG_PATHS):
        fail('intent_invalid')
    for config, expected_path in zip(configs, CONFIG_PATHS):
        if (not isinstance(config, dict) or set(config) != {'path', 'identity', 'sha256'} or
                config['path'] != expected_path or not isinstance(config['identity'], list) or
                len(config['identity']) != 9 or
                any(type(part) is not int for part in config['identity']) or
                not isinstance(config['sha256'], str) or not HEX64.fullmatch(config['sha256'])):
            fail('intent_invalid')


def validate_receipt(value):
    if (set(value) != {'schema', 'outcome', 'exit_code'} or
            value['schema'] != 'deploy_result.v1' or
            not isinstance(value['outcome'], str) or
            value['outcome'] not in RECEIPT_EXITS or
            type(value['exit_code']) is not int or
            value['exit_code'] != RECEIPT_EXITS[value['outcome']]):
        fail('receipt_invalid')
    return value['exit_code']


def inspect(args):
    if os.geteuid() != 0 or os.uname().nodename.split('.')[0] != 'booking-server':
        fail('host_invalid')
    validate_args(args)
    lock_fd = open_lock()
    try:
        trusted_parent('/root', 0o700)
        if args.mode == 'no-guard':
            if not guard_absent():
                fail('guard_present')
            active, marker_id = marker_release()
            if active != args.expected_active_release:
                fail('marker_mismatch')
            if not guard_absent():
                fail('guard_changed')
            still_same(MARKER, marker_id, 'marker')
            return 'no_pending_guard', 0

        intent_path = '/root/fh-deploy-intent-' + args.release + '.json'
        result_path = '/root/fh-deploy-result-' + args.run_id + '.json'
        guard, guard_id = read_json(GUARD, 4096, 'guard')
        validate_guard(guard, args, intent_path, result_path)
        intent, intent_id = read_json(intent_path, 4096, 'intent')
        validate_intent(intent, args)
        receipt, receipt_id = read_json(result_path, 256, 'receipt')
        exit_code = validate_receipt(receipt)
        active, marker_id = marker_release()
        if active not in (args.release, args.expected_active_release):
            fail('marker_mismatch')
        if exit_code == 0:
            if active != args.release:
                fail('marker_conflict')
            result_class = 'terminal_deployed'
        elif exit_code == 30:
            if active != args.expected_active_release:
                fail('marker_conflict')
            result_class = 'terminal_confirmed_failed'
        elif exit_code == 143:
            if active != args.expected_active_release:
                fail('marker_conflict')
            result_class = 'recovery_required_exit143'
        else:
            result_class = 'recovery_required_exit' + str(exit_code)
        for path, observed, label in (
                (GUARD, guard_id, 'guard'), (intent_path, intent_id, 'intent'),
                (result_path, receipt_id, 'receipt'), (MARKER, marker_id, 'marker')):
            still_same(path, observed, label)
        return result_class, 0 if result_class.startswith('terminal_') else 70
    finally:
        os.close(lock_fd)


def main():
    parser = argparse.ArgumentParser(description='Read only a bound release recovery state')
    parser.add_argument('--mode', choices=('no-guard', 'recovery'), required=True)
    parser.add_argument('--expected-active-release', required=True)
    parser.add_argument('--release')
    parser.add_argument('--commit')
    parser.add_argument('--run-id')
    for key in BOUND_HASH_FIELDS:
        parser.add_argument('--' + key.replace('_sha256', '-sha').replace('_', '-'), dest=key)
    args = parser.parse_args()
    try:
        result_class, code = inspect(args)
    except InspectionError as error:
        result_class, code = error.result_class, error.code
    except (OSError, ValueError, TypeError):
        result_class, code = 'observation_unknown', 70
    print(json.dumps({
        'schema': 'bound_release_recovery_inspect.v1',
        'status': 'passed' if code == 0 else 'blocked',
        'result_class': result_class,
    }, sort_keys=True, separators=(',', ':')))
    return code


if __name__ == '__main__':
    sys.exit(main())
