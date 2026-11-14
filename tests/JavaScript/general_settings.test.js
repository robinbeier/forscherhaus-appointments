const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '..', '..', 'assets/js/pages/general_settings.js'), 'utf8');
const view = fs.readFileSync(path.join(__dirname, '..', '..', 'application/views/pages/general_settings.php'), 'utf8');
const pageFieldNames = [...view.matchAll(/data-field="([^"]+)"/g)].map((match) => match[1]);

class Field {
    constructor(name, value, type = 'text') {
        this.name = name;
        this.value = value;
        this.type = type;
        this.handlers = {};
        this.length = 1;
        this.props = {};
        this[0] = {files: []};
    }

    data(key) {
        return key === 'field' ? this.name : undefined;
    }

    is(selector) {
        return selector === ':checkbox' && this.type === 'checkbox';
    }

    val(value) {
        if (value === undefined) {
            return this.value;
        }

        this.value = value;
        return this;
    }

    prop(name, value) {
        if (value === undefined) {
            return this.props[name];
        }

        this.props[name] = value;
        return this;
    }

    on(name, callback) {
        this.handlers[name] = callback;
        return this;
    }

    trigger(name) {
        this.handlers[name]?.();
        return this;
    }

    removeClass() {
        return this;
    }

    addClass() {
        return this;
    }
}

class EmptyCollection {
    constructor() {
        this.length = 0;
    }

    each() {
        return this;
    }

    removeClass() {
        return this;
    }
}

function loadPage() {
    const values = {
        company_name: 'Forscherhaus',
        company_link: 'https://example.test',
        company_logo: '',
        company_color: '#ffffff',
        dashboard_conflict_threshold: '0.5',
    };
    const fields = pageFieldNames.map((name) => new Field(name, values[name] ?? 'default'));
    const field = (name) => fields.find((item) => item.name === name);
    const nodes = new Map([
        ['#save-settings', new Field('save-settings', '')],
        ['#company-logo', field('company_logo')],
        ['#company-logo-preview', new Field('company-logo-preview', '')],
        ['#remove-company-logo', new Field('remove-company-logo', '')],
        ['#company-color', field('company_color')],
        ['#reset-company-color', new Field('reset-company-color', '')],
    ]);
    let savedPayload;
    const node = (selector) => {
        if (selector === '[data-field]') {
            return {
                each(callback) {
                    fields.forEach((field, index) => callback(index, field));
                    return this;
                },
            };
        }

        if (selector === '#general-settings .required' || selector === '[data-field="dashboard_conflict_threshold"]') {
            return new EmptyCollection();
        }

        if (typeof selector === 'object') {
            return selector;
        }

        if (!nodes.has(selector)) {
            nodes.set(selector, new Field(selector, ''));
        }

        return nodes.get(selector);
    };
    const context = {
        App: {
            Http: {
                GeneralSettings: {
                    save(payload) {
                        savedPayload = payload;
                        return {
                            done(callback) {
                                callback();
                            },
                        };
                    },
                },
            },
            Layouts: {Backend: {displayNotification() {}}},
            Utils: {File: {toBase64: async () => ''}},
            Pages: {},
        },
        document: {
            addEventListener(event, callback) {
                if (event === 'DOMContentLoaded') {
                    callback();
                }
            },
        },
        lang: (key) => key,
        vars: () => [],
        window: {},
        $: node,
    };

    vm.runInNewContext(source, context, {filename: 'general_settings.js'});
    nodes.get('#save-settings').trigger('click');

    return {fields, savedPayload};
}

test('general settings page emits one payload entry per setting', () => {
    const {fields, savedPayload} = loadPage();
    const names = savedPayload.map((setting) => setting.name);

    assert.ok(pageFieldNames.includes('company_logo'));
    assert.equal(new Set(names).size, names.length);
    assert.equal(names.filter((name) => name === 'company_logo').length, 1);
    assert.equal(
        JSON.stringify(names),
        JSON.stringify(
            fields
                .map((field) => field.name)
                .filter((name) => name !== 'company_logo')
                .concat('company_logo'),
        ),
    );
});
