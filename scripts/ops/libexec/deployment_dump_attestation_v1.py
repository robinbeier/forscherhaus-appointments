#!/usr/bin/python3
import base64
import ctypes
import datetime
import errno
import fcntl
import gzip
import hashlib
import json
import os
import re
import select
import signal
import stat
import subprocess
import sys
import time

BACKUP_ROOT = '/root/backups/easyappointments'
EVIDENCE_ROOT = '/var/lib/fh-deploy-evidence'
ORCHESTRATOR_ROOT = '/var/lib/fh-deploy-orchestrator'
DOCKER = '/usr/bin/docker'
PHP = '/usr/bin/php'
TERMINAL_VALIDATOR = '/usr/local/libexec/fh/validate_deployment_terminal_bundle_v1.php'
TERMINAL_CONTRACT = '/usr/local/libexec/fh/DeploymentContractV1.php'
IMAGE = 'mariadb@sha256:2f2b6bbcdbaf88afe53b76cb8d73927b623559180c5ab15db2049736f32ec590'
MAX_COMPRESSED = 16 * 1024 * 1024 * 1024
MAX_UNCOMPRESSED = 64 * 1024 * 1024 * 1024
MAX_RATIO = 100
FIXED_HEADROOM = 512 * 1024 * 1024
MIN_FREE_INODES = 100_000
MAX_DELETE_ENTRIES = 1_000_000
MAX_RESTORE_BYTES = 16 * 1024 * 1024 * 1024
RESTORE_MULTIPLIER = 3
IMPORT_TIMEOUT = 3600
IBTMP_MAX_BYTES = 256 * 1024 * 1024
REDO_MAX_BYTES = 128 * 1024 * 1024
MAX_ATTESTATION = 4096
MAX_CREATE_TABLES = 10_000
SANDBOX_PREAMBLES = (
    b'/*M!999999\\- enable the sandbox mode */\n',
    b'/*M!999999\\- enable the sandbox mode */\r\n',
)
ID_RE = re.compile(r'^20[0-9]{6}T[0-9]{6}Z$')
RUN_ID_RE = re.compile(r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')
RUN_RE = re.compile(r'^\.run-[0-9a-f]{32}$')
TEMP_RE = re.compile(r'^\.attestation-[0-9a-f]{64}\.tmp-[0-9a-f]{32}$')
MARKER_TEMP_RE = re.compile(r'^\.last_verify_success\.utc\.tmp-[0-9a-f]{32}$')
RENAME_NOREPLACE = 1
LIBC = ctypes.CDLL(None, use_errno=True)
TERMINAL_STATES = {'succeeded', 'failed_before_write', 'failed_pre_switch',
                   'failed_switch_recovery_required',
                   'failed_post_switch_rollback_succeeded', 'failed_post_switch_rollback_failed',
                   'manual_recovery_required'}
STATE_KEYS = {'active_action', 'deploy', 'events_sha256', 'evidence_sha256', 'intent_sha256', 'post_gates',
              'rollback', 'run_id', 'schema', 'sequence', 'state', 'terminal', 'updated_at_utc'}
INTENT_KEYS = {'artifact_expectation', 'deploy_invocation_count', 'dump_policy', 'exit_code',
               'expected_commit', 'intent_sha256', 'reason', 'record_type', 'recorded_at_utc',
               'release_id', 'run_id', 'schema', 'sequence', 'state', 'traffic_mode'}
TRANSITION_KEYS = {'deploy_invocation_count', 'exit_code', 'intent_sha256', 'previous_state', 'reason',
                   'record_type', 'recorded_at_utc', 'run_id', 'schema', 'sequence', 'state'}
PROGRESS_STATES = ('planned', 'built', 'uploaded', 'accepted', 'lock_acquired',
                   'expected_commit_verified', 'traffic_gate_passed', 'dump_verified', 'capacity_passed',
                   'artifact_verified', 'deploy_running', 'post_gates_running', 'succeeded')
DEPLOY_STATE_KEYS = {'execution_input_sha256', 'invocation_count', 'observed_exit_code', 'receipt_sha256',
                     'request_sha256', 'unit_invocation_id', 'unit_launch_sha256', 'unit_manager_boot_id',
                     'unit_missing_observed_boot_id', 'unit_name', 'unit_state'}
ROLLBACK_STATE_KEYS = DEPLOY_STATE_KEYS - {'receipt_sha256'} | {'verdict'}
POST_GATE_STATE_KEYS = {'deploy_report_sha256', 'deploy_submission_count', 'deploy_verdict',
                        'rollback_report_sha256', 'rollback_submission_count', 'rollback_verdict'}
EXIT_REASONS = {'ok': 0, 'traffic_hard_stop': 20, 'traffic_evidence_invalid': 21,
                'dump_verification_failed': 22, 'capacity_gate_failed': 23,
                'artifact_verification_failed': 24, 'expected_commit_mismatch': 25, 'deploy_failed': 30,
                'rollback_failed': 31, 'switch_recovery_required': 32, 'contract_invalid': 70,
                'state_conflict': 75, 'interrupted': 143}
STATE_EXITS = {'succeeded': {0}, 'failed_before_write': {20, 21, 22, 23, 24, 25, 70, 75, 143},
               'failed_pre_switch': {30, 143}, 'failed_switch_recovery_required': {32},
               'failed_post_switch_rollback_succeeded': {30},
               'failed_post_switch_rollback_failed': {31}, 'manual_recovery_required': {31, 70, 143}}


class DumpSqlInspector:
    """Streaming validator for the deliberately small MariaDB dump v1 grammar."""

    def __init__(self):
        self.state = 'normal'
        self.executable_comment = False
        self.executable_version = True
        self.quote = None
        self.escaped = False
        self.quote_value = bytearray()
        self.quote_other = False
        self.word = bytearray()
        self.words = []
        self.word_count = 0
        self.last_words = []
        self.flags = set()
        self.engine_values = []
        self.expect_engine = False
        self.create_tables = 0
        self.statements = 0
        self.prefix = bytearray()
        self.prefix_checked = False
        self.sandbox_seen = False

    def feed(self, data):
        for value in data:
            if not self.prefix_checked:
                self.prefix.append(value)
                prefix = bytes(self.prefix)
                if prefix in SANDBOX_PREAMBLES:
                    self.prefix.clear()
                    self.prefix_checked = True
                    self.sandbox_seen = True
                    continue
                if not any(candidate.startswith(prefix) for candidate in SANDBOX_PREAMBLES):
                    self.prefix.clear()
                    self.prefix_checked = True
                    for queued in prefix:
                        self._consume(queued)
                continue
            self._consume(value)

    def finish(self):
        if not self.prefix_checked:
            prefix = bytes(self.prefix)
            if prefix in SANDBOX_PREAMBLES:
                self.sandbox_seen = True
            else:
                for queued in prefix:
                    self._consume(queued)
        if not self.sandbox_seen:
            reject()
        if self.state == 'line_comment':
            self.state = 'normal'
        if self.state not in ('normal', 'dash1', 'slash1') or self.executable_comment:
            reject()
        self._emit_word()
        self._finish_statement()
        return self.create_tables

    def _consume(self, value):
        while True:
            if self.state == 'line_comment':
                if value in (10, 13):
                    self.state = 'normal'
                return
            if self.state == 'block_comment':
                if value == 42:
                    self.state = 'block_star'
                return
            if self.state == 'block_star':
                if value == 47:
                    self.state = 'normal'
                elif value != 42:
                    self.state = 'block_comment'
                return
            if self.state == 'comment_probe':
                if value == 33:
                    self.executable_comment = True
                    self.executable_version = True
                    self.state = 'normal'
                elif value == 77:
                    self.state = 'comment_probe_m'
                else:
                    self.state = 'block_star' if value == 42 else 'block_comment'
                return
            if self.state == 'comment_probe_m':
                if value == 33:
                    self.executable_comment = True
                    self.executable_version = True
                    self.state = 'normal'
                else:
                    self.state = 'block_comment'
                return
            if self.state == 'exec_star':
                if value == 47:
                    self._emit_word()
                    self.executable_comment = False
                    self.executable_version = False
                    self.state = 'normal'
                    return
                self.state = 'normal'
                continue
            if self.state in ('single', 'double', 'backtick'):
                delimiter = {'single': 39, 'double': 34, 'backtick': 96}[self.state]
                if self.escaped:
                    self.escaped = False
                    self.quote_other = True
                    return
                if value == 92:
                    self.escaped = True
                    return
                if value == delimiter:
                    self.state = self.state + '_end'
                elif self.state != 'backtick':
                    if value > 126 or value < 32 or len(self.quote_value) >= 64:
                        self.quote_other = True
                    elif not self.quote_other:
                        self.quote_value.append(value)
                return
            if self.state in ('single_end', 'double_end', 'backtick_end'):
                base = self.state[:-4]
                delimiter = {'single': 39, 'double': 34, 'backtick': 96}[base]
                if value == delimiter:
                    self.state = base
                    self.quote_other = True
                    return
                if base == 'backtick':
                    self._record_word(b'IDENT')
                else:
                    self._record_word(b'STR:OTHER' if self.quote_other else b'STR:' + bytes(self.quote_value).upper())
                self.state = 'normal'
                continue
            if self.state == 'dash1':
                if value == 45:
                    self.state = 'dash2'
                    return
                self.state = 'normal'
                continue
            if self.state == 'dash2':
                if value <= 32:
                    self.state = 'line_comment'
                    return
                self.state = 'normal'
                continue
            if self.state == 'slash1':
                if value == 42:
                    self.state = 'comment_probe'
                    return
                self.state = 'normal'
                continue

            if self.executable_comment and self.executable_version:
                if 48 <= value <= 57:
                    return
                self.executable_version = False
            if self.executable_comment and value == 42:
                self._emit_word()
                self.state = 'exec_star'
                return
            if value == 39:
                self._emit_word()
                self.quote_value.clear()
                self.quote_other = False
                self.state = 'single'
                return
            if value == 34:
                self._emit_word()
                self.quote_value.clear()
                self.quote_other = False
                self.state = 'double'
                return
            if value == 96:
                self._emit_word()
                self.state = 'backtick'
                return
            if value == 35:
                self._emit_word()
                self.state = 'line_comment'
                return
            if value == 45:
                self._emit_word()
                self.state = 'dash1'
                return
            if value == 47:
                self._emit_word()
                self.state = 'slash1'
                return
            if value == 59:
                self._emit_word()
                self._finish_statement()
                return
            if ((65 <= value <= 90) or (97 <= value <= 122) or (48 <= value <= 57) or
                    value in (36, 64, 95)):
                self.word.append(value)
                if len(self.word) > 1024:
                    reject()
                return
            self._emit_word()
            if value == 92 or value > 126 or (value < 32 and value not in (9, 10, 13)):
                reject()
            return

    def _emit_word(self):
        if not self.word:
            return
        self._record_word(bytes(self.word).upper())
        self.word.clear()

    def _record_word(self, word):
        self.word_count += 1
        if len(self.words) < 32:
            self.words.append(word)
        self.last_words = (self.last_words + [word])[-3:]
        if word in {
            b'GLOBAL', b'PERSIST', b'TEMPORARY', b'PARTITION', b'TABLESPACE', b'DIRECTORY',
            b'SELECT', b'OUTFILE', b'DUMPFILE', b'DELIMITER', b'PREPARE', b'EXECUTE', b'CALL',
            b'LOAD', b'PROCEDURE', b'FUNCTION', b'TRIGGER', b'EVENT', b'VIEW', b'PLUGIN',
            b'USER', b'ROLE', b'GRANT', b'REVOKE', b'DUPLICATE', b'REPLACE', b'VALUES', b'WRITE',
            b'LIKE', b'FULLTEXT', b'SPATIAL',
        }:
            self.flags.add(word)
        if self.expect_engine:
            self.engine_values.append(word)
            self.expect_engine = False
        elif word == b'ENGINE':
            self.expect_engine = True

    def _finish_statement(self):
        if not self.words and self.word_count == 0:
            return
        self.statements += 1
        if self.statements > 1_000_000 or self.expect_engine:
            reject()
        words = self.words
        first = words[0] if words else b''
        second = words[1] if len(words) > 1 else b''
        allowed = False
        if first == b'SET':
            allowed_shapes = (
                [b'SET', b'NAMES'], [b'SET', b'NAMES', b'UTF8'], [b'SET', b'NAMES', b'UTF8MB4'],
                [b'SET', b'SQL_MODE', b'STR:NO_AUTO_VALUE_ON_ZERO'],
                [b'SET', b'TIME_ZONE', b'STR:+00:00'],
                [b'SET', b'CHARACTER_SET_CLIENT', b'UTF8'],
                [b'SET', b'CHARACTER_SET_CLIENT', b'UTF8MB3'],
                [b'SET', b'CHARACTER_SET_CLIENT', b'UTF8MB4'],
                [b'SET', b'CHARACTER_SET_CLIENT', b'@SAVED_CS_CLIENT'],
                [b'SET', b'CHARACTER_SET_CLIENT', b'@OLD_CHARACTER_SET_CLIENT'],
                [b'SET', b'CHARACTER_SET_RESULTS', b'@OLD_CHARACTER_SET_RESULTS'],
                [b'SET', b'COLLATION_CONNECTION', b'@OLD_COLLATION_CONNECTION'],
                [b'SET', b'@OLD_CHARACTER_SET_CLIENT', b'@@CHARACTER_SET_CLIENT'],
                [b'SET', b'@OLD_CHARACTER_SET_RESULTS', b'@@CHARACTER_SET_RESULTS'],
                [b'SET', b'@OLD_COLLATION_CONNECTION', b'@@COLLATION_CONNECTION'],
                [b'SET', b'@OLD_TIME_ZONE', b'@@TIME_ZONE'],
                [b'SET', b'@OLD_UNIQUE_CHECKS', b'@@UNIQUE_CHECKS', b'UNIQUE_CHECKS', b'0'],
                [b'SET', b'@OLD_FOREIGN_KEY_CHECKS', b'@@FOREIGN_KEY_CHECKS', b'FOREIGN_KEY_CHECKS', b'0'],
                [b'SET', b'@OLD_SQL_MODE', b'@@SQL_MODE', b'SQL_MODE', b'STR:NO_AUTO_VALUE_ON_ZERO'],
                [b'SET', b'@OLD_SQL_NOTES', b'@@SQL_NOTES', b'SQL_NOTES', b'0'],
                [b'SET', b'SQL_NOTES', b'@OLD_SQL_NOTES'],
                [b'SET', b'UNIQUE_CHECKS', b'@OLD_UNIQUE_CHECKS'],
                [b'SET', b'FOREIGN_KEY_CHECKS', b'@OLD_FOREIGN_KEY_CHECKS'],
                [b'SET', b'SQL_MODE', b'@OLD_SQL_MODE'],
                [b'SET', b'TIME_ZONE', b'@OLD_TIME_ZONE'],
                [b'SET', b'@OLD_NOTE_VERBOSITY', b'@@NOTE_VERBOSITY', b'NOTE_VERBOSITY', b'0'],
                [b'SET', b'NOTE_VERBOSITY', b'@OLD_NOTE_VERBOSITY'],
            )
            allowed = words in allowed_shapes and self.word_count == len(words)
        elif first == b'START':
            allowed = words == [b'START', b'TRANSACTION'] and self.word_count == 2
        elif first == b'COMMIT':
            allowed = self.word_count == 1
        elif first == b'DROP' and second == b'TABLE':
            allowed = words in ([b'DROP', b'TABLE', b'IDENT'],
                                [b'DROP', b'TABLE', b'IF', b'EXISTS', b'IDENT'])
        elif first == b'CREATE' and second == b'TABLE':
            forbidden = {b'TEMPORARY', b'PARTITION', b'TABLESPACE', b'DIRECTORY', b'SELECT', b'LIKE',
                         b'FULLTEXT', b'SPATIAL', b'PROCEDURE', b'FUNCTION', b'TRIGGER', b'EVENT', b'VIEW'}
            allowed = not (forbidden & self.flags) and all(value == b'INNODB' for value in self.engine_values)
            if allowed:
                self.create_tables += 1
                if self.create_tables > MAX_CREATE_TABLES:
                    reject()
        elif first == b'LOCK' and second == b'TABLES':
            allowed = b'WRITE' in self.flags
        elif first == b'UNLOCK' and second == b'TABLES':
            allowed = True
        elif first == b'ALTER' and second == b'TABLE':
            allowed = self.word_count == 5 and words[2] == b'IDENT' and words[3:] in (
                [b'DISABLE', b'KEYS'], [b'ENABLE', b'KEYS'],
            )
        elif first == b'INSERT' and second == b'INTO':
            allowed = b'VALUES' in self.flags and not ({b'SELECT', b'OUTFILE', b'DUMPFILE', b'DUPLICATE'} & self.flags)
        if not allowed or ({b'DELIMITER', b'PREPARE', b'EXECUTE', b'CALL', b'LOAD', b'PLUGIN',
                            b'GRANT', b'REVOKE', b'REPLACE'} & self.flags):
            reject()
        self.words = []
        self.word_count = 0
        self.last_words = []
        self.flags = set()
        self.engine_values = []
        self.expect_engine = False


def reject(code=70):
    sys.stderr.write('dump attestation rejected\n')
    raise SystemExit(code)


def bind_to_parent_death():
    parent = os.getppid()
    if parent == 1 or LIBC.prctl(1, signal.SIGKILL, 0, 0, 0) != 0:
        reject()
    if os.getppid() != parent:
        os.kill(os.getpid(), signal.SIGKILL)


def identity(value):
    return (value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_gid, value.st_nlink,
            value.st_size, value.st_mtime_ns, value.st_ctime_ns)


def safe_directory(meta, exact_mode=None):
    mode = stat.S_IMODE(meta.st_mode)
    if (not stat.S_ISDIR(meta.st_mode) or meta.st_uid != 0 or meta.st_nlink < 2 or
            (exact_mode is not None and mode != exact_mode) or (exact_mode is None and mode & 0o022)):
        reject()


def open_absolute_directory(path, exact_mode=None):
    fd = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW)
    try:
        safe_directory(os.fstat(fd))
        for leaf in path.split('/')[1:]:
            parent = os.fstat(fd)
            before = os.stat(leaf, dir_fd=fd, follow_symlinks=False)
            safe_directory(before)
            child = os.open(leaf, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW, dir_fd=fd)
            if identity(parent) != identity(os.fstat(fd)) or identity(before) != identity(os.fstat(child)):
                os.close(child)
                reject()
            os.close(fd)
            fd = child
        safe_directory(os.fstat(fd), exact_mode)
        return fd
    except BaseException:
        os.close(fd)
        raise


