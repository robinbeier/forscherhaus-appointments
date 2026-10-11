#!/usr/bin/python3
"""One-pass, provenance-pair-only manual archive cleanup.

This operator is deliberately independent of the inactive general retention
service.  It may remove only complete ``.tar.gz`` plus canonical provenance
sidecar pairs selected by an exact, hash-bound plan.
"""

import fcntl
import hashlib
import json
import os
import re
import secrets
import socket
import stat
import sys
import types


SCHEMA = 'manual_archive_cleanup.v1'
HOST = 'booking-server'
WEB_ROOT = '/var/www/html'
RELEASES_ROOT = '/root/releases'
STATE_ROOT = '/var/lib/fh-release-retention'
HELPER_PATH = '/usr/local/libexec/fh-release-archive-dump-retention-v1'
HELPER_SHA256 = 'befdc249e430c43e933a1b63c8d4dcaa81fd9adeff86f353a274e0cd5553b01b'
MAX_PER_PASS = 4
MAX_CLASS_SCAN = 10_000
PAIR = re.compile(r'([A-Za-z0-9._-]{1,128})\.(tar\.gz|build-provenance\.json)\Z')
SHA256 = re.compile(r'[0-9a-f]{64}\Z')


class MutationLedger:
    def __init__(self):
        self.counts = {'quarantined_files': 0, 'deleted_files': 0}
        self.outcome = 'none'

    def reset(self):
        self.counts = {'quarantined_files': 0, 'deleted_files': 0}
        self.outcome = 'none'

    def quarantine(self):
        self.counts['quarantined_files'] += 1
        self.outcome = 'known'

    def deleted(self):
        self.counts['deleted_files'] += 1
        self.outcome = 'known'

    def unknown(self):
        if self.counts['quarantined_files']:
            self.outcome = 'unknown'

    def fields(self):
        return {'mutation_counts': self.counts.copy(), 'mutation_outcome': self.outcome,
                'deletion_performed': self.counts['deleted_files'] > 0}


MUTATIONS = MutationLedger()


class CleanupError(Exception):
    def __init__(self, reason, code=70):
        super().__init__(reason)
        self.reason, self.code = reason, code


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
                or not 0 < before.st_size <= 1_048_576):
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


def pair_identity(helper, releases, release_id):
    archive, size, identity, meta = helper.stable_hash(
        releases, release_id + '.tar.gz', 0, 0, {0o600}, helper.MAX_ARCHIVE_BYTES)
    sidecar, side_identity, side_meta = helper.stable_regular(
        releases, release_id + '.build-provenance.json', 0, 0, {0o600}, helper.MAX_SIDECAR_BYTES)
    helper.validate_provenance(sidecar, release_id, archive, size)
    return {
        'archive_identity': identity,
        'archive_sha256': archive,
        'archive_size_bytes': size,
        'archive_mtime_ns': meta.st_mtime_ns,
        'archive_ctime_ns': meta.st_ctime_ns,
        'provenance_identity': side_identity,
        'provenance_sha256': hashlib.sha256(sidecar).hexdigest(),
        'provenance_size_bytes': len(sidecar),
        'provenance_mtime_ns': side_meta.st_mtime_ns,
        'provenance_ctime_ns': side_meta.st_ctime_ns,
    }


def open_release_pair_lock(helper, releases):
    leaf = '.release-pair.lock'
    before = os.stat(leaf, dir_fd=releases, follow_symlinks=False)
    directory = os.fstat(releases)
    descriptor = os.open(leaf, os.O_RDWR | os.O_CLOEXEC | os.O_NOFOLLOW | os.O_NONBLOCK,
                         dir_fd=releases)
    try:
        opened = os.fstat(descriptor)
        if (helper.file_identity(before) != helper.file_identity(opened)
                or not stat.S_ISREG(opened.st_mode)
                or opened.st_uid != directory.st_uid or opened.st_gid != directory.st_gid
                or stat.S_IMODE(opened.st_mode) != 0o600 or opened.st_nlink != 1):
            reject('release_pair_lock_invalid', 75)
        try:
            fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            reject('release_pair_lock_busy', 75)
        if helper.file_identity(os.stat(leaf, dir_fd=releases, follow_symlinks=False)) != helper.file_identity(opened):
            reject('release_pair_lock_changed', 75)
        return descriptor
    except Exception:
        os.close(descriptor)
        raise


