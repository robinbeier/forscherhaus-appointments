#!/usr/bin/env python3
"""Fail-closed ROB-490 activation of Kuma retention monitoring."""

import argparse
import ctypes
import errno
import fcntl
import hashlib
import json
import os
import re
import secrets
import stat
import sys
from pathlib import Path


ENV_PATH = '/root/backups/uptime-kuma-push.env'
STATE_ROOT = '/var/lib/fh-kuma-monitoring-v1'
LOCK_PATH = '/run/fh-kuma-monitoring-v1.lock'
KEY = 'KUMA_RELEASE_RETENTION_MONITOR_ENABLED'
CONFIRMATION = 'ROB-490'
SCHEMA = 'fh_kuma_monitoring_recovery.v1'
MAX_ENV_BYTES = 4_000_000
MAX_EVIDENCE_BYTES = 4_100_000
RENAME_NOREPLACE = 1
RENAME_EXCHANGE = 2


class ContractError(Exception):
    def __init__(self, reason, mutated=False, rollback='not_required'):
        super().__init__(reason)
        self.reason = reason
        self.mutated = mutated
        self.rollback = rollback


def fail(reason, mutated=False, rollback='not_required'):
    raise ContractError(reason, mutated, rollback)


def emit(status, ready=False, mutated=False, monitoring='unknown', recovery='unknown',
         rollback='not_required', reason=None):
    payload = {
        'execution_ready': ready,
        'monitoring_state': monitoring,
        'mutation_performed': mutated,
        'recovery_state': recovery,
        'rollback_outcome': rollback,
        'status': status,
    }
    if reason is not None:
        payload['reason'] = reason
    sys.stdout.write(json.dumps(payload, sort_keys=True, separators=(',', ':')) + '\n')


def digest(data):
    return hashlib.sha256(data).hexdigest()


def identity(value):
    return (
        value.st_dev,
        value.st_ino,
        value.st_mode,
        value.st_uid,
        value.st_gid,
        value.st_nlink,
        value.st_size,
        value.st_mtime_ns,
        value.st_ctime_ns,
    )


def exchange_stable_identity(value):
    return identity(value)[:-1]


def trusted_gid(expected_uid):
    return 0 if expected_uid == 0 else os.getegid()


def mapped(root_prefix, absolute_path):
    if not absolute_path.startswith('/'):
        fail('path_contract_invalid')
    if root_prefix == Path('/'):
        return Path(absolute_path)
    return root_prefix / absolute_path.lstrip('/')


def validate_directory(path, expected_uid, exact_mode=None):
    try:
        before = os.lstat(path)
        after = os.lstat(path)
    except OSError:
        fail('directory_contract_invalid')
    mode = stat.S_IMODE(after.st_mode)
    if (
        identity(before) != identity(after)
        or not stat.S_ISDIR(after.st_mode)
        or after.st_uid != expected_uid
        or after.st_gid != trusted_gid(expected_uid)
        or (mode & 0o022) != 0
        or (exact_mode is not None and mode != exact_mode)
    ):
        fail('directory_contract_invalid')
    return after


def validate_ancestors(path, stop, expected_uid):
    try:
        path = Path(os.path.abspath(path))
        stop = stop.resolve(strict=True)
        relative = path.relative_to(stop)
    except (OSError, ValueError):
        fail('path_contract_invalid')
    current = stop
    validate_directory(current, expected_uid)
    for leaf in relative.parts:
        current /= leaf
        validate_directory(current, expected_uid)


def stable_read(path, expected_uid, expected_mode, maximum, reason):
    try:
        before = os.lstat(path)
        fd = os.open(path, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK)
    except OSError:
        fail(reason)
    try:
        opened = os.fstat(fd)
        if (
            identity(before) != identity(opened)
            or not stat.S_ISREG(opened.st_mode)
            or opened.st_uid != expected_uid
            or opened.st_gid != trusted_gid(expected_uid)
            or stat.S_IMODE(opened.st_mode) != expected_mode
            or opened.st_nlink != 1
            or opened.st_size < 0
            or opened.st_size > maximum
        ):
            fail(reason)
        data = bytearray()
        while True:
            chunk = os.read(fd, min(131072, maximum + 1 - len(data)))
            if not chunk:
                break
            data.extend(chunk)
            if len(data) > maximum:
                fail(reason)
        after = os.fstat(fd)
        if identity(after) != identity(opened):
            fail(reason.replace('_contract_invalid', '_changed'))
    finally:
        os.close(fd)
    try:
        current = os.lstat(path)
    except OSError:
        fail(reason.replace('_contract_invalid', '_changed'))
    if identity(current) != identity(opened):
        fail(reason.replace('_contract_invalid', '_changed'))
    return bytes(data), opened