def open_child(parent, leaf, exact_mode=None):
    parent_meta = os.fstat(parent)
    before = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
    safe_directory(before, exact_mode)
    child = os.open(leaf, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW, dir_fd=parent)
    if identity(parent_meta) != identity(os.fstat(parent)) or identity(before) != identity(os.fstat(child)):
        os.close(child)
        reject()
    return child


def ensure_child(parent, leaf):
    try:
        os.mkdir(leaf, 0o700, dir_fd=parent)
        os.fsync(parent)
    except FileExistsError:
        pass
    return open_child(parent, leaf, 0o700)


def write_all(fd, data):
    offset = 0
    while offset < len(data):
        written = os.write(fd, data[offset:])
        if written <= 0:
            reject()
        offset += written


def require_capacity(path, minimum_bytes, minimum_inodes=MIN_FREE_INODES):
    if minimum_bytes < 0 or minimum_bytes > MAX_COMPRESSED + MAX_RESTORE_BYTES + FIXED_HEADROOM:
        reject()
    value = os.statvfs(path)
    if value.f_frsize * value.f_bavail < minimum_bytes or value.f_favail < minimum_inodes:
        reject()


def open_dump(backup_id):
    root = open_absolute_directory(BACKUP_ROOT)
    try:
        backup = open_child(root, backup_id)
        try:
            db = open_child(backup, 'db')
            try:
                before = os.stat('easyappointments.sql.gz', dir_fd=db, follow_symlinks=False)
                fd = os.open('easyappointments.sql.gz', os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK,
                             dir_fd=db)
                after = os.fstat(fd)
                if (identity(before) != identity(after) or not stat.S_ISREG(after.st_mode) or after.st_uid != 0 or
                        after.st_gid != 0 or after.st_nlink != 1 or stat.S_IMODE(after.st_mode) != 0o600 or
                        after.st_size <= 0 or after.st_size > MAX_COMPRESSED):
                    os.close(fd)
                    reject()
                return fd, after
            finally:
                os.close(db)
        finally:
            os.close(backup)
    finally:
        os.close(root)