def bounded_web_names(web, helper):
    names = []
    with os.scandir(web) as entries:
        for entry in entries:
            if len(names) >= helper.MAX_CLASS_SCAN:
                reject('web_scan_limit')
            names.append(entry.name)
    return names


def collect(helper, releases, current, rollback):
    names = []
    with os.scandir(releases) as entries:
        for entry in entries:
            if len(names) >= MAX_CLASS_SCAN:
                reject('archive_scan_limit')
            names.append(entry.name)
    grouped = {}
    foreign = 0
    for name in names:
        if name == '.release-pair.lock':
            continue
        match = PAIR.fullmatch(name)
        if match is None:
            foreign += 1
            continue
        grouped.setdefault(match.group(1), set()).add(match.group(2))
    holds = helper.read_legacy_hold() or {}
    hold_binding = hashlib.sha256(canonical(holds)).hexdigest()
    for release_id, sides in grouped.items():
        if release_id in holds:
            # A valid legacy hold can predate provenance sidecars. It still
            # requires the exact held archive, but is never a cleanup target.
            if sides not in ({'tar.gz'}, {'tar.gz', 'build-provenance.json'}):
                reject('protected_archive_missing')
            continue
        if sides != {'tar.gz', 'build-provenance.json'}:
            reject('ambiguous_archive_pair')
    held_archives = {}
    for release_id, hold in holds.items():
        if release_id not in grouped:
            reject('protected_archive_missing')
        archive_sha, archive_size, archive_identity, _ = helper.stable_hash(
            releases, release_id + '.tar.gz', 0, 0, {0o600}, helper.MAX_ARCHIVE_BYTES)
        if archive_sha != hold['sha256'] or archive_size != hold['size_bytes']:
            reject('protected_archive_changed')
        held_archives[release_id] = {'archive_identity': archive_identity,
                                     'archive_sha256': archive_sha,
                                     'archive_size_bytes': archive_size}
        if grouped[release_id] == {'tar.gz', 'build-provenance.json'}:
            held_archives[release_id]['complete_pair'] = pair_identity(helper, releases, release_id)
    protected = {current, rollback}
    for release_id in protected:
        if release_id in holds:
            continue
        if grouped.get(release_id) != {'tar.gz', 'build-provenance.json'}:
            reject('protected_archive_missing')
    protected_pairs = {release_id: pair_identity(helper, releases, release_id)
                       for release_id in sorted(protected - holds.keys())}
    records = []
    for release_id in sorted(grouped):
        if release_id in protected or release_id in holds:
            continue
        pair = pair_identity(helper, releases, release_id)
        records.append({'release_id': release_id, 'pair': pair,
                        'sort_key': max(pair['archive_mtime_ns'], pair['provenance_mtime_ns'])})
    records.sort(key=lambda item: (item['sort_key'], item['release_id']))
    selected = records[:MAX_PER_PASS]
    plan = {
        'schema': SCHEMA,
        'active_release': current,
        'rollback_release': rollback,
        'foreign_entry_count': foreign,
        'unrelated_entry_count': foreign,
        'protected_pair_count': len(protected | set(holds)),
        'protected_pairs': protected_pairs,
        'protected_hold_archives': held_archives,
        'legacy_hold_binding_sha256': hold_binding,
        'eligible_count': len(records),
        'selected': [{'release_id': item['release_id'], 'pair': item['pair']}
                     for item in selected],
    }
    return plan, selected


