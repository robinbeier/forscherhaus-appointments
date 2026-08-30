const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const moment = require('moment-timezone');

const repositoryRoot = path.resolve(__dirname, '..', '..');
const adapterSource = fs.readFileSync(path.join(repositoryRoot, 'assets/js/pages/booking_webmcp.js'), 'utf8');
const httpClientSource = fs.readFileSync(path.join(repositoryRoot, 'assets/js/http/booking_http_client.js'), 'utf8');
const bookingPageSource = fs.readFileSync(path.join(repositoryRoot, 'assets/js/pages/booking.js'), 'utf8');

moment.now = () => Date.UTC(2026, 7, 27, 12, 0, 0);

function projectAvailableHour({selectedDate, availableHour, providerTimezone, selectedTimezone, timeFormat = 'HH:mm'}) {
    const displayMoment = moment
        .tz(`${selectedDate} ${String(availableHour)}:00`, providerTimezone)
        .tz(selectedTimezone);

    if (displayMoment.format('YYYY-MM-DD') !== selectedDate) {
        return null;
    }

    return {
        value: String(availableHour),
        displayStart: displayMoment.format('YYYY-MM-DDTHH:mm:ssZ'),
        displayText: displayMoment.format(timeFormat),
    };
}

function createHarness(options = {}) {
    const values = {
        webmcp_booking_pilot_enabled: options.enabled === false ? '0' : '1',
        available_services: [
            {
                id: 11,
                name: 'Consultation\u0000  meeting',
                duration: 30,
                description: 'Do not expose this free-form field.',
                price: 100,
            },
        ],
        available_providers: [
            {
                id: 71,
                first_name: 'Alice',
                last_name: 'Teacher',
                email: 'alice@example.test',
                room: 'Private room',
                timezone: 'Europe/Berlin',
                services: [11],
            },
        ],
        default_timezone: 'Europe/Berlin',
        future_booking_limit: 30,
        manage_mode: options.manageMode || false,
        ...options.values,
    };
    const registeredCalls = [];
    const activeTools = new Map();
    const queryCalls = [];
    const preparationCalls = [];
    const trackedAvailabilityRequests = new Set();
    const documentListeners = new Map();
    const windowListeners = new Map();
    let registrationAttempt = 0;
    let rejectRegistrationAt = options.rejectRegistrationAt || null;
    let deferredRegistration = null;

    const modelContext =
        options.supported === false
            ? undefined
            : {
                  async registerTool(tool, registrationOptions) {
                      registrationAttempt += 1;

                      if (registrationAttempt === rejectRegistrationAt) {
                          throw new Error('registration rejected');
                      }

                      if (registrationAttempt === options.deferRegistrationAt) {
                          return new Promise((resolve, reject) => {
                              deferredRegistration = {resolve, reject};
                          });
                      }

                      registeredCalls.push({tool, registrationOptions});
                      activeTools.set(tool.name, tool);
                      registrationOptions.signal.addEventListener(
                          'abort',
                          () => {
                              activeTools.delete(tool.name);
                          },
                          {once: true},
                      );
                  },
              };

    const context = {
        AbortController,
        DOMException,
        App: {
            Http: {
                Booking: {
                    async queryAvailableHours(input) {
                        queryCalls.push(input);

                        const response = options.queryAvailableHours
                            ? options.queryAvailableHours(input)
                            : options.availableHours || ['09:00', 'invalid', '10:30'];

                        if (input.trackRequest && response?.abort && response?.always) {
                            trackedAvailabilityRequests.add(response);
                            response.always(() => trackedAvailabilityRequests.delete(response));
                        }

                        return response;
                    },
                    abortTrackedAvailabilityRequests() {
                        [...trackedAvailabilityRequests].forEach((request) => request.abort());
                        trackedAvailabilityRequests.clear();
                    },
                    projectAvailableHour,
                },
            },
            Pages: {
                Booking: {
                    async prepareBookingSelection(input) {
                        preparationCalls.push(input);

                        if (options.prepareBookingSelection) {
                            return options.prepareBookingSelection(input, {bookingHttp: context.App.Http.Booking});
                        }

                        if (options.prepareError) {
                            throw new Error(options.prepareError);
                        }

                        return {
                            selected_date: input.selectedDate,
                            selected_time: input.selectedTime,
                        };
                    },
                },
            },
        },
        console: {
            warn() {},
        },
        document: {
            modelContext,
            addEventListener(name, listener) {
                documentListeners.set(name, listener);
            },
        },
        vars(key) {
            return values[key];
        },
        window: {
            moment,
            addEventListener(name, listener) {
                windowListeners.set(name, listener);
            },
        },
        $(selector) {
            assert.equal(selector, '#select-timezone');
            return {
                val() {
                    return options.selectedTimezone || 'Europe/Berlin';
                },
            };
        },
    };

    vm.createContext(context);
    vm.runInContext(adapterSource, context, {filename: 'booking_webmcp.js'});

    return {
        api: context.App.Pages.BookingWebMcp,
        activeTools,
        documentListeners,
        preparationCalls,
        queryCalls,
        registeredCalls,
        trackedAvailabilityRequests,
        values,
        windowListeners,
        allowRegistrations() {
            rejectRegistrationAt = null;
        },
        rejectDeferredRegistration() {
            assert.ok(deferredRegistration);
            deferredRegistration.reject(new Error('old registration rejected'));
            deferredRegistration = null;
        },
        resolveDeferredRegistration() {
            assert.ok(deferredRegistration);
            deferredRegistration.resolve();
            deferredRegistration = null;
        },
    };
}

function createAbortableQueryRequest() {
    let resolvePromise;
    let rejectPromise;
    let settled = false;
    const alwaysCallbacks = [];
    const promise = new Promise((resolve, reject) => {
        resolvePromise = resolve;
        rejectPromise = reject;
    });

    const finish = () => {
        alwaysCallbacks.splice(0).forEach((callback) => callback());
    };

    return {
        aborted: false,
        abort() {
            if (settled) {
                return;
            }

            settled = true;
            this.aborted = true;
            finish();
            rejectPromise(new DOMException('aborted', 'AbortError'));
        },
        always(callback) {
            alwaysCallbacks.push(callback);
            return this;
        },
        then(resolve, reject) {
            return promise.then(resolve, reject);
        },
        resolve(value) {
            if (settled) {
                return;
            }

            settled = true;
            finish();
            resolvePromise(value);
        },
    };
}

function createHttpClientHarness() {
    const request = {
        abortCount: 0,
        alwaysCallbacks: [],
        abort() {
            this.abortCount += 1;
        },
        always(callback) {
            this.alwaysCallbacks.push(callback);
            return this;
        },
        complete() {
            this.alwaysCallbacks.forEach((callback) => callback());
        },
    };
    const jquery = () => ({});
    jquery.ajax = () => request;
    const context = {
        App: {
            Http: {},
            Pages: {Booking: {}},
            Utils: {Url: {siteUrl: (value) => value, queryParam: () => null}},
        },
        $: jquery,
        lang: (value) => value,
        vars: () => null,
        window: {moment},
    };

    vm.createContext(context);
    vm.runInContext(httpClientSource, context, {filename: 'booking_http_client.js'});

    return {api: context.App.Http.Booking, request};
}

