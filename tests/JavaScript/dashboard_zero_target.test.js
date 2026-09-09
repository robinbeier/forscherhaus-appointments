const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

const source = fs.readFileSync(path.join(__dirname, '../../assets/js/pages/dashboard.js'), 'utf8');
// Execute the production table renderer with a small DOM adapter, without starting charts or HTTP requests.
const renderer = source.slice(
    source.indexOf('    function renderTable()'),
    source.indexOf('    function buildAfter15Cell('),
);

function render(metric) {
    function node(tag, attributes = {}) {
        return {
            tag,
            attributes,
            children: [],
            appendTo(parent) {
                parent.children.push(this);
                return this;
            },
            find() {
                return {
                    not() {
                        return {remove() {}};
                    },
                };
            },
            show() {},
            hide() {},
        };
    }
    const table = node('tbody');
    vm.runInNewContext(`${renderer}\nrenderTable();`, {
        $: node,
        $tableBody: table,
        $emptyRow: node('tr'),
        getVisibleMetrics: () => [metric],
        formatSlotsSummary: () => '2 offered',
        lang: (key) => key,
        buildAfter15Cell: () => node('td'),
        buildStatusCell: () => node('td'),
    });
    return table.children[0].children;
}

const base = {provider_name: 'Example', booked: 0, open: 0, fill_rate: 0, has_plan: true};

test('explicit zero displays zero needed and zero open without a percentage or missing-target label', () => {
    const cells = render({...base, class_size_default: 0, target: 0, has_explicit_target: true});
    assert.equal(cells[1].children[0].attributes.text, 0);
    assert.equal(cells[3].attributes.text, 0);
    assert.equal(cells[4].children[0].attributes.text, '—');
});

test('missing target remains missing and positive fallback remains visible', () => {
    const missing = render({...base, class_size_default: null, target: 0, has_explicit_target: false});
    assert.equal(missing[1].children[0].attributes.text, 'dashboard_no_target');
    assert.equal(missing[3].attributes.text, '—');
    const fallback = render({
        ...base,
        class_size_default: null,
        target: 12,
        has_explicit_target: false,
        is_target_fallback: true,
    });
    assert.equal(fallback[1].children[0].attributes.text, 12);
    assert.equal(fallback[1].children[1].attributes.text, 'dashboard_target_fallback_badge');
});
