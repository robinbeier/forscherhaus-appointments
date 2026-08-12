#!/usr/bin/python3
import base64
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

MAX_BYTES = 1_048_576
RENAME_NOREPLACE = 1
UUID = r'[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}'

def emit(status, data=b''):
    result = {'bytes_base64': base64.b64encode(data).decode('ascii') if data else None,
              'sha256': hashlib.sha256(data).hexdigest() if data else None, 'status': status}
    sys.stdout.write(json.dumps(result, sort_keys=True, separators=(',', ':')) + '\n')

def reject(code=70):
    sys.stderr.write('deploy timing pin rejected\n')
    raise SystemExit(code)

def identity(value):
    return (value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_nlink,
            value.st_size, value.st_mtime_ns, value.st_ctime_ns)

def directory_identity(value):
    return (value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_nlink)

def safe_directory(path, exact_mode):
    if not path.startswith('/') or path == '/': raise OSError('unsafe directory path')
    parent = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW)
    try:
        for index, leaf in enumerate(part for part in path.split('/') if part):
            before = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
            opened_fd = os.open(leaf, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW, dir_fd=parent)
            opened = os.fstat(opened_fd)
            final = index == len([part for part in path.split('/') if part]) - 1
            mode = stat.S_IMODE(opened.st_mode)
            safe_mode = mode == exact_mode if final else (mode & 0o022) == 0
            if (directory_identity(before) != directory_identity(opened)
                    or not stat.S_ISDIR(opened.st_mode) or opened.st_uid != 0 or not safe_mode):
                os.close(opened_fd); raise OSError('unsafe directory')
            os.close(parent)
            parent = opened_fd
        return parent
    except BaseException:
        os.close(parent)
        raise

def safe_child_directory(parent, leaf, exact_mode):
    before = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
    fd = os.open(leaf, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW, dir_fd=parent)
    opened = os.fstat(fd)
    if (directory_identity(before) != directory_identity(opened)
            or not stat.S_ISDIR(opened.st_mode) or opened.st_uid != 0
            or stat.S_IMODE(opened.st_mode) != exact_mode):
        os.close(fd); raise OSError('unsafe directory')
    return fd

def stable_read_at(directory, leaf, missing_ok=False):
    try:
        before = os.stat(leaf, dir_fd=directory, follow_symlinks=False)
        fd = os.open(leaf, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory)
    except FileNotFoundError:
        if missing_ok: return None
        raise
    try:
        opened = os.fstat(fd)
        if identity(before) != identity(opened) or not stat.S_ISREG(opened.st_mode) or opened.st_uid != 0 or stat.S_IMODE(opened.st_mode) != 0o600 or opened.st_nlink != 1 or opened.st_size <= 0 or opened.st_size > MAX_BYTES:
            raise OSError('unsafe timing file')
        data = bytearray()
        while len(data) <= MAX_BYTES:
            chunk = os.read(fd, min(65536, MAX_BYTES + 1 - len(data)))
            if not chunk: break
            data.extend(chunk)
        after = os.fstat(fd)
        post = os.stat(leaf, dir_fd=directory, follow_symlinks=False)
        if identity(opened) != identity(after) or identity(after) != identity(post) or len(data) != opened.st_size:
            raise OSError('timing file changed')
        return bytes(data)
    finally:
        os.close(fd)

def rename_noreplace(directory, source, target):
    libc = ctypes.CDLL(None, use_errno=True)
    result = libc.renameat2(directory, source.encode(), directory, target.encode(), RENAME_NOREPLACE)
    if result != 0:
        error = ctypes.get_errno()
        raise OSError(error, os.strerror(error))

def main():
    if len(sys.argv) != 3 or not re.fullmatch(UUID, sys.argv[1]) or not re.fullmatch(UUID, sys.argv[2]): reject()
    timing_id, run_id = sys.argv[1:]
    source_parent = safe_directory('/var/lib/fh-deploy-timing', 0o700)
    try: data = stable_read_at(source_parent, f'{timing_id}.jsonl', True)
    finally: os.close(source_parent)
    if data is None: emit('not_observed'); return
    state = safe_directory('/var/lib/fh-deploy-orchestrator', 0o700)
    runs = safe_child_directory(state, 'runs', 0o700)
    run = safe_child_directory(runs, run_id, 0o700)
    os.close(runs); os.close(state)
    try:
        # Serialize publication and stale-temp reconciliation on the protected
        # run directory. This prevents one pin attempt from unlinking another
        # attempt's live private temp file.
        fcntl.flock(run, fcntl.LOCK_EX)
        for name in os.listdir(run):
            if not re.fullmatch(r'\.deploy-timing\.jsonl\.tmp-[0-9a-f]{32}', name): continue
            candidate = os.stat(name, dir_fd=run, follow_symlinks=False)
            if not stat.S_ISREG(candidate.st_mode) or candidate.st_uid != 0 or stat.S_IMODE(candidate.st_mode) != 0o600 or candidate.st_nlink != 1 or candidate.st_size > MAX_BYTES:
                reject()
            os.unlink(name, dir_fd=run)
            os.fsync(run)
        current = stable_read_at(run, 'deploy-timing.jsonl', True)
        if current is not None:
            if current != data: reject(75)
            os.fsync(run); emit('attached', current); return
        temp = '.deploy-timing.jsonl.tmp-' + secrets.token_hex(16)
        fd = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK, 0o600, dir_fd=run)
        published = False
        try:
            offset = 0
            while offset < len(data): offset += os.write(fd, data[offset:])
            os.fsync(fd)
            temporary = os.fstat(fd)
            if not stat.S_ISREG(temporary.st_mode) or stat.S_IMODE(temporary.st_mode) != 0o600 or temporary.st_uid != 0 or temporary.st_nlink != 1:
                raise OSError('unsafe temp')
            try: rename_noreplace(run, temp, 'deploy-timing.jsonl'); published = True
            except OSError as error:
                if error.errno != errno.EEXIST: raise
                current = stable_read_at(run, 'deploy-timing.jsonl')
                if current != data: reject(75)
            os.fsync(run)
        finally:
            os.close(fd)
            try: os.unlink(temp, dir_fd=run)
            except FileNotFoundError: pass
        final = stable_read_at(run, 'deploy-timing.jsonl')
        if final != data: reject()
        emit('pinned' if published else 'attached', final)
    finally: os.close(run)

try: main()
except SystemExit: raise
except (OSError, ValueError, TypeError): reject()
