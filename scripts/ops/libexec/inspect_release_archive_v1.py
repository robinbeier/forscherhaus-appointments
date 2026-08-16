#!/usr/bin/python3
import hashlib
import json
import os
import stat
import sys
import tarfile

BLOCK = 4096
MAX_ARCHIVE = 17_179_869_184
MAX_ENTRIES = 1_000_000
MAX_UNPACKED = 68_719_476_736

def reject():
    sys.stderr.write('release archive rejected\n')
    raise SystemExit(70)

def main():
    if len(sys.argv) != 2:
        reject()
    path = sys.argv[1]
    if not path.startswith('/') or '\x00' in path:
        reject()
    flags = os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK
    fd = os.open(path, flags)
    try:
        before = os.fstat(fd)
        if not stat.S_ISREG(before.st_mode) or before.st_nlink != 1 or before.st_size <= 0 or before.st_size > MAX_ARCHIVE:
            reject()
        digest = hashlib.sha256()
        with os.fdopen(os.dup(fd), 'rb', closefd=True) as source:
            while True:
                chunk = source.read(1024 * 1024)
                if not chunk:
                    break
                digest.update(chunk)
        os.lseek(fd, 0, os.SEEK_SET)
        member_names = set()
        stage_types = {}
        count = 0
        unpacked = BLOCK
        with os.fdopen(os.dup(fd), 'rb', closefd=True) as source, tarfile.open(fileobj=source, mode='r:gz') as archive:
            for member in archive:
                name = member.name[2:] if member.name.startswith('./') else member.name
                if name in ('', '.') and member.isdir():
                    continue
                if (
                    not name or name.startswith('/') or len(name.encode('utf-8')) > 4096 or
                    name in ('.', '..') or '\x00' in name or '\\' in name or
                    any(part in ('', '.', '..') or len(part.encode('utf-8')) > 255 for part in name.split('/')) or
                    any(ord(character) < 32 or ord(character) == 127 for character in name)
                ):
                    reject()
                if any(part.startswith('._') for part in name.split('/')):
                    reject()
                if name in member_names or not (member.isfile() or member.isdir()):
                    reject()
                member_names.add(name)
                parts = name.split('/')
                for index in range(1, len(parts)):
                    parent_name = '/'.join(parts[:index])
                    if stage_types.get(parent_name) == 'file':
                        reject()
                    if parent_name not in stage_types:
                        stage_types[parent_name] = 'directory'
                        unpacked += BLOCK
                        if unpacked > MAX_UNPACKED:
                            reject()
                member_type = 'directory' if member.isdir() else 'file'
                previous_type = stage_types.get(name)
                if previous_type is not None and previous_type != member_type:
                    reject()
                if previous_type is None:
                    stage_types[name] = member_type
                if member.isfile():
                    count += 1
                if len(member_names) > MAX_ENTRIES or len(stage_types) > MAX_ENTRIES:
                    reject()
                if member.isdir() and previous_type is None:
                    unpacked += BLOCK
                elif member.isfile():
                    unpacked += max(BLOCK, ((member.size + BLOCK - 1) // BLOCK) * BLOCK)
                if unpacked > MAX_UNPACKED:
                    reject()
        after = os.fstat(fd)
        identity = lambda s: (s.st_dev, s.st_ino, s.st_mode, s.st_uid, s.st_nlink, s.st_size, s.st_mtime_ns, s.st_ctime_ns)
        if identity(before) != identity(after):
            reject()
        output = {
            'archive_sha256': digest.hexdigest(),
            'archive_size_bytes': before.st_size,
            'entry_count': count,
            'stage_inode_count': len(stage_types) + 1,
            'stage_unpacked_bytes': unpacked,
        }
        sys.stdout.write(json.dumps(output, sort_keys=True, separators=(',', ':')) + '\n')
    finally:
        os.close(fd)

try:
    main()
except (OSError, ValueError, tarfile.TarError):
    reject()
