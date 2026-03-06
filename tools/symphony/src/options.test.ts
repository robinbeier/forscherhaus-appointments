import assert from 'node:assert/strict';
import test from 'node:test';
import path from 'node:path';
import {parseCliOptions} from './options.js';
import {resolveWorkflowPath} from './workflow.js';

test('parseCliOptions parses --check', () => {
    const options = parseCliOptions(['--check']);
    assert.equal(options.checkOnly, true);
});

test('parseCliOptions parses --workflow value', () => {
    const options = parseCliOptions(['--workflow', 'custom.md']);
    assert.equal(options.workflowPath, 'custom.md');
});

test('parseCliOptions parses --workflow=value', () => {
    const options = parseCliOptions(['--workflow=custom.md']);
    assert.equal(options.workflowPath, 'custom.md');
});

test('parseCliOptions rejects unknown options', () => {
    assert.throws(() => parseCliOptions(['--unknown']), /Unknown argument/);
});

test('resolveWorkflowPath defaults to repo root WORKFLOW.md', () => {
    const workflowPath = resolveWorkflowPath({
        cwd: '/tmp/symphony',
        moduleDir: '/repo/tools/symphony/src',
    });

    assert.equal(workflowPath, '/repo/WORKFLOW.md');
});

test('resolveWorkflowPath resolves custom path relative to cwd', () => {
    const workflowPath = resolveWorkflowPath({
        cliWorkflowPath: 'docs/workflow.md',
        cwd: '/tmp/symphony',
        moduleDir: '/repo/tools/symphony/src',
    });

    assert.equal(workflowPath, path.resolve('/tmp/symphony', 'docs/workflow.md'));
});
