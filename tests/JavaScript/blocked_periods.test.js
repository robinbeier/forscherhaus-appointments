const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const moment = require('moment');
const test = require('node:test');

const source = fs.readFileSync(path.join(__dirname, '..', '..', 'assets/js/pages/blocked_periods.js'), 'utf8');

function loadPage(start, end) {
    const handlers = {};
    const values = new Map();
    const objects = new Map();
    const objectFor = (selector) => {
        if (!objects.has(selector)) {
            objects.set(selector, {
                on: (event, delegatedSelector, callback) => {
                    handlers[`${event}:${delegatedSelector}`] = callback;
                },
                val: () => '',
                find: () => objectFor(`${selector} find`),
                prop: () => {},
                css: () => {},
                hide: () => {},
                show: () => {},
                empty: () => {},
                append: () => objectFor(selector),
                appendTo: () => {},
                removeClass: () => {},
                addClass: () => {},
            });
        }
        return objects.get(selector);
    };
    const startInput = objectFor('#start-date-time');
    const endInput = objectFor('#end-date-time');
    values.set(startInput, start);
    values.set(endInput, end);
    const setValues = [];
    const context = {
        window: {
            moment,
            App: {
                Pages: {},
                Utils: {
                    UI: {
                        getDateTimePickerValue: (input) => values.get(input),
                        setDateTimePickerValue: (input, value) => {
                            values.set(input, value);
                            setValues.push({input, value});
                        },
                    },
                },
            },
        },
        App: {Pages: {}, Utils: {UI: {}}},
        document: {addEventListener: () => {}},
        $: (selector) => {
            if (selector === '#start-date-time') return startInput;
            if (selector === '#end-date-time') return endInput;
            return objectFor(selector);
        },
        moment,
        lang: () => '',
        vars: () => ({}),
    };
    context.App = context.window.App;
    vm.runInNewContext(source, context);
    context.window.App.Pages.BlockedPeriods.addEventListeners();
    return {
        startInput,
        endInput,
        handlers,
        values,
        setValues,
        trigger: (event, selector) => handlers[`${event}:${selector}`]({}),
    };
}

function date(value) {
    return new Date(`2026-09-09T${value}:00`);
}

test('keeps the original blocked-period duration when moving start forward, backward, and repeatedly', () => {
    const page = loadPage(date('09:00'), date('10:30'));
    page.trigger('focus', '#start-date-time');
    page.values.set(page.startInput, date('11:00'));
    page.trigger('change', '#start-date-time');
    assert.equal(page.values.get(page.endInput).toISOString(), date('12:30').toISOString());

    // The saved start is refreshed after the first change, so this works without another focus event.
    page.values.set(page.startInput, date('08:00'));
    page.trigger('change', '#start-date-time');
    assert.equal(page.values.get(page.endInput).toISOString(), date('09:30').toISOString());
});

test('does not alter the end when a required picker value is missing', () => {
    const page = loadPage(date('09:00'), date('10:30'));
    page.trigger('focus', '#start-date-time');
    page.values.set(page.startInput, null);
    page.trigger('change', '#start-date-time');
    assert.equal(page.setValues.length, 0);
});

test('renders blocked periods as escaped read-only content and omits unauthorized notes', () => {
    const elements = [];
    const createElement = (tag, attributes = {}) => {
        const element = {tag, attributes, children: attributes.html || []};
        elements.push(element);
        return element;
    };
    const context = {
        App: {Utils: {Date: {format: (value) => value}}},
        window: {App: {Utils: {Date: {format: (value) => value}}}, moment},
        vars: () => 'YYYY-MM-DD',
        moment,
        lang: (key) => key,
        $: (tag, attributes) => createElement(tag, attributes),
    };
    vm.runInNewContext(
        fs.readFileSync(path.join(__dirname, '..', '..', 'assets/js/utils/calendar_event_popover.js'), 'utf8'),
        context,
    );

    const malicious = '<script>alert("x")</script>';
    const content = context.App.Utils.CalendarEventPopover.renderBlockedPeriod({
        event: {
            start: date('09:00'),
            end: date('10:00'),
            extendedProps: {data: {name: malicious, start_datetime: date('09:00'), end_datetime: date('10:00')}},
        },
    });
    const rendered = JSON.stringify(content);
    assert.equal(content.children[1].attributes.text, malicious);
    assert.equal(rendered.includes('delete-popover'), false);
    assert.equal(rendered.includes('edit-popover'), false);
    assert.equal(rendered.includes('notes'), false);
});
