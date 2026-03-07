import assert from 'node:assert/strict';
import {execFile as execFileCallback} from 'node:child_process';
import {access, mkdtemp, readFile, writeFile} from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import {promisify} from 'node:util';
import type {Logger} from './logger.js';
import {
    ensurePathWithinRoot,
    sanitizeWorkspaceKey,
    WorkspaceManager,
    WorkspaceManagerError,
} from './workspace-manager.js';

const execFile = promisify(execFileCallback);

function createLoggerStub(records: Array<Record<string, unknown>>): Logger {
    return {
        info(message, fields) {
            records.push({level: 'info', message, ...(fields ?? {})});
        },
        warn(message, fields) {
            records.push({level: 'warn', message, ...(fields ?? {})});
        },
        error(message, fields) {
            records.push({level: 'error', message, ...(fields ?? {})});
        },
    };
}

test('sanitizeWorkspaceKey replaces unsafe characters', () => {
    assert.equal(sanitizeWorkspaceKey('ROB/11: unsafe key'), 'ROB_11__unsafe_key');
    assert.equal(sanitizeWorkspaceKey('   '), 'workspace');
});

test('ensurePathWithinRoot rejects escaped paths', () => {
    assert.throws(
        () => ensurePathWithinRoot('/tmp/workspaces', '/tmp/other/path'),
        (error) => error instanceof WorkspaceManagerError && error.errorClass === 'workspace_path_escape',
    );
});

test('prepareWorkspace creates workspace and runs after_create hooks', async () => {
    const temporaryRoot = await mkdtemp(path.join(os.tmpdir(), 'symphony-workspace-'));
    const logRecords: Array<Record<string, unknown>> = [];

    const manager = new WorkspaceManager({
        logger: createLoggerStub(logRecords),
        config: {
            root: temporaryRoot,
            hooks: {
                timeoutMs: 5000,
                afterCreate: ['echo "created" > after-create.txt'],
                beforeRun: [],
                afterRun: [],
                beforeRemove: [],
            },
        },
    });

    const handle = await manager.prepareWorkspace('ROB-11/unsafe');
    assert.equal(handle.created, true);
    assert.ok(handle.path.startsWith(temporaryRoot));

    const markerContent = await readFile(path.join(handle.path, 'after-create.txt'), 'utf8');
    assert.equal(markerContent.trim(), 'created');
});

test('before_run hook failures are fatal', async () => {
    const temporaryRoot = await mkdtemp(path.join(os.tmpdir(), 'symphony-workspace-fatal-'));
    const logRecords: Array<Record<string, unknown>> = [];

    const manager = new WorkspaceManager({
        logger: createLoggerStub(logRecords),
        config: {
            root: temporaryRoot,
            hooks: {
                timeoutMs: 5000,
                afterCreate: [],
                beforeRun: ['exit 17'],
                afterRun: [],
                beforeRemove: [],
            },
        },
    });

    const handle = await manager.prepareWorkspace('ROB-11-fatal');
    await assert.rejects(
        () => manager.runBeforeRunHooks(handle.path),
        (error) => error instanceof WorkspaceManagerError && error.errorClass === 'workspace_hook_failed',
    );
});

test('after_run hook failures are best effort', async () => {
    const temporaryRoot = await mkdtemp(path.join(os.tmpdir(), 'symphony-workspace-best-effort-'));
    const logRecords: Array<Record<string, unknown>> = [];

    const manager = new WorkspaceManager({
        logger: createLoggerStub(logRecords),
        config: {
            root: temporaryRoot,
            hooks: {
                timeoutMs: 5000,
                afterCreate: [],
                beforeRun: [],
                afterRun: ['exit 23'],
                beforeRemove: [],
            },
        },
    });

    const handle = await manager.prepareWorkspace('ROB-11-best-effort');
    await manager.runAfterRunHooks(handle.path);

    const errorLog = logRecords.find((entry) => entry.level === 'error');
    assert.ok(errorLog);
    assert.equal(errorLog?.errorClass, 'workspace_hook_failed');
});

test('hook timeouts are classified and before_remove remains best effort', async () => {
    const temporaryRoot = await mkdtemp(path.join(os.tmpdir(), 'symphony-workspace-timeout-'));
    const logRecords: Array<Record<string, unknown>> = [];

    const manager = new WorkspaceManager({
        logger: createLoggerStub(logRecords),
        config: {
            root: temporaryRoot,
            hooks: {
                timeoutMs: 20,
                afterCreate: [],
                beforeRun: [],
                afterRun: [],
                beforeRemove: ['sleep 1'],
            },
        },
    });

    const handle = await manager.prepareWorkspace('ROB-11-timeout');
    await manager.cleanupTerminalWorkspace(handle.path);

    await assert.rejects(() => access(handle.path));
    const errorLog = logRecords.find(
        (entry) =>
            entry.level === 'error' &&
            entry.message === 'Workspace hook failed' &&
            entry.errorClass === 'workspace_hook_timeout',
    );
    assert.ok(errorLog);
});

test('cleanupTerminalWorkspace runs before_remove and deletes workspace', async () => {
    const temporaryRoot = await mkdtemp(path.join(os.tmpdir(), 'symphony-workspace-cleanup-'));
    const logRecords: Array<Record<string, unknown>> = [];

    const manager = new WorkspaceManager({
        logger: createLoggerStub(logRecords),
        config: {
            root: temporaryRoot,
            hooks: {
                timeoutMs: 5000,
                afterCreate: [],
                beforeRun: [],
                afterRun: [],
                beforeRemove: ['echo "cleanup" > ../before-remove-marker.txt'],
            },
        },
    });

    const handle = await manager.prepareWorkspace('ROB-11-cleanup');
    await writeFile(path.join(handle.path, 'work.txt'), 'payload', 'utf8');

    await manager.cleanupTerminalWorkspace(handle.path);

    await assert.rejects(() => access(handle.path));
    const marker = await readFile(path.join(temporaryRoot, 'before-remove-marker.txt'), 'utf8');
    assert.equal(marker.trim(), 'cleanup');
});

test('captureWorkspaceState returns head sha and tracked status changes', async () => {
    const temporaryRoot = await mkdtemp(path.join(os.tmpdir(), 'symphony-workspace-state-'));
    const workspacePath = path.join(temporaryRoot, 'ROB-11-state');
    await writeFile(path.join(temporaryRoot, 'README.md'), 'root\n', 'utf8');

    await execFile('git', ['init'], {cwd: temporaryRoot});
    await execFile('git', ['checkout', '-b', 'main'], {cwd: temporaryRoot});
    await execFile('git', ['add', 'README.md'], {cwd: temporaryRoot});
    await execFile('git', ['-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '-m', 'init'], {
        cwd: temporaryRoot,
    });
    await execFile('git', ['worktree', 'add', '-b', 'codex/symphony-rob-11-state', workspacePath, 'HEAD'], {
        cwd: temporaryRoot,
    });
    await writeFile(path.join(workspacePath, 'README.md'), 'changed\n', 'utf8');

    const manager = new WorkspaceManager({
        logger: createLoggerStub([]),
        config: {
            root: temporaryRoot,
            hooks: {
                timeoutMs: 5000,
                afterCreate: [],
                beforeRun: [],
                afterRun: [],
                beforeRemove: [],
            },
        },
    });

    const snapshot = await manager.captureWorkspaceState(workspacePath);

    assert.match(snapshot.headSha, /^[0-9a-f]{40}$/);
    assert.match(snapshot.statusText, /^ M README\.md$/m);
    assert.equal(snapshot.branchName, 'codex/symphony-rob-11-state');
});
