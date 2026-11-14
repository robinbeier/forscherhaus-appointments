#!/usr/bin/env python3
"""Atomic, crash-recoverable deploy-helper replacement.

The production invocation has no caller-selected target, lock, transaction
directory, or postflight command.  A dropped SSH session leaves the lock and
pending record on disk; the next invocation recovers it before admitting a new
writer.  The script intentionally performs no application deployment.
"""
from __future__ import annotations

import argparse
import fcntl
import hashlib
import json
import os
import stat
import subprocess
import sys
import tempfile
import re
from pathlib import Path

TARGET = Path('/root/deploy_ea.sh')
CANDIDATE = Path('/root/.fh-deploy-ea.sh.candidate')
LOCK = Path('/var/lib/fh-deploy-orchestrator/locks/fh-production-change.lock')
TX_ROOT = Path('/var/lib/fh-deploy-orchestrator/helper-sync.v1')
PENDING = TX_ROOT / 'pending.json'
FOREIGN_MARKERS = (
    Path('/var/lib/fh-deploy-orchestrator/active-run.json'),
    Path('/root/fh-deploy-recovery-pending.v1.json'),
    Path('/var/lib/fh-deploy-orchestrator/backup-timer-transition.v1.json'),
    Path('/var/lib/fh-defense-ordinary/request-unconfirmed'),
    Path('/var/lib/fh-defense-ordinary/run.pending'),
    Path('/var/lib/fh-deploy-orchestrator/csp-report-only-pilot.state.json'),
    Path('/var/lib/fh-zero-surprise-canary/active.json'),
    Path('/var/lib/fh-defense-ordinary/state.json'),
    Path('/var/lib/fh-defense-ordinary/defense-verification.json'),
    Path('/var/lib/fh-defense-ordinary/defense-verification.json.tmp'),
    Path('/var/lib/fh-defense-ordinary/sessions.json'),
    Path('/var/lib/fh-defense-ordinary/sessions.json.tmp'),
)
OLD_SHA256 = '788d3bfa57e75022e2597a2244a767cce8c119ea459400cdb3ee6f18f138c9c5'
OLD_SIZE = 99968
NEW_SHA256 = 'ab9bbc124579fa465c25cdbda5e47f5ab60a450530616e527e41232611a40b28'
NEW_SIZE = 100492

class SyncError(RuntimeError): pass

def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open('rb', buffering=0) as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b''): h.update(chunk)
    return h.hexdigest()

def trusted_dir(path: Path) -> None:
    if not path.is_absolute() or path.is_symlink(): raise SyncError('untrusted_directory')
    cur = Path('/')
    for part in path.parts[1:]:
        cur /= part
        st = cur.lstat()
        if not stat.S_ISDIR(st.st_mode) or stat.S_ISLNK(st.st_mode) or st.st_uid != 0 or stat.S_IMODE(st.st_mode) & 0o022:
            raise SyncError('untrusted_directory')

def ensure_tx_root(path: Path) -> None:
    """Create the operation directory only after its parent is trusted/locked."""
    if not path.exists():
        try: path.mkdir(mode=0o700)
        except FileExistsError: pass
        fsync_dir(path.parent)
    trusted_dir(path)
    for child in path.iterdir():
        if child.name == 'pending.json': continue
        if not re.fullmatch(r'[0-9a-f]{32}', child.name) or not child.is_dir() or child.is_symlink():
            raise SyncError('transaction_root_foreign_content')

def ident(path: Path) -> dict:
    st = path.lstat()
    if not stat.S_ISREG(st.st_mode) or stat.S_ISLNK(st.st_mode) or st.st_uid != 0 or st.st_nlink != 1:
        raise SyncError('invalid_file_identity')
    return {'dev': st.st_dev, 'ino': st.st_ino, 'size': st.st_size,
            'mode': stat.S_IMODE(st.st_mode), 'uid': st.st_uid,
            'gid': st.st_gid, 'nlink': st.st_nlink, 'sha256': sha256(path)}

def fsync_dir(path: Path) -> None:
    fd = os.open(path, os.O_RDONLY | getattr(os, 'O_DIRECTORY', 0))
    try: os.fsync(fd)
    finally: os.close(fd)

def write_json(path: Path, data: dict) -> None:
    fd, tmp = tempfile.mkstemp(prefix='.state.', dir=path.parent)
    try:
        with os.fdopen(fd, 'w', encoding='utf-8') as out:
            json.dump(data, out, sort_keys=True, separators=(',', ':')); out.write('\n'); out.flush(); os.fsync(out.fileno())
        os.chmod(tmp, 0o600); os.replace(tmp, path); fsync_dir(path.parent)
    finally:
        try: os.unlink(tmp)
        except FileNotFoundError: pass