def _quarantine_file(helper, releases, state, leaf, identity, kind, expected_sha, expected_size):
    pending = '.pending-archive-' + kind + '-' + secrets.token_hex(16)
    try:
        os.stat(pending, dir_fd=state, follow_symlinks=False)
    except FileNotFoundError:
        pass
    else:
        reject('pending_cleanup_collision', 75)
    before = os.stat(leaf, dir_fd=releases, follow_symlinks=False)
    if helper.file_identity(before) != identity:
        reject('candidate_changed', 75)
    # Validate contents while the source still has its required nlink=1.
    if kind == 'archive':
        observed_sha, observed_size, _, _ = helper.stable_hash(
            releases, leaf, 0, 0, {0o600}, helper.MAX_ARCHIVE_BYTES)
    else:
        observed_data, _, _ = helper.stable_regular(
            releases, leaf, 0, 0, {0o600}, helper.MAX_SIDECAR_BYTES)
        observed_sha, observed_size = hashlib.sha256(observed_data).hexdigest(), len(observed_data)
    if observed_sha != expected_sha or (expected_size is not None and observed_size != expected_size):
        reject('candidate_changed', 75)
    stable_before = (before.st_dev, before.st_ino, before.st_mode, before.st_uid,
                     before.st_gid, before.st_size, before.st_mtime_ns)
    # linkat-style no-clobber move: link creates the destination atomically
    # with EEXIST protection, then source unlink completes the same-filesystem
    # move after identity is checked again.
    try:
        os.link(leaf, pending, src_dir_fd=releases, dst_dir_fd=state, follow_symlinks=False)
    except Exception:
        try:
            os.stat(pending, dir_fd=state, follow_symlinks=False)
        except FileNotFoundError:
            pass
        else:
            MUTATIONS.quarantine()
            MUTATIONS.unknown()
        raise
    MUTATIONS.quarantine()
    linked = os.stat(pending, dir_fd=state, follow_symlinks=False)
    source = os.stat(leaf, dir_fd=releases, follow_symlinks=False)
    stable_linked = (linked.st_dev, linked.st_ino, linked.st_mode, linked.st_uid,
                     linked.st_gid, linked.st_size, linked.st_mtime_ns)
    stable_source = (source.st_dev, source.st_ino, source.st_mode, source.st_uid,
                     source.st_gid, source.st_size, source.st_mtime_ns)
    if (stable_linked != stable_before or stable_source != stable_before
            or linked.st_nlink != 2 or source.st_nlink != 2):
        reject('candidate_changed', 75)
    # The recovery link must be durable before the only source name is removed.
    os.fsync(state)
    os.unlink(leaf, dir_fd=releases)
    os.fsync(releases)
    os.fsync(state)
    # Source is gone, so the pending file has the helper's required nlink=1.
    if kind == 'archive':
        pending_sha, pending_size, _, _ = helper.stable_hash(
            state, pending, 0, 0, {0o600}, helper.MAX_ARCHIVE_BYTES)
    else:
        pending_data, _, _ = helper.stable_regular(
            state, pending, 0, 0, {0o600}, helper.MAX_SIDECAR_BYTES)
        pending_sha, pending_size = hashlib.sha256(pending_data).hexdigest(), len(pending_data)
    if pending_sha != expected_sha or (expected_size is not None and pending_size != expected_size):
        reject('quarantined_candidate_changed', 75)
    return pending


