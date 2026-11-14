const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const repositoryRoot = path.resolve(__dirname, '..', '..');
const bookingSource = fs.readFileSync(path.join(repositoryRoot, 'assets/js/pages/booking.js'), 'utf8');
const httpClientSource = fs.readFileSync(path.join(repositoryRoot, 'assets/js/http/booking_http_client.js'), 'utf8');

function createHarness({manageMode}) {
    let postData = '';
    let submitted;
    let refreshed = 0;
    const consentState = {terms: false, privacy: false};

    const hiddenPostData = {
        val(value) {
            if (value !== undefined) {
                postData = value;
            }

            return postData;
        },
    };
    const captcha = {length: 0};
    const fakeMoment = () => ({
        add() {
            return this;
        },
        clone() {
            return this;
        },
        format(format) {
            return format === 'HH:mm' ? '10:00' : '2026-09-09';
        },
    });
    const context = {
        App: {
            Http: {},
            Pages: {
                Booking: {},
            },
            Utils: {
                Url: {siteUrl: (pathValue) => `/index.php/${pathValue}`},
                Date: {format: () => '2026-09-09'},
                String: {escapeHtml: (value) => String(value)},
                UI: {getDateTimePickerValue: () => new Date('2026-09-09T10:00:00Z')},
            },
        },
        DOMException,
        document: {addEventListener() {}},
        lang: (value) => value,
        moment: fakeMoment,
        window: {location: {}, moment: fakeMoment},
        setTimeout,
        clearTimeout,
        vars(key) {
            return {
                csrf_token: 'csrf',
                manage_mode: manageMode,
                appointment_data: {id: 42},
                customer_data: {id: 84},
                available_services: [{id: 11, duration: 30, price: 100, currency: '€', location: 'Room A'}],
                available_providers: [{id: 71, first_name: 'Ada', last_name: 'Lovelace', room: ''}],
                date_format: 'YYYY-MM-DD',
                time_format: 'HH:mm',
            }[key];
        },
        $(selector) {
            if (selector === '.captcha-text') {
                return captcha;
            }
            if (selector === 'input[name="post_data"]') {
                return hiddenPostData;
            }
            if (selector === '#available-hours') {
                return {
                    length: 1,
                    find() {
                        return {text: () => '10:00', data: () => '10:00'};
                    },
                };
            }
            if (selector === '#accept-to-terms-and-conditions' || selector === '#accept-to-privacy-policy') {
                return {
                    length: 1,
                    prop: () =>
                        selector === '#accept-to-terms-and-conditions' ? consentState.terms : consentState.privacy,
                };
            }
            if (selector === '#select-service' || selector === '#select-provider') {
                return {
                    val: () => (selector === '#select-service' ? '11' : '71'),
                    find: () => ({text: () => (selector === '#select-service' ? 'Forschung' : 'Ada Lovelace')}),
                };
            }
            if (typeof selector === 'string' && selector.startsWith('<')) {
                return {
                    appendTo() {
                        return this;
                    },
                    css() {
                        return this;
                    },
                    remove() {},
                };
            }
            return new Proxy(
                {
                    length: 0,
                    val: () => '',
                    prop: () => false,
                    data: () => '10:00',
                    text: () => 'Forschung',
                    html() {
                        return this;
                    },
                    removeClass() {
                        return this;
                    },
                    closest() {
                        return {length: 0, remove() {}};
                    },
                },
                {
                    get(target, property) {
                        if (property in target) {
                            return target[property];
                        }

                        return () => target;
                    },
                },
            );
        },
        jQuery: null,
    };
    context.$.ajax = (options) => {
        submitted = options.data;
        return {
            done() {
                return this;
            },
            fail() {
                return this;
            },
            always() {
                return this;
            },
        };
    };

    vm.createContext(context);
    vm.runInContext(bookingSource, context, {filename: 'booking.js'});
    const originalUpdateConfirmFrame = context.App.Pages.Booking.updateConfirmFrame;
    context.App.Pages.Booking.updateConfirmFrame = () => {
        refreshed += 1;
        originalUpdateConfirmFrame();
    };
    vm.runInContext(httpClientSource, context, {filename: 'booking_http_client.js'});

    return {
        registerAppointment: context.App.Http.Booking.registerAppointment,
        refreshConfirmFrame: context.App.Pages.Booking.updateConfirmFrame,
        consentState,
        getSubmitted: () => submitted,
        getPostData: () => JSON.parse(postData),
        getRefreshCount: () => refreshed,
    };
}

function formEncodedConsentValues(submitted) {
    return {
        terms_and_conditions: String(submitted.post_data.consent.terms_and_conditions),
        privacy_policy: String(submitted.post_data.consent.privacy_policy),
    };
}

test('public booking refreshes consent at submit and preserves accepted values through form encoding', () => {
    const harness = createHarness({manageMode: false});
    harness.refreshConfirmFrame();
    harness.consentState.terms = true;
    harness.consentState.privacy = true;

    harness.registerAppointment();

    assert.equal(harness.getRefreshCount(), 2);
    assert.deepEqual(formEncodedConsentValues(harness.getSubmitted()), {
        terms_and_conditions: '1',
        privacy_policy: '1',
    });
    assert.deepEqual(harness.getPostData().consent, {
        terms_and_conditions: 1,
        privacy_policy: 1,
    });
});

test('reschedule refreshes consent before including the appointment identity', () => {
    const harness = createHarness({manageMode: true});
    harness.refreshConfirmFrame();
    harness.consentState.terms = true;
    harness.consentState.privacy = true;

    harness.registerAppointment();

    assert.equal(harness.getRefreshCount(), 2);
    assert.equal(harness.getSubmitted().exclude_appointment_id, 42);
    assert.deepEqual(formEncodedConsentValues(harness.getSubmitted()), {
        terms_and_conditions: '1',
        privacy_policy: '1',
    });
});
