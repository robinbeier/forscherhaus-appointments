import assert from 'node:assert/strict';
import test from 'node:test';
import {extractRequiredHeadings, PrBodyCheckError, validatePrBody} from './pr-body-check.js';

const template = `## Linear

- Issue: ROB-24

## Summary

<!-- Was wurde geaendert und warum? -->

## Scope

<!-- Was ist Teil dieses PRs? -->

## Validation

<!-- Liste exakt die ausgefuehrten Kommandos -->
`;

const validBody = `## Linear

- Issue: ROB-24

## Summary

Fixes Symphony approval handling.

## Scope

- Align approval semantics with upstream tests.

## Validation

- npm --prefix tools/symphony test
`;

test('extractRequiredHeadings reads headings from the template in order', () => {
    assert.deepEqual(extractRequiredHeadings(template), ['## Linear', '## Summary', '## Scope', '## Validation']);
});

test('validatePrBody accepts a fully populated PR body', () => {
    assert.doesNotThrow(() =>
        validatePrBody({
            template,
            body: validBody,
        }),
    );
});

test('validatePrBody fails when placeholder comments remain', () => {
    assert.throws(
        () =>
            validatePrBody({
                template,
                body: template,
            }),
        (error) =>
            error instanceof PrBodyCheckError &&
            error.message === 'PR description still contains template placeholder comments.',
    );
});

test('validatePrBody fails when a heading is missing', () => {
    assert.throws(
        () =>
            validatePrBody({
                template,
                body: validBody.replace('## Scope\n\n- Align approval semantics with upstream tests.\n\n', ''),
            }),
        (error) => error instanceof PrBodyCheckError && error.message === 'Missing required heading: ## Scope',
    );
});

test('validatePrBody fails when headings are out of order', () => {
    const outOfOrderBody = `## Linear

- Issue: ROB-24

## Scope

- Scope entry.

## Summary

Summary text.

## Validation

- npm --prefix tools/symphony test
`;

    assert.throws(
        () =>
            validatePrBody({
                template,
                body: outOfOrderBody,
            }),
        (error) => error instanceof PrBodyCheckError && error.message === 'Required headings are out of order.',
    );
});

test('validatePrBody fails when a section is empty', () => {
    const emptySectionBody = `## Linear

- Issue: ROB-24

## Summary

Summary text.

## Scope


## Validation

- npm --prefix tools/symphony test
`;

    assert.throws(
        () =>
            validatePrBody({
                template,
                body: emptySectionBody,
            }),
        (error) => error instanceof PrBodyCheckError && error.message === 'Section cannot be empty: ## Scope',
    );
});