function createUnavailableDatesHarness(options = {}) {
    const disabledDates = [];
    let ajaxCalls = 0;
    let selectedDateValue = null;
    let settleRequest;
    const requestPromise = new Promise((resolve, reject) => {
        settleRequest = {resolve, reject};
    });
    const request = {
        doneCallback: null,
        failCallback: null,
        alwaysCallbacks: [],
        abort() {},
        done(callback) {
            this.doneCallback = callback;
            return this;
        },
        fail(callback) {
            this.failCallback = callback;
            return this;
        },
        always(callback) {
            this.alwaysCallbacks.push(callback);
            return this;
        },
        then(resolve, reject) {
            return requestPromise.then(resolve, reject);
        },
    };
    const datePicker = {
        set(name, dates) {
            assert.equal(name, 'disable');
            disabledDates.push(...dates);
        },
    };
    const selectDate = {
        0: {_flatpickr: datePicker},
        parent() {
            return {fadeTo() {}};
        },
    };
    const emptyHours = {empty() {}, text() {}};
    const jquery = (selector) => {
        if (selector === '#select-date') {
            return selectDate;
        }
        if (selector === '#available-hours') {
            return emptyHours;
        }
        return {fadeTo() {}};
    };
    jquery.ajax = () => {
        ajaxCalls += 1;
        return request;
    };
    const context = {
        App: {
            Http: {},
            Pages: {Booking: {manageMode: false}},
            Utils: {
                Url: {
                    siteUrl: (value) => value,
                    queryParam: (key) => (key === 'date' ? (options.dateQueryParam ?? null) : null),
                },
                UI: {
                    setDateTimePickerValue(unusedTarget, value) {
                        selectedDateValue = moment(value).format('YYYY-MM-DD');
                    },
                },
            },
        },
        $: jquery,
        lang: (value) => value,
        vars(key) {
            return key === 'no_slot_fallback_enabled' ? '0' : null;
        },
        window: {moment},
    };

    vm.createContext(context);
    vm.runInContext(httpClientSource, context, {filename: 'booking_http_client.js'});

    return {
        api: context.App.Http.Booking,
        get ajaxCalls() {
            return ajaxCalls;
        },
        disabledDates,
        get selectedDateValue() {
            return selectedDateValue;
        },
        request,
        resolveRequest(response) {
            request.doneCallback?.(response);
            settleRequest.resolve(response);
            request.alwaysCallbacks.forEach((callback) => callback());
        },
    };
}