def copy_new(src: Path, dst: Path, mode: int) -> None:
    fd = os.open(dst, os.O_WRONLY | os.O_CREAT | os.O_EXCL, mode)
    try:
        with os.fdopen(fd, 'wb') as out, src.open('rb', buffering=0) as inp:
            for chunk in iter(lambda: inp.read(1024 * 1024), b''): out.write(chunk)
            os.fchown(out.fileno(), 0, 0); out.flush(); os.fsync(out.fileno())
    except Exception:
        try: dst.unlink()
        except FileNotFoundError: pass
        raise
    fsync_dir(dst.parent)

def restore_copy(backup: Path, restore: Path, mode: int) -> None:
    if restore.exists() or restore.is_symlink():
        if restore.is_symlink(): raise SyncError('restore_staging_mismatch')
        try: same = ident(restore)['sha256'] == sha256(backup)
        except SyncError: same = False
        if not same:
            restore.unlink()
            copy_new(backup, restore, mode)
    else:
        copy_new(backup, restore, mode)

def ensure_run_receipt(tx: Path, run_id: str, filename: str, result: str) -> None:
    path = tx / filename
    if path.is_symlink(): raise SyncError('rollback_receipt_invalid')
    if path.exists():
        if not path.is_file(): raise SyncError('rollback_receipt_invalid')
        try: receipt = json.loads(path.read_text(encoding='utf-8'))
        except Exception as exc: raise SyncError('rollback_receipt_invalid') from exc
        if receipt != {'schema': 'helper_sync.receipt.v1', 'run_id': run_id, 'result': result}:
            raise SyncError('rollback_receipt_invalid')
    else:
        write_json(path, {'schema': 'helper_sync.receipt.v1', 'run_id': run_id, 'result': result})

def internal_postflight() -> None:
    """Bound health invariants; no caller-supplied executable is trusted."""
    for unit in ('apache2', 'php8.5-fpm', 'mariadb', 'docker'):
        result = subprocess.run(['/usr/bin/systemctl', 'is-active', '--quiet', unit],
                                stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                                stderr=subprocess.DEVNULL, timeout=20, check=False)
        if result.returncode != 0: raise SyncError(f'postflight_unit_{unit}')
    checks = [
        ('app_https', 200, ['https://dasforscherhaus-leg.de/']),
        ('monitor_https', 302, ['https://monitor.dasforscherhaus-leg.de/']),
        ('renderer_http', 200, ['http://127.0.0.1:3003/healthz']),
    ]
    token = ''
    try: token = Path('/etc/fh/healthz.token').read_text(encoding='utf-8')
    except OSError: raise SyncError('postflight_health_token_missing')
    if not re.fullmatch(r'[A-Za-z0-9._~+/=-]{1,256}\n?', token): raise SyncError('postflight_health_token_invalid')
    token = token.rstrip('\n')
    checks.append(('deep_health', 200, ['http://127.0.0.1/index.php/healthz']))
    for name, expected, args in checks[:-1]:
        result = subprocess.run(['/usr/bin/curl', '--fail', '--silent', '--show-error', '--max-time', '10', '--http1.1', '--write-out', '%{http_code}', *args],
                                stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                stderr=subprocess.DEVNULL, timeout=20, check=False)
        if result.returncode != 0 or result.stdout[-3:] != str(expected).encode(): raise SyncError(f'postflight_{name}')
    result = subprocess.run(['/usr/bin/curl', '--fail', '--silent', '--show-error', '--max-time', '10', '--http1.1', '--write-out', '%{http_code}', '-K', '-', checks[-1][2][0]],
                            input=f'header = "X-Health-Token: {token}"\n'.encode(),
                            stdout=subprocess.PIPE,
                            stderr=subprocess.DEVNULL, timeout=20, check=False)
    if result.returncode != 0 or result.stdout[-3:] != b'200': raise SyncError('postflight_deep_health')
    kuma = subprocess.run(['/usr/bin/sqlite3', '/var/lib/uptime-kuma-data/kuma.db',
                           "SELECT CASE WHEN COUNT(*) > 0 AND SUM(CASE WHEN latest_status = 1 THEN 1 ELSE 0 END) = COUNT(*) THEN 0 ELSE 1 END FROM (SELECT m.id, COALESCE((SELECT h.status FROM heartbeat h WHERE h.monitor_id=m.id ORDER BY h.time DESC LIMIT 1), -1) latest_status FROM monitor m WHERE m.active=1);"],
                          stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=20, check=False)
    if kuma.returncode != 0 or kuma.stdout.strip() != b'0': raise SyncError('postflight_kuma')

