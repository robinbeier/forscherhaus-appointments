#!/usr/bin/env python3
"""Fail-closed no-clobber installer for the ROB-490 monitoring helper."""

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
import tempfile
from pathlib import Path


TARGET = '/usr/local/libexec/fh-kuma-monitoring-env-v1'
LOCK_PATH = '/run/fh-kuma-monitoring-env-install-v1.lock'
CONFIRMATION = 'ROB-490'
MAX_SOURCE_BYTES = 1_000_000
RENAME_NOREPLACE = 1
RENAME_EXCL = 0x00000004
INVOKE_BOOTSTRAP = r'''
import hashlib
import json
import os
import sys

descriptor = int(sys.argv[1])
expected_size = int(sys.argv[2])
expected_sha256 = sys.argv[3]
program = sys.argv[4]
sys.argv = [program, *sys.argv[5:]]
chunks = []
while True:
    chunk = os.read(descriptor, 65536)
    if not chunk:
        break
    chunks.append(chunk)
os.close(descriptor)
source = b''.join(chunks)
if len(source) != expected_size or hashlib.sha256(source).hexdigest() != expected_sha256:
    payload = {
        'execution_ready': False,
        'mutation_performed': False,
        'reason': 'execution_snapshot_invalid',
        'status': 'fail',
    }
    sys.stdout.write(json.dumps(payload, sort_keys=True, separators=(',', ':')) + '\n')
    raise SystemExit(70)
scope = {
    '__cached__': None,
    '__file__': program,
    '__name__': '__main__',
    '__package__': None,
}
exec(compile(source, program, 'exec'), scope, scope)
'''


class ContractError(Exception):
    def __init__(self, reason, mutated=False):
        super().__init__(reason)
        self.reason = reason
        self.mutated = mutated


def fail(reason, mutated=False):
    raise ContractError(reason, mutated)


def emit(status, ready=False, mutated=False, install_state='unknown', reason=None):
    payload = {
        'execution_ready': ready,
        'install_state': install_state,
        'mutation_performed': mutated,
        'status': status,
    }
    if reason is not None:
        payload['reason'] = reason
    sys.stdout.write(json.dumps(payload, sort_keys=True, separators=(',', ':')) + '\n')


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
        resolved = path.resolve(strict=True)
        after = os.lstat(path)
    except OSError:
        fail('directory_contract_invalid')
    mode = stat.S_IMODE(after.st_mode)
    if (
        identity(before) != identity(after)
        or not stat.S_ISDIR(after.st_mode)
        or resolved != path.resolve(strict=False)
        or after.st_uid != expected_uid
        or after.st_gid != trusted_gid(expected_uid)
        or (mode & 0o022) != 0
        or (exact_mode is not None and mode != exact_mode)
    ):
        fail('directory_contract_invalid')
    return identity(after)


def validate_ancestors(path, stop, expected_uid):
    path = Path(os.path.abspath(path))
    stop = stop.resolve(strict=True)
    try:
        relative = path.relative_to(stop)
    except ValueError:
        fail('path_contract_invalid')
    current = stop
    validate_directory(current, expected_uid)
    for leaf in relative.parts:
        current = current / leaf
        validate_directory(current, expected_uid)


def stable_read(path, expected_uid, expected_mode, reason='source_contract_invalid',
                replace_after_read=False):
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
            or opened.st_size <= 0
            or opened.st_size > MAX_SOURCE_BYTES
        ):
            fail(reason)
        data = bytearray()
        while True:
            chunk = os.read(fd, min(65536, MAX_SOURCE_BYTES + 1 - len(data)))
            if not chunk:
                break
            data.extend(chunk)
            if len(data) > MAX_SOURCE_BYTES:
                fail(reason)
        after = os.fstat(fd)
        if identity(after) != identity(opened):
            fail(reason.replace('_contract_invalid', '_changed'))
        if replace_after_read:
            foreign = path.parent / ('.fh-kuma-monitoring-env-v1.foreign-' + secrets.token_hex(16))
            foreign_fd = os.open(
                foreign,
                os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC | os.O_NOFOLLOW,
                0o555,
            )
            try:
                os.write(foreign_fd, b'foreign-target-race\n')
                os.fchmod(foreign_fd, 0o555)
                os.fsync(foreign_fd)
            finally:
                os.close(foreign_fd)
            os.replace(foreign, path)
            fsync_directory(path.parent)
        try:
            current = os.lstat(path)
        except OSError:
            fail(reason.replace('_contract_invalid', '_changed'))
        if identity(current) != identity(opened):
            fail(reason.replace('_contract_invalid', '_changed'))
        return bytes(data), identity(opened)
    finally:
        os.close(fd)