function createBookingSelectionHarness(options = {}) {
    const service = {value: '11', options: [{value: '11'}]};
    const provider = {value: '71', options: [{value: '71'}, {value: '72'}]};
    const date = {};
    const timezone = {value: 'Europe/Berlin', options: []};
    const availableHours = {emptyCalls: 0, manualHoursPresent: false, renderedResponses: 0, allowSelection: false};
    const wizardFrame = {
        stop() {
            return this;
        },
        hide() {},
        show() {},
    };
    let resolveUnavailable;
    let resolveAvailable;
    let wizardFrame3Shown = false;
    let wizardFrame4Shown = false;
    let wizardFrame2Shown = false;
    let step2Active = false;
    let availableStarted = false;
    let ordinaryRequest = null;
    let ordinaryRenderedResponses = 0;
    let unavailableCalls = 0;
    let availableCalls = 0;
    const unavailableRequests = [];
    const availableRequests = [];
    const trackedAvailabilityRequests = new Set();
    let datePickerOptions = null;
    let domReadyCallback = null;
    let nextTimerId = 1;
    const timers = new Map();
    let rejectDateSelection = false;
    function createRequest() {
        let resolvePromise;
        let rejectPromise;
        let settled = false;
        const promise = new Promise((resolve, reject) => {
            resolvePromise = resolve;
            rejectPromise = reject;
        });
        const request = {
            aborted: false,
            deferAbort: false,
            doneCallback: null,
            failCallback: null,
            alwaysCallback: null,
            done(callback) {
                this.doneCallback = callback;
                return this;
            },
            fail(callback) {
                this.failCallback = callback;
                return this;
            },
            always(callback) {
                this.alwaysCallback = callback;
                return this;
            },
            then(resolve, reject) {
                return promise.then(resolve, reject);
            },
            resolve(value) {
                if (settled) return;
                settled = true;
                this.doneCallback?.(value);
                this.alwaysCallback?.();
                resolvePromise(value);
            },
            abort() {
                if (settled) return;
                settled = true;
                this.aborted = true;
                if (this.deferAbort) {
                    settled = false;
                    return;
                }
                const error = new DOMException('aborted', 'AbortError');
                this.failCallback?.(error);
                this.alwaysCallback?.();
                rejectPromise(error);
            },
            releaseAbort() {
                this.deferAbort = false;
                this.abort();
            },
        };
        return request;
    }
    const unavailable = createRequest();
    const available = createRequest();
    unavailableRequests.push(unavailable);
    availableRequests.push(available);
    function markRendered(request, ordinary = false) {
        request.done(() => {
            availableHours.renderedResponses += 1;
            if (ordinary) ordinaryRenderedResponses += 1;
            availableHours.manualHoursPresent = true;
        });
    }
    markRendered(available);
    resolveUnavailable = (value) => {
        if (options.driftPreparedDateTo) {
            date.value = options.driftPreparedDateTo;
        }
        unavailableRequests.at(-1).resolve(value);
    };
    resolveAvailable = (value) => availableRequests.at(-1).resolve(value);
    const resolveUnavailableRequest = (index, value) => {
        if (options.driftPreparedDateTo) {
            date.value = options.driftPreparedDateTo;
        }
        unavailableRequests[index].resolve(value);
    };
    const resolveAvailableRequest = (index, value) => availableRequests[index].resolve(value);

    function control(state) {
        return {
            val(value) {
                if (arguments.length) {
                    state.value = String(value);
                    return this;
                }
                return state.value;
            },
            find(selector) {
                if (selector === 'option') {
                    return {
                        toArray: () => state.options,
                        text() {
                            return '';
                        },
                    };
                }
                return {
                    toArray: () => [],
                    length: 0,
                    text() {
                        return '';
                    },
                };
            },
            trigger() {
                return this;
            },
            on(event, callback) {
                if (event === 'change') state.onChange = callback;
                return this;
            },
            userChange(value) {
                state.value = String(value);
                state.onChange?.({target: this});
            },
            empty() {
                state.options = [];
                return this;
            },
            append() {
                return this;
            },
            parent() {
                return {prop() {}, fadeTo() {}};
            },
            closest() {
                return {find: () => ({trigger() {}})};
            },
        };
    }

    const serviceControl = control(service);
    const providerControl = control(provider);
    const dateControl = control(date);
    const timezoneControl = control(timezone);
    const availableControl = {
        on() {
            return this;
        },
        empty() {
            availableHours.emptyCalls += 1;
            availableHours.manualHoursPresent = false;
            return this;
        },
        find() {
            return {
                filter: () => ({
                    length: availableHours.allowSelection ? 1 : 0,
                    first() {
                        return {addClass() {}};
                    },
                }),
                length: 0,
                removeClass() {
                    return this;
                },
                text() {
                    return '';
                },
            };
        },
    };
    const genericControl = {
        on() {
            return this;
        },
        find() {
            return {
                length: 0,
                toArray: () => [],
                each() {
                    return this;
                },
            };
        },
        each() {
            return this;
        },
        val() {
            return '';
        },
        prop() {
            return false;
        },
        text() {
            return '';
        },
        empty() {
            return this;
        },
        append() {
            return this;
        },
        parent() {
            return this;
        },
        closest() {
            return this;
        },
        stop() {
            return this;
        },
        hide() {
            return this;
        },
        show() {
            return this;
        },
        removeClass() {
            return this;
        },
        addClass() {
            return this;
        },
        css() {
            return this;
        },
        fadeIn() {
            return this;
        },
        fadeOut() {
            return this;
        },
        replaceWith() {
            return this;
        },
    };
    const context = {
        AbortController,
        DOMException,
        Option: function Option(text, value) {
            this.text = text;
            this.value = value;
        },
        App: {
            Http: {
                Booking: {
                    getUnavailableDates(providerId, serviceId, selectedDate, monthChangeStep, options = {}) {
                        unavailableCalls += 1;
                        const request = unavailableRequests[unavailableCalls - 1] || createRequest();
                        unavailableRequests[unavailableCalls - 1] = request;
                        options.signal?.addEventListener('abort', () => request.abort(), {once: true});
                        trackedAvailabilityRequests.add(request);
                        request.always(() => trackedAvailabilityRequests.delete(request));
                        return request;
                    },
                    getAvailableHours(selectedDate, signal) {
                        const wasOrdinary = Boolean(ordinaryRequest);
                        const trackedPreparation = Boolean(signal);
                        const request =
                            ordinaryRequest ||
                            (trackedPreparation ? availableRequests[availableCalls] || createRequest() : available);
                        if (trackedPreparation) {
                            availableRequests[availableCalls] = request;
                            availableCalls += 1;
                        }
                        if (request !== available) markRendered(request);
                        ordinaryRequest = null;
                        if (!wasOrdinary) availableStarted = true;
                        if (signal) {
                            signal.addEventListener('abort', () => request.abort(), {once: true});
                        }
                        trackedAvailabilityRequests.add(request);
                        request.always(() => trackedAvailabilityRequests.delete(request));
                        return request;
                    },
                    abortTrackedAvailabilityRequests() {
                        [...trackedAvailabilityRequests].forEach((request) => request.abort());
                        trackedAvailabilityRequests.clear();
                    },
                },
            },
            Utils: {
                String: {
                    escapeHtml(value) {
                        return String(value);
                    },
                },
                Url: {
                    queryParam() {
                        return null;
                    },
                },
                UI: {
                    setDateTimePickerValue(unusedTarget, value) {
                        if (rejectDateSelection) {
                            rejectDateSelection = false;
                            return;
                        }

                        date.value = moment(value).format('YYYY-MM-DD');
                    },
                    getDateTimePickerValue() {
                        return date.value ? new Date(date.value) : new Date('2026-09-01');
                    },
                    initializeDatePicker(target, options) {
                        datePickerOptions = options;
                    },
                },
            },
            Pages: {},
        },
        document: {
            addEventListener(name, callback) {
                if (name === 'DOMContentLoaded') domReadyCallback = callback;
            },
        },
        lang: (value) => value,
        moment,
        vars(key) {
            if (key === 'manage_mode') return false;
            if (key === 'available_services') return [{id: 11, duration: 30}];
            if (key === 'available_providers')
                return [
                    {id: 71, services: [11]},
                    {id: 72, services: [11]},
                ];
            return null;
        },
        setTimeout(callback) {
            const id = nextTimerId++;
            timers.set(id, callback);
            return id;
        },
        clearTimeout(id) {
            timers.delete(id);
        },
        window: {moment, tippy() {}},
        $: (selector) => {
            if (selector === '#select-service') return serviceControl;
            if (selector === '#select-provider') return providerControl;
            if (selector === '#select-date') return dateControl;
            if (selector === '#select-timezone') return timezoneControl;
            if (selector === '#available-hours') return availableControl;
            if (selector === '#wizard-frame-3') {
                return {
                    ...genericControl,
                    show() {
                        wizardFrame3Shown = true;
                        wizardFrame4Shown = false;
                    },
                    is() {
                        return wizardFrame3Shown;
                    },
                };
            }
            if (selector === '#wizard-frame-4') {
                return {
                    ...genericControl,
                    show() {
                        wizardFrame3Shown = false;
                        wizardFrame4Shown = true;
                    },
                    is() {
                        return wizardFrame4Shown;
                    },
                };
            }
            if (selector === '#wizard-frame-2') {
                return {
                    ...genericControl,
                    show() {
                        wizardFrame3Shown = false;
                        wizardFrame4Shown = false;
                        wizardFrame2Shown = true;
                    },
                };
            }
            if (selector === '#step-2') {
                return {
                    ...genericControl,
                    addClass() {
                        step2Active = true;
                    },
                };
            }
            if (selector === '.active-step') {
                return {
                    ...genericControl,
                    removeClass() {
                        step2Active = false;
                    },
                };
            }
            return genericControl;
        },
    };

    vm.createContext(context);
    vm.runInContext(bookingPageSource, context, {filename: 'booking.js'});
    domReadyCallback?.();

    return {
        api: context.App.Pages.Booking,
        availableHours,
        provider,
        resolveAvailable,
        resolveUnavailable,
        service,
        get wizardFrame3Shown() {
            return wizardFrame3Shown;
        },
        get wizardFrame4Shown() {
            return wizardFrame4Shown;
        },
        get wizardFrame2Shown() {
            return wizardFrame2Shown;
        },
        get step2Active() {
            return step2Active;
        },
        resolveUnavailableRequest,
        resolveAvailableRequest,
        deferAvailableAbort(index) {
            availableRequests[index].deferAbort = true;
        },
        rejectAvailableRequest(index) {
            availableRequests[index].releaseAbort();
        },
        setManualHours() {
            availableHours.manualHoursPresent = true;
        },
        setSelectionAvailable(isAvailable) {
            availableHours.allowSelection = isAvailable;
        },
        userChangeService(value) {
            serviceControl.userChange(value);
        },
        userChangeProvider(value) {
            providerControl.userChange(value);
        },
        userChangeDate(value) {
            date.value = value;
            datePickerOptions.onChange([new Date(value)]);
        },
        rejectNextDateSelection() {
            rejectDateSelection = true;
        },
        userChangeTimezone() {
            timezoneControl.userChange('America/Los_Angeles');
        },
        showConfirmationFrame() {
            wizardFrame3Shown = false;
            wizardFrame4Shown = true;
            wizardFrame2Shown = false;
        },
        startOrdinaryAvailability() {
            ordinaryRequest = createRequest();
            markRendered(ordinaryRequest, true);
            const request = context.App.Http.Booking.getAvailableHours('2026-08-31');
            request.then(
                () => {},
                () => {},
            );
            return request;
        },
        get manualHoursPresent() {
            return availableHours.manualHoursPresent;
        },
        get availableStarted() {
            return availableStarted;
        },
        get availableCalls() {
            return availableCalls;
        },
        get datePickerOptions() {
            return datePickerOptions;
        },
        get unavailableCalls() {
            return unavailableCalls;
        },
        runTimers() {
            const pending = [...timers.values()];
            timers.clear();
            pending.forEach((callback) => callback());
        },
        get ordinaryRenderedResponses() {
            return ordinaryRenderedResponses;
        },
    };
}

