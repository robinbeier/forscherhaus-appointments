import assert from 'node:assert/strict';
import test from 'node:test';
import {renderStrictTemplate, validateStrictTemplate} from './template.js';

test('validateStrictTemplate rejects unsupported root keys', () => {
    assert.throws(() => validateStrictTemplate('Hello {{project.slug}}'), /Template root "project" is not allowed/);
});

test('renderStrictTemplate renders issue and attempt placeholders', () => {
    const rendered = renderStrictTemplate('Issue {{issue.identifier}} attempt {{attempt}}', {
        issue: {
            identifier: 'ROB-9',
        },
        attempt: 2,
    });

    assert.equal(rendered, 'Issue ROB-9 attempt 2');
});

test('renderStrictTemplate throws for missing issue path', () => {
    assert.throws(
        () =>
            renderStrictTemplate('Missing {{issue.slug}}', {
                issue: {
                    identifier: 'ROB-9',
                },
                attempt: 1,
            }),
        /Template value "slug" is not available/,
    );
});
