#!/usr/bin/python3
"""Fail-closed installer for the immutable ROB-489 Kuma Push runtime."""

import argparse
import ctypes
import fcntl
import hashlib
import json
import os
import re
import secrets
import shutil
import stat
import sys
from pathlib import Path


SCHEMA = 'fh_kuma_push_runtime_bundle.v1'
RUNTIME = 'v1'
INSTALL_ROOT = '/usr/local/libexec/fh-kuma-push-runtime-v1'
CRON_PATH = '/etc/cron.d/fh-uptime-kuma-push'
STATE_ROOT = '/var/lib/fh-kuma-push-runtime-v1'
LEGACY_ROOT = '/var/www/html/easyappointments'
MANIFEST_PATH = 'scripts/ops/config/kuma_push_runtime_bundle_v1.json'
CONFIRMATION = 'ROB-489'
MAX_FILE_BYTES = 2_000_000
MAX_CRON_BYTES = 64_000
RENAME_NOREPLACE = 1

ENTRYPOINTS = (
    'kuma_push_apache_scanner_activity.sh',
    'kuma_push_app_logs.sh',
    'kuma_push_backup_creation.sh',
    'kuma_push_host_resources.sh',
    'kuma_push_host_services.sh',
    'kuma_push_ops_jobs.sh',
    'kuma_push_pdf_export.sh',
    'kuma_push_pdf_renderer_logs.sh',
    'kuma_push_php_fpm_logs.sh',
)
EXPECTED_INVOCATIONS = sorted(ENTRYPOINTS + ('kuma_push_app_logs.sh',))
EXPECTED_FILES = {
    **{f'scripts/ops/{name}': 'entrypoint' for name in ENTRYPOINTS},
    'scripts/ops/lib/kuma_push_common.sh': 'shell_library',
    'scripts/ops/lib/app_log_classification.sh': 'shell_library',
    'scripts/release-gate/dashboard_release_gate.php': 'pdf_gate',
    'scripts/release-gate/lib/GateAssertions.php': 'pdf_gate_library',
    'scripts/release-gate/lib/GateCliSupport.php': 'pdf_gate_library',
    'scripts/release-gate/lib/GateHttpClient.php': 'pdf_gate_library',
}


class ContractError(Exception):
    def __init__(self, reason, mutated=False):
        super().__init__(reason)
        self.reason = reason
        self.mutated = mutated


def fail(reason, mutated=False):
    raise ContractError(reason, mutated)


def emit(status, ready=False, mutated=False, **extra):
    payload = {
        'bundle_files': len(EXPECTED_FILES),
        'cron_invocations': len(EXPECTED_INVOCATIONS),
        'entrypoints': len(ENTRYPOINTS),
        'execution_ready': ready,
        'mutation_performed': mutated,
        'status': status,
    }
    payload.update(extra)
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


def sha256(data):
    return hashlib.sha256(data).hexdigest()


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
    path = path.resolve(strict=False)
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


def stable_read(path, expected_uid, allowed_modes, maximum=MAX_FILE_BYTES):
    try:
        before = os.lstat(path)
        fd = os.open(path, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK)
    except OSError:
        fail('file_contract_invalid')
    try:
        opened = os.fstat(fd)
        mode = stat.S_IMODE(opened.st_mode)
        if (
            identity(before) != identity(opened)
            or not stat.S_ISREG(opened.st_mode)
            or opened.st_uid != expected_uid
            or opened.st_gid != trusted_gid(expected_uid)
            or opened.st_nlink != 1
            or mode not in allowed_modes
            or (mode & 0o022) != 0
            or opened.st_size <= 0
            or opened.st_size > maximum
        ):
            fail('file_contract_invalid')
        data = bytearray()
        while len(data) <= maximum:
            chunk = os.read(fd, min(65536, maximum + 1 - len(data)))
            if not chunk:
                break
            data.extend(chunk)
        final = os.fstat(fd)
        post = os.lstat(path)
        if identity(opened) != identity(final) or identity(final) != identity(post) or len(data) != opened.st_size:
            fail('file_changed')
        return bytes(data), identity(final)
    finally:
        os.close(fd)