def delete_tree_at(parent, leaf, expected_device, budget, depth=0, allow_database_tree=False):
    before = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
    if (not stat.S_ISDIR(before.st_mode) or before.st_dev != expected_device or
            (not allow_database_tree and (before.st_uid != 0 or before.st_gid != 0 or
                                          stat.S_IMODE(before.st_mode) != 0o700)) or
            (allow_database_tree and stat.S_IMODE(before.st_mode) & 0o002)):
        reject()
    child = os.open(leaf, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC | os.O_NOFOLLOW, dir_fd=parent)
    try:
        if identity(before) != identity(os.fstat(child)):
            reject()
        for entry in os.listdir(child):
            budget[0] += 1
            if budget[0] > MAX_DELETE_ENTRIES or entry in ('.', '..'):
                reject()
            meta = os.stat(entry, dir_fd=child, follow_symlinks=False)
            database_entry = allow_database_tree or (depth == 0 and entry == 'datadir')
            if meta.st_dev != expected_device or (not database_entry and (meta.st_uid != 0 or meta.st_gid != 0)):
                reject()
            if stat.S_ISDIR(meta.st_mode):
                delete_tree_at(child, entry, expected_device, budget, depth + 1, database_entry)
            elif (stat.S_ISREG(meta.st_mode) and meta.st_nlink == 1 and
                  (database_entry or stat.S_IMODE(meta.st_mode) == 0o600)):
                os.unlink(entry, dir_fd=child)
            else:
                reject()
        os.fsync(child)
    finally:
        os.close(child)
    os.rmdir(leaf, dir_fd=parent)


def reconcile_files(directory, prefix, pattern, directories):
    device = os.fstat(directory).st_dev
    for leaf in os.listdir(directory):
        if not leaf.startswith(prefix):
            continue
        if not pattern.fullmatch(leaf):
            reject()
        meta = os.stat(leaf, dir_fd=directory, follow_symlinks=False)
        if directories:
            if not stat.S_ISDIR(meta.st_mode) or meta.st_uid != 0 or stat.S_IMODE(meta.st_mode) != 0o700:
                reject()
            delete_tree_at(directory, leaf, device, [0])
        else:
            if (not stat.S_ISREG(meta.st_mode) or meta.st_uid != 0 or meta.st_nlink != 1 or
                    stat.S_IMODE(meta.st_mode) != 0o600 or meta.st_size > MAX_ATTESTATION):
                reject()
            os.unlink(leaf, dir_fd=directory)
    os.fsync(directory)


def docker(arguments, timeout=120):
    before = trusted_executable(DOCKER)
    try:
        result = subprocess.run([DOCKER] + arguments, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                text=True, timeout=timeout, check=False, env={'PATH': '/usr/bin:/bin'})
        if result.returncode != 0:
            reject()
        return result.stdout
    finally:
        if before != trusted_executable(DOCKER):
            reject()


def docker_run(arguments, **kwargs):
    before = trusted_executable(DOCKER)
    try:
        return subprocess.run([DOCKER] + arguments, env={'PATH': '/usr/bin:/bin'}, **kwargs)
    finally:
        if before != trusted_executable(DOCKER):
            reject()


def docker_popen(arguments, **kwargs):
    before = trusted_executable(DOCKER)
    process = subprocess.Popen([DOCKER] + arguments, env={'PATH': '/usr/bin:/bin'}, **kwargs)
    return process, before


def verify_docker_after(before):
    if before != trusted_executable(DOCKER):
        reject()


