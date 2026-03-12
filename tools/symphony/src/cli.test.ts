import assert from 'node:assert/strict';
import test from 'node:test';
import {resolveLoggerMode, validateCliRuntimeOptions} from './cli.js';

test('validateCliRuntimeOptions rejects --tui in non-interactive runtime mode', () => {
    assert.throws(
        () =>
            validateCliRuntimeOptions({
                tui: true,
                checkOnly: false,
                stdoutIsTTY: false,
            }),
        /interactive TTY terminal/,
    );
});

test('validateCliRuntimeOptions allows --tui with --check in non-interactive mode', () => {
    assert.doesNotThrow(() =>
        validateCliRuntimeOptions({
            tui: true,
            checkOnly: true,
            stdoutIsTTY: false,
        }),
    );
});

test('resolveLoggerMode only enables tui logger mode for non-check tui runs', () => {
    assert.equal(resolveLoggerMode({tui: true, checkOnly: false}), 'tui');
    assert.equal(resolveLoggerMode({tui: true, checkOnly: true}), 'default');
    assert.equal(resolveLoggerMode({tui: false, checkOnly: false}), 'default');
});
