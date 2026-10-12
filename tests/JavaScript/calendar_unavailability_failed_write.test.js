const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const moment = require('moment');
const test = require('node:test');

const modules = ['assets/js/utils/calendar_default_view.js', 'assets/js/utils/calendar_table_view.js'];

function makeJQueryObject(triggers) {
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
        trigger(name) {
            triggers.push(name);
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
    const notificationActions = [];
    const triggers = [];
    const jqueryObject = makeJQueryObject(triggers);
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
                        return {
                            done(callback) {
                                args.doneCallback = callback;
                                return this;
                            },
                            fail(callback) {
                                args.failCallback = callback;
                                return this;
                            },
                        };
                    },
                },
            },
            Layouts: {
                Backend: {
                    displayNotification(message, actions) {
                        notifications.push(message);
                        notificationActions.push(actions || []);
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
        getUndo: () => notificationActions.at(-1).find((action) => action.label === 'undo')?.function,
        notifications,
        triggers,
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

    for (const callbackName of ['onEventResize', 'onEventDrop']) {
        test(`${label} reconciles a failed ${callbackName} undo without claiming it succeeded`, () => {
            const calendar = loadCallbacks(modulePath);
            const info = createInfo(callbackName);

            calendar.callbacks[callbackName](info);
            calendar.getRequest()[1]();
            const persistedData = {...info.event.extendedProps.data};
            const undo = calendar.getUndo();
            assert.equal(typeof undo, 'function');

            undo();
            undo();
            assert.equal(calendar.getRequests().length, 2, 'an in-flight undo must not be submitted twice');
            assert.equal(info.revertCalls, 0, 'the event must not revert before the undo write succeeds');
            assert.deepEqual(info.event.extendedProps.data, persistedData);

            const undoRequest = calendar.getRequest();
            assert.equal(undoRequest[0].id, info.originalData.id);
            assert.equal(undoRequest[0].start_datetime, info.originalData.start_datetime);
            assert.equal(undoRequest[0].end_datetime, info.originalData.end_datetime);
            assert.equal(undoRequest[0].id_users_provider, info.originalData.id_users_provider);
            assert.deepEqual(Object.keys(undoRequest[0]).sort(), [
                'end_datetime',
                'id',
                'id_users_provider',
                'start_datetime',
            ]);
            assert.equal(typeof undoRequest.failCallback, 'function');
            undoRequest.failCallback();
            assert.equal(calendar.notifications.at(-1), 'service_communication_error');
            assert.equal(calendar.triggers.at(-1), 'click', 'reload the authoritative calendar after a failed undo');
            assert.equal(info.revertCalls, 0, 'a failed undo must not revert the event locally');
            assert.deepEqual(info.event.extendedProps.data, persistedData);
        });

        test(`${label} reverts a ${callbackName} event only after its undo write succeeds`, () => {
            const calendar = loadCallbacks(modulePath);
            const info = createInfo(callbackName);

            calendar.callbacks[callbackName](info);
            calendar.getRequest()[1]();
            calendar.getUndo()();
            assert.equal(info.revertCalls, 0);

            const undoRequest = calendar.getRequest();
            assert.equal(typeof undoRequest.doneCallback, 'function');
            undoRequest.doneCallback();
            assert.equal(info.revertCalls, 1);
            assert.deepEqual(info.event.extendedProps.data, info.originalData);
            assert.deepEqual(calendar.notifications, ['unavailability_updated']);
            assert.deepEqual(calendar.triggers, []);
        });

        test(`${label} rejects an edit while ${callbackName} undo is in flight`, () => {
            const calendar = loadCallbacks(modulePath);
            const info = createInfo(callbackName);

            calendar.callbacks[callbackName](info);
            calendar.getRequest()[1]();
            calendar.getUndo()();

            const competingEdit = createInfo(callbackName);
            competingEdit.event.extendedProps.data.id = info.originalData.id;
            calendar.callbacks[callbackName](competingEdit);

            assert.equal(calendar.getRequests().length, 2, 'an edit must not race an in-flight undo');
            assert.equal(competingEdit.revertCalls, 1);
            assert.equal(calendar.notifications.at(-1), 'error');
        });

        if (callbackName === 'onEventDrop') {
            test(`${label} uses the latest prior state for a sequential drop undo`, () => {
                const calendar = loadCallbacks(modulePath);
                const info = createInfo(callbackName);

                calendar.callbacks.onEventDrop(info);
                calendar.getRequest()[1]();

                info.event.start = new Date('2026-10-12T11:00:00');
                info.event.end = new Date('2026-10-12T12:00:00');
                calendar.callbacks.onEventDrop(info);
                calendar.getRequest()[1]();

                const secondUndo = calendar.getUndo();
                secondUndo();
                const secondUndoRequest = calendar.getRequest();

                assert.equal(secondUndoRequest[0].id, info.originalData.id);
                assert.equal(secondUndoRequest[0].start_datetime, '2026-10-12 10:00:00');
                assert.equal(secondUndoRequest[0].end_datetime, '2026-10-12 11:00:00');

                secondUndoRequest.doneCallback();
                assert.deepEqual(info.event.extendedProps.data.start_datetime, '2026-10-12 10:00:00');
                assert.deepEqual(info.event.extendedProps.data.end_datetime, '2026-10-12 11:00:00');
                assert.equal(info.revertCalls, 1);
            });
        }
    }
}