def parse_env(data):
    if b'\x00' in data:
        fail('env_contract_invalid')
    try:
        text = data.decode('utf-8')
    except UnicodeDecodeError:
        fail('env_invalid_utf8')
    matches = []
    offset = 0
    for line in text.splitlines(keepends=True):
        body = line.rstrip('\r\n')
        if KEY in body:
            if body not in {KEY + '=0', KEY + '=1'}:
                fail('definition_ambiguous')
            matches.append((body[-1], offset + len(KEY) + 1))
        offset += len(line.encode('utf-8'))
    if len(matches) > 1:
        fail('duplicate_definition')
    return matches[0] if matches else (None, None)


def desired_env(original, value, value_offset):
    if value is None:
        separator = b'\n' if original and not original.endswith(b'\n') else b''
        return original + separator + (KEY + '=1\n').encode('ascii')
    if value == '1':
        return original
    if value != '0' or value_offset is None or original[value_offset:value_offset + 1] != b'0':
        fail('env_changed')
    return original[:value_offset] + b'1' + original[value_offset + 1:]


def rename_operation(parent, source, target, flag, unavailable_reason):
    libc = ctypes.CDLL(None, use_errno=True)
    parent_fd = os.open(parent, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
    try:
        function = getattr(libc, 'renameat2', None)
        if sys.platform.startswith('linux') and function is not None:
            result = function(parent_fd, source.encode(), parent_fd, target.encode(), flag)
        elif sys.platform == 'darwin' and hasattr(libc, 'renameatx_np'):
            darwin_flag = 0x00000004 if flag == RENAME_NOREPLACE else 0x00000002
            result = libc.renameatx_np(parent_fd, source.encode(), parent_fd, target.encode(), darwin_flag)
        else:
            fail(unavailable_reason)
        if result != 0:
            error = ctypes.get_errno()
            raise OSError(error, os.strerror(error))
    finally:
        os.close(parent_fd)


def rename_noreplace(parent, source, target):
    rename_operation(parent, source, target, RENAME_NOREPLACE, 'rename_noreplace_unavailable')


def rename_exchange(parent, source, target):
    rename_operation(parent, source, target, RENAME_EXCHANGE, 'rename_exchange_unavailable')


def fsync_directory(path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def write_exclusive(path, data, mode, expected_uid, reason):
    fd = None
    try:
        fd = os.open(
            path,
            os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC | os.O_NOFOLLOW,
            mode,
        )
    except OSError:
        fail(reason)
    try:
        try:
            offset = 0
            while offset < len(data):
                offset += os.write(fd, data[offset:])
            os.fchmod(fd, mode)
            if os.geteuid() == 0:
                os.fchown(fd, expected_uid, trusted_gid(expected_uid))
            os.fsync(fd)
            result = os.fstat(fd)
        except BaseException as error:
            try:
                opened = os.fstat(fd)
                current = os.lstat(path)
                if identity(opened) != identity(current):
                    raise ContractError(reason, True, 'failed')
                os.unlink(path)
                fsync_directory(path.parent)
            except FileNotFoundError:
                pass
            except ContractError:
                raise
            except BaseException as cleanup_error:
                raise ContractError(reason, True, 'failed') from cleanup_error
            if isinstance(error, ContractError):
                raise
            raise ContractError(reason) from error
    finally:
        if fd is not None:
            try:
                os.close(fd)
            except OSError as error:
                raise ContractError(reason, True, 'failed') from error
    return result


def evidence_payload(original, desired, issue=CONFIRMATION):
    return (json.dumps({
        'desired_sha256': digest(desired),
        'env_path': ENV_PATH,
        'issue': issue,
        'original_sha256': digest(original),
        'schema': SCHEMA,
    }, sort_keys=True, separators=(',', ':')) + '\n').encode('utf-8')


def recovery_files(state, expected_uid):
    state_before = validate_directory(state, expected_uid, 0o700)
    try:
        entries = list(os.scandir(state))
    except OSError:
        fail('recovery_invalid')
    if len(entries) != 2:
        fail('recovery_invalid')
    values = []
    for entry in entries:
        if re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._-]{0,127}', entry.name) is None:
            fail('recovery_invalid')
        values.append((entry.name, *stable_read(
            state / entry.name, expected_uid, 0o600, MAX_EVIDENCE_BYTES,
            'recovery_contract_invalid',
        )))
    if identity(state_before) != identity(validate_directory(state, expected_uid, 0o700)):
        fail('recovery_changed')
    return values


def validate_recovery(state, current, desired, expected_uid, current_enabled):
    values = recovery_files(state, expected_uid)
    candidates = []
    for index, (_, data, _) in enumerate(values):
        try:
            parsed = json.loads(data.decode('utf-8'))
        except (UnicodeDecodeError, json.JSONDecodeError):
            continue
        if not isinstance(parsed, dict) or set(parsed) != {
            'desired_sha256', 'env_path', 'issue', 'original_sha256', 'schema'
        }:
            continue
        if (
            parsed['env_path'] != ENV_PATH
            or parsed['issue'] not in {'ROB-488', CONFIRMATION}
            or parsed['schema'] != SCHEMA
        ):
            continue
        candidates.append((index, parsed))
    if len(candidates) != 1:
        fail('recovery_invalid')
    evidence_index, evidence = candidates[0]
    original = values[1 - evidence_index][1]
    original_value, original_offset = parse_env(original)
    if original_value == '1':
        fail('recovery_invalid')
    reconstructed = desired_env(original, original_value, original_offset)
    if (
        evidence['original_sha256'] != digest(original)
        or evidence['desired_sha256'] != digest(reconstructed)
        or reconstructed != desired
        or (current_enabled and current != desired)
        or (not current_enabled and current != original)
    ):
        fail('recovery_mismatch')
    return 'intact'


def remove_private_tree(path, expected_uid, expected):
    validate_directory(path, expected_uid, 0o700)
    entries = {entry.name for entry in os.scandir(path)}
    if entries != set(expected):
        fail('private_cleanup_invalid')
    for leaf, (expected_data, expected_identity) in expected.items():
        data, current = stable_read(
            path / leaf, expected_uid, 0o600, MAX_EVIDENCE_BYTES,
            'private_cleanup_invalid',
        )
        if data != expected_data or identity(current) != identity(expected_identity):
            fail('private_cleanup_invalid')
        os.unlink(path / leaf)
    os.rmdir(path)


def test_hook(root_prefix, name):
    # Fault injection is structurally unreachable for fixed production paths.
    if root_prefix == Path('/'):
        return ''
    return os.environ.get(name, '')


def publish_recovery(state, original, desired, root_prefix, expected_uid):
    if state.exists() or state.is_symlink():
        validate_directory(state, expected_uid, 0o700)
        validate_recovery(state, original, desired, expected_uid, False)
        return False
    parent = state.parent
    temporary = parent / ('.fh-kuma-monitoring-v1.pending-' + secrets.token_hex(16))
    published = False
    private_expected = {}
    try:
        os.mkdir(temporary, 0o700)
        if os.geteuid() == 0:
            os.chown(temporary, expected_uid, trusted_gid(expected_uid))
        original_leaf = 'rob-488-env.before'
        evidence_leaf = 'rob-490-recovery.json'
        evidence_data = evidence_payload(original, desired)
        private_expected[original_leaf] = (original, write_exclusive(
            temporary / original_leaf, original, 0o600, expected_uid, 'recovery_publication_failed',
        ))
        private_expected[evidence_leaf] = (evidence_data, write_exclusive(
            temporary / evidence_leaf, evidence_data,
            0o600, expected_uid, 'recovery_publication_failed',
        ))
        fsync_directory(temporary)
        race = test_hook(root_prefix, 'FH_KUMA_MONITORING_TEST_RECOVERY_PUBLISH_RACE')
        if race:
            if race not in {'exact', 'foreign'}:
                fail('test_hook_invalid')
            os.mkdir(state, 0o700)
            if race == 'exact':
                write_exclusive(state / 'legacy.before', original, 0o600, expected_uid, 'test_hook_failed')
                write_exclusive(
                    state / 'legacy.json', evidence_payload(original, desired, 'ROB-488'),
                    0o600, expected_uid, 'test_hook_failed',
                )
            else:
                write_exclusive(state / 'foreign', b'foreign\n', 0o600, expected_uid, 'test_hook_failed')
                write_exclusive(state / 'foreign.json', b'{}\n', 0o600, expected_uid, 'test_hook_failed')
            fsync_directory(state)
        try:
            rename_noreplace(parent, temporary.name, state.name)
            published = True
            if test_hook(root_prefix, 'FH_KUMA_MONITORING_TEST_FAIL_RECOVERY_DURABILITY') == '1':
                fail('recovery_durability_unknown', True, 'failed')
            fsync_directory(parent)
        except OSError as error:
            if error.errno != errno.EEXIST:
                raise
        if not published:
            remove_private_tree(temporary, expected_uid, private_expected)
        validate_recovery(state, original, desired, expected_uid, False)
        return published
    except ContractError as error:
        if not published and temporary.exists():
            try:
                remove_private_tree(temporary, expected_uid, private_expected)
            except BaseException as cleanup_error:
                raise ContractError('private_cleanup_invalid', True, 'failed') from cleanup_error
        if published and not error.mutated:
            raise ContractError(error.reason, True, 'failed') from error
        raise
    except BaseException as error:
        if not published and temporary.exists():
            try:
                remove_private_tree(temporary, expected_uid, private_expected)
            except BaseException as cleanup_error:
                raise ContractError('private_cleanup_invalid', True, 'failed') from cleanup_error
        raise ContractError('recovery_publication_failed', published, 'failed') from error


def inject_foreign_env(env, data, expected_uid):
    temporary = env.parent / ('.fh-kuma-monitoring-env-v1.foreign-' + secrets.token_hex(16))
    try:
        write_exclusive(temporary, data, 0o600, expected_uid, 'test_hook_failed')
        os.replace(temporary, env)
        fsync_directory(env.parent)
    finally:
        try:
            os.unlink(temporary)
        except FileNotFoundError:
            pass


def exact_exchange_object(path, expected_data, expected_identity, expected_uid, reason):
    data, current = stable_read(path, expected_uid, 0o600, MAX_ENV_BYTES, reason)
    if data != expected_data or exchange_stable_identity(current) != exchange_stable_identity(expected_identity):
        fail(reason)
    return current


def rollback_exchange(env, pending, live_data, live_identity, displaced_data,
                      displaced_identity, expected_uid, root_prefix, failure_reason):
    if test_hook(root_prefix, 'FH_KUMA_MONITORING_TEST_CONCURRENT_DURING_RESTORE') == '1':
        inject_foreign_env(env, b'FOREIGN_RESTORE_WRITER=1\n', expected_uid)
    try:
        exact_exchange_object(env, live_data, live_identity, expected_uid, 'rollback_live_changed')
        rename_exchange(env.parent, pending.name, env.name)
        exact_exchange_object(env, displaced_data, displaced_identity, expected_uid, 'rollback_restore_invalid')
        exact_exchange_object(pending, live_data, live_identity, expected_uid, 'rollback_displaced_invalid')
        os.unlink(pending)
        fsync_directory(env.parent)
        return ContractError(failure_reason, True, 'succeeded')
    except BaseException:
        return ContractError('rollback_failed', True, 'failed')


def execute_transaction(context):
    root_prefix = context['root_prefix']
    expected_uid = context['expected_uid']
    env = context['env']
    original = context['original']
    desired = context['desired']
    env_identity = context['env_identity']
    recovery_mutated = publish_recovery(
        context['state'], original, desired, root_prefix, expected_uid,
    )
    pending = env.parent / ('.fh-kuma-monitoring-env-v1.pending-' + secrets.token_hex(16))
    exchanged = False
    exchange_begun = False
    replacement_identity = None
    try:
        replacement_identity = write_exclusive(
            pending, desired, 0o600, expected_uid, 'pending_publication_failed',
        )
        if test_hook(root_prefix, 'FH_KUMA_MONITORING_TEST_CONCURRENT_BEFORE_EXCHANGE') == '1':
            inject_foreign_env(env, b'FOREIGN_PRE_EXCHANGE_WRITER=1\n', expected_uid)
        try:
            current = os.lstat(env)
        except OSError:
            fail('env_changed', recovery_mutated)
        if identity(current) != identity(env_identity):
            fail('env_changed', recovery_mutated)
        rename_exchange(env.parent, pending.name, env.name)
        exchanged = True
        exchange_begun = True
        displaced_data, displaced_identity = stable_read(
            pending, expected_uid, 0o600, MAX_ENV_BYTES, 'displaced_contract_invalid',
        )
        live_matches = True
        try:
            exact_exchange_object(
                env, desired, replacement_identity, expected_uid, 'published_contract_invalid',
            )
        except ContractError:
            live_matches = False
        original_matches = (
            displaced_data == original
            and exchange_stable_identity(displaced_identity) == exchange_stable_identity(env_identity)
        )
        if not original_matches or not live_matches:
            raise rollback_exchange(
                env, pending, desired, replacement_identity, displaced_data,
                displaced_identity, expected_uid, root_prefix, 'concurrent_writer',
            )
        if test_hook(root_prefix, 'FH_KUMA_MONITORING_TEST_FAIL_AFTER_EXCHANGE') == '1':
            raise rollback_exchange(
                env, pending, desired, replacement_identity, original, env_identity,
                expected_uid, root_prefix, 'test_failure_after_exchange',
            )
        if test_hook(root_prefix, 'FH_KUMA_MONITORING_TEST_FAIL_ENV_DURABILITY') == '1':
            raise rollback_exchange(
                env, pending, desired, replacement_identity, original, env_identity,
                expected_uid, root_prefix, 'test_failure_env_durability',
            )
        if test_hook(root_prefix, 'FH_KUMA_MONITORING_TEST_FAIL_UNEXPECTED_AFTER_EXCHANGE') == '1':
            raise OSError(errno.EIO, 'test unexpected post-exchange failure')
        fsync_directory(env.parent)
        exact_exchange_object(pending, original, env_identity, expected_uid, 'displaced_changed')
        os.unlink(pending)
        exchanged = False
        if test_hook(root_prefix, 'FH_KUMA_MONITORING_TEST_FAIL_FINAL_DURABILITY') == '1':
            fail('final_durability_unknown', True, 'failed')
        fsync_directory(env.parent)
        if test_hook(root_prefix, 'FH_KUMA_MONITORING_TEST_FAIL_POSTFLIGHT') == '1':
            fail('postflight_unknown', True, 'failed')
        refreshed = inspect_context(root_prefix)
        if refreshed['value'] != '1' or refreshed['desired'] != desired:
            fail('postflight_failed', True, 'failed')
        validate_recovery(context['state'], refreshed['original'], desired, expected_uid, True)
        return True
    except ContractError as error:
        if not exchanged:
            try:
                if pending.exists() or pending.is_symlink():
                    data, current = stable_read(
                        pending, expected_uid, 0o600, MAX_ENV_BYTES, 'pending_cleanup_invalid',
                    )
                    if replacement_identity is not None and (
                        data != desired or identity(current) != identity(replacement_identity)
                    ):
                        raise ContractError('pending_cleanup_invalid', True, 'failed')
                    os.unlink(pending)
            except FileNotFoundError:
                pass
        if (recovery_mutated or exchange_begun) and not error.mutated:
            rollback = 'failed' if exchange_begun else error.rollback
            raise ContractError(error.reason, True, rollback) from error
        raise
    except BaseException as error:
        if exchanged and replacement_identity is not None:
            raise rollback_exchange(
                env, pending, desired, replacement_identity, original, env_identity,
                expected_uid, root_prefix, 'execution_failed',
            ) from error
        raise ContractError('execution_failed', recovery_mutated or exchange_begun, 'failed') from error


def inspect_context(root_prefix):
    production = root_prefix == Path('/')
    expected_uid = 0 if production else os.geteuid()
    if production and os.geteuid() != 0:
        fail('root_required')
    if production and not sys.platform.startswith('linux'):
        fail('production_platform_invalid')
    validate_directory(root_prefix, expected_uid)
    env = mapped(root_prefix, ENV_PATH)
    state = mapped(root_prefix, STATE_ROOT)
    lock = mapped(root_prefix, LOCK_PATH)
    validate_ancestors(env.parent, root_prefix, expected_uid)
    validate_ancestors(state.parent, root_prefix, expected_uid)
    validate_ancestors(lock.parent, root_prefix, expected_uid)
    original, env_identity = stable_read(
        env, expected_uid, 0o600, MAX_ENV_BYTES, 'env_contract_invalid',
    )
    value, value_offset = parse_env(original)
    desired = desired_env(original, value, value_offset)
    if len(desired) > MAX_ENV_BYTES:
        fail('desired_env_too_large')
    if value == '1':
        if not state.exists() or state.is_symlink():
            fail('recovery_missing')
        validate_recovery(state, original, original, expected_uid, True)
        recovery_state = 'intact'
    elif state.exists() or state.is_symlink():
        validate_directory(state, expected_uid, 0o700)
        validate_recovery(state, original, desired, expected_uid, False)
        recovery_state = 'intact'
    else:
        recovery_state = 'absent'
    return {
        'desired': desired,
        'env': env,
        'env_identity': env_identity,
        'expected_uid': expected_uid,
        'lock': lock,
        'original': original,
        'recovery_state': recovery_state,
        'root_prefix': root_prefix,
        'state': state,
        'value': value,
    }


def open_lock(path, expected_uid):
    try:
        fd = os.open(path, os.O_RDWR | os.O_CREAT | os.O_CLOEXEC | os.O_NOFOLLOW, 0o600)
    except OSError:
        fail('lock_invalid')
    opened = os.fstat(fd)
    try:
        current = os.lstat(path)
    except OSError:
        os.close(fd)
        fail('lock_invalid')
    if (
        identity(opened) != identity(current)
        or not stat.S_ISREG(opened.st_mode)
        or opened.st_uid != expected_uid
        or opened.st_gid != trusted_gid(expected_uid)
        or stat.S_IMODE(opened.st_mode) != 0o600
        or opened.st_nlink != 1
    ):
        os.close(fd)
        fail('lock_invalid')
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        os.close(fd)
        fail('lock_busy')
    return fd


def parse_arguments():
    parser = argparse.ArgumentParser()
    parser.add_argument('--root-prefix', default='/')
    parser.add_argument('--execute', action='store_true')
    parser.add_argument('--confirm-live-write', default='')
    args = parser.parse_args()
    if args.execute:
        if args.confirm_live_write != CONFIRMATION:
            fail('confirmation_invalid')
    elif args.confirm_live_write:
        fail('confirmation_without_execute')
    requested = Path(args.root_prefix)
    if not requested.is_absolute():
        fail('root_prefix_invalid')
    root_prefix = requested.resolve(strict=True)
    if root_prefix == Path('/') and requested != root_prefix:
        fail('root_prefix_invalid')
    args.root_prefix = root_prefix
    return args


def main():
    args = parse_arguments()
    context = inspect_context(args.root_prefix)
    if not args.execute:
        monitoring = 'enabled' if context['value'] == '1' else 'would_enable'
        emit('pass', True, False, monitoring, context['recovery_state'])
        return
    lock_fd = open_lock(context['lock'], context['expected_uid'])
    try:
        context = inspect_context(args.root_prefix)
        if context['value'] == '1':
            emit('pass', True, False, 'enabled', 'intact')
            return
        mutated = execute_transaction(context)
    finally:
        os.close(lock_fd)
    emit('pass', True, mutated, 'enabled', 'intact')


try:
    main()
except ContractError as error:
    emit('fail', False, error.mutated, rollback=error.rollback, reason=error.reason)
    raise SystemExit(70)
except (OSError, ValueError, TypeError) as error:
    reason = 'internal_error'
    if '--root-prefix' in sys.argv:
        root_index = sys.argv.index('--root-prefix') + 1
        if root_index < len(sys.argv) and Path(sys.argv[root_index]) != Path('/'):
            traceback = error.__traceback__
            while traceback is not None and traceback.tb_next is not None:
                traceback = traceback.tb_next
            line = traceback.tb_lineno if traceback is not None else 0
            error_number = error.errno if isinstance(error, OSError) else 0
            reason = f'internal_error_{type(error).__name__}_{error_number}_{line}'
    emit('fail', False, False, reason=reason)
    raise SystemExit(70)
