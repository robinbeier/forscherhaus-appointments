import assert from 'node:assert/strict';
import test from 'node:test';
import {parseMaxConcurrentRenders} from './concurrency.js';

test('accepts only exact decimal concurrency values from 1 through 16', () => {
    assert.equal(parseMaxConcurrentRenders('1'), 1);
    assert.equal(parseMaxConcurrentRenders('16'), 16);
    assert.equal(parseMaxConcurrentRenders('0'), 4);
    assert.equal(parseMaxConcurrentRenders('17'), 4);
    assert.equal(parseMaxConcurrentRenders('2.5'), 4);
    assert.equal(parseMaxConcurrentRenders('4junk'), 4);
    assert.equal(parseMaxConcurrentRenders(undefined), 4);
});