def trusted_executable(path):
    current = ''
    for component in path.strip('/').split('/')[:-1]:
        current += '/' + component
        meta = os.lstat(current)
        if not stat.S_ISDIR(meta.st_mode) or meta.st_uid != 0 or meta.st_gid != 0 or stat.S_IMODE(meta.st_mode) & 0o022:
            reject()
    before = os.lstat(path)
    fd = os.open(path, os.O_PATH | os.O_CLOEXEC | os.O_NOFOLLOW)
    try:
        opened = os.fstat(fd)
    finally:
        os.close(fd)
    after = os.lstat(path)
    if (identity(before) != identity(opened) or identity(opened) != identity(after) or
            not stat.S_ISREG(opened.st_mode) or opened.st_uid != 0 or opened.st_gid != 0 or opened.st_nlink != 1 or
            stat.S_IMODE(opened.st_mode) & 0o022 or not stat.S_IMODE(opened.st_mode) & 0o111):
        reject()
    return identity(opened)


def activity_count():
    patterns = (
        re.compile(r'(^|/)(?:deploy_ea\.sh|deployment_host_runner_v1\.php|zero_surprise_replay\.php)(?:\s|$)'),
        re.compile(r'(^|/)(?:prod_(?:customers|provider)_ui_smoke\.sh|traffic_gate_v1\.php)(?:\s|$)'),
        re.compile(r'(^|/)(?:mysqldump|mariadb-dump|backup_easyappointments\.sh|import_prod_backup\.sh)(?:\s|$)'),
        re.compile(r'(^|/)(?:prod_(?:session|build_cache|release_archive_dump)_retention\.sh)(?:\s|$)'),
    )
    count = 0
    with os.scandir('/proc') as entries:
        for entry in entries:
            if not entry.name.isdigit() or int(entry.name) == os.getpid():
                continue
            try:
                with open('/proc/' + entry.name + '/cmdline', 'rb') as handle:
                    raw = handle.read(131_073)
            except (FileNotFoundError, ProcessLookupError):
                continue
            except (PermissionError, OSError):
                reject()
            if len(raw) > 131_072:
                reject()
            command = raw.replace(b'\0', b' ').decode('utf-8', 'replace').strip()
            if command and any(pattern.search(command) for pattern in patterns):
                count += 1
    return count


def read_stable_bytes(directory, leaf, maximum):
    before = os.stat(leaf, dir_fd=directory, follow_symlinks=False)
    if (not stat.S_ISREG(before.st_mode) or before.st_uid != 0 or before.st_gid != 0 or before.st_nlink != 1 or
            stat.S_IMODE(before.st_mode) != 0o600 or before.st_size <= 0 or before.st_size > maximum):
        reject()
    fd = os.open(leaf, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory)
    try:
        data = os.read(fd, maximum + 1)
        after = os.fstat(fd)
    finally:
        os.close(fd)
    if identity(before) != identity(after) or len(data) > maximum:
        reject()
    return data


def read_stable_json(directory, leaf):
    data = read_stable_bytes(directory, leaf, 4096)
    try:
        value = json.loads(data)
    except json.JSONDecodeError:
        reject()
    if canonical(value) != data:
        reject()
    return value


def parse_utc(value):
    try:
        return datetime.datetime.strptime(value, '%Y-%m-%dT%H:%M:%SZ')
    except (TypeError, ValueError):
        reject(75)


def valid_uuid(value):
    return isinstance(value, str) and re.fullmatch(
        r'[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}', value) is not None


def validate_action_unit(action, value, run_id, intent):
    count = value['invocation_count']
    if count == 0:
        fields = ('unit_name', 'unit_launch_sha256', 'unit_manager_boot_id', 'unit_invocation_id',
                  'unit_missing_observed_boot_id', 'observed_exit_code')
        if any(value[field] is not None for field in fields) or value['unit_state'] != 'not_created':
            reject(75)
        return
    expected_name = 'fh-%s-%s-%s.service' % (action, run_id, intent[:12])
    invocation = value['unit_invocation_id']
    missing_boot = value['unit_missing_observed_boot_id']
    if (value['unit_name'] != expected_name or value['unit_launch_sha256'] is None or
            not valid_uuid(value['unit_manager_boot_id']) or value['unit_state'] == 'not_created'):
        reject(75)
    if value['unit_state'] in {'running', 'exited', 'failed', 'killed'} and (
            not isinstance(invocation, str) or re.fullmatch(r'(?!0{32})[0-9a-f]{32}', invocation) is None):
        reject(75)
    if value['unit_state'] == 'missing':
        if not valid_uuid(missing_boot) or missing_boot == value['unit_manager_boot_id']:
            reject(75)
    elif missing_boot is not None:
        reject(75)
    if value['observed_exit_code'] is not None and value['unit_state'] not in {'exited', 'failed'}:
        reject(75)


def validate_run_journal(events, run_id):
    if (not events or len(events) > 1_048_576 or b'\0' in events or not events.endswith(b'\n') or
            events.endswith(b'\n\n')):
        reject(75)
    records = []
    for line in events[:-1].split(b'\n'):
        try:
            record = json.loads(line)
        except json.JSONDecodeError:
            reject(75)
        if not isinstance(record, dict) or canonical(record) != line + b'\n':
            reject(75)
        records.append(record)
    first = records[0]
    if (set(first) != INTENT_KEYS or first.get('schema') != 'deployment_run.v1' or
            first.get('record_type') != 'intent' or first.get('run_id') != run_id or first.get('sequence') != 1 or
            first.get('state') != 'planned' or first.get('deploy_invocation_count') != 0 or
            first.get('exit_code') != 0 or first.get('reason') != 'ok' or
            first.get('traffic_mode') not in {'normal', 'no-business-traffic'} or
            first.get('dump_policy') != 'fresh_verified_under_240m' or
            first.get('artifact_expectation') != 'build_from_expected_commit' or
            not isinstance(first.get('expected_commit'), str) or re.fullmatch(r'[0-9a-f]{40}', first['expected_commit']) is None or
            not isinstance(first.get('release_id'), str) or re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._-]{0,127}', first['release_id']) is None):
        reject(75)
    fields = {key: first[key] for key in ('artifact_expectation', 'dump_policy', 'expected_commit',
                                          'release_id', 'traffic_mode')}
    intent = hashlib.sha256(canonical(fields)[:-1]).hexdigest()
    if first.get('intent_sha256') != intent:
        reject(75)
    previous = 'planned'
    invocation = 0
    previous_time = parse_utc(first.get('recorded_at_utc'))
    for sequence, record in enumerate(records[1:], 2):
        if (set(record) != TRANSITION_KEYS or record.get('schema') != 'deployment_run.v1' or
                record.get('record_type') != 'transition' or record.get('run_id') != run_id or
                record.get('intent_sha256') != intent or record.get('sequence') != sequence or
                record.get('previous_state') != previous):
            reject(75)
        recorded = parse_utc(record.get('recorded_at_utc'))
        if recorded < previous_time or previous in TERMINAL_STATES:
            reject(75)
        state = record.get('state')
        if previous == 'rollback_running':
            allowed = {'failed_post_switch_rollback_succeeded', 'failed_post_switch_rollback_failed',
                       'manual_recovery_required'}
        else:
            try:
                index = PROGRESS_STATES.index(previous)
            except ValueError:
                reject(75)
            allowed = {PROGRESS_STATES[index + 1]} if index + 1 < len(PROGRESS_STATES) else set()
            if previous == 'post_gates_running':
                allowed.add('rollback_running')
            if index < PROGRESS_STATES.index('deploy_running'):
                allowed.add('failed_before_write')
            if previous == 'deploy_running':
                allowed.update({'failed_pre_switch', 'failed_switch_recovery_required',
                                'failed_post_switch_rollback_succeeded', 'failed_post_switch_rollback_failed',
                                'manual_recovery_required'})
            if previous == 'post_gates_running':
                allowed.add('manual_recovery_required')
        if state not in allowed:
            reject(75)
        count = record.get('deploy_invocation_count')
        expects_one = state in {'deploy_running', 'post_gates_running', 'rollback_running', 'succeeded',
                                'failed_pre_switch', 'failed_switch_recovery_required',
                                'failed_post_switch_rollback_succeeded', 'failed_post_switch_rollback_failed',
                                'manual_recovery_required'}
        if isinstance(count, bool) or count not in (0, 1) or count < invocation or count != int(expects_one):
            reject(75)
        reason = record.get('reason')
        exit_code = record.get('exit_code')
        if EXIT_REASONS.get(reason) != exit_code or exit_code not in STATE_EXITS.get(state, {0}):
            reject(75)
        required = {'traffic_hard_stop': 'expected_commit_verified',
                    'traffic_evidence_invalid': 'expected_commit_verified',
                    'dump_verification_failed': 'traffic_gate_passed',
                    'capacity_gate_failed': 'dump_verified',
                    'artifact_verification_failed': 'capacity_passed',
                    'expected_commit_mismatch': 'lock_acquired'}.get(reason)
        if required is not None and previous != required:
            reject(75)
        previous, invocation, previous_time = state, count, recorded
    return {'intent_sha256': intent, 'state': previous, 'records': len(records),
            'deploy_invocation_count': invocation, 'last': records[-1]}