def validate_source_root(source_root, expected_uid, require_canonical):
    if not source_root.is_absolute():
        fail('source_path_invalid')
    resolved = source_root.resolve(strict=True)
    if require_canonical and source_root != resolved:
        fail('source_path_invalid')
    source_root = resolved
    validate_directory(source_root, expected_uid)
    return source_root


def load_contract(source_root, expected_uid):
    manifest_bytes, _ = stable_read(source_root / MANIFEST_PATH, expected_uid, {0o600, 0o640, 0o644})
    try:
        manifest = json.loads(manifest_bytes)
    except (UnicodeDecodeError, json.JSONDecodeError):
        fail('manifest_invalid')
    if not isinstance(manifest, dict) or set(manifest) != {
        'schema', 'runtime', 'install_root', 'cron_path', 'cron_source', 'cron_sha256', 'files'
    }:
        fail('manifest_invalid')
    if (
        manifest['schema'] != SCHEMA
        or manifest['runtime'] != RUNTIME
        or manifest['install_root'] != INSTALL_ROOT
        or manifest['cron_path'] != CRON_PATH
        or manifest['cron_source'] != 'scripts/ops/config/fh-uptime-kuma-push.cron'
        or not re.fullmatch(r'[0-9a-f]{64}', str(manifest['cron_sha256']))
        or not isinstance(manifest['files'], list)
        or len(manifest['files']) != len(EXPECTED_FILES)
    ):
        fail('manifest_invalid')

    contract = {}
    for entry in manifest['files']:
        if not isinstance(entry, dict) or set(entry) != {'source', 'install', 'role', 'sha256'}:
            fail('manifest_invalid')
        source = entry['source']
        if (
            source not in EXPECTED_FILES
            or entry['install'] != source
            or entry['role'] != EXPECTED_FILES[source]
            or source in contract
            or not re.fullmatch(r'[0-9a-f]{64}', str(entry['sha256']))
        ):
            fail('manifest_invalid')
        contract[source] = entry['sha256']
    if set(contract) != set(EXPECTED_FILES):
        fail('manifest_invalid')

    sources = {}
    for relative, expected_hash in contract.items():
        source_path = source_root / relative
        if source_path.resolve(strict=False) != source_path:
            fail('source_path_invalid')
        validate_ancestors(source_path.parent, source_root, expected_uid)
        data, source_identity = stable_read(source_path, expected_uid, {0o500, 0o555, 0o600, 0o640, 0o644, 0o700, 0o750, 0o755})
        if sha256(data) != expected_hash:
            fail('source_hash_mismatch')
        sources[relative] = (data, source_identity)

    cron_path = source_root / manifest['cron_source']
    validate_ancestors(cron_path.parent, source_root, expected_uid)
    cron_template, _ = stable_read(cron_path, expected_uid, {0o600, 0o640, 0o644})
    if sha256(cron_template) != manifest['cron_sha256']:
        fail('cron_source_hash_mismatch')
    return manifest, sources, cron_template


def classify_cron(data, cron_template, production):
    try:
        text = data.decode('utf-8')
        desired_text = cron_template.decode('utf-8')
    except UnicodeDecodeError:
        fail('cron_invalid')
    legacy_prefix = LEGACY_ROOT + '/scripts/ops/'
    runtime_prefix = INSTALL_ROOT + '/scripts/ops/'
    legacy_names = re.findall(re.escape(legacy_prefix) + r'(kuma_push_[a-z_]+\.sh)', text)
    runtime_names = re.findall(re.escape(runtime_prefix) + r'(kuma_push_[a-z_]+\.sh)', text)
    if legacy_names and runtime_names:
        fail('cron_mixed_runtime')
    names = legacy_names or runtime_names
    if sorted(names) != EXPECTED_INVOCATIONS or len(names) != 10:
        fail('cron_invocation_set_invalid')
    if LEGACY_ROOT in text.replace(legacy_prefix, ''):
        fail('cron_legacy_executable_invalid')
    migrated = text.replace(legacy_prefix, runtime_prefix)
    if runtime_names and migrated != text:
        fail('cron_invalid')
    if production:
        expected = desired_text.replace(runtime_prefix, legacy_prefix) if legacy_names else desired_text
        if text != expected:
            fail('cron_bytes_invalid')
    return ('legacy' if legacy_names else 'installed'), migrated.encode('utf-8')