function createRecursiveUnavailableDatesHarness() {
    const disabledDates = [];
    let ajaxCalls = 0;
    const requests = [];

    function createRequest() {
        let resolvePromise;
        let rejectPromise;
        let settled = false;
        const promise = new Promise((resolve, reject) => {
            resolvePromise = resolve;
            rejectPromise = reject;
        });
        const request = {
            aborted: false,
            doneCallback: null,
            alwaysCallback: null,
            done(callback) {
                this.doneCallback = callback;
                return this;
            },
            fail() {
                return this;
            },
            always(callback) {
                this.alwaysCallback = callback;
                return this;
            },
            then(resolve, reject) {
                return promise.then(resolve, reject);
            },
            resolve(value) {
                if (settled) return;
                settled = true;
                this.doneCallback?.(value);
                this.alwaysCallback?.();
                resolvePromise(value);
            },
            abort() {
                if (settled) return;
                settled = true;
                this.aborted = true;
                this.alwaysCallback?.();
                rejectPromise(new DOMException('aborted', 'AbortError'));
            },
        };
        requests.push(request);
        return request;
    }

    const selectDate = {
        0: {
            _flatpickr: {
                set(name, dates) {
                    assert.equal(name, 'disable');
                    disabledDates.push(...dates);
                },
            },
        },
        parent() {
            return {fadeTo() {}};
        },
    };
    const selectService = {
        val() {
            return '11';
        },
    };
    const selectProvider = {
        val() {
            return '71';
        },
    };
    const availableHours = {empty() {}, text() {}};
    const hoursRequest = createRequest();
    let renderedHours = 0;
    hoursRequest.done(() => {
        renderedHours += 1;
    });
    const jquery = (selector) => {
        if (selector === '#select-date') return selectDate;
        if (selector === '#available-hours') return availableHours;
        if (selector === '#select-service') return selectService;
        if (selector === '#select-provider') return selectProvider;
        return {fadeTo() {}};
    };
    jquery.ajax = ({url} = {}) => {
        ajaxCalls += 1;
        if (url === 'booking/get_available_hours') return hoursRequest;
        return createRequest();
    };
    const context = {
        App: {
            Http: {},
            Pages: {Booking: {manageMode: false}},
            Utils: {
                Url: {
                    siteUrl: (value) => value,
                    queryParam: () => null,
                },
                UI: {setDateTimePickerValue() {}},
            },
        },
        lang: (value) => value,
        vars(key) {
            if (key === 'no_slot_fallback_enabled') return '0';
            if (key === 'available_services') return [{id: 11, duration: 15}];
            return null;
        },
        window: {moment},
    };
    context.$ = jquery;
    vm.createContext(context);
    vm.runInContext(httpClientSource, context, {filename: 'booking_http_client.js'});

    return {
        api: context.App.Http.Booking,
        ajaxCalls: () => ajaxCalls,
        disabledDates,
        requests,
        hoursRequest,
        get renderedHours() {
            return renderedHours;
        },
        abortTracked() {
            context.App.Http.Booking.abortTrackedAvailabilityRequests();
        },
    };
}

test('feature disabled and unsupported browsers preserve the normal booking flow', async () => {
    const disabled = createHarness({enabled: false});
    assert.equal(await disabled.api.initialize(), false);
    assert.equal(disabled.registeredCalls.length, 0);

    const unsupported = createHarness({supported: false});
    assert.equal(await unsupported.api.initialize(), false);
    assert.equal(unsupported.registeredCalls.length, 0);
});

test('registers exactly the three closed-contract tools with narrow schemas', async () => {
    const harness = createHarness();

    assert.equal(await harness.api.initialize(), true);
    assert.equal(await harness.api.initialize(), true);
    assert.deepEqual([...harness.activeTools.keys()], ['list_services', 'find_available_slots', 'prepare_booking']);
    assert.deepEqual(Array.from(harness.api.toolNames), ['list_services', 'find_available_slots', 'prepare_booking']);
    assert.equal(harness.registeredCalls.length, 3);

    for (const {tool} of harness.registeredCalls) {
        assert.equal(tool.inputSchema.additionalProperties, false);
    }

    const schemas = JSON.stringify(harness.registeredCalls.map(({tool}) => tool.inputSchema));
    for (const forbidden of ['first_name', 'last_name', 'email', 'phone', 'address', 'notes', 'captcha', 'consent']) {
        assert.equal(schemas.includes(forbidden), false, forbidden);
    }

    assert.equal(harness.activeTools.get('list_services').annotations.readOnlyHint, true);
    assert.equal(harness.activeTools.get('find_available_slots').annotations.readOnlyHint, true);
    assert.equal(harness.activeTools.get('prepare_booking').annotations.readOnlyHint, false);
});

test('registers only list_services when the public catalog has no providers', async () => {
    const harness = createHarness({
        values: {
            available_providers: [],
        },
    });

    assert.equal(await harness.api.initialize(), true);
    assert.deepEqual([...harness.activeTools.keys()], ['list_services']);

    const result = await harness.activeTools.get('list_services').execute({}, {signal: new AbortController().signal});
    assert.deepEqual([...result.services[0].provider_keys], []);
});

test('list_services returns only minimal public service data and opaque provider keys', async () => {
    const harness = createHarness();
    await harness.api.initialize();

    const result = await harness.activeTools.get('list_services').execute({}, {signal: new AbortController().signal});
    assert.deepEqual(JSON.parse(JSON.stringify(result)), {
        services: [
            {
                service_key: 'service_1',
                name: 'Consultation meeting',
                duration_minutes: 30,
                provider_keys: ['provider_1'],
            },
        ],
    });

    const encoded = JSON.stringify(result);
    for (const forbidden of ['Alice', 'Teacher', 'alice@example.test', 'Private room', 'Europe/Berlin', '"id":71']) {
        assert.equal(encoded.includes(forbidden), false, forbidden);
    }
});

test('list_services preserves valid positive service durations above one day', async () => {
    const harness = createHarness({
        values: {
            available_services: [{id: 11, name: 'Long session', duration: 1500}],
        },
    });
    await harness.api.initialize();

    const result = await harness.activeTools.get('list_services').execute({}, {signal: new AbortController().signal});
    assert.equal(result.services[0].duration_minutes, 1500);
});

test('find_available_slots uses only the server-authoritative read query and returns no provider data', async () => {
    const harness = createHarness();
    await harness.api.initialize();

    const result = await harness.activeTools.get('find_available_slots').execute(
        {
            service_key: 'service_1',
            provider_key: 'provider_1',
            start_date: '2026-09-01',
            end_date: '2026-09-02',
        },
        {signal: new AbortController().signal},
    );

    assert.equal(harness.queryCalls.length, 2);
    assert.equal(harness.preparationCalls.length, 0);
    assert.deepEqual(
        harness.queryCalls.map(({serviceId, providerId, selectedDate, manageMode, appointmentId}) => ({
            serviceId,
            providerId,
            selectedDate,
            manageMode,
            appointmentId,
        })),
        [
            {serviceId: 11, providerId: 71, selectedDate: '2026-09-01', manageMode: 0, appointmentId: null},
            {serviceId: 11, providerId: 71, selectedDate: '2026-09-02', manageMode: 0, appointmentId: null},
        ],
    );
    assert.equal(result.slots.length, 4);
    assert.deepEqual(Object.keys(result.slots[0]).sort(), ['date', 'display_start', 'display_timezone', 'time']);

    const encoded = JSON.stringify(result);
    for (const forbidden of [
        'Alice',
        'Teacher',
        'alice@example.test',
        'Private room',
        '"provider_id"',
        '"service_id"',
    ]) {
        assert.equal(encoded.includes(forbidden), false, forbidden);
    }
});