def validate_terminal_run(state, events, evidence, run_id):
    if (not isinstance(state, dict) or set(state) != STATE_KEYS or
            state.get('schema') != 'deployment_host_runner_state.v1' or state.get('run_id') != run_id or
            not isinstance(state.get('intent_sha256'), str) or
            re.fullmatch(r'[0-9a-f]{64}', state['intent_sha256']) is None or
            isinstance(state.get('sequence'), bool) or not isinstance(state.get('sequence'), int) or
            state['sequence'] <= 0 or not isinstance(state.get('events_sha256'), str) or
            re.fullmatch(r'[0-9a-f]{64}', state['events_sha256']) is None or
            not isinstance(state.get('deploy'), dict) or set(state['deploy']) != DEPLOY_STATE_KEYS or
            not isinstance(state.get('rollback'), dict) or set(state['rollback']) != ROLLBACK_STATE_KEYS or
            not isinstance(state.get('post_gates'), dict) or set(state['post_gates']) != POST_GATE_STATE_KEYS or
            state.get('active_action') not in {'none', 'deploy', 'rollback'} or
            not isinstance(state.get('evidence_sha256'), str) or
            re.fullmatch(r'[0-9a-f]{64}', state['evidence_sha256']) is None):
        reject(75)
    parse_utc(state.get('updated_at_utc'))
    for action in ('deploy', 'rollback'):
        value = state[action]
        if (isinstance(value.get('invocation_count'), bool) or value.get('invocation_count') not in (0, 1) or
                value.get('unit_state') not in {'not_created', 'starting', 'running', 'exited', 'failed',
                                                'killed', 'missing', 'unknown'} or
                (value.get('observed_exit_code') is not None and
                 (isinstance(value['observed_exit_code'], bool) or not isinstance(value['observed_exit_code'], int)))):
            reject(75)
        for field in ('request_sha256', 'execution_input_sha256', 'unit_launch_sha256'):
            if value.get(field) is not None and (not isinstance(value[field], str) or
                                                 re.fullmatch(r'[0-9a-f]{64}', value[field]) is None):
                reject(75)
        for field in ('unit_name', 'unit_manager_boot_id', 'unit_invocation_id', 'unit_missing_observed_boot_id'):
            if value.get(field) is not None and (not isinstance(value[field], str) or not value[field]):
                reject(75)
        validate_action_unit(action, value, run_id, state['intent_sha256'])
    if state['deploy']['request_sha256'] is None:
        reject(75)
    deploy_states = {'deploy_running', 'post_gates_running', 'rollback_running', 'succeeded',
                     'failed_pre_switch', 'failed_switch_recovery_required',
                     'failed_post_switch_rollback_succeeded', 'failed_post_switch_rollback_failed',
                     'manual_recovery_required'}
    if state['deploy']['invocation_count'] != int(state['state'] in deploy_states):
        reject(75)
    if state['active_action'] != 'none' or any(
            value['invocation_count'] == 1 and value['unit_state'] not in {'exited', 'failed', 'killed', 'missing'}
            for value in (state['deploy'], state['rollback'])):
        reject(75)
    if state['deploy']['invocation_count'] == 0:
        if state['deploy']['execution_input_sha256'] is not None or state['deploy']['receipt_sha256'] is not None:
            reject(75)
    elif state['deploy']['execution_input_sha256'] is None:
        reject(75)
    rollback = state['rollback']
    rollback_allowed = state['state'] in {'failed_post_switch_rollback_succeeded',
                                          'failed_post_switch_rollback_failed',
                                          'manual_recovery_required'}
    if rollback['invocation_count'] == 1 and not rollback_allowed:
        reject(75)
    if rollback['invocation_count'] == 0:
        if (rollback['request_sha256'] is not None or rollback['execution_input_sha256'] is not None or
                rollback['verdict'] != 'not_invoked'):
            reject(75)
    elif rollback['request_sha256'] is None or rollback['execution_input_sha256'] is None or rollback['verdict'] == 'not_invoked':
        reject(75)
    if (state['deploy'].get('receipt_sha256') is not None and
            (not isinstance(state['deploy']['receipt_sha256'], str) or
             re.fullmatch(r'[0-9a-f]{64}', state['deploy']['receipt_sha256']) is None)):
        reject(75)
    if state['rollback'].get('verdict') not in {'not_invoked', 'verification_pending', 'succeeded', 'failed', 'unknown'}:
        reject(75)
    post = state['post_gates']
    for field in ('deploy_submission_count', 'rollback_submission_count'):
        if isinstance(post.get(field), bool) or post.get(field) not in (0, 1):
            reject(75)
    if (post.get('deploy_verdict') not in {'not_submitted', 'passed', 'failed'} or
            post.get('rollback_verdict') not in {'not_submitted', 'passed', 'failed'}):
        reject(75)
    for field in ('deploy_report_sha256', 'rollback_report_sha256'):
        if post.get(field) is not None and (not isinstance(post[field], str) or
                                            re.fullmatch(r'[0-9a-f]{64}', post[field]) is None):
            reject(75)
    for subject in ('deploy', 'rollback'):
        count = post[subject + '_submission_count']
        report = post[subject + '_report_sha256']
        verdict = post[subject + '_verdict']
        if (count == 0 and (report is not None or verdict != 'not_submitted')) or (
                count == 1 and (report is None or verdict == 'not_submitted')):
            reject(75)
    if state['state'] == 'succeeded' and (
            state['active_action'] != 'none' or state['deploy']['invocation_count'] != 1 or
            state['deploy']['unit_state'] != 'exited' or state['deploy']['observed_exit_code'] != 0 or
            state['deploy']['receipt_sha256'] is None or post['deploy_verdict'] != 'passed' or
            state['rollback']['invocation_count'] != 0):
        reject(75)
    if state['state'] == 'failed_before_write' and state['deploy']['invocation_count'] != 0:
        reject(75)
    terminal = state.get('terminal')
    if (not isinstance(terminal, dict) or set(terminal) != {'exit_code', 'reason', 'state'} or
            terminal.get('state') not in TERMINAL_STATES or state.get('state') != terminal.get('state') or
            isinstance(terminal.get('exit_code'), bool) or not isinstance(terminal.get('exit_code'), int) or
            not isinstance(terminal.get('reason'), str) or
            EXIT_REASONS.get(terminal.get('reason')) != terminal.get('exit_code') or
            terminal.get('exit_code') not in STATE_EXITS.get(terminal.get('state'), set())):
        reject(75)
    if hashlib.sha256(events).hexdigest() != state['events_sha256']:
        reject(75)
    run = validate_run_journal(events, run_id)
    validated = validate_terminal_bundle_authority(events, evidence)
    if (run['intent_sha256'] != state['intent_sha256'] or run['state'] != state['state'] or
            run['records'] != state['sequence'] or run['deploy_invocation_count'] != state['deploy']['invocation_count'] or
            run['last'].get('exit_code') != terminal['exit_code'] or run['last'].get('reason') != terminal['reason'] or
            not evidence or len(evidence) > 65_536 or hashlib.sha256(evidence).hexdigest() != state['evidence_sha256'] or
            validated != {'intent_sha256': state['intent_sha256'], 'records': state['sequence'],
                          'run_id': run_id, 'schema': 'deployment_terminal_bundle_validation.v1',
                          'state': state['state']}):
        reject(75)


