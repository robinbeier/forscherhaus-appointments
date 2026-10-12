const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const moment = require('moment');
const test = require('node:test');

class JQueryStub {
    constructor(name) {
        this.name = name;
        this.value = '';
        this.textValue = '';
        this.classes = new Set();
        this.handlers = {};
        this.props = {};
        this.modalState = null;
        this.triggerCount = 0;
        this.length = 1;
    }

    addClass(name) {
        this.classes.add(name);
        return this;
    }

    removeClass(name) {
        this.classes.delete(name);
        return this;
    }

    hasClass(name) {
        return this.classes.has(name);
    }

    on(name, callback) {
        this.handlers[name] = callback;
        return this;
    }

    trigger(name) {
        this.triggerCount += 1;
        if (this.handlers[name]) {
            this.handlers[name]({type: name});
        }
        return this;
    }

    val(value) {
        if (value === undefined) {
            return this.value;
        }

        this.value = value;
        return this;
    }

    text(value) {
        if (value === undefined) {
            return this.textValue;
        }

        this.textValue = value;
        return this;
    }

    prop(name, value) {
        if (value === undefined) {
            return this.props[name];
        }

        this.props[name] = value;
        return this;
    }

    find(selector) {
        return this.children[selector] || this;
    }

    closest() {
        return this;
    }

    hide() {
        return this;
    }

    modal(state) {
        this.modalState = state;
        return this;
    }

    append() {
        return this;
    }
}

function loadModal() {
    const source = fs.readFileSync(
        path.join(__dirname, '..', '..', 'assets/js/components/unavailabilities_modal.js'),
        'utf8',
    );
    const nodes = new Map();
    const node = (selector) => {
        if (!nodes.has(selector)) {
            nodes.set(selector, new JQueryStub(selector));
        }

        return nodes.get(selector);
    };

    const modal = node('#unavailabilities-modal');
    modal.children = {
        '.modal-message': node('.modal-message'),
        '.is-invalid': node('.is-invalid'),
        '.alert': node('.alert'),
        '#unavailability-notes': node('#unavailability-notes'),
    };

    const requests = [];
    const reloadAppointments = node('#reload-appointments');
    const context = {
        App: {
            Components: {},
            Http: {
                Calendar: {
                    saveUnavailability(unavailability, successCallback) {
                        const request = {
                            unavailability,
                            successCallback,
                            failCallback: null,
                            fail(callback) {
                                this.failCallback = callback;
                                return this;
                            },
                        };
                        requests.push(request);
                        return request;
                    },
                },
            },
            Layouts: {
                Backend: {
                    displayNotification() {},
                },
            },
            Utils: {
                UI: {
                    getDateTimePickerValue(input) {
                        return input.val();
                    },
                    initializeDateTimePicker() {},
                },
                Date: {
                    format() {
                        return '12/10/2026 09:00';
                    },
                },
            },
        },
        document: {
            addEventListener(event, callback) {
                if (event === 'DOMContentLoaded') {
                    callback();
                }
            },
        },
        Option: function Option() {},
        $: (selector) => node(selector),
        lang: (key) => key,
        moment,
        vars(key) {
            if (key === 'available_providers') {
                return [{id: 1, first_name: 'Test', last_name: 'Teacher', timezone: 'Europe/Berlin'}];
            }

            if (key === 'date_format') {
                return 'DMY';
            }

            if (key === 'time_format') {
                return 'military';
            }

            if (key === 'timezones') {
                return {'Europe/Berlin': 'Europe/Berlin'};
            }

            return '';
        },
        window: {moment},
    };

    vm.runInNewContext(source, context);

    node('#unavailability-provider').val('1');
    node('#unavailability-start').val('2026-10-12 09:00:00');
    node('#unavailability-end').val('2026-10-12 10:00:00');
    node('#unavailability-id').val('');

    return {
        clickSave() {
            node('#save-unavailability').handlers.click();
        },
        message: node('.modal-message'),
        reloadAppointments,
        requests,
        saveButton: node('#save-unavailability'),
    };
}

test('unavailability modal sends only one create while a save is in flight', () => {
    const modal = loadModal();

    modal.clickSave();
    modal.clickSave();

    assert.equal(modal.requests.length, 1);
    assert.equal(modal.saveButton.prop('disabled'), true);
});

test('successful unavailability save clears the in-flight guard', () => {
    const modal = loadModal();

    modal.clickSave();
    modal.requests[0].successCallback();

    assert.equal(modal.saveButton.prop('disabled'), false);

    modal.clickSave();
    assert.equal(modal.requests.length, 2);
});

test('failed unavailability save clears the in-flight guard for an explicit retry', () => {
    const modal = loadModal();

    modal.clickSave();
    modal.requests[0].failCallback({status: 422});

    assert.equal(modal.saveButton.prop('disabled'), false);

    modal.clickSave();
    assert.equal(modal.requests.length, 2);
});

test('server error keeps the existing global error handling and clears the guard', () => {
    const modal = loadModal();

    modal.clickSave();
    modal.requests[0].failCallback({status: 500});

    assert.equal(modal.message.text(), '');
    assert.equal(modal.saveButton.prop('disabled'), false);
});