test('find_available_slots stays inside the visible booking window and hides timezone-shifted slots', async () => {
    const harness = createHarness({
        selectedTimezone: 'America/Los_Angeles',
        availableHours: ['00:30', '09:00'],
    });
    await harness.api.initialize();
    const tool = harness.activeTools.get('find_available_slots');

    const inRangeResult = await tool.execute(
        {
            service_key: 'service_1',
            provider_key: 'provider_1',
            start_date: '2026-08-27',
            end_date: '2026-08-27',
        },
        {signal: new AbortController().signal},
    );

    assert.equal(inRangeResult.slots.length, 1);
    assert.equal(inRangeResult.slots[0].time, '09:00');
    assert.equal(inRangeResult.slots[0].display_start, '2026-08-27T00:00:00-07:00');

    await assert.rejects(
        tool.execute(
            {
                service_key: 'service_1',
                provider_key: 'provider_1',
                start_date: '2026-08-26',
                end_date: '2026-08-27',
            },
            {signal: new AbortController().signal},
        ),
        /visible booking range/,
    );

    await assert.rejects(
        tool.execute(
            {
                service_key: 'service_1',
                provider_key: 'provider_1',
                start_date: '2026-09-27',
                end_date: '2026-09-27',
            },
            {signal: new AbortController().signal},
        ),
        /visible booking range/,
    );
});

test('find_available_slots rejects a provider that does not belong to the selected service', async () => {
    const harness = createHarness({
        values: {
            available_providers: [
                {
                    id: 71,
                    timezone: 'Europe/Berlin',
                    services: [11],
                },
                {
                    id: 72,
                    timezone: 'Europe/Berlin',
                    services: [99],
                },
            ],
        },
    });
    await harness.api.initialize();

    await assert.rejects(
        harness.activeTools.get('find_available_slots').execute(
            {
                service_key: 'service_1',
                provider_key: 'provider_2',
                start_date: '2026-09-01',
                end_date: '2026-09-01',
            },
            {signal: new AbortController().signal},
        ),
        /not available for this service/,
    );
    assert.equal(harness.queryCalls.length, 0);
});

test('find_available_slots rejects overbroad, invalid and personal inputs before network access', async () => {
    const harness = createHarness();
    await harness.api.initialize();
    const tool = harness.activeTools.get('find_available_slots');

    await assert.rejects(
        tool.execute(
            {
                service_key: 'service_1',
                provider_key: 'provider_1',
                start_date: '2026-09-01',
                end_date: '2026-09-15',
            },
            {signal: new AbortController().signal},
        ),
        /limited to 14 days/,
    );
    await assert.rejects(
        tool.execute(
            {
                service_key: 'service_1',
                provider_key: 'provider_1',
                start_date: '2026-09-01',
                end_date: '2026-09-01',
                email: 'person@example.test',
            },
            {signal: new AbortController().signal},
        ),
        /Unsupported tool input/,
    );
    assert.equal(harness.queryCalls.length, 0);
});

test('prepare_booking delegates only visible selection state and never accepts contact or confirmation input', async () => {
    const harness = createHarness();
    await harness.api.initialize();
    const tool = harness.activeTools.get('prepare_booking');

    const result = await tool.execute(
        {
            service_key: 'service_1',
            provider_key: 'provider_1',
            date: '2026-09-01',
            time: '09:00',
        },
        {signal: new AbortController().signal},
    );

    assert.equal(harness.preparationCalls.length, 1);
    assert.deepEqual(
        Object.fromEntries(Object.entries(harness.preparationCalls[0]).filter(([key]) => key !== 'signal')),
        {serviceId: 11, providerId: 71, selectedDate: '2026-09-01', selectedTime: '09:00'},
    );
    assert.equal(harness.queryCalls.length, 0);
    assert.equal(result.prepared, true);
    assert.equal(JSON.stringify(result).includes('71'), false);

    await assert.rejects(
        tool.execute(
            {
                service_key: 'service_1',
                provider_key: 'provider_1',
                date: '2026-09-01',
                time: '09:00',
                confirm: true,
            },
            {signal: new AbortController().signal},
        ),
        /Unsupported tool input/,
    );
    assert.equal(harness.preparationCalls.length, 1);
});

test('prepare_booking does not abort an independent slot search request', async () => {
    const pendingSearchRequest = createAbortableQueryRequest();
    const harness = createHarness({
        queryAvailableHours() {
            return pendingSearchRequest;
        },
        prepareBookingSelection(input, {bookingHttp}) {
            bookingHttp.abortTrackedAvailabilityRequests();

            return {
                selected_date: input.selectedDate,
                selected_time: input.selectedTime,
            };
        },
    });
    await harness.api.initialize();

    const slotSearch = harness.activeTools.get('find_available_slots').execute(
        {
            service_key: 'service_1',
            provider_key: 'provider_1',
            start_date: '2026-09-01',
            end_date: '2026-09-01',
        },
        {signal: new AbortController().signal},
    );

    const prepared = await harness.activeTools.get('prepare_booking').execute(
        {
            service_key: 'service_1',
            provider_key: 'provider_1',
            date: '2026-09-01',
            time: '09:00',
        },
        {signal: new AbortController().signal},
    );

    assert.equal(prepared.prepared, true);
    assert.equal(pendingSearchRequest.aborted, false);
    assert.equal(harness.trackedAvailabilityRequests.size, 0);

    pendingSearchRequest.resolve(['09:00']);
    const searchResult = await slotSearch;
    assert.equal(searchResult.slots.length, 1);
});

test('prepare_booking rejects dates outside the visible booking window', async () => {
    const harness = createHarness();
    await harness.api.initialize();

    await assert.rejects(
        harness.activeTools.get('prepare_booking').execute(
            {
                service_key: 'service_1',
                provider_key: 'provider_1',
                date: '2026-08-26',
                time: '09:00',
            },
            {signal: new AbortController().signal},
        ),
        /visible booking range/,
    );

    assert.equal(harness.preparationCalls.length, 0);
});

test('reschedule, abort and preparation errors remain free of booking mutation', async () => {
    const reschedule = createHarness({manageMode: true});
    await reschedule.api.initialize();
    await assert.rejects(
        reschedule.activeTools
            .get('prepare_booking')
            .execute(
                {service_key: 'service_1', provider_key: 'provider_1', date: '2026-09-01', time: '09:00'},
                {signal: new AbortController().signal},
            ),
        /unavailable while rescheduling/,
    );
    assert.equal(reschedule.preparationCalls.length, 0);

    const aborted = createHarness();
    await aborted.api.initialize();
    const controller = new AbortController();
    controller.abort(new DOMException('stop', 'AbortError'));
    await assert.rejects(
        aborted.activeTools.get('find_available_slots').execute(
            {
                service_key: 'service_1',
                provider_key: 'provider_1',
                start_date: '2026-09-01',
                end_date: '2026-09-01',
            },
            {signal: controller.signal},
        ),
        /stop/,
    );
    assert.equal(aborted.queryCalls.length, 0);

    const failed = createHarness({prepareError: 'slot disappeared'});
    await failed.api.initialize();
    await assert.rejects(
        failed.activeTools
            .get('prepare_booking')
            .execute(
                {service_key: 'service_1', provider_key: 'provider_1', date: '2026-09-01', time: '09:00'},
                {signal: new AbortController().signal},
            ),
        /slot disappeared/,
    );
    assert.equal(failed.preparationCalls.length, 1);
});