def trusted_program(path, allowed_modes):
    current = ''
    for component in path.strip('/').split('/')[:-1]:
        current += '/' + component
        metadata = os.lstat(current)
        if (not stat.S_ISDIR(metadata.st_mode) or metadata.st_uid != 0 or metadata.st_nlink < 2 or
                stat.S_IMODE(metadata.st_mode) & 0o022):
            reject(75)
    before = os.lstat(path)
    if (not stat.S_ISREG(before.st_mode) or before.st_uid != 0 or before.st_gid != 0 or before.st_nlink != 1 or
            stat.S_IMODE(before.st_mode) not in allowed_modes):
        reject(75)
    return identity(before)


def validate_terminal_bundle_authority(events, evidence):
    validator = trusted_program(TERMINAL_VALIDATOR, {0o555})
    contract = trusted_program(TERMINAL_CONTRACT, {0o444})
    envelope = json.dumps({'events': base64.b64encode(events).decode('ascii'),
                           'evidence': base64.b64encode(evidence).decode('ascii')},
                          sort_keys=True, separators=(',', ':')).encode('ascii')
    try:
        result = subprocess.run([PHP, '-n', TERMINAL_VALIDATOR], input=envelope, stdout=subprocess.PIPE,
                                stderr=subprocess.DEVNULL, timeout=30, check=False)
    except (OSError, subprocess.TimeoutExpired):
        reject(75)
    if (result.returncode != 0 or len(result.stdout) > 4096 or
            trusted_program(TERMINAL_VALIDATOR, {0o555}) != validator or
            trusted_program(TERMINAL_CONTRACT, {0o444}) != contract):
        reject(75)
    try:
        value = json.loads(result.stdout)
    except json.JSONDecodeError:
        reject(75)
    if not isinstance(value, dict) or canonical(value) != result.stdout:
        reject(75)
    return value


def assert_no_nonterminal_runs(orchestrator):
    if activity_count() != 0:
        reject(75)
    try:
        os.stat('active-run.json', dir_fd=orchestrator, follow_symlinks=False)
        reject(75)
    except FileNotFoundError:
        pass
    try:
        runs = open_child(orchestrator, 'runs', 0o700)
    except FileNotFoundError:
        return
    try:
        names = os.listdir(runs)
        if len(names) > 10_000:
            reject()
        for name in names:
            if RUN_ID_RE.fullmatch(name) is None:
                reject()
            run = open_child(runs, name, 0o700)
            try:
                try:
                    state = read_stable_json(run, 'state.json')
                except FileNotFoundError:
                    reject(75)
                try:
                    events = read_stable_bytes(run, 'events.jsonl', 1_048_576)
                except FileNotFoundError:
                    reject(75)
                try:
                    evidence = read_stable_bytes(run, 'evidence.json', 65_536)
                except FileNotFoundError:
                    reject(75)
                validate_terminal_run(state, events, evidence, name)
            finally:
                os.close(run)
    finally:
        os.close(runs)


def reject_docker_orphans():
    if not os.path.isfile(DOCKER) or os.path.islink(DOCKER):
        reject()
    if docker(['ps', '-aq', '--filter', 'label=fh.dump-attestation=v1'], 30).strip():
        reject()
    if docker(['volume', 'ls', '-q', '--filter', 'label=fh.dump-attestation=v1'], 30).strip():
        reject()


def pin_dump(source, source_meta, run_path):
    path = run_path + '/dump.sql.gz'
    target = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC | os.O_NOFOLLOW, 0o600)
    copied = 0
    try:
        while True:
            chunk = os.read(source, 1024 * 1024)
            if not chunk:
                break
            copied += len(chunk)
            if copied > MAX_COMPRESSED:
                reject()
            write_all(target, chunk)
        os.fsync(target)
    finally:
        os.close(target)
    if copied != source_meta.st_size or identity(source_meta) != identity(os.fstat(source)):
        reject()
    pinned = os.open(path, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK)
    meta = os.fstat(pinned)
    if not stat.S_ISREG(meta.st_mode) or meta.st_nlink != 1 or stat.S_IMODE(meta.st_mode) != 0o600:
        os.close(pinned)
        reject()
    digest = hashlib.sha256()
    os.lseek(pinned, 0, os.SEEK_SET)
    while True:
        chunk = os.read(pinned, 1024 * 1024)
        if not chunk:
            break
        digest.update(chunk)
    unpacked = 0
    sql = DumpSqlInspector()
    os.lseek(pinned, 0, os.SEEK_SET)
    with os.fdopen(os.dup(pinned), 'rb', closefd=True) as raw, gzip.GzipFile(fileobj=raw) as stream:
        while True:
            chunk = stream.read(1024 * 1024)
            if not chunk:
                break
            unpacked += len(chunk)
            if unpacked > MAX_UNCOMPRESSED or unpacked > copied * MAX_RATIO:
                reject()
            sql.feed(chunk)
    if unpacked <= 0 or identity(meta) != identity(os.fstat(pinned)):
        os.close(pinned)
        reject()
    return pinned, meta, digest.hexdigest(), copied, unpacked, sql.finish()


def tree_usage(path):
    allocated = 0
    inodes = 0
    root_device = os.lstat(path).st_dev
    for current, directories, files in os.walk(path, topdown=True, followlinks=False):
        current_meta = os.lstat(current)
        if current_meta.st_dev != root_device or not stat.S_ISDIR(current_meta.st_mode):
            reject()
        inodes += 1
        allocated += current_meta.st_blocks * 512
        for leaf in directories + files:
            item = os.lstat(current + '/' + leaf)
            if item.st_dev != root_device or stat.S_ISLNK(item.st_mode) or not (
                    stat.S_ISDIR(item.st_mode) or stat.S_ISREG(item.st_mode)):
                reject()
            if not stat.S_ISDIR(item.st_mode):
                inodes += 1
                allocated += item.st_blocks * 512
    if allocated <= 0 or inodes <= 1:
        reject()
    return allocated, inodes


