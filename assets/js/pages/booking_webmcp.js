/* ----------------------------------------------------------------------------
 * Forscherhaus Appointments - bounded WebMCP booking pilot
 *
 * This adapter intentionally exposes only public booking discovery and visible
 * UI preparation. It never accepts contact data and never submits a booking.
 * ------------------------------------------------------------------------- */

App.Pages.BookingWebMcp = (function () {
    const TOOL_NAMES = ['list_services', 'find_available_slots', 'prepare_booking'];
    const MAX_SEARCH_DAYS = 14;
    const SERVICE_NAME_LIMIT = 120;
    const KEY_PATTERN = /^[a-z]+_[1-9][0-9]{0,3}$/;
    const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;
    const TIME_PATTERN = /^(?:[01]\d|2[0-3]):[0-5]\d$/;

    const moment = window.moment;
    const catalog = buildCatalog();

    let registrationController = null;
    let registrationPromise = null;

    function isEnabled() {
        return String(vars('webmcp_booking_pilot_enabled') || '0') === '1';
    }

    function isSupported() {
        return Boolean(document.modelContext && typeof document.modelContext.registerTool === 'function');
    }

    function buildCatalog() {
        const availableServices = Array.isArray(vars('available_services')) ? vars('available_services') : [];
        const availableProviders = Array.isArray(vars('available_providers')) ? vars('available_providers') : [];
        const services = [];
        const serviceByKey = new Map();
        const providerByKey = new Map();

        availableProviders.forEach((provider, index) => {
            const providerKey = `provider_${index + 1}`;
            providerByKey.set(providerKey, provider);
        });

        availableServices.forEach((service, index) => {
            const serviceKey = `service_${index + 1}`;
            const providerKeys = [];

            providerByKey.forEach((provider, providerKey) => {
                const providerServices = Array.isArray(provider.services) ? provider.services : [];

                if (providerServices.some((serviceId) => Number(serviceId) === Number(service.id))) {
                    providerKeys.push(providerKey);
                }
            });

            const publicService = Object.freeze({
                service_key: serviceKey,
                name: sanitizePublicText(service.name, SERVICE_NAME_LIMIT),
                duration_minutes: normalizeDuration(service.duration),
                provider_keys: Object.freeze(providerKeys),
            });

            services.push(publicService);
            serviceByKey.set(serviceKey, {record: service, publicService});
        });

        return Object.freeze({
            services: Object.freeze(services),
            serviceByKey,
            providerByKey,
        });
    }

    function sanitizePublicText(value, limit) {
        return Array.from(String(value ?? ''))
            .map((character) => {
                const codePoint = character.codePointAt(0);
                return codePoint <= 31 || codePoint === 127 ? ' ' : character;
            })
            .join('')
            .replace(/\s+/g, ' ')
            .trim()
            .slice(0, limit);
    }

    function normalizeDuration(value) {
        const duration = Number(value);
        return Number.isInteger(duration) && duration > 0 && duration <= 1440 ? duration : 1;
    }

    function assertNotAborted(signal) {
        if (signal?.aborted) {
            throw signal.reason ?? new DOMException('The WebMCP tool call was aborted.', 'AbortError');
        }
    }

    function assertAllowedKeys(input, allowedKeys) {
        if (!input || typeof input !== 'object' || Array.isArray(input)) {
            throw new Error('Tool input must be an object.');
        }

        const unexpectedKeys = Object.keys(input).filter((key) => !allowedKeys.includes(key));

        if (unexpectedKeys.length) {
            throw new Error(`Unsupported tool input: ${unexpectedKeys.join(', ')}`);
        }
    }

    function resolveService(serviceKey) {
        if (!KEY_PATTERN.test(String(serviceKey)) || !catalog.serviceByKey.has(serviceKey)) {
            throw new Error('Unknown service_key.');
        }

        return catalog.serviceByKey.get(serviceKey);
    }

    function resolveProvider(providerKey, service) {
        if (!KEY_PATTERN.test(String(providerKey)) || !catalog.providerByKey.has(providerKey)) {
            throw new Error('Unknown provider_key.');
        }

        if (!service.publicService.provider_keys.includes(providerKey)) {
            throw new Error('The provider is not available for this service.');
        }

        return catalog.providerByKey.get(providerKey);
    }

    function parseDate(value, fieldName) {
        if (!DATE_PATTERN.test(String(value))) {
            throw new Error(`${fieldName} must use YYYY-MM-DD.`);
        }

        const parsedDate = moment(value, 'YYYY-MM-DD', true);

        if (!parsedDate.isValid()) {
            throw new Error(`${fieldName} is invalid.`);
        }

        return parsedDate;
    }

    function assertDateWithinBookingWindow(parsedDate, fieldName) {
        const futureBookingLimit = Number(vars('future_booking_limit') || 0);
        const minDate = moment().startOf('day');
        const maxDate = minDate.clone().add(Math.max(futureBookingLimit, 0), 'days');

        if (parsedDate.isBefore(minDate, 'day') || parsedDate.isAfter(maxDate, 'day')) {
            throw new Error(
                `${fieldName} must stay within the visible booking range from ${minDate.format('YYYY-MM-DD')} to ` +
                    `${maxDate.format('YYYY-MM-DD')}.`,
            );
        }
    }

    function parseTime(value) {
        if (!TIME_PATTERN.test(String(value))) {
            throw new Error('time must use HH:mm.');
        }

        return String(value);
    }

    function buildDateRange(startDate, endDate) {
        const start = parseDate(startDate, 'start_date');
        const end = parseDate(endDate, 'end_date');

        assertDateWithinBookingWindow(start, 'start_date');
        assertDateWithinBookingWindow(end, 'end_date');

        if (end.isBefore(start, 'day')) {
            throw new Error('end_date must not be before start_date.');
        }

        const dayCount = end.diff(start, 'days') + 1;

        if (dayCount > MAX_SEARCH_DAYS) {
            throw new Error(`The availability range is limited to ${MAX_SEARCH_DAYS} days.`);
        }

        return Array.from({length: dayCount}, (unused, index) => start.clone().add(index, 'days').format('YYYY-MM-DD'));
    }

    function selectedTimezone() {
        const currentTimezone = $('#select-timezone').val();
        return String(currentTimezone || vars('default_timezone') || 'UTC');
    }

    async function listServices(input, {signal} = {}) {
        assertAllowedKeys(input, []);
        assertNotAborted(signal);

        return {
            services: catalog.services.map((service) => ({
                service_key: service.service_key,
                name: service.name,
                duration_minutes: service.duration_minutes,
                provider_keys: [...service.provider_keys],
            })),
        };
    }

    async function findAvailableSlots(input, {signal} = {}) {
        assertAllowedKeys(input, ['service_key', 'provider_key', 'start_date', 'end_date']);
        assertNotAborted(signal);

        const service = resolveService(input.service_key);
        const provider = resolveProvider(input.provider_key, service);
        const dates = buildDateRange(input.start_date, input.end_date);
        const timezone = selectedTimezone();
        const slots = [];

        for (const selectedDate of dates) {
            assertNotAborted(signal);

            const response = await App.Http.Booking.queryAvailableHours({
                serviceId: service.record.id,
                providerId: provider.id,
                selectedDate,
                serviceDuration: service.publicService.duration_minutes,
                manageMode: 0,
                appointmentId: null,
                signal,
            });

            assertNotAborted(signal);

            const hours = Array.isArray(response) ? response : [];

            hours.forEach((hour) => {
                if (!TIME_PATTERN.test(String(hour))) {
                    return;
                }

                const projectedHour = App.Http.Booking.projectAvailableHour({
                    selectedDate,
                    availableHour: String(hour),
                    providerTimezone: provider.timezone,
                    selectedTimezone: timezone,
                });

                if (!projectedHour) {
                    return;
                }

                slots.push({
                    date: selectedDate,
                    time: String(hour),
                    display_start: projectedHour.displayStart,
                    display_timezone: timezone,
                });
            });
        }

        return {
            service_key: input.service_key,
            provider_key: input.provider_key,
            slots,
        };
    }

    async function prepareBooking(input, {signal} = {}) {
        assertAllowedKeys(input, ['service_key', 'provider_key', 'date', 'time']);
        assertNotAborted(signal);

        if (Boolean(Number(vars('manage_mode') || 0))) {
            throw new Error('prepare_booking is unavailable while rescheduling.');
        }

        const service = resolveService(input.service_key);
        const provider = resolveProvider(input.provider_key, service);
        const selectedDateMoment = parseDate(input.date, 'date');
        assertDateWithinBookingWindow(selectedDateMoment, 'date');
        const selectedDate = selectedDateMoment.format('YYYY-MM-DD');
        const selectedTime = parseTime(input.time);

        const prepared = await App.Pages.Booking.prepareBookingSelection({
            serviceId: service.record.id,
            providerId: provider.id,
            selectedDate,
            selectedTime,
            signal,
        });

        assertNotAborted(signal);

        return {
            prepared: true,
            service_key: input.service_key,
            provider_key: input.provider_key,
            date: prepared.selected_date,
            time: prepared.selected_time,
            next_step: 'Enter and verify contact details in the visible form, then confirm the booking yourself.',
        };
    }

    function toolDefinitions() {
        const serviceKeys = [...catalog.serviceByKey.keys()];
        const providerKeys = [...catalog.providerByKey.keys()];

        return [
            {
                name: 'list_services',
                title: 'List public appointment services',
                description: 'Lists the minimal public service catalog and page-local provider selection keys.',
                inputSchema: {
                    type: 'object',
                    properties: {},
                    additionalProperties: false,
                },
                annotations: {
                    readOnlyHint: true,
                    untrustedContentHint: true,
                },
                execute: listServices,
            },
            {
                name: 'find_available_slots',
                title: 'Find available appointment slots',
                description:
                    'Reads server-authoritative availability for one service and provider over at most 14 days.',
                inputSchema: {
                    type: 'object',
                    properties: {
                        service_key: {type: 'string', enum: serviceKeys},
                        provider_key: {type: 'string', enum: providerKeys},
                        start_date: {type: 'string', pattern: '^\\d{4}-\\d{2}-\\d{2}$'},
                        end_date: {type: 'string', pattern: '^\\d{4}-\\d{2}-\\d{2}$'},
                    },
                    required: ['service_key', 'provider_key', 'start_date', 'end_date'],
                    additionalProperties: false,
                },
                annotations: {
                    readOnlyHint: true,
                    untrustedContentHint: true,
                },
                execute: findAvailableSlots,
            },
            {
                name: 'prepare_booking',
                title: 'Prepare an appointment in the visible form',
                description:
                    'Selects a returned public slot in the existing form without contact data or booking confirmation.',
                inputSchema: {
                    type: 'object',
                    properties: {
                        service_key: {type: 'string', enum: serviceKeys},
                        provider_key: {type: 'string', enum: providerKeys},
                        date: {type: 'string', pattern: '^\\d{4}-\\d{2}-\\d{2}$'},
                        time: {type: 'string', pattern: '^(?:[01]\\d|2[0-3]):[0-5]\\d$'},
                    },
                    required: ['service_key', 'provider_key', 'date', 'time'],
                    additionalProperties: false,
                },
                annotations: {
                    readOnlyHint: false,
                    untrustedContentHint: false,
                },
                execute: prepareBooking,
            },
        ];
    }

    function initialize() {
        if (!isEnabled() || !isSupported()) {
            return Promise.resolve(false);
        }

        if (registrationPromise) {
            return registrationPromise;
        }

        const controller = new AbortController();
        registrationController = controller;

        registrationPromise = (async () => {
            for (const tool of toolDefinitions()) {
                await document.modelContext.registerTool(tool, {signal: controller.signal});
            }

            return true;
        })().catch((error) => {
            controller.abort();
            registrationController = null;
            registrationPromise = null;
            console.warn('WebMCP booking pilot registration failed.', error);
            return false;
        });

        return registrationPromise;
    }

    function shutdown() {
        registrationController?.abort();
        registrationController = null;
        registrationPromise = null;
    }

    document.addEventListener('DOMContentLoaded', initialize, {once: true});
    window.addEventListener('pagehide', shutdown);
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            initialize();
        }
    });

    return Object.freeze({
        initialize,
        shutdown,
        toolNames: Object.freeze([...TOOL_NAMES]),
    });
})();
