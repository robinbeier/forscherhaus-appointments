const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const moment = require('moment');
const test = require('node:test');

const repositoryRoot = path.resolve(__dirname, '..', '..');
const dateUtilitySource = fs.readFileSync(path.join(repositoryRoot, 'assets/js/utils/date.js'), 'utf8');
const context = {moment, window: {App: {Utils: {}}}};

vm.runInNewContext(dateUtilitySource, context);

const isSchoolWeekRange = context.window.App.Utils.Date.isSchoolWeekRange;

test('accepts a single weekday and a contiguous range in one ISO school week', () => {
    assert.equal(isSchoolWeekRange('2026-09-09', '2026-09-09'), true);
    assert.equal(isSchoolWeekRange('2026-09-07', '2026-09-11'), true);
    assert.equal(isSchoolWeekRange('2026-12-28', '2027-01-01'), true);
});

test('rejects invalid, reversed, weekend, and cross-week ranges', () => {
    assert.equal(isSchoolWeekRange('2026-02-30', '2026-03-02'), false);
    assert.equal(isSchoolWeekRange('2026-09-11', '2026-09-07'), false);
    assert.equal(isSchoolWeekRange('2026-09-06', '2026-09-07'), false);
    assert.equal(isSchoolWeekRange('2026-09-11', '2026-09-14'), false);
    assert.equal(isSchoolWeekRange('2025-09-08', '2026-09-11'), false);
});
