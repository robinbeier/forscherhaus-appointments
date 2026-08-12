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
        names = set()
        count = 0
        unpacked = BLOCK
        with os.fdopen(os.dup(fd), 'rb', closefd=True) as source, tarfile.open(fileobj=source, mode='r:gz') as archive:
            for member in archive:
                name = member.name[2:] if member.name.startswith('./') else member.name
                if name in ('', '.') and member.isdir():
                    continue
                if not name or name.startswith('/') or name in ('.', '..') or any(part in ('', '.', '..') for part in name.split('/')):
                    reject()
                if any(part.startswith('._') for part in name.split('/')):
                    reject()
                if name in names or not (member.isfile() or member.isdir()):
                    reject()
                names.add(name)
                if member.isfile():
                    count += 1
                if len(names) > MAX_ENTRIES:
                    reject()
                if member.isdir():
                    unpacked += BLOCK
                else:
                    unpacked += max(BLOCK, ((member.size + BLOCK - 1) // BLOCK) * BLOCK)
        after = os.fstat(fd)
        identity = lambda s: (s.st_dev, s.st_ino, s.st_mode, s.st_uid, s.st_nlink, s.st_size, s.st_mtime_ns, s.st_ctime_ns)
        if identity(before) != identity(after):
            reject()
        output = {'archive_sha256': digest.hexdigest(), 'archive_size_bytes': before.st_size, 'entry_count': count, 'stage_unpacked_bytes': unpacked}
        sys.stdout.write(json.dumps(output, sort_keys=True, separators=(',', ':')) + '\n')
    finally:
        os.close(fd)

try:
    main()
except (OSError, ValueError, tarfile.TarError):
    reject()