def validate_target(target, source_contract, expected_uid):
    if not target.exists():
        return False
    validate_directory(target, expected_uid, 0o755)
    seen_files = set()
    seen_dirs = {'.'}
    for current, directories, files in os.walk(target, topdown=True, followlinks=False):
        current_path = Path(current)
        validate_directory(current_path, expected_uid, 0o755)
        relative_dir = current_path.relative_to(target)
        seen_dirs.add(str(relative_dir))
        for name in directories:
            child = current_path / name
            if child.is_symlink():
                fail('target_invalid')
        for name in files:
            relative = str((relative_dir / name))
            data, _ = stable_read(current_path / name, expected_uid, {0o555})
            if relative not in source_contract or sha256(data) != sha256(source_contract[relative][0]):
                fail('target_invalid')
            seen_files.add(relative)
    expected_dirs = {'.'}
    for relative in source_contract:
        parent = Path(relative).parent
        while str(parent) != '.':
            expected_dirs.add(str(parent))
            parent = parent.parent
    if seen_files != set(source_contract) or seen_dirs != expected_dirs:
        fail('target_invalid')
    return True


def rename_noreplace(parent, source, target):
    libc = ctypes.CDLL(None, use_errno=True)
    parent_fd = os.open(parent, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
    try:
        if hasattr(libc, 'renameat2'):
            result = libc.renameat2(parent_fd, source.encode(), parent_fd, target.encode(), RENAME_NOREPLACE)
        elif sys.platform == 'darwin' and hasattr(libc, 'renameatx_np'):
            result = libc.renameatx_np(parent_fd, source.encode(), parent_fd, target.encode(), 0x00000004)
        else:
            fail('rename_noreplace_unavailable')
        if result != 0:
            error = ctypes.get_errno()
            raise OSError(error, os.strerror(error))
    finally:
        os.close(parent_fd)


def write_file_exclusive(path, data, mode, expected_uid):
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC | os.O_NOFOLLOW, mode)
    try:
        offset = 0
        while offset < len(data):
            offset += os.write(fd, data[offset:])
        os.fchmod(fd, mode)
        if os.geteuid() == 0:
            os.fchown(fd, expected_uid, trusted_gid(expected_uid))
        os.fsync(fd)
    finally:
        os.close(fd)