def restore(pinned, pinned_meta, unpacked, create_tables, run_path, nonce):
    name = 'fh-dump-attestation-' + nonce
    if unpacked > (MAX_RESTORE_BYTES - FIXED_HEADROOM) // RESTORE_MULTIPLIER:
        reject()
    restore_bytes = max(512 * 1024 * 1024, unpacked * RESTORE_MULTIPLIER)
    max_mib = restore_bytes // (1024 * 1024)
    datadir = run_path + '/datadir'
    os.mkdir(datadir, 0o700)
    lease_path = run_path + '/.container-lease'
    lease_create = os.open(lease_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC | os.O_NOFOLLOW, 0o600)
    os.close(lease_create)
    lease_fd = os.open(lease_path, os.O_RDWR | os.O_NONBLOCK | os.O_CLOEXEC | os.O_NOFOLLOW)
    fcntl.flock(lease_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    started = False
    try:
        docker(['run', '-d', '--rm', '--pull', 'never', '--name', name,
                '--label', 'fh.dump-attestation=v1', '--network', 'none', '--read-only',
                '--tmpfs', '/run/mysqld:rw,noexec,nosuid,size=16m',
                '--tmpfs', '/tmp:rw,noexec,nosuid,size=16m',
                '--mount', 'type=bind,source=' + datadir + ',target=/var/lib/mysql',
                '--mount', 'type=bind,source=' + lease_path + ',target=/run/fh-lease,readonly',
                '--memory', str(2 * 1024 * 1024 * 1024), '--memory-swap', str(2 * 1024 * 1024 * 1024),
                '--pids-limit', '256', '--cpus', '2',
                '-e', 'MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1', IMAGE,
                '/bin/sh', '-c',
                'command -v flock >/dev/null || exit 70; '
                '/usr/local/bin/docker-entrypoint.sh mariadbd --skip-log-bin --skip-name-resolve '
                '--innodb-file-per-table=OFF --innodb-data-file-path=ibdata1:12M:autoextend:max:' + str(max_mib) + 'M '
                '--innodb-log-file-size=64M --innodb-undo-tablespaces=0 --tmp-table-size=64M '
                '--innodb-temp-data-file-path=ibtmp1:12M:autoextend:max:256M --tmpdir=/run/mysqld '
                '--max-heap-table-size=64M --default-storage-engine=InnoDB '
                '--enforce-storage-engine=InnoDB --sql-mode=NO_ENGINE_SUBSTITUTION '
                '--disabled-storage-engines=MyISAM,Aria & '
                'child=$!; (flock -s /run/fh-lease -c true; kill -TERM "$child" 2>/dev/null; sleep 10; '
                'kill -KILL "$child" 2>/dev/null) & watcher=$!; '
                'wait "$child"; status=$?; kill "$watcher" 2>/dev/null; wait "$watcher" 2>/dev/null; exit "$status"'], 120)
        started = True
        for attempt in range(90):
            probe = docker_run(['exec', name, 'mariadb-admin', '-uroot', 'ping'],
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=5, check=False)
            if probe.returncode == 0:
                break
            time.sleep(1)
        else:
            reject()
        version = docker(['exec', name, 'mariadb', '-uroot', '--batch', '--skip-column-names',
                          '-e', 'SELECT VERSION();'], 30).strip()
        if re.match(r'^10\.11\.[0-9]+-MariaDB', version) is None:
            reject()
        docker(['exec', name, 'mariadb', '-uroot', '-e',
                "CREATE DATABASE easyappointments; CREATE USER 'fh_restore'@'localhost'; "
                "GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,DROP,ALTER,INDEX,REFERENCES,LOCK TABLES "
                "ON easyappointments.* TO 'fh_restore'@'localhost';"], 30)
        process, docker_before = docker_popen(
            ['exec', '-i', name, 'mariadb', '-ufh_restore', '--one-database', 'easyappointments'],
            stdin=subprocess.PIPE, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        )
        try:
            deadline = time.monotonic() + IMPORT_TIMEOUT
            os.lseek(pinned, 0, os.SEEK_SET)
            with os.fdopen(os.dup(pinned), 'rb', closefd=True) as raw, gzip.GzipFile(fileobj=raw) as stream:
                while True:
                    chunk = stream.read(1024 * 1024)
                    if not chunk:
                        break
                    view = memoryview(chunk)
                    while view:
                        remaining = deadline - time.monotonic()
                        if remaining <= 0 or process.poll() is not None:
                            reject()
                        writable = select.select([], [process.stdin], [], min(remaining, 5.0))[1]
                        if not writable:
                            continue
                        written = os.write(process.stdin.fileno(), view)
                        if written <= 0:
                            reject()
                        view = view[written:]
            process.stdin.close()
            remaining = deadline - time.monotonic()
            if remaining <= 0 or process.wait(timeout=remaining) != 0:
                reject()
        except BaseException:
            process.kill()
            process.wait()
            raise
        finally:
            verify_docker_after(docker_before)
        count = docker(['exec', name, 'mariadb', '-uroot', '--batch', '--skip-column-names', '-e',
                        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='easyappointments' "
                        "AND table_name IN ('ea_appointments','ea_roles','ea_services','ea_settings','ea_users');"], 60)
        if count.strip() != '5':
            reject()
        non_innodb = docker(['exec', name, 'mariadb', '-uroot', '--batch', '--skip-column-names', '-e',
                             "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='easyappointments' "
                             "AND engine IS NOT NULL AND engine <> 'InnoDB';"], 60)
        if non_innodb.strip() != '0':
            reject()
        check = docker(['exec', name, 'mariadb', '-uroot', '--batch', '--skip-column-names', '-e',
                        'CHECK TABLE easyappointments.ea_appointments,easyappointments.ea_roles,'
                        'easyappointments.ea_services,easyappointments.ea_settings,easyappointments.ea_users QUICK;'], 300)
        rows = [row.split('\t') for row in check.strip().splitlines()]
        if len(rows) != 5 or any(len(row) != 4 or row[2:] != ['status', 'OK'] for row in rows):
            reject()
        docker(['exec', name, 'mariadb-admin', '-uroot', 'shutdown'], 60)
        for attempt in range(60):
            if not docker(['ps', '-q', '--filter', 'name=^/' + name + '$'], 10).strip():
                break
            time.sleep(1)
        else:
            reject()
        values = tree_usage(datadir)
        admitted = restore_bytes + IBTMP_MAX_BYTES + REDO_MAX_BYTES
        if values[0] > admitted or values[1] > max(4096, create_tables * 8 + 4096):
            reject()
        if identity(pinned_meta) != identity(os.fstat(pinned)):
            reject()
        started = False
        return values
    finally:
        if started:
            docker_run(['stop', '--time', '30', name], stdout=subprocess.DEVNULL,
                       stderr=subprocess.DEVNULL, timeout=60, check=False)
        os.close(lease_fd)


def canonical(value):
    return (json.dumps(value, sort_keys=True, separators=(',', ':')) + '\n').encode('ascii')


def validate_attestation(data, digest, size, unpacked, created_at):
    try:
        value = json.loads(data)
    except json.JSONDecodeError:
        reject()
    if len(data) > MAX_ATTESTATION or canonical(value) != data or set(value) != {
            'attested_at_utc', 'dump', 'schema', 'verification'}:
        reject()
    dump = value.get('dump')
    verification = value.get('verification')
    if (value.get('schema') != 'deployment_dump_attestation.v1' or not isinstance(dump, dict) or
            set(dump) != {'created_at_utc', 'sha256', 'size_bytes', 'uncompressed_size_bytes'} or
            dump != {'created_at_utc': created_at, 'sha256': digest, 'size_bytes': size,
                     'uncompressed_size_bytes': unpacked} or not isinstance(verification, dict) or
            set(verification) != {'gzip_verified', 'image', 'method', 'restore_verified', 'restored_at_utc',
                                  'restored_datadir_allocated_bytes', 'restored_datadir_inode_count', 'sha256_verified'} or
            verification.get('method') != 'mariadb_10_11_isolated_restore_v1' or
            verification.get('image') != IMAGE or verification.get('sha256_verified') is not True or
            verification.get('gzip_verified') is not True or verification.get('restore_verified') is not True):
        reject()
    for field in ('restored_datadir_allocated_bytes', 'restored_datadir_inode_count'):
        if isinstance(verification.get(field), bool) or not isinstance(verification.get(field), int) or verification[field] <= 0:
            reject()
    try:
        created = datetime.datetime.strptime(created_at, '%Y-%m-%dT%H:%M:%SZ')
        restored = datetime.datetime.strptime(verification['restored_at_utc'], '%Y-%m-%dT%H:%M:%SZ')
        attested = datetime.datetime.strptime(value['attested_at_utc'], '%Y-%m-%dT%H:%M:%SZ')
    except (KeyError, TypeError, ValueError):
        reject()
    now = datetime.datetime.utcnow()
    if created > restored or restored > attested or attested > now or (now - created).total_seconds() >= 14_400:
        reject()
    return value


def exact_file(directory, leaf, expected):
    fd = os.open(leaf, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory)
    try:
        meta = os.fstat(fd)
        data = os.read(fd, MAX_ATTESTATION + 1)
        if (not stat.S_ISREG(meta.st_mode) or meta.st_uid != 0 or meta.st_gid != 0 or meta.st_nlink != 1 or
                stat.S_IMODE(meta.st_mode) != 0o600 or data != expected):
            reject()
    finally:
        os.close(fd)


def publish(directory, data, digest, nonce):
    leaf = digest + '.json'
    temporary = '.attestation-' + digest + '.tmp-' + nonce
    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC | os.O_NOFOLLOW, 0o600,
                 dir_fd=directory)
    try:
        write_all(fd, data)
        os.fsync(fd)
    finally:
        os.close(fd)
    exact_file(directory, temporary, data)
    result = LIBC.renameat2(directory, temporary.encode(), directory, leaf.encode(), RENAME_NOREPLACE)
    if result == 0:
        os.fsync(directory)
        return 'published'
    if ctypes.get_errno() != errno.EEXIST:
        reject()
    os.unlink(temporary, dir_fd=directory)
    exact_file(directory, leaf, data)
    os.fsync(directory)
    return 'attached'