def execute_pair(helper, releases, state, item):
    rid, pair = item['release_id'], item['pair']
    pending_archive = _quarantine_file(helper, releases, state, rid + '.tar.gz', pair['archive_identity'], 'archive',
                                       pair['archive_sha256'], pair['archive_size_bytes'])
    try:
        pending_sidecar = _quarantine_file(helper, releases, state, rid + '.build-provenance.json', pair['provenance_identity'], 'sidecar',
                                           pair['provenance_sha256'], pair['provenance_size_bytes'])
    except Exception:
        reject('interrupted_pair', 75)
    # Revalidate both quarantined files through the helper before unlinking.
    for pending, identity, maximum in ((pending_archive, pair['archive_identity'], helper.MAX_ARCHIVE_BYTES),
                                       (pending_sidecar, pair['provenance_identity'], helper.MAX_SIDECAR_BYTES)):
        observed = os.stat(pending, dir_fd=state, follow_symlinks=False)
        if (helper.file_identity(observed)[:5] != identity[:5]
                or observed.st_nlink != 1):
            reject('quarantined_candidate_changed', 75)
        if pending == pending_archive:
            observed_sha, observed_size, observed_identity, _ = helper.stable_hash(
                state, pending, 0, 0, {0o600}, maximum)
            if observed_sha != pair['archive_sha256'] or observed_size != pair['archive_size_bytes']:
                reject('quarantined_candidate_changed', 75)
        else:
            data, observed_identity, _ = helper.stable_regular(state, pending, 0, 0, {0o600}, maximum)
            if hashlib.sha256(data).hexdigest() != pair['provenance_sha256']:
                reject('quarantined_candidate_changed', 75)
            helper.validate_provenance(data, rid, pair['archive_sha256'], pair['archive_size_bytes'])
        if observed_identity[:5] != identity[:5]:
            reject('quarantined_candidate_changed', 75)
    pending_identities = {'identities': {tuple(pair['archive_identity'][:2]),
                                         tuple(pair['provenance_identity'][:2])}}
    if helper.open_file_identities([pending_identities]):
        reject('quarantined_candidate_open', 75)
    os.unlink(pending_archive, dir_fd=state)
    MUTATIONS.deleted()
    os.fsync(state)
    if helper.open_file_identities([pending_identities]):
        reject('quarantined_candidate_open', 75)
    os.unlink(pending_sidecar, dir_fd=state)
    MUTATIONS.deleted()
    os.fsync(state)