test('overlapping prepare_booking calls supersede older visible state', async () => {
    const harness = createHarness({
        prepareBookingSelection(input) {
            if (input.selectedTime === '09:00') {
                return new Promise((resolve, reject) => {
                    input.signal.addEventListener('abort', () => reject(input.signal.reason), {once: true});
                });
            }

            return Promise.resolve({selected_date: input.selectedDate, selected_time: input.selectedTime});
        },
    });
    await harness.api.initialize();
    const tool = harness.activeTools.get('prepare_booking');

    const first = tool.execute(
        {service_key: 'service_1', provider_key: 'provider_1', date: '2026-09-01', time: '09:00'},
        {signal: new AbortController().signal},
    );
    const second = tool.execute(
        {service_key: 'service_1', provider_key: 'provider_1', date: '2026-09-01', time: '10:30'},
        {signal: new AbortController().signal},
    );

    await assert.rejects(first, /superseded|aborted/i);
    assert.deepEqual(JSON.parse(JSON.stringify(await second)), {
        prepared: true,
        service_key: 'service_1',
        provider_key: 'provider_1',
        date: '2026-09-01',
        time: '10:30',
        next_step: 'Enter and verify contact details in the visible form, then confirm the booking yourself.',
    });
    assert.equal(harness.preparationCalls.length, 2);
});

test('an invalid retry does not supersede an active valid preparation', async () => {
    let finishPreparation;
    const harness = createHarness({
        prepareBookingSelection(input) {
            return new Promise((resolve) => {
                finishPreparation = () =>
                    resolve({selected_date: input.selectedDate, selected_time: input.selectedTime});
            });
        },
    });
    await harness.api.initialize();
    const tool = harness.activeTools.get('prepare_booking');

    const valid = tool.execute(
        {service_key: 'service_1', provider_key: 'provider_1', date: '2026-09-01', time: '09:00'},
        {signal: new AbortController().signal},
    );

    await assert.rejects(
        tool.execute(
            {service_key: 'service_999', provider_key: 'provider_1', date: '2026-09-01', time: '10:30'},
            {signal: new AbortController().signal},
        ),
        /Unknown service_key/,
    );

    assert.equal(harness.preparationCalls[0].signal.aborted, false);
    finishPreparation();
    assert.equal((await valid).prepared, true);
});

test('booking preparation aborts after a visible provider change during unavailable-date wait', async () => {
    const harness = createBookingSelectionHarness();
    const execution = harness.api.prepareBookingSelection({
        serviceId: 11,
        providerId: 71,
        selectedDate: '2026-09-01',
        selectedTime: '09:00',
        signal: new AbortController().signal,
    });

    harness.userChangeProvider('72');
    harness.setManualHours();
    harness.resolveUnavailable([]);
    await assert.rejects(execution, (error) => error.name === 'AbortError');
    assert.equal(harness.availableHours.emptyCalls > 0, true);
    assert.equal(harness.manualHoursPresent, true);
    assert.equal(harness.ordinaryRenderedResponses, 0);
    assert.equal(harness.wizardFrame3Shown, false);
});

test('booking preparation aborts after a visible service change during available-hours wait', async () => {
    const harness = createBookingSelectionHarness();
    const execution = harness.api.prepareBookingSelection({
        serviceId: 11,
        providerId: 71,
        selectedDate: '2026-09-01',
        selectedTime: '09:00',
        signal: new AbortController().signal,
    });

    harness.resolveUnavailable([]);
    while (!harness.availableStarted) {
        await new Promise((resolve) => setImmediate(resolve));
    }
    harness.userChangeService('12');
    harness.setManualHours();
    harness.resolveAvailable([]);
    await assert.rejects(execution, (error) => error.name === 'AbortError');
    assert.equal(harness.availableHours.emptyCalls > 0, true);
    assert.equal(harness.manualHoursPresent, true);
    assert.equal(harness.availableHours.renderedResponses, 0);
    assert.equal(harness.wizardFrame3Shown, false);
});

test('booking preparation aborts after a visible date change during available-hours wait', async () => {
    const harness = createBookingSelectionHarness();
    const execution = harness.api.prepareBookingSelection({
        serviceId: 11,
        providerId: 71,
        selectedDate: '2026-09-01',
        selectedTime: '09:00',
        signal: new AbortController().signal,
    });
    harness.resolveUnavailable([]);
    while (!harness.availableStarted) await new Promise((resolve) => setImmediate(resolve));
    harness.userChangeDate('2026-09-02');
    harness.setManualHours();
    harness.resolveAvailable([]);
    await assert.rejects(execution, (error) => error.name === 'AbortError');
    assert.equal(harness.wizardFrame3Shown, false);
    assert.equal(harness.availableHours.renderedResponses, 0);
    assert.equal(harness.manualHoursPresent, true);
});

test('booking preparation aborts after a visible timezone change during available-hours wait', async () => {
    const harness = createBookingSelectionHarness();
    const execution = harness.api.prepareBookingSelection({
        serviceId: 11,
        providerId: 71,
        selectedDate: '2026-09-01',
        selectedTime: '09:00',
        signal: new AbortController().signal,
    });
    harness.resolveUnavailable([]);
    while (!harness.availableStarted) await new Promise((resolve) => setImmediate(resolve));
    harness.userChangeTimezone();
    harness.setManualHours();
    harness.resolveAvailable([]);
    await assert.rejects(execution, (error) => error.name === 'AbortError');
    assert.equal(harness.wizardFrame3Shown, false);
    assert.equal(harness.availableHours.renderedResponses, 0);
    assert.equal(harness.manualHoursPresent, true);
});

test('booking preparation aborts pre-existing ordinary availability before its late render', async () => {
    const harness = createBookingSelectionHarness();
    const ordinaryRequest = harness.startOrdinaryAvailability();
    const execution = harness.api.prepareBookingSelection({
        serviceId: 11,
        providerId: 71,
        selectedDate: '2026-09-01',
        selectedTime: '09:00',
        signal: new AbortController().signal,
    });

    assert.equal(ordinaryRequest.aborted, true);
    harness.resolveUnavailable([]);
    while (!harness.availableStarted) await new Promise((resolve) => setImmediate(resolve));
    harness.userChangeService('12');
    harness.setManualHours();
    harness.resolveAvailable([]);
    await assert.rejects(execution, (error) => error.name === 'AbortError');
    assert.equal(harness.ordinaryRenderedResponses, 0);
    assert.equal(harness.manualHoursPresent, true);
    assert.equal(harness.wizardFrame3Shown, false);
});

