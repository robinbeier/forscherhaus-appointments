const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const repositoryRoot = path.resolve(__dirname, '..', '..');

function loadPage(page, response) {
    const source = fs.readFileSync(path.join(repositoryRoot, `assets/js/pages/${page}.js`), 'utf8');
    const rootSelector = page === 'admins' ? '#admins' : '#secretaries';
    const handlers = {};
    const objects = new Map();
    const formMessage = {
        value: '',
        visible: false,
        text(value) {
            if (value === undefined) {
                return this.value;
            }
            this.value = value;
            return this;
        },
        show() {
            this.visible = true;
        },
        hide() {
            this.visible = false;
        },
    };
    const recordId = {val: () => '42'};
    const parent = {
        find(selector) {
            return selector === '.record-id' ? recordId : formMessage;
        },
    };
    const target = {
        classes: new Set(),
        attributes: new Map(),
        prop: (name) => (name === 'readonly' ? false : undefined),
        val: () => 'duplicate-user',
        parents: () => ({eq: () => parent}),
        addClass(className) {
            this.classes.add(className);
            return this;
        },
        removeClass(className) {
            this.classes.delete(className);
            return this;
        },
        attr(name, value) {
            this.attributes.set(name, value);
            return this;
        },
    };
    const root = {
        on(event, selector, callback) {
            handlers[`${event}:${selector}`] = callback;
        },
    };
    const generic = {
        on() {},
        find: () => generic,
        prop: () => generic,
        val: () => '',
        hide: () => generic,
        show: () => generic,
        removeClass: () => generic,
        addClass: () => generic,
    };

    const $ = (selector) => {
        if (selector === rootSelector) {
            return root;
        }
        if (selector === target) {
            return target;
        }
        if (!objects.has(selector)) {
            objects.set(selector, generic);
        }
        return objects.get(selector);
    };
    const context = {
        App: {
            Http: {
                Account: {
                    validateUsername: () => ({done: (callback) => callback(response)}),
                },
            },
            Pages: {},
        },
        $,
        document: {addEventListener() {}},
        lang: (key) => key,
        vars: () => '',
    };

    vm.runInNewContext(source, context);
    const pageModule = context.App.Pages[page === 'admins' ? 'Admins' : 'Secretaries'];
    pageModule.addEventListeners();
    const triggerBlur = () => handlers['blur:#username']({currentTarget: target, target});
    triggerBlur();

    return {formMessage, target, triggerBlur};
}

for (const page of ['admins', 'secretaries']) {
    test(`${page} treats the boolean username response as invalid and preserves valid UX`, () => {
        const response = {is_valid: false};
        const pageState = loadPage(page, response);
        assert.equal(pageState.target.classes.has('is-invalid'), true);
        assert.equal(pageState.target.attributes.get('already-exists'), 'true');
        assert.equal(pageState.formMessage.value, 'username_already_exists');
        assert.equal(pageState.formMessage.visible, true);

        response.is_valid = true;
        pageState.triggerBlur();
        assert.equal(pageState.target.classes.has('is-invalid'), false);
        assert.equal(pageState.target.attributes.get('already-exists'), 'false');
        assert.equal(pageState.formMessage.visible, false);
    });
}