def recover_pending() -> None:
    if PENDING.is_symlink(): raise SyncError('pending_state_symlink')
    if not PENDING.exists(): return
    try: state = json.loads(PENDING.read_text(encoding='utf-8'))
    except Exception as exc: raise SyncError('pending_state_unreadable') from exc
    if state.get('schema') != 'helper_sync.v1' or state.get('target') != str(TARGET): raise SyncError('pending_state_invalid')
    run_id = state.get('run_id')
    if not isinstance(run_id, str) or not re.fullmatch(r'[0-9a-f]{32}', run_id) or state.get('tx') != str(TX_ROOT / run_id):
        raise SyncError('pending_state_invalid')
    backup = Path(state.get('backup', ''))
    if backup != TX_ROOT / run_id / 'old-helper' or backup.is_symlink() or not backup.is_file():
        raise SyncError('rollback_source_missing')
    old = state.get('old', {})
    if sha256(backup) != old.get('sha256') or backup.stat().st_size != old.get('size'): raise SyncError('rollback_source_mismatch')
    current = ident(TARGET)
    committed = Path(state['tx']) / 'committed.json'
    if committed.is_file():
        try: receipt = json.loads(committed.read_text(encoding='utf-8'))
        except Exception as exc: raise SyncError('commit_receipt_unreadable') from exc
        if receipt.get('schema') != 'helper_sync.receipt.v1' or receipt.get('run_id') != state.get('run_id'):
            raise SyncError('commit_receipt_invalid')
        if current['sha256'] == state.get('new', {}).get('sha256') and current['size'] == state.get('new', {}).get('size') and current['mode'] == 0o700 and current['uid'] == 0 and current['gid'] == 0 and current['nlink'] == 1:
            raise SyncError('commit_ack_required')
        raise SyncError('commit_target_unknown')
    if current['sha256'] == old.get('sha256') and current['size'] == old.get('size') and current['mode'] == old.get('mode') and current['uid'] == 0 and current['gid'] == 0 and current['nlink'] == 1:
        tx = TX_ROOT / run_id
        rollback_started = tx / 'rollback-started.json'
        rolled_back = tx / 'rolled-back.json'
        aborted = tx / 'aborted-before-replace.json'
        if rollback_started.exists() or rollback_started.is_symlink() or rolled_back.exists() or rolled_back.is_symlink():
            if not rollback_started.exists() or rollback_started.is_symlink() or aborted.exists() or aborted.is_symlink():
                raise SyncError('rollback_receipt_conflict')
            ensure_run_receipt(tx, run_id, 'rollback-started.json', 'rollback_started')
            ensure_run_receipt(tx, run_id, 'rolled-back.json', 'rolled_back')
        else:
            ensure_run_receipt(tx, run_id, 'aborted-before-replace.json', 'aborted_before_replace')
        PENDING.unlink(); fsync_dir(PENDING.parent)
        return
    if current['sha256'] != state.get('new', {}).get('sha256'):
        raise SyncError('pending_target_unknown')
    if (TX_ROOT / run_id / 'aborted-before-replace.json').exists() or (TX_ROOT / run_id / 'aborted-before-replace.json').is_symlink():
        raise SyncError('rollback_receipt_conflict')
    ensure_run_receipt(TX_ROOT / run_id, run_id, 'rollback-started.json', 'rollback_started')
    restore = backup.with_name('restore-helper')
    restore_copy(backup, restore, int(old['mode']))
    os.replace(restore, TARGET); os.chown(TARGET, 0, 0); os.chmod(TARGET, int(old['mode'])); fsync_dir(TARGET.parent)
    if ident(TARGET)['sha256'] != old['sha256']: raise SyncError('rollback_identity_mismatch')
    ensure_run_receipt(TX_ROOT / run_id, run_id, 'rolled-back.json', 'rolled_back')
    PENDING.unlink(); fsync_dir(PENDING.parent)

def check_foreign_markers() -> None:
    for marker in FOREIGN_MARKERS:
        if marker.is_symlink() or marker.exists(): raise SyncError('foreign_recovery_marker')
    check_canary_cleanup_units_clear()