test('a failed preparation after confirmation returns the visible wizard to step 2', async () => {
    const harness = createBookingSelectionHarness();
    harness.setSelectionAvailable(true);
    const successfulPreparation = harness.api.prepareBookingSelection({
        serviceId: 11,
        providerId: 71,
        selectedDate: '2026-09-01',
        selectedTime: '09:00',
        signal: new AbortController().signal,
    });
    harness.resolveUnavailable([]);
    while (!harness.availableStarted) await new Promise((resolve) => setImmediate(resolve));
    harness.resolveAvailable([]);
    await successfulPreparation;
    assert.equal(harness.wizardFrame3Shown, true);

    harness.setSelectionAvailable(false);
    const failedPreparation = harness.api.prepareBookingSelection({
        serviceId: 11,
        providerId: 71,
        selectedDate: '2026-09-01',
        selectedTime: '10:30',
        signal: new AbortController().signal,
    });
    harness.resolveUnavailable([]);
    while (harness.availableCalls < 2) await new Promise((resolve) => setImmediate(resolve));
    harness.resolveAvailable([]);
    await assert.rejects(failedPreparation, /no longer available/);
    assert.equal(harness.wizardFrame3Shown, false);
    assert.equal(harness.wizardFrame2Shown, true);
    assert.equal(harness.step2Active, true);
});

test('a failed preparation from the final confirmation step returns to slot selection', async () => {
    const harness = createBookingSelectionHarness();
    harness.setSelectionAvailable(true);
    const successfulPreparation = harness.api.prepareBookingSelection({
        serviceId: 11,
        providerId: 71,
        selectedDate: '2026-09-01',
        selectedTime: '09:00',
        signal: new AbortController().signal,
    });
    harness.resolveUnavailable([]);
    while (!harness.availableStarted) await new Promise((resolve) => setImmediate(resolve));
    harness.resolveAvailable([]);
    await successfulPreparation;
    harness.showConfirmationFrame();

    harness.setSelectionAvailable(false);
    const failedPreparation = harness.api.prepareBookingSelection({
        serviceId: 11,
        providerId: 71,
        selectedDate: '2026-09-01',
        selectedTime: '10:30',
        signal: new AbortController().signal,
    });
    harness.resolveUnavailable([]);
    while (harness.availableCalls < 2) await new Promise((resolve) => setImmediate(resolve));
    harness.resolveAvailable([]);
    await assert.rejects(failedPreparation, /no longer available/);
    assert.equal(harness.wizardFrame4Shown, false);
    assert.equal(harness.wizardFrame2Shown, true);
    assert.equal(harness.step2Active, true);
});

test('an older failed preparation cannot hide a newer successful confirmation', async () => {
    const harness = createBookingSelectionHarness();
    harness.setSelectionAvailable(true);
    const oldPreparation = harness.api.prepareBookingSelection({
        serviceId: 11,
        providerId: 71,
        selectedDate: '2026-09-01',
        selectedTime: '09:00',
        signal: new AbortController().signal,
    });
    harness.resolveUnavailableRequest(0, []);
    while (!harness.availableStarted) await new Promise((resolve) => setImmediate(resolve));
    harness.deferAvailableAbort(0);

    const newerPreparation = harness.api.prepareBookingSelection({
        serviceId: 11,
        providerId: 71,
        selectedDate: '2026-09-01',
        selectedTime: '10:30',
        signal: new AbortController().signal,
    });
    harness.resolveUnavailableRequest(1, []);
    await new Promise((resolve) => setImmediate(resolve));
    harness.resolveAvailableRequest(1, []);
    await newerPreparation;
    assert.equal(harness.wizardFrame3Shown, true);
    const oldFailure = assert.rejects(oldPreparation, (error) => error.name === 'AbortError');
    harness.rejectAvailableRequest(0);
    await oldFailure;
    assert.equal(harness.wizardFrame3Shown, true);
    assert.equal(harness.wizardFrame2Shown, false);
});

test('a late abort stops an in-flight availability tool call', async () => {
    let resolveQuery;
    const harness = createHarness({
        queryAvailableHours() {
            return new Promise((resolve) => {
                resolveQuery = resolve;
            });
        },
    });
    await harness.api.initialize();
    const controller = new AbortController();
    const execution = harness.activeTools.get('find_available_slots').execute(
        {
            service_key: 'service_1',
            provider_key: 'provider_1',
            start_date: '2026-09-01',
            end_date: '2026-09-01',
        },
        {signal: controller.signal},
    );

    assert.equal(harness.queryCalls.length, 1);
    assert.equal(harness.queryCalls[0].signal, controller.signal);
    controller.abort(new DOMException('stop', 'AbortError'));
    resolveQuery(['09:00']);

    await assert.rejects(execution, /stop/);
});

test('the shared availability client projects timezone shifts and aborts an in-flight request', () => {
    const harness = createHttpClientHarness();
    const controller = new AbortController();

    assert.equal(
        harness.api.projectAvailableHour({
            selectedDate: '2026-08-27',
            availableHour: '00:30',
            providerTimezone: 'Europe/Berlin',
            selectedTimezone: 'America/Los_Angeles',
        }),
        null,
    );
    assert.equal(
        harness.api.projectAvailableHour({
            selectedDate: '2026-08-27',
            availableHour: '09:00',
            providerTimezone: 'Europe/Berlin',
            selectedTimezone: 'America/Los_Angeles',
        }).displayStart,
        '2026-08-27T00:00:00-07:00',
    );

    harness.api.queryAvailableHours({
        serviceId: 11,
        providerId: 71,
        selectedDate: '2026-08-27',
        signal: controller.signal,
    });
    controller.abort();
    assert.equal(harness.request.abortCount, 1);

    const cleanupHarness = createHttpClientHarness();
    let registeredAbortListener;
    let removedAbortListenerCount = 0;
    const trackedSignal = {
        aborted: false,
        addEventListener(name, listener) {
            assert.equal(name, 'abort');
            registeredAbortListener = listener;
        },
        removeEventListener(name, listener) {
            assert.equal(name, 'abort');
            assert.equal(listener, registeredAbortListener);
            removedAbortListenerCount += 1;
        },
    };

    cleanupHarness.api.queryAvailableHours({
        serviceId: 11,
        providerId: 71,
        selectedDate: '2026-08-27',
        signal: trackedSignal,
    });
    assert.equal(typeof registeredAbortListener, 'function');
    cleanupHarness.request.complete();
    assert.equal(removedAbortListenerCount, 1);
});

test('preserveSelection marks the requested unavailable month without a forward search', async () => {
    const harness = createUnavailableDatesHarness();
    const controller = new AbortController();
    const completion = harness.api.getUnavailableDates(71, 11, '2026-09-15', 1, {
        preserveSelection: true,
        signal: controller.signal,
    });
    let settled = false;
    const awaitedCompletion = Promise.resolve(completion).then(() => {
        settled = true;
    });

    assert.equal(harness.request.doneCallback !== null, true);
    await Promise.resolve();
    assert.equal(settled, false);
    harness.resolveRequest({is_month_unavailable: true});
    await awaitedCompletion;

    assert.equal(settled, true);
    assert.equal(harness.ajaxCalls, 1);
    assert.equal(harness.disabledDates.length, 30);
    assert.equal(moment(harness.disabledDates[0]).format('YYYY-MM-DD'), '2026-09-01');
    assert.equal(moment(harness.disabledDates.at(-1)).format('YYYY-MM-DD'), '2026-09-30');
});

