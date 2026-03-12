import assert from 'node:assert/strict';
import test from 'node:test';
import {validateCliRuntimeOptions} from './cli.js';

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