def check_canary_cleanup_units_clear() -> None:
    for unit in ('fh-zero-surprise-canary-cleanup.timer', 'fh-zero-surprise-canary-cleanup.service'):
        try:
            result = subprocess.run(
                ['/usr/bin/systemctl', 'show', unit,
                 '--property=LoadState,ActiveState,SubState,UnitFileState,Result', '--no-pager'],
                stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                timeout=20, check=False,
            )
        except (OSError, subprocess.TimeoutExpired) as exc:
            raise SyncError('canary_cleanup_unit_unknown') from exc
        if result.returncode != 0:
            raise SyncError('canary_cleanup_unit_unknown')
        fields = dict(line.split('=', 1) for line in result.stdout.decode('utf-8').splitlines() if '=' in line)
        if (fields.get('LoadState'), fields.get('ActiveState'), fields.get('SubState'),
            fields.get('UnitFileState')) != ('not-found', 'inactive', 'dead', ''):
            raise SyncError('canary_cleanup_unresolved')

def open_lock() -> int:
    before = LOCK.lstat()
    if not stat.S_ISREG(before.st_mode) or stat.S_ISLNK(before.st_mode) or before.st_uid != 0 or before.st_gid != 0 or stat.S_IMODE(before.st_mode) != 0o600 or before.st_nlink != 1 or before.st_size != 0:
        raise SyncError('lock_identity_invalid')
    fd = os.open(LOCK, os.O_RDWR | getattr(os, 'O_NOFOLLOW', 0))
    try:
        try: fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError: raise SyncError('lock_busy')
        opened = os.fstat(fd); current = LOCK.lstat()
        if (opened.st_dev, opened.st_ino) != (current.st_dev, current.st_ino) or stat.S_ISLNK(current.st_mode) or current.st_size != 0:
            raise SyncError('lock_identity_changed')
        return fd
    except Exception:
        os.close(fd); raise

def transaction_path(run_id: str) -> Path:
    if not re.fullmatch(r'[0-9a-f]{32}', run_id): raise SyncError('run_id_invalid')
    return TX_ROOT / run_id

def inspect_ack(run_id: str) -> dict:
    if not re.fullmatch(r'[0-9a-f]{32}', run_id): raise SyncError('run_id_invalid')
    trusted_dir(LOCK.parent); trusted_dir(TX_ROOT.parent)
    fd = open_lock()
    try:
        try: fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError: raise SyncError('lock_busy')
        ensure_tx_root(TX_ROOT)
        if PENDING.is_symlink(): raise SyncError('pending_state_symlink')
        if not PENDING.exists():
            terminal = TX_ROOT / run_id / 'acknowledged.json'
            if terminal.is_file():
                receipt = json.loads(terminal.read_text(encoding='utf-8'))
                if receipt.get('schema') == 'helper_sync.ack.v1' and receipt.get('run_id') == run_id and receipt.get('result') == 'acknowledged' and receipt.get('new_sha256') == NEW_SHA256:
                    current = ident(TARGET)
                    if current['sha256'] == NEW_SHA256 and current['size'] == NEW_SIZE and current['mode'] == 0o700 and current['uid'] == 0 and current['gid'] == 0 and current['nlink'] == 1:
                        return {'status':'acknowledged','run_id':run_id,'sha256':NEW_SHA256}
            raise SyncError('pending_missing')
        state = json.loads(PENDING.read_text(encoding='utf-8'))
        if state.get('run_id') != run_id: raise SyncError('run_id_mismatch')
        receipt_path = Path(state['tx']) / 'committed.json'
        receipt = json.loads(receipt_path.read_text(encoding='utf-8'))
        if receipt.get('schema') != 'helper_sync.receipt.v1' or receipt.get('result') != 'committed' or receipt.get('run_id') != run_id or receipt.get('new_sha256') != NEW_SHA256:
            raise SyncError('commit_receipt_invalid')
        current = ident(TARGET)
        if current['sha256'] != NEW_SHA256 or current['size'] != NEW_SIZE or current['mode'] != 0o700 or current['uid'] != 0 or current['gid'] != 0 or current['nlink'] != 1:
            raise SyncError('commit_target_unknown')
        check_foreign_markers(); internal_postflight()
        write_json(Path(state['tx']) / 'acknowledged.json', {'schema':'helper_sync.ack.v1','run_id':run_id,'result':'acknowledged','new_sha256':NEW_SHA256})
        PENDING.unlink(); fsync_dir(PENDING.parent)
        return {'status':'acknowledged','run_id':run_id,'sha256':NEW_SHA256}
    finally: os.close(fd)