test('preserveSelection ignores a same-month booking URL date override', async () => {
    const harness = createUnavailableDatesHarness({dateQueryParam: '2026-09-20'});
    const completion = harness.api.getUnavailableDates(71, 11, '2026-09-15', 1, {
        preserveSelection: true,
    });

    harness.resolveRequest(['2026-09-01']);
    await completion;

    assert.equal(harness.selectedDateValue, null);
});

test('unavailable-date forward search routes recursive child requests through the exported seam', async () => {
    const harness = createRecursiveUnavailableDatesHarness();
    const completion = harness.api.getUnavailableDates(71, 11, '2026-09-15');
    const parent = harness.requests[1];
    parent.resolve({is_month_unavailable: true});
    await completion;

    assert.equal(harness.ajaxCalls(), 2);
    const child = harness.requests[2];
    assert.equal(child.aborted, false);
    child.then(
        () => {},
        () => {},
    );

    // A preparation boundary aborts all tracked availability work, including the recursive child.
    harness.abortTracked();
    assert.equal(child.aborted, true);
    child.resolve({is_month_unavailable: false, dates: []});
    assert.equal(harness.disabledDates.length, 0);
});

test('unavailable-date application tracks the follow-up hours request before preparation aborts it', async () => {
    const harness = createRecursiveUnavailableDatesHarness();
    const completion = harness.api.getUnavailableDates(71, 11, '2026-09-15');
    const parent = harness.requests[1];
    const availableDates = Array.from({length: 29}, (_, index) => `2026-09-${String(index + 2).padStart(2, '0')}`);

    parent.resolve(availableDates);
    await completion;
    assert.equal(harness.hoursRequest.aborted, false);
    harness.hoursRequest.then(
        () => {},
        () => {},
    );

    // The preparation boundary cancels the exported follow-up hours request before a late done/render.
    harness.abortTracked();
    assert.equal(harness.hoursRequest.aborted, true);
    harness.hoursRequest.resolve(['09:00']);
    assert.equal(harness.renderedHours, 0);
});

test('calendar month and year refresh timers are cancelled before preparation', async () => {
    const harness = createBookingSelectionHarness();
    const callbacks = harness.datePickerOptions;
    const initialUnavailableCalls = harness.unavailableCalls;
    assert.equal(typeof callbacks.onMonthChange, 'function');
    assert.equal(typeof callbacks.onYearChange, 'function');

    callbacks.onMonthChange([], '', {
        selectedDates: [new Date('2026-08-27')],
        currentYearElement: {value: '2026'},
        monthsDropdownContainer: {value: '8'},
    });
    callbacks.onYearChange([], '', {
        selectedDates: [new Date('2026-08-27')],
        currentYearElement: {value: '2027'},
        monthsDropdownContainer: {value: '8'},
    });

    const preparation = harness.api.prepareBookingSelection({
        serviceId: 11,
        providerId: 71,
        selectedDate: '2026-09-01',
        selectedTime: '09:00',
        signal: new AbortController().signal,
    });
    harness.runTimers();
    assert.equal(harness.unavailableCalls, initialUnavailableCalls + 1);

    harness.resolveUnavailable([]);
    while (!harness.availableStarted) await new Promise((resolve) => setImmediate(resolve));
    harness.resolveAvailable([]);
    await assert.rejects(preparation, /no longer available/);
});

test('ordinary date selection preserves the pending month refresh debounce', () => {
    const harness = createBookingSelectionHarness();
    const callbacks = harness.datePickerOptions;
    const initialUnavailableCalls = harness.unavailableCalls;

    callbacks.onMonthChange([], '', {
        selectedDates: [new Date('2026-08-27')],
        currentYearElement: {value: '2026'},
        monthsDropdownContainer: {value: '8'},
    });
    harness.userChangeDate('2026-09-02');
    harness.runTimers();

    assert.equal(harness.unavailableCalls, initialUnavailableCalls + 1);
});

test('preparation rejects when the requested date was not accepted by the date picker', async () => {
    const harness = createBookingSelectionHarness();
    harness.rejectNextDateSelection();

    const preparation = harness.api.prepareBookingSelection({
        serviceId: 11,
        providerId: 71,
        selectedDate: '2026-09-05',
        selectedTime: '09:00',
        signal: new AbortController().signal,
    });

    await assert.rejects(preparation, /date is no longer available/);
    assert.equal(harness.availableStarted, false);
    assert.equal(harness.wizardFrame3Shown, false);
});

test('preparation rejects when the unavailable-date refresh changes the selected date', async () => {
    const harness = createBookingSelectionHarness({driftPreparedDateTo: '2026-09-02'});

    const preparation = harness.api.prepareBookingSelection({
        serviceId: 11,
        providerId: 71,
        selectedDate: '2026-09-05',
        selectedTime: '09:00',
        signal: new AbortController().signal,
    });

    harness.resolveUnavailable([]);
    await assert.rejects(preparation, /date is no longer available/);
    assert.equal(harness.availableStarted, false);
    assert.equal(harness.wizardFrame3Shown, false);
});

test('registration is idempotent, abort-controlled and recovers from partial failure', async () => {
    const harness = createHarness();
    await harness.api.initialize();
    await harness.api.initialize();
    assert.equal(harness.registeredCalls.length, 3);
    assert.equal(harness.activeTools.size, 3);

    harness.api.shutdown();
    assert.equal(harness.activeTools.size, 0);
    await harness.api.initialize();
    assert.equal(harness.registeredCalls.length, 6);
    assert.equal(harness.activeTools.size, 3);

    const partial = createHarness({rejectRegistrationAt: 2});
    assert.equal(await partial.api.initialize(), false);
    assert.equal(partial.activeTools.size, 0);
    partial.allowRegistrations();
    assert.equal(await partial.api.initialize(), true);
    assert.equal(partial.activeTools.size, 3);
});

test('a late old registration failure cannot clear a BFCache replacement', async () => {
    const harness = createHarness({deferRegistrationAt: 2});
    const oldInitialization = harness.api.initialize();
    await new Promise((resolve) => setImmediate(resolve));

    harness.windowListeners.get('pagehide')();
    harness.windowListeners.get('pageshow')({persisted: true});
    const newInitialization = harness.api.initialize();
    assert.equal(await newInitialization, true);
    assert.equal(harness.activeTools.size, 3);

    harness.rejectDeferredRegistration();
    assert.equal(await oldInitialization, false);
    assert.equal(harness.activeTools.size, 3);

    harness.api.shutdown();
    assert.equal(harness.activeTools.size, 0);
});

test('a late old registration success cannot continue after a BFCache replacement', async () => {
    const harness = createHarness({deferRegistrationAt: 2});
    const oldInitialization = harness.api.initialize();
    await new Promise((resolve) => setImmediate(resolve));

    harness.windowListeners.get('pagehide')();
    harness.windowListeners.get('pageshow')({persisted: true});
    assert.equal(await harness.api.initialize(), true);
    assert.equal(harness.registeredCalls.length, 4);
    assert.equal(harness.activeTools.size, 3);

    harness.resolveDeferredRegistration();
    assert.equal(await oldInitialization, false);
    assert.equal(harness.registeredCalls.length, 4);
    assert.equal(harness.activeTools.size, 3);

    harness.api.shutdown();
    assert.equal(harness.activeTools.size, 0);
});