def success_marker(backups, restored_at, nonce):
    candidate = datetime.datetime.strptime(restored_at, '%Y-%m-%dT%H:%M:%SZ')
    try:
        existing = os.stat('last_verify_success.utc', dir_fd=backups, follow_symlinks=False)
        if (not stat.S_ISREG(existing.st_mode) or existing.st_uid != 0 or existing.st_gid != 0 or
                existing.st_nlink != 1 or stat.S_IMODE(existing.st_mode) != 0o600):
            reject()
        current_before = existing
        current_fd = os.open('last_verify_success.utc', os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK,
                             dir_fd=backups)
        try:
            current_opened = os.fstat(current_fd)
            current_data = os.read(current_fd, 64)
            if os.read(current_fd, 1) or re.fullmatch(rb'20[0-9]{2}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\n', current_data) is None:
                reject()
            current = datetime.datetime.strptime(current_data.decode('ascii').strip(), '%Y-%m-%dT%H:%M:%SZ')
        finally:
            os.close(current_fd)
        current_after = os.stat('last_verify_success.utc', dir_fd=backups, follow_symlinks=False)
        if identity(current_before) != identity(current_opened) or identity(current_opened) != identity(current_after):
            reject()
        if current >= candidate:
            return
    except FileNotFoundError:
        pass
    temporary = '.last_verify_success.utc.tmp-' + nonce
    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC | os.O_NOFOLLOW, 0o600,
                 dir_fd=backups)
    try:
        write_all(fd, (restored_at + '\n').encode('ascii'))
        os.fsync(fd)
    finally:
        os.close(fd)
    os.replace(temporary, 'last_verify_success.utc', src_dir_fd=backups, dst_dir_fd=backups)
    os.fsync(backups)


def attach_existing(attestations, backups, digest, size, unpacked, created_at, nonce):
    leaf = digest + '.json'
    data = read_stable_bytes(attestations, leaf, MAX_ATTESTATION)
    value = validate_attestation(data, digest, size, unpacked, created_at)
    success_marker(backups, value['verification']['restored_at_utc'], nonce)
    return data


def main():
    bind_to_parent_death()
    if len(sys.argv) != 2 or not ID_RE.fullmatch(sys.argv[1]):
        reject()
    try:
        created = datetime.datetime.strptime(sys.argv[1], '%Y%m%dT%H%M%SZ').replace(tzinfo=datetime.timezone.utc)
    except ValueError:
        reject()
    now = datetime.datetime.now(datetime.timezone.utc)
    if created > now or (now - created).total_seconds() >= 14_400:
        reject()
    created_at = created.strftime('%Y-%m-%dT%H:%M:%SZ')
    orchestrator = open_absolute_directory(ORCHESTRATOR_ROOT, 0o700)
    locks = open_child(orchestrator, 'locks', 0o700)
    global_lock = os.open('fh-production-change.lock', os.O_RDWR | os.O_CLOEXEC | os.O_NOFOLLOW, dir_fd=locks)
    global_meta = os.fstat(global_lock)
    if (not stat.S_ISREG(global_meta.st_mode) or global_meta.st_uid != 0 or global_meta.st_gid != 0 or
            global_meta.st_nlink != 1 or stat.S_IMODE(global_meta.st_mode) != 0o600):
        reject()
    try:
        fcntl.flock(global_lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        reject(75)
    global_after = os.stat('fh-production-change.lock', dir_fd=locks, follow_symlinks=False)
    if identity(global_meta) != identity(global_after):
        reject()
    assert_no_nonterminal_runs(orchestrator)
    evidence = open_absolute_directory(EVIDENCE_ROOT, 0o700)
    backups = open_absolute_directory(BACKUP_ROOT)
    lock = os.open('.dump-attestation.lock', os.O_RDWR | os.O_CREAT | os.O_CLOEXEC | os.O_NOFOLLOW, 0o600,
                   dir_fd=evidence)
    try:
        lock_meta = os.fstat(lock)
        if (not stat.S_ISREG(lock_meta.st_mode) or lock_meta.st_uid != 0 or lock_meta.st_gid != 0 or
                lock_meta.st_nlink != 1 or stat.S_IMODE(lock_meta.st_mode) != 0o600):
            reject()
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            reject(75)
        lock_after = os.stat('.dump-attestation.lock', dir_fd=evidence, follow_symlinks=False)
        if identity(lock_meta) != identity(lock_after):
            reject()
        reject_docker_orphans()
        attestations = ensure_child(evidence, 'dump-attestations')
        scratch = ensure_child(evidence, 'dump-attestation-scratch')
        scratch_path = EVIDENCE_ROOT + '/dump-attestation-scratch'
        try:
            reconcile_files(scratch, '.run-', RUN_RE, True)
            reconcile_files(attestations, '.attestation-', TEMP_RE, False)
            reconcile_files(backups, '.last_verify_success.utc.tmp-', MARKER_TEMP_RE, False)
            nonce = os.urandom(16).hex()
            run_leaf = '.run-' + nonce
            os.mkdir(run_leaf, 0o700, dir_fd=scratch)
            run_path = scratch_path + '/' + run_leaf
            source = None
            pinned = None
            try:
                source, source_meta = open_dump(sys.argv[1])
                require_capacity(EVIDENCE_ROOT, source_meta.st_size + FIXED_HEADROOM)
                pinned, pinned_meta, digest, size, unpacked, create_tables = pin_dump(source, source_meta, run_path)
                leaf = digest + '.json'
                try:
                    existing = os.open(leaf, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK,
                                       dir_fd=attestations)
                except FileNotFoundError:
                    existing = None
                if existing is not None:
                    os.close(existing)
                    data = attach_existing(attestations, backups, digest, size, unpacked, created_at, nonce)
                    status = 'attached'
                else:
                    if unpacked > (MAX_RESTORE_BYTES - FIXED_HEADROOM) // RESTORE_MULTIPLIER:
                        reject()
                    max_datadir = max(512 * 1024 * 1024, unpacked * RESTORE_MULTIPLIER)
                    require_capacity(
                        EVIDENCE_ROOT,
                        size + max_datadir + IBTMP_MAX_BYTES + REDO_MAX_BYTES + FIXED_HEADROOM,
                        max(MIN_FREE_INODES, create_tables * 8 + 4096),
                    )
                    allocated, inodes = restore(pinned, pinned_meta, unpacked, create_tables, run_path, nonce)
                    restored_at = datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
                    value = {'schema': 'deployment_dump_attestation.v1',
                             'dump': {'sha256': digest, 'size_bytes': size,
                                      'uncompressed_size_bytes': unpacked, 'created_at_utc': created_at},
                             'verification': {'method': 'mariadb_10_11_isolated_restore_v1', 'image': IMAGE,
                                              'sha256_verified': True, 'gzip_verified': True,
                                              'restore_verified': True,
                                              'restored_datadir_allocated_bytes': allocated,
                                              'restored_datadir_inode_count': inodes,
                                              'restored_at_utc': restored_at},
                             'attested_at_utc': restored_at}
                    data = canonical(value)
                    if len(data) > MAX_ATTESTATION:
                        reject()
                    status = publish(attestations, data, digest, nonce)
                    success_marker(backups, restored_at, nonce)
                output = {'attestation_bytes_base64': base64.b64encode(data).decode('ascii'),
                          'attestation_sha256': hashlib.sha256(data).hexdigest(), 'dump_sha256': digest,
                          'path': EVIDENCE_ROOT + '/dump-attestations/' + digest + '.json', 'status': status}
                sys.stdout.write(json.dumps(output, sort_keys=True, separators=(',', ':')) + '\n')
            finally:
                original_error = sys.exc_info()[0] is not None
                if pinned is not None:
                    os.close(pinned)
                if source is not None:
                    os.close(source)
                try:
                    delete_tree_at(scratch, run_leaf, os.fstat(scratch).st_dev, [0])
                    os.fsync(scratch)
                except BaseException:
                    if not original_error:
                        raise
        finally:
            os.close(scratch)
            os.close(attestations)
    finally:
        os.close(lock)
        os.close(backups)
        os.close(evidence)
        os.close(global_lock)
        os.close(locks)
        os.close(orchestrator)


if __name__ == '__main__':
    try:
        main()
    except (BrokenPipeError, gzip.BadGzipFile, json.JSONDecodeError, OSError, subprocess.SubprocessError, ValueError):
        reject()