def execute(candidate_sha: str, candidate_size: int, run_id: str) -> dict:
    if os.geteuid() != 0: raise SyncError('root_required')
    if not re.fullmatch(r'[0-9a-f]{32}', run_id): raise SyncError('run_id_invalid')
    trusted_dir(LOCK.parent); trusted_dir(TX_ROOT.parent)
    if not CANDIDATE.is_file() or CANDIDATE.is_symlink(): raise SyncError('candidate_missing')
    cand = ident(CANDIDATE)
    if cand['uid'] != 0 or cand['gid'] != 0 or cand['mode'] != 0o700 or cand['sha256'] != NEW_SHA256 or cand['size'] != NEW_SIZE:
        raise SyncError('candidate_identity_invalid')
    if candidate_sha != NEW_SHA256 or candidate_size != NEW_SIZE or cand['sha256'] != candidate_sha or cand['size'] != candidate_size:
        raise SyncError('candidate_identity_mismatch')
    fd = open_lock()
    try:
        ensure_tx_root(TX_ROOT)
        check_foreign_markers()
        recover_pending()
        old = ident(TARGET)
        if (old['sha256'] != OLD_SHA256 or old['size'] != OLD_SIZE or old['uid'] != 0 or old['gid'] != 0 or old['mode'] != 0o700 or old['nlink'] != 1):
            raise SyncError('old_helper_identity_invalid')
        # Caller-supplied ID is correlation only; authorization remains bound
        # to hashes, lock identity, and the checks above.
        tx = transaction_path(run_id)
        tx.mkdir(mode=0o700); os.chown(tx, 0, 0); fsync_dir(TX_ROOT)
        backup, staged = tx / 'old-helper', tx / 'new-helper'
        copy_new(TARGET, backup, old['mode']); copy_new(CANDIDATE, staged, cand['mode'])
        if ident(backup)['sha256'] != old['sha256'] or ident(backup)['size'] != old['size'] or ident(backup)['mode'] != old['mode']:
            raise SyncError('backup_identity_mismatch')
        if ident(staged)['sha256'] != cand['sha256'] or ident(staged)['size'] != cand['size'] or ident(staged)['mode'] != cand['mode']:
            raise SyncError('staged_identity_mismatch')
        state = {'schema':'helper_sync.v1','run_id':run_id,'target':str(TARGET),'tx':str(tx),'backup':str(backup),'old':old,'new':cand}
        write_json(PENDING, state)
        try:
            os.replace(staged, TARGET); os.chown(TARGET, 0, 0); os.chmod(TARGET, old['mode']); fsync_dir(TARGET.parent)
            if ident(TARGET)['sha256'] != cand['sha256']: raise SyncError('installed_identity_mismatch')
            internal_postflight()
            write_json(tx / 'committed.json', {'schema':'helper_sync.receipt.v1','run_id':run_id,'result':'committed','new_sha256':cand['sha256']})
            return {'status':'committed_pending_ack','run_id':run_id,'sha256':cand['sha256']}
        except Exception:
            if not backup.exists(): raise SyncError('rollback_source_missing')
            ensure_run_receipt(tx, run_id, 'rollback-started.json', 'rollback_started')
            restore = backup.with_name('restore-helper')
            restore_copy(backup, restore, old['mode'])
            os.replace(restore, TARGET); os.chown(TARGET, 0, 0); os.chmod(TARGET, old['mode']); fsync_dir(TARGET.parent)
            if ident(TARGET)['sha256'] != old['sha256']: raise SyncError('rollback_identity_mismatch')
            ensure_run_receipt(tx, run_id, 'rolled-back.json', 'rolled_back')
            PENDING.unlink(); fsync_dir(PENDING.parent)
            raise
    finally: os.close(fd)

def main(argv: list[str]) -> int:
    p = argparse.ArgumentParser(); p.add_argument('--candidate-sha256'); p.add_argument('--candidate-size', type=int); p.add_argument('--run-id'); p.add_argument('--ack-run-id'); a = p.parse_args(argv)
    try:
        if bool(a.ack_run_id) == bool(a.run_id): raise SyncError('execute_or_ack_required')
        if a.ack_run_id:
            if a.candidate_sha256 is not None or a.candidate_size is not None: raise SyncError('ack_arguments_invalid')
            print(json.dumps(inspect_ack(a.ack_run_id)))
        else:
            if a.candidate_sha256 is None or a.candidate_size is None: raise SyncError('candidate_arguments_required')
            print(json.dumps(execute(a.candidate_sha256, a.candidate_size, a.run_id)))
        return 0
    except SyncError as exc: print(json.dumps({'status':'failed','result_class':str(exc)})); return 20

if __name__ == '__main__': raise SystemExit(main(sys.argv[1:]))
