import assert from 'node:assert/strict';
import test from 'node:test';
import {createLogger} from './logger.js';

test('createLogger default mode keeps info/warn on stdout and errors on stderr', () => {
    const stdout: string[] = [];
    const stderr: string[] = [];
    const logger = createLogger({
        writeStdout: (line) => {
            stdout.push(line);
        },
        writeStderr: (line) => {
            stderr.push(line);
        },
    });

    logger.info('info-line', {a: 1});
    logger.warn('warn-line', {b: 2});
    logger.error('error-line', {c: 3});

    assert.equal(stdout.length, 2);
    assert.equal(stderr.length, 1);
    assert.match(stdout[0] ?? '', /"level":"info"/);
    assert.match(stdout[1] ?? '', /"level":"warn"/);
    assert.match(stderr[0] ?? '', /"level":"error"/);
});

test('createLogger tui mode suppresses info and routes warn/error to stderr', () => {
    const stdout: string[] = [];
    const stderr: string[] = [];
    const logger = createLogger({
        mode: 'tui',
        writeStdout: (line) => {
            stdout.push(line);
        },
        writeStderr: (line) => {
            stderr.push(line);
        },
    });

    logger.info('heartbeat');
    logger.warn('watch-this');
    logger.error('broken');

    assert.deepEqual(stdout, []);
    assert.equal(stderr.length, 2);
    assert.match(stderr[0] ?? '', /"level":"warn"/);
    assert.match(stderr[1] ?? '', /"level":"error"/);
});
