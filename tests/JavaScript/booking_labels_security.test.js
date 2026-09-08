const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const repositoryRoot = path.resolve(__dirname, '..', '..');
const bookingSource = fs.readFileSync(path.join(repositoryRoot, 'assets/js/pages/booking.js'), 'utf8');

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function createHarness({serviceName, providerName, currency, location}) {
    const rendered = {
        appointmentDetails: '',
        displayBookingSelection: '',
        serviceDescription: [],
    };
    const values = {
        manage_mode: false,
        available_services: [{id: 11, duration: 30, price: 100, currency, location, description: ''}],
        available_providers: [{id: 71, first_name: 'Ada', last_name: 'Lovelace', room: ''}],
        date_format: 'YYYY-MM-DD',
        time_format: 'HH:mm',
    };

    const fields = new Map([
        ['#select-service', {val: () => '11', find: () => ({text: () => serviceName})}],
        ['#select-provider', {val: () => '71', find: () => ({text: () => providerName})}],
        ['#available-hours', {find: () => ({text: () => '10:00'})}],
        ['#select-date', {val: () => ''}],
        ['#first-name', {val: () => ''}],
        ['#last-name', {val: () => ''}],
        ['#email', {val: () => ''}],
        ['#phone-number', {val: () => ''}],
        ['#address', {val: () => ''}],
        ['#city', {val: () => ''}],
        ['#zip-code', {val: () => ''}],
    ]);

    const appointmentDetails = {
        html(value) {
            rendered.appointmentDetails = value;
        },
        find(selector) {
            if (selector === '.fas.fa-clock') {
                return {closest: () => ({length: 0})};
            }

            return {closest: () => ({remove() {}})};
        },
    };
    const serviceDescription = {
        empty() {},
    };
    const customerDetails = {html() {}};
    const fakeMoment = (value) => ({
        add() {
            return this;
        },
        clone() {
            return fakeMoment(value);
        },
        format: (format) => (format === 'HH:mm' ? '10:00' : '2026-09-09'),
    });
    const context = {
        App: {
            Http: {Booking: {}},
            Pages: {},
            Utils: {
                Date: {format: () => '2026-09-09'},
                String: {escapeHtml},
                UI: {getDateTimePickerValue: () => new Date('2026-09-09T10:00:00Z')},
            },
        },
        DOMException,
        document: {addEventListener() {}},
        lang: (value) => value,
        moment: fakeMoment,
        vars(key) {
            return values[key];
        },
        window: {moment: fakeMoment},
        $displayBookingSelection: null,
        $(selector) {
            if (selector === '.display-booking-selection') {
                return {
                    text(value) {
                        rendered.displayBookingSelection = value;
                    },
                };
            }
            if (selector === '#appointment-details') {
                return appointmentDetails;
            }
            if (selector === '#service-description') {
                return serviceDescription;
            }
            if (selector === '#customer-details') {
                return customerDetails;
            }
            if (typeof selector === 'string' && selector.trimStart().startsWith('<')) {
                return {
                    appendTo() {
                        rendered.serviceDescription.push(selector);
                    },
                };
            }

            return (
                fields.get(selector) || {
                    data: () => '10:00',
                    find: () => ({text: () => ''}),
                    val: () => '',
                }
            );
        },
    };

    vm.createContext(context);
    vm.runInContext(bookingSource, context, {filename: 'booking.js'});

    return {api: context.App.Pages.Booking, rendered};
}

test('booking HTML sinks escape hostile labels and service metadata', () => {
    const harness = createHarness({
        serviceName: 'Forschung & Café <img src=x onerror=alert(1)>',
        providerName: 'Dr. Änne <script>alert(1)</script>',
        currency: '€ <b onmouseover=alert(1)>',
        location: 'Raum 3 & <svg onload=alert(1)>',
    });

    harness.api.updateConfirmFrame();
    harness.api.updateServiceDescription(11);

    assert.equal(
        harness.rendered.displayBookingSelection,
        'Forschung & Café <img src=x onerror=alert(1)> │ Dr. Änne <script>alert(1)</script>',
    );
    assert.match(harness.rendered.appointmentDetails, /Forschung &amp; Café &lt;img src=x onerror=alert\(1\)&gt;/);
    assert.match(harness.rendered.appointmentDetails, /Dr\. Änne &lt;script&gt;alert\(1\)&lt;\/script&gt;/);
    assert.match(harness.rendered.appointmentDetails, /€ &lt;b onmouseover=alert\(1\)&gt;/);
    assert.equal(harness.rendered.appointmentDetails.includes('<img'), false);
    assert.equal(harness.rendered.appointmentDetails.includes('<script>'), false);
    assert.match(harness.rendered.serviceDescription.join(''), /Raum 3 &amp; &lt;svg onload=alert\(1\)&gt;/);
    assert.equal(harness.rendered.serviceDescription.join('').includes('<svg'), false);
});

test('booking HTML sinks preserve ordinary ampersands and accents as text', () => {
    const harness = createHarness({
        serviceName: 'Mathe & Musik für Schüler:innen',
        providerName: 'Jörg Ährens',
        currency: '€',
        location: 'Raum A & B',
    });

    harness.api.updateConfirmFrame();
    harness.api.updateServiceDescription(11);

    assert.match(harness.rendered.appointmentDetails, /Mathe &amp; Musik für Schüler:innen/);
    assert.match(harness.rendered.appointmentDetails, /Jörg Ährens/);
    assert.match(harness.rendered.serviceDescription.join(''), /Raum A &amp; B/);
});
