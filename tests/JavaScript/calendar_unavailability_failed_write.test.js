const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const moment = require('moment');
const test = require('node:test');

const modules = ['assets/js/utils/calendar_default_view.js', 'assets/js/utils/calendar_table_view.js'];

function makeJQueryObject() {
    const object = {
        0: {
            addEventListener: () => {},
            removeEventListener: () => {},
            style: {},
        },
        length: 1,
        addClass() {
            return this;
        },
        append() {
            return this;
        },
        appendTo() {
            return this;
        },
        attr() {
            return 'provider';
        },
        css() {
            return this;
        },
        data() {
            return this;
        },
        find() {
            return this;
        },
        hide() {
            return this;
        },
        is() {
            return false;
        },
        off() {
            return this;
        },
        on() {
            return this;
        },
        position() {
            return {top: 300};
        },
        prop() {
            return this;
        },
        removeClass() {
            return this;
        },
        show() {
            return this;
        },
        text() {
            return this;
        },
        trigger() {
            return this;
        },
        val() {
            return '1';
        },
    };

    return object;
}

function loadCallbacks(modulePath) {
    const source = fs.readFileSync(path.join(__dirname, '..', '..', modulePath), 'utf8');
    const instrumentedSource = source.replace(
        /return \{\n\s*initialize,/,
        'return {\n        __test: {onEventResize, onEventDrop},\n        initialize,',
    );

    const savedRequests = [];
    const notifications = [];
    const jqueryObject = makeJQueryObject();
    const calendar = {
        Calendar: function () {
            return {
                addEventSource() {},
                getEventSources() {
                    return [];
                },
                next() {},
                prev() {},
                render() {},
                setOption() {},
            };
        },
    };
    const context = {
        window: {
            innerWidth: 1024,
            moment,
        },
        App: {
            Components: {
                AppointmentsModal: {},
                ColorSelection: {},
                UnavailabilitiesModal: {},
                WorkingPlanExceptionsModal: {},
            },
            Http: {
                Calendar: {
                    saveAppointment() {},
                    saveUnavailability(...args) {
                        savedRequests.push(args);
                        return {};
                    },
                },
            },
            Layouts: {
                Backend: {
                    displayNotification(message) {
                        notifications.push(message);
                    },
                },
            },
            Utils: {
                Date: {getWeekdayId: () => 1},
                UI: {},
            },
        },
        document: {
            addEventListener() {},
        },
        FullCalendar: calendar,
        Option: function () {},
        $: () => jqueryObject,
        lang: (key) => key,
        moment,
        vars: (key) => {
            if (key === 'privileges') {
                return {appointments: {edit: true}};
            }

            if (key === 'available_providers') {
                return [];
            }

            if (key === 'date_format') {
                return 'DMY';
            }

            if (key === 'time_format') {
                return 'military';
            }

            return 'en';
        },
    };

    vm.runInNewContext(instrumentedSource, context);

    const moduleName = modulePath.includes('table') ? 'CalendarTableView' : 'CalendarDefaultView';

    return {
        callbacks: context.App.Utils[moduleName].__test,
        getRequest: () => savedRequests.at(-1),
        getRequests: () => savedRequests,
        notifications,
    };
}

for (const modulePath of modules) {
    const label = path.basename(modulePath);

    test(`${label} rejects an overlapping edit but permits a later edit after completion`, () => {
        const calendar = loadCallbacks(modulePath);
        const first = createInfo('onEventResize');

        calendar.callbacks.onEventResize(first);
        calendar.callbacks.onEventDrop(first);

        assert.equal(calendar.getRequests().length, 1);
        assert.equal(first.revertCalls, 1);

        calendar.getRequests()[0][2]();
        assert.equal(first.revertCalls, 2);
        assert.deepEqual(first.event.extendedProps.data, first.originalData);

        calendar.callbacks.onEventResize(first);
        assert.equal(calendar.getRequests().length, 2);
    });

    test(`${label} allows concurrent edits for different unavailability records`, () => {
        const calendar = loadCallbacks(modulePath);
        const first = createInfo('onEventResize');
        const second = createInfo('onEventResize');
        second.event.extendedProps.data.id = 18;

        calendar.callbacks.onEventResize(first);
        calendar.callbacks.onEventResize(second);

        assert.equal(calendar.getRequests().length, 2);
        assert.equal(first.revertCalls, 0);
        assert.equal(second.revertCalls, 0);
    });
}

function createInfo(kind) {
    const originalData = {
        id: 17,
        id_users_provider: 4,
        is_unavailability: true,
        start_datetime: '2026-10-12 09:00:00',
        end_datetime: '2026-10-12 10:00:00',
    };
    const event = {
        end: new Date('2026-10-12T11:00:00'),
        extendedProps: {data: {...originalData}},
        setPropCalls: [],
        setProp(name, value) {
            this.setPropCalls.push({name, value});
        },
        start: new Date('2026-10-12T10:00:00'),
    };

    return {
        endDelta: {days: 0, milliseconds: 60 * 60 * 1000},
        event,
        delta: {days: 0, milliseconds: 60 * 60 * 1000},
        revertCalls: 0,
        revert() {
            this.revertCalls += 1;
        },
        originalData,
        kind,
    };
}

for (const modulePath of modules) {
    const label = path.basename(modulePath);

    test(`${label} restores the event after a failed unavailability write`, () => {
        const calendar = loadCallbacks(modulePath);

        for (const callbackName of ['onEventResize', 'onEventDrop']) {
            const info = createInfo(callbackName);

            calendar.callbacks[callbackName](info);
            const request = calendar.getRequest();

            assert.equal(typeof request[2], 'function');
            request[2]();

            assert.deepEqual(info.event.extendedProps.data, info.originalData);
            assert.equal(info.revertCalls, 1);
            assert.equal(info.event.setPropCalls.at(-1).name, 'data');
            assert.equal(calendar.notifications.at(-1), 'service_communication_error');
        }
    });

    test(`${label} preserves the successful unavailability callback`, () => {
        const calendar = loadCallbacks(modulePath);
        const info = createInfo('onEventResize');

        calendar.callbacks.onEventResize(info);
        calendar.getRequest()[1]();

        assert.equal(info.revertCalls, 0);
        assert.deepEqual(calendar.notifications, ['unavailability_updated']);

        calendar.callbacks.onEventDrop(info);
        assert.equal(calendar.getRequests().length, 2);
    });
}