def install_bundle(target, source_contract, expected_uid):
    parent = target.parent
    temporary = parent / ('.fh-kuma-push-runtime-v1.pending-' + secrets.token_hex(16))
    os.mkdir(temporary, 0o700)
    published = False
    try:
        for relative, (data, _) in source_contract.items():
            destination = temporary / relative
            destination.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
            write_file_exclusive(destination, data, 0o555, expected_uid)
        for current, _, _ in os.walk(temporary, topdown=False):
            os.chmod(current, 0o755)
            if os.geteuid() == 0:
                os.chown(current, expected_uid, trusted_gid(expected_uid))
            fd = os.open(current, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
            try:
                os.fsync(fd)
            finally:
                os.close(fd)
        rename_noreplace(parent, temporary.name, target.name)
        published = True
    finally:
        if not published and temporary.exists():
            shutil.rmtree(temporary)


def ensure_state(root_prefix, expected_uid):
    state = mapped(root_prefix, STATE_ROOT)
    parent = state.parent
    validate_ancestors(parent, root_prefix, expected_uid)
    if not state.exists():
        os.mkdir(state, 0o700)
        if os.geteuid() == 0:
            os.chown(state, expected_uid, trusted_gid(expected_uid))
        parent_fd = os.open(parent, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
        try:
            os.fsync(parent_fd)
        finally:
            os.close(parent_fd)
    validate_directory(state, expected_uid, 0o700)
    return state


def fsync_directory(path):
    directory_fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
    try:
        os.fsync(directory_fd)
    finally:
        os.close(directory_fd)


def attach_recovery(state, cron_data, desired_data, expected_uid):
    backup = state / 'rob-489-cron.before'
    recovery = state / 'rob-489-recovery.json'
    recovery_data = (json.dumps({
        'cron_path': CRON_PATH,
        'desired_sha256': sha256(desired_data),
        'issue': CONFIRMATION,
        'original_sha256': sha256(cron_data),
        'runtime_root': INSTALL_ROOT,
        'schema': 'fh_kuma_push_runtime_recovery.v1',
    }, sort_keys=True, separators=(',', ':')) + '\n').encode('utf-8')
    for path, data in ((backup, cron_data), (recovery, recovery_data)):
        if path.exists():
            attached, _ = stable_read(path, expected_uid, {0o600})
            if attached != data:
                fail('recovery_conflict')
        else:
            write_file_exclusive(path, data, 0o600, expected_uid)
    fsync_directory(state)
    return backup


def atomic_replace(path, data, expected_uid, expected_identity):
    current = os.lstat(path)
    if identity(current) != expected_identity:
        fail('cron_changed')
    temporary = path.parent / ('.fh-uptime-kuma-push.rob489-' + secrets.token_hex(16) + '.tmp')
    try:
        write_file_exclusive(temporary, data, 0o644, expected_uid)
        if identity(os.lstat(path)) != expected_identity:
            fail('cron_changed')
        os.replace(temporary, path)
    finally:
        try:
            os.unlink(temporary)
        except FileNotFoundError:
            pass


def remove_published_bundle(target, source_contract, expected_uid):
    validate_target(target, source_contract, expected_uid)
    shutil.rmtree(target)
    parent_fd = os.open(target.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
    try:
        os.fsync(parent_fd)
    finally:
        os.close(parent_fd)


def preflight(args):
    requested_root_prefix = Path(args.root_prefix)
    if not requested_root_prefix.is_absolute():
        fail('root_prefix_invalid')
    root_prefix = requested_root_prefix.resolve(strict=True)
    production = root_prefix == Path('/')
    if production and requested_root_prefix != root_prefix:
        fail('root_prefix_invalid')
    expected_uid = 0 if production else os.geteuid()
    if production and os.geteuid() != 0:
        fail('root_required')
    validate_directory(root_prefix, expected_uid)
    source_root = validate_source_root(Path(args.source_root), expected_uid, production)
    if production:
        validate_ancestors(source_root.parent, Path('/'), expected_uid)
    manifest, sources, cron_template = load_contract(source_root, expected_uid)

    target = mapped(root_prefix, INSTALL_ROOT)
    validate_ancestors(target.parent, root_prefix, expected_uid)
    installed = validate_target(target, sources, expected_uid)

    cron = mapped(root_prefix, CRON_PATH)
    validate_ancestors(cron.parent, root_prefix, expected_uid)
    cron_data, cron_identity = stable_read(cron, expected_uid, {0o644}, MAX_CRON_BYTES)
    cron_state, desired_data = classify_cron(cron_data, cron_template, production)
    if cron_state == 'installed' and not installed:
        fail('cron_without_bundle')
    return {
        'cron': cron,
        'cron_data': cron_data,
        'cron_identity': cron_identity,
        'cron_state': cron_state,
        'desired_data': desired_data,
        'expected_uid': expected_uid,
        'installed': installed,
        'manifest': manifest,
        'production': production,
        'root_prefix': root_prefix,
        'source_root': source_root,
        'sources': sources,
        'target': target,
    }


def execute(context):
    target_created = False
    cron_replaced = False
    backup = None
    try:
        if not context['installed']:
            install_bundle(context['target'], context['sources'], context['expected_uid'])
            target_created = True
            if (
                not context['production']
                and os.environ.get('FH_KUMA_PUSH_RUNTIME_TEST_FAIL_BUNDLE_DURABILITY') == '1'
            ):
                fail('test_failure_during_bundle_durability')
            fsync_directory(context['target'].parent)
        if context['cron_state'] == 'legacy':
            state = ensure_state(context['root_prefix'], context['expected_uid'])
            backup = attach_recovery(
                state, context['cron_data'], context['desired_data'], context['expected_uid'],
            )
            atomic_replace(
                context['cron'], context['desired_data'], context['expected_uid'], context['cron_identity'],
            )
            cron_replaced = True
            if (
                not context['production']
                and os.environ.get('FH_KUMA_PUSH_RUNTIME_TEST_FAIL_CRON_DURABILITY') == '1'
            ):
                fail('test_failure_during_cron_durability')
            fsync_directory(context['cron'].parent)
            if (
                not context['production']
                and os.environ.get('FH_KUMA_PUSH_RUNTIME_TEST_FAIL_AFTER_CRON_REPLACE') == '1'
            ):
                fail('test_failure_after_cron_replace')
        refreshed = preflight(argparse.Namespace(
            root_prefix=str(context['root_prefix']),
            source_root=str(context['source_root']),
        ))
        if not refreshed['installed'] or refreshed['cron_state'] != 'installed':
            fail('postflight_failed')
        return target_created or cron_replaced
    except BaseException:
        rollback_error = None
        if cron_replaced and backup is not None:
            try:
                backup_data, _ = stable_read(backup, context['expected_uid'], {0o600})
                _, current_identity = stable_read(
                    context['cron'], context['expected_uid'], {0o644}, MAX_CRON_BYTES,
                )
                atomic_replace(context['cron'], backup_data, context['expected_uid'], current_identity)
                if (
                    not context['production']
                    and os.environ.get('FH_KUMA_PUSH_RUNTIME_TEST_FAIL_CRON_ROLLBACK_DURABILITY') == '1'
                ):
                    fail('test_failure_during_cron_rollback_durability')
                fsync_directory(context['cron'].parent)
            except BaseException as error:
                rollback_error = error
        if target_created and rollback_error is None:
            try:
                remove_published_bundle(context['target'], context['sources'], context['expected_uid'])
            except BaseException as error:
                rollback_error = rollback_error or error
        if rollback_error is not None:
            fail('rollback_failed', True)
        raise


def parse_arguments():
    parser = argparse.ArgumentParser(add_help=True)
    parser.add_argument('--source-root', required=True)
    parser.add_argument('--root-prefix', default='/')
    parser.add_argument('--execute', action='store_true')
    parser.add_argument('--confirm-live-write', default='')
    args = parser.parse_args()
    if args.execute:
        if args.confirm_live_write != CONFIRMATION:
            fail('confirmation_invalid')
    elif args.confirm_live_write:
        fail('confirmation_without_execute')
    return args


def main():
    args = parse_arguments()
    context = preflight(args)
    if not args.execute:
        emit('pass', True, False, bundle_installed=context['installed'], cron_state=context['cron_state'])
        return
    lock_path = mapped(context['root_prefix'], '/run/fh-kuma-push-runtime-v1.lock')
    validate_ancestors(lock_path.parent, context['root_prefix'], context['expected_uid'])
    lock_fd = os.open(lock_path, os.O_RDWR | os.O_CREAT | os.O_CLOEXEC | os.O_NOFOLLOW, 0o600)
    try:
        lock_stat = os.fstat(lock_fd)
        if (
            not stat.S_ISREG(lock_stat.st_mode)
            or lock_stat.st_uid != context['expected_uid']
            or lock_stat.st_gid != trusted_gid(context['expected_uid'])
            or stat.S_IMODE(lock_stat.st_mode) != 0o600
            or lock_stat.st_nlink != 1
            or identity(lock_stat) != identity(os.lstat(lock_path))
        ):
            fail('lock_invalid')
        try:
            fcntl.flock(lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            fail('lock_busy')
        mutated = execute(context)
    finally:
        os.close(lock_fd)
    emit('pass', True, mutated, bundle_installed=True, cron_state='installed')


try:
    main()
except ContractError as error:
    emit('fail', False, error.mutated, reason=error.reason)
    raise SystemExit(70)
except (OSError, ValueError, TypeError):
    emit('fail', False, False, reason='internal_error')
    raise SystemExit(70)