def validate_target(path, expected_uid, expected_data, expected_sha256, root_prefix):
    if not path.exists():
        try:
            os.lstat(path)
        except FileNotFoundError:
            return 'absent'
        except OSError:
            fail('target_contract_invalid')
    replace_after_read = (
        root_prefix != Path('/')
        and os.environ.get('FH_KUMA_MONITORING_INSTALL_TEST_TARGET_REPLACE_AFTER_READ', '') == '1'
    )
    data, _ = stable_read(
        path, expected_uid, 0o555, 'target_contract_invalid', replace_after_read,
    )
    if data != expected_data or hashlib.sha256(data).hexdigest() != expected_sha256:
        fail('target_conflict')
    return 'installed'


def fsync_directory(path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def current_interpreter():
    if sys.platform.startswith('linux'):
        kernel_path = Path('/proc/self/exe')
        try:
            os.readlink(kernel_path)
        except OSError:
            fail('execution_interpreter_unavailable')
        return str(kernel_path)
    candidate = Path(sys.executable)
    if not sys.executable or not candidate.is_absolute():
        fail('execution_interpreter_unavailable')
    return str(candidate)


def rename_noreplace(parent, source, target):
    libc = ctypes.CDLL(None, use_errno=True)
    parent_fd = os.open(parent, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
    try:
        function = getattr(libc, 'renameat2', None)
        if sys.platform.startswith('linux') and function is not None:
            result = function(
                parent_fd, source.encode(), parent_fd, target.encode(), RENAME_NOREPLACE,
            )
        elif sys.platform == 'darwin' and hasattr(libc, 'renameatx_np'):
            result = libc.renameatx_np(
                parent_fd, source.encode(), parent_fd, target.encode(), RENAME_EXCL,
            )
        else:
            fail('rename_noreplace_unavailable')
        if result != 0:
            error = ctypes.get_errno()
            raise OSError(error, os.strerror(error))
    finally:
        os.close(parent_fd)


def publish(target, data, expected_uid, root_prefix):
    parent = target.parent
    temporary = parent / ('.fh-kuma-monitoring-env-v1.pending-' + secrets.token_hex(16))
    fd = None
    published = False
    pending_identity = None
    try:
        fd = os.open(
            temporary,
            os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC | os.O_NOFOLLOW,
            0o555,
        )
        offset = 0
        while offset < len(data):
            offset += os.write(fd, data[offset:])
        os.fchmod(fd, 0o555)
        if os.geteuid() == 0:
            os.fchown(fd, expected_uid, trusted_gid(expected_uid))
        os.fsync(fd)
        pending_identity = os.fstat(fd)
        os.close(fd)
        fd = None
        try:
            rename_noreplace(parent, temporary.name, target.name)
        except OSError as error:
            if error.errno != errno.EEXIST:
                raise
            return False
        published = True
        if (
            root_prefix != Path('/')
            and os.environ.get('FH_KUMA_MONITORING_INSTALL_TEST_FAIL_DURABILITY', '') == '1'
        ):
            fail('installation_durability_unknown', True)
        try:
            fsync_directory(parent)
        except OSError as error:
            raise ContractError('installation_durability_unknown', True) from error
        return True
    finally:
        if fd is not None:
            try:
                pending_identity = os.fstat(fd)
            except OSError as error:
                raise ContractError('pending_cleanup_invalid', True) from error
            try:
                os.close(fd)
            except OSError as error:
                raise ContractError('pending_cleanup_invalid', True) from error
        if not published and pending_identity is not None:
            try:
                current = os.lstat(temporary)
                if identity(current) != identity(pending_identity):
                    fail('pending_cleanup_invalid', True)
                os.unlink(temporary)
                fsync_directory(parent)
            except FileNotFoundError:
                pass
            except ContractError:
                raise
            except BaseException as error:
                raise ContractError('pending_cleanup_invalid', True) from error


def preflight(args):
    requested_root = Path(args.root_prefix)
    if not requested_root.is_absolute():
        fail('root_prefix_invalid')
    root_prefix = requested_root.resolve(strict=True)
    production = root_prefix == Path('/')
    if production and requested_root != root_prefix:
        fail('root_prefix_invalid')
    expected_uid = 0 if production else os.geteuid()
    if production and os.geteuid() != 0:
        fail('root_required')
    if production and not sys.platform.startswith('linux'):
        fail('production_platform_invalid')
    validate_directory(root_prefix, expected_uid)

    source_input = Path(args.source)
    if not source_input.is_absolute():
        fail('source_contract_invalid')
    try:
        source = source_input.parent.resolve(strict=True) / source_input.name
    except OSError:
        fail('source_contract_invalid')
    if production:
        try:
            source.relative_to(Path('/root'))
        except ValueError:
            fail('source_contract_invalid')
        validate_ancestors(source.parent, Path('/'), expected_uid)
    else:
        try:
            source.relative_to(root_prefix)
        except ValueError:
            fail('source_contract_invalid')
        validate_ancestors(source.parent, root_prefix, expected_uid)
    source_data, source_identity = stable_read(source, expected_uid, 0o555)
    if hashlib.sha256(source_data).hexdigest() != args.expected_sha256:
        fail('source_hash_invalid')

    target = mapped(root_prefix, TARGET)
    validate_ancestors(target.parent, root_prefix, expected_uid)
    install_state = validate_target(
        target, expected_uid, source_data, args.expected_sha256, root_prefix,
    )
    return {
        'expected_sha256': args.expected_sha256,
        'expected_uid': expected_uid,
        'install_state': install_state,
        'root_prefix': root_prefix,
        'source': source,
        'source_data': source_data,
        'source_identity': source_identity,
        'target': target,
    }


def invoke_installed(context, execute_helper):
    if context['install_state'] != 'installed':
        fail('installed_helper_required')
    target = context['target']
    snapshot = None
    try:
        before = os.lstat(target)
        fd = os.open(target, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK)
    except OSError:
        fail('target_contract_invalid')
    try:
        opened = os.fstat(fd)
        if identity(before) != identity(opened):
            fail('target_changed')
        data = bytearray()
        while True:
            chunk = os.read(fd, min(65536, MAX_SOURCE_BYTES + 1 - len(data)))
            if not chunk:
                break
            data.extend(chunk)
            if len(data) > MAX_SOURCE_BYTES:
                fail('target_contract_invalid')
        after = os.fstat(fd)
        current = os.lstat(target)
        if (
            identity(after) != identity(opened)
            or identity(current) != identity(opened)
            or bytes(data) != context['source_data']
            or hashlib.sha256(data).hexdigest() != context['expected_sha256']
        ):
            fail('target_changed')
        try:
            snapshot = tempfile.TemporaryFile()
            snapshot.write(data)
            snapshot.flush()
            snapshot.seek(0)
            snapshot_fd = snapshot.fileno()
            os.set_inheritable(snapshot_fd, True)
        except OSError:
            fail('execution_snapshot_unavailable')
        interpreter = current_interpreter()
        helper_arguments = [
            interpreter,
            '-I',
            '-B',
            '-c',
            INVOKE_BOOTSTRAP,
            str(snapshot_fd),
            str(len(data)),
            context['expected_sha256'],
            str(target),
        ]
        if context['root_prefix'] != Path('/'):
            helper_arguments.extend(['--root-prefix', str(context['root_prefix'])])
        if execute_helper:
            helper_arguments.extend(['--execute', '--confirm-live-write', CONFIRMATION])
        os.execve(interpreter, helper_arguments, os.environ.copy())
    finally:
        if snapshot is not None:
            snapshot.close()
        os.close(fd)


def execute(context):
    if context['install_state'] == 'installed':
        return False
    current_data, current_identity = stable_read(
        context['source'], context['expected_uid'], 0o555,
    )
    if current_data != context['source_data'] or current_identity != context['source_identity']:
        fail('source_changed')
    if context['root_prefix'] != Path('/'):
        race = os.environ.get('FH_KUMA_MONITORING_INSTALL_TEST_PUBLISH_RACE', '')
        if race:
            if race not in {'exact', 'foreign'}:
                fail('test_hook_invalid')
            race_data = context['source_data'] if race == 'exact' else b'foreign-installer-race\n'
            race_fd = os.open(
                context['target'],
                os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC | os.O_NOFOLLOW,
                0o555,
            )
            try:
                os.write(race_fd, race_data)
                os.fchmod(race_fd, 0o555)
                os.fsync(race_fd)
            finally:
                os.close(race_fd)
            fsync_directory(context['target'].parent)
    published = publish(
        context['target'], context['source_data'], context['expected_uid'],
        context['root_prefix'],
    )
    try:
        target_state = validate_target(
            context['target'], context['expected_uid'], context['source_data'],
            context['expected_sha256'], context['root_prefix'],
        )
    except ContractError as error:
        if published and not error.mutated:
            raise ContractError(error.reason, True) from error
        raise
    if target_state != 'installed':
        fail('postflight_failed', published)
    return published


def parse_arguments():
    parser = argparse.ArgumentParser()
    parser.add_argument('--source', required=True)
    parser.add_argument('--expected-sha256', required=True)
    parser.add_argument('--root-prefix', default='/')
    parser.add_argument('--execute', action='store_true')
    parser.add_argument('--invoke-installed', choices=('inspect', 'execute'))
    parser.add_argument('--confirm-live-write', default='')
    args = parser.parse_args()
    if re.fullmatch(r'[0-9a-f]{64}', args.expected_sha256) is None:
        fail('expected_hash_invalid')
    if args.execute and args.invoke_installed is not None:
        fail('action_contract_invalid')
    live_write = args.execute or args.invoke_installed == 'execute'
    if live_write:
        if args.confirm_live_write != CONFIRMATION:
            fail('confirmation_invalid')
    elif args.confirm_live_write:
        fail('confirmation_without_execute')
    return args


def main():
    args = parse_arguments()
    context = preflight(args)
    if args.invoke_installed is not None:
        invoke_installed(context, args.invoke_installed == 'execute')
    if not args.execute:
        emit('pass', True, False, context['install_state'])
        return
    lock = mapped(context['root_prefix'], LOCK_PATH)
    validate_ancestors(lock.parent, context['root_prefix'], context['expected_uid'])
    lock_fd = os.open(lock, os.O_RDWR | os.O_CREAT | os.O_CLOEXEC | os.O_NOFOLLOW, 0o600)
    try:
        opened = os.fstat(lock_fd)
        if (
            not stat.S_ISREG(opened.st_mode)
            or opened.st_uid != context['expected_uid']
            or opened.st_gid != trusted_gid(context['expected_uid'])
            or stat.S_IMODE(opened.st_mode) != 0o600
            or opened.st_nlink != 1
            or identity(opened) != identity(os.lstat(lock))
        ):
            fail('lock_invalid')
        try:
            fcntl.flock(lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            fail('lock_busy')
        refreshed = preflight(args)
        mutated = execute(refreshed)
    finally:
        os.close(lock_fd)
    emit('pass', True, mutated, 'installed')


if __name__ == '__main__':
    try:
        main()
    except ContractError as error:
        emit('fail', False, error.mutated, reason=error.reason)
        raise SystemExit(70)
    except (OSError, ValueError, TypeError):
        emit('fail', False, False, reason='internal_error')
        raise SystemExit(70)