def run(mode, expected_plan_sha=None, helper=None):
    if os.geteuid() != 0 or socket.gethostname().split('.')[0] != HOST:
        reject('wrong_runtime')
    helper = helper or load_pinned_helper()
    MUTATIONS.reset()
    global_lock = helper.open_global_lock()
    releases = release_pair_lock = state = web = orchestrator = None
    try:
        if helper.activity_count() != 0:
            reject('active_production_work', 75)
        helper.assert_no_nonterminal_runs()
        releases = helper.open_absolute_directory(RELEASES_ROOT, exact_mode=0o700)
        release_pair_lock = open_release_pair_lock(helper, releases)
        state = helper.open_absolute_directory(STATE_ROOT, exact_mode=0o700)
        web = helper.open_absolute_directory(WEB_ROOT)
        orchestrator = helper.open_absolute_directory(helper.ORCHESTRATOR_ROOT, exact_mode=0o700)
        web_names = bounded_web_names(web, helper)
        helper.assert_no_nested_mounts(web_names, orchestrator)
        try:
            fcntl.flock(state, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            reject('cleanup_lock_busy', 75)
        if any(name.startswith('.pending-') for name in os.listdir(state)):
            reject('pending_cleanup_unresolved', 75)
        if 'easyappointments' not in web_names:
            reject('active_release_missing')
        current_fd = helper.open_child_directory(web, 'easyappointments')
        try:
            current = helper.read_release_marker(current_fd)
        finally:
            os.close(current_fd)
        rollback_fd = helper.open_child_directory(web, 'easyappointments_prev_' + current)
        try:
            rollback = helper.read_release_marker(rollback_fd)
        finally:
            os.close(rollback_fd)
        plan, selected = collect(helper, releases, current, rollback)
        digest = hashlib.sha256(canonical(plan)).hexdigest()
        if mode == 'plan':
            return {'status': 'pass', 'plan_sha256': digest, **plan,
                    'deletion_performed': False, 'mutation_count': 0}
        if digest != expected_plan_sha:
            reject('plan_identity_changed', 75)
        if not selected:
            return {'status': 'pass', 'schema': SCHEMA, 'plan_sha256': digest,
                    'deleted_archive_pairs': 0, 'deletion_performed': False, 'mutation_count': 0}
        # Recollect immediately before mutation so every selected pair is still exact.
        fresh_plan, fresh_selected = collect(helper, releases, current, rollback)
        if hashlib.sha256(canonical(fresh_plan)).hexdigest() != digest:
            reject('plan_identity_changed', 75)
        if helper.open_file_identities([{'identities': {tuple(item['pair']['archive_identity'][:2]), tuple(item['pair']['provenance_identity'][:2])}}
                                       for item in fresh_selected]):
            reject('candidate_open', 75)
        deleted = 0
        protected_names = ('easyappointments', 'easyappointments_prev_' + current)
        protected_dir_ids = {
            name: helper.directory_identity(os.stat(name, dir_fd=web, follow_symlinks=False))
            for name in protected_names
        }
        for item in fresh_selected:
            if helper.activity_count() != 0:
                reject('active_production_work', 75)
            helper.assert_no_nonterminal_runs()
            for name, expected_identity in protected_dir_ids.items():
                protected_fd = helper.open_child_directory(web, name)
                try:
                    expected_marker = current if name == 'easyappointments' else rollback
                    if (helper.directory_identity(os.fstat(protected_fd)) != expected_identity
                            or helper.read_release_marker(protected_fd) != expected_marker):
                        reject('protected_release_changed', 75)
                finally:
                    os.close(protected_fd)
            for protected_id, protected_pair in plan['protected_pairs'].items():
                if pair_identity(helper, releases, protected_id) != protected_pair:
                    reject('protected_archive_changed', 75)
            if hashlib.sha256(canonical(helper.read_legacy_hold() or {})).hexdigest() != plan['legacy_hold_binding_sha256']:
                reject('protected_archive_changed', 75)
            for held_id, held_archive in plan['protected_hold_archives'].items():
                archive_sha, archive_size, archive_identity, _ = helper.stable_hash(
                    releases, held_id + '.tar.gz', 0, 0, {0o600}, helper.MAX_ARCHIVE_BYTES)
                observed_held = {'archive_identity': archive_identity,
                                 'archive_sha256': archive_sha,
                                 'archive_size_bytes': archive_size}
                expected_held = {key: held_archive[key] for key in observed_held}
                if observed_held != expected_held:
                    reject('protected_archive_changed', 75)
                if 'complete_pair' in held_archive and pair_identity(helper, releases, held_id) != held_archive['complete_pair']:
                    reject('protected_archive_changed', 75)
            try:
                execute_pair(helper, releases, state, item)
            except Exception:
                MUTATIONS.unknown()
                raise
            deleted += 1
        return {'status': 'pass', 'schema': SCHEMA, 'plan_sha256': digest,
                'deleted_archive_pairs': deleted, 'deletion_performed': deleted > 0,
                **MUTATIONS.fields()}
    finally:
        for fd in (orchestrator, web, state, release_pair_lock, releases, global_lock):
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
        helper = load_pinned_helper()
        print(canonical(run(mode, expected, helper)).decode(), end='')
    except Exception as error:
        MUTATIONS.unknown()
        known = isinstance(error, CleanupError) or (helper is not None and isinstance(error, helper.RetentionError))
        reason = error.reason if known else 'internal_rejection'
        payload = {'schema': SCHEMA, 'status': 'blocked', 'reason': reason}
        payload.update(MUTATIONS.fields())
        print(canonical(payload).decode(), end='')
        raise SystemExit(error.code if known else 70)


if __name__ == '__main__':
    main(sys.argv[1:])
