/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.5.0
 * ---------------------------------------------------------------------------- */

/**
 * Booking HTTP client.
 *
 * This module implements the booking related HTTP requests.
 *
 * Old Name: FrontendBookApi
 */
App.Http.Booking = (function () {
    const $selectDate = $('#select-date');
    const $selectService = $('#select-service');
    const $selectProvider = $('#select-provider');
    const $availableHours = $('#available-hours');
    const $captchaHint = $('#captcha-hint');
    const $captchaTitle = $('.captcha-title');

    const MONTH_SEARCH_LIMIT = 2; // Months in the future

    const moment = window.moment;

    let unavailableDatesBackup;
    let selectedDateStringBackup;
    let processingUnavailableDates = false;
    let searchedMonthStart;
    let searchedMonthCounter = 0;
    const activeAvailabilityRequests = new Set();

    function trackAvailabilityRequest(request) {
        if (!request?.abort || !request?.always) {
            return request;
        }

        activeAvailabilityRequests.add(request);
        request.always(() => activeAvailabilityRequests.delete(request));
        return request;
    }

    /**
     * Abort availability requests started by this client (including recursive searches).
     * This is intentionally narrow: callers cannot cancel unrelated HTTP requests.
     */
    function abortTrackedAvailabilityRequests() {
        [...activeAvailabilityRequests].forEach((request) => request.abort());
        activeAvailabilityRequests.clear();
    }

    function isNoSlotFallbackEnabled() {
        const fallbackState = vars('no_slot_fallback_enabled');
        return fallbackState === undefined ? true : String(fallbackState) === '1';
    }

    function setNoSlotFallbackProminent(isProminent) {
        if (App.Pages?.Booking?.setNoSlotFallbackProminent) {
            App.Pages.Booking.setNoSlotFallbackProminent(isProminent, 'inline');
        }
    }

    function trackNoSlotEmptyStateShown() {
        if (App.Pages?.Booking?.trackNoSlotEmptyStateShown) {
            App.Pages.Booking.trackNoSlotEmptyStateShown('empty_state');
        }
    }

    function renderNoAvailableHoursState() {
        if (!isNoSlotFallbackEnabled()) {
            $availableHours.text(lang('no_available_hours'));
            return;
        }

        const $state = $('<div/>', {
            'id': 'no-slot-empty-state',
            'class': 'alert alert-warning',
            'html': [
                $('<div/>', {
                    'class': 'mb-2',
                    'text': lang('no_available_hours'),
                }),
                $('<button/>', {
                    'type': 'button',
                    'id': 'no-slot-empty-state-trigger',
                    'class': 'btn btn-dark btn-sm',
                    'text': lang('no_slot_fallback_trigger_prominent'),
                }),
            ],
        });

        $availableHours.empty().append($state);
        setNoSlotFallbackProminent(true);
        trackNoSlotEmptyStateShown();
    }

    /**
     * Get Available Hours
     *
     * This function makes an AJAX call and returns the available hours for the selected service,
     * provider and date.
     *
     * @param {String} selectedDate The selected date of the available hours we need.
     * @param {AbortSignal} [signal] Optional cancellation signal for assisted UI preparation.
     */
    function getAvailableHours(selectedDate, signal) {
        $availableHours.empty();

        // Find the selected service duration (it is going to be send within the "data" object).
        const serviceId = $selectService.val();

        // Default value of duration (in minutes).
        let serviceDuration = 15;

        const service = vars('available_services').find(
            (availableService) => Number(availableService.id) === Number(serviceId),
        );

        if (service) {
            serviceDuration = service.duration;
        }

        // If the manage mode is true then the appointment's start date should return as available too.
        const appointmentId = vars('manage_mode') ? vars('appointment_data').id : null;

        const request = queryAvailableHours({
            serviceId: $selectService.val(),
            providerId: $selectProvider.val(),
            selectedDate,
            serviceDuration,
            manageMode: Number(vars('manage_mode') || 0),
            appointmentId,
            signal,
        });

        request.done((response) => {
            renderAvailableHours(response, selectedDate, serviceId);
        });

        return request;
    }

    /**
     * Query the existing read-only availability endpoint without changing the booking UI.
     *
     * @param {Object} options
     * @param {Number|String} options.serviceId
     * @param {Number|String} options.providerId
     * @param {String} options.selectedDate
     * @param {Number} [options.serviceDuration]
     * @param {Number} [options.manageMode]
     * @param {Number|null} [options.appointmentId]
     * @param {AbortSignal} [options.signal]
     *
     * @return {JQuery.jqXHR}
     */
    function queryAvailableHours({
        serviceId,
        providerId,
        selectedDate,
        serviceDuration,
        manageMode = 0,
        appointmentId = null,
        signal,
    }) {
        const url = App.Utils.Url.siteUrl('booking/get_available_hours');

        const data = {
            csrf_token: vars('csrf_token'),
            service_id: serviceId,
            provider_id: providerId,
            selected_date: selectedDate,
            service_duration: serviceDuration,
            manage_mode: Number(manageMode || 0),
            appointment_id: appointmentId,
        };

        const request = $.ajax({
            url,
            method: 'post',
            data,
            dataType: 'json',
        });

        return trackAvailabilityRequest(bindAbortSignal(request, signal));
    }

    function bindAbortSignal(request, signal) {
        if (!signal) {
            return request;
        }

        const abortRequest = () => request.abort();

        if (signal.aborted) {
            abortRequest();
        } else {
            signal.addEventListener('abort', abortRequest, {once: true});
            request.always(() => signal.removeEventListener('abort', abortRequest));
        }

        return request;
    }

    /**
     * Project one server-authoritative provider hour into the timezone selected on the public booking page.
     *
     * Returning null preserves the existing rule that a timezone-shifted hour outside the selected calendar day
     * is not visible to the user.
     *
     * @param {Object} options
     * @param {String} options.selectedDate
     * @param {String} options.availableHour
     * @param {String} options.providerTimezone
     * @param {String} options.selectedTimezone
     * @param {String} [options.timeFormat]
     *
     * @return {Object|null}
     */
    function projectAvailableHour({
        selectedDate,
        availableHour,
        providerTimezone,
        selectedTimezone,
        timeFormat = 'HH:mm',
    }) {
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

    function renderAvailableHours(response, selectedDate, serviceId) {
        $availableHours.empty();
        App.Pages.Booking.resetTimeSelectionScroll();

        // The response contains the available hours for the selected provider and service. Fill the available
        // hours div with response data.
        if (response.length > 0) {
            let providerId = $selectProvider.val();

            if (providerId === 'any-provider') {
                for (const availableProvider of vars('available_providers')) {
                    if (availableProvider.services.indexOf(Number(serviceId)) !== -1) {
                        providerId = availableProvider.id; // Use first available provider.
                        break;
                    }
                }
            }

            const provider = vars('available_providers').find(
                (availableProvider) => Number(providerId) === Number(availableProvider.id),
            );

            if (!provider) {
                throw new Error('Could not find provider.');
            }

            const providerTimezone = provider.timezone;
            const selectedTimezone = $('#select-timezone').val();
            const timeFormat = vars('time_format') === 'regular' ? 'h:mm a' : 'HH:mm';

            response.forEach((availableHour) => {
                const projectedHour = projectAvailableHour({
                    selectedDate,
                    availableHour,
                    providerTimezone,
                    selectedTimezone,
                    timeFormat,
                });

                if (!projectedHour) {
                    return; // Due to the selected timezone the available hour belongs to another date.
                }

                $availableHours.append(
                    $('<button/>', {
                        'class': 'btn btn-outline-secondary w-100 shadow-none available-hour',
                        'data': {
                            'value': projectedHour.value,
                        },
                        'text': projectedHour.displayText,
                    }),
                );
            });

            if (App.Pages.Booking.manageMode) {
                // Set the appointment's start time as the default selection.
                $('.available-hour')
                    .removeClass('selected-hour')
                    .filter(
                        (index, availableHourEl) =>
                            $(availableHourEl).text() ===
                            moment(vars('appointment_data').start_datetime).format(timeFormat),
                    )
                    .addClass('selected-hour');
            } else {
                // Set the first available hour as the default selection.
                $('.available-hour:eq(0)').addClass('selected-hour');
            }

            App.Pages.Booking.updateConfirmFrame();
            setNoSlotFallbackProminent(false);
        }

        App.Pages.Booking.scrollToFirstAvailableHour();

        if (!$availableHours.find('.available-hour').length) {
            renderNoAvailableHoursState();
        }
    }

    /**
     * Register an appointment to the database.
     *
     * This method will make an ajax call to the appointments controller that will register
     * the appointment to the database.
     */
    function registerAppointment() {
        const $captchaText = $('.captcha-text');

        if ($captchaText.length > 0) {
            $captchaText.removeClass('is-invalid');
            if ($captchaText.val() === '') {
                $captchaText.addClass('is-invalid');
                return;
            }
        }

        const formData = JSON.parse($('input[name="post_data"]').val());

        const data = {
            csrf_token: vars('csrf_token'),
            post_data: formData,
        };

        if ($captchaText.length > 0) {
            data.captcha = $captchaText.val();
        }

        if (vars('manage_mode')) {
            data.exclude_appointment_id = vars('appointment_data').id;
        }

        const url = App.Utils.Url.siteUrl('booking/register');

        const $layer = $('<div/>');

        $.ajax({
            url: url,
            method: 'post',
            data: data,
            dataType: 'json',
            beforeSend: () => {
                $layer.appendTo('body').css({
                    background: 'white',
                    position: 'fixed',
                    top: '0',
                    left: '0',
                    height: '100vh',
                    width: '100vw',
                    opacity: '0.5',
                });
            },
        })
            .done((response) => {
                if (response.captcha_verification === false) {
                    $captchaHint.text(lang('captcha_is_wrong')).fadeTo(400, 1);

                    setTimeout(() => {
                        $captchaHint.fadeTo(400, 0);
                    }, 3000);

                    $captchaTitle.find('button').trigger('click');

                    $captchaText.addClass('is-invalid');

                    return false;
                }

                window.location.href = App.Utils.Url.siteUrl('booking_confirmation/of/' + response.appointment_hash);
            })
            .fail(() => {
                $captchaTitle.find('button').trigger('click');
            })
            .always(() => {
                $layer.remove();
            });
    }

    /**
     * Get the unavailable dates of a provider.
     *
     * This method will fetch the unavailable dates of the selected provider and service and then it will
     * select the first available date (if any). It uses the "FrontendBookApi.getAvailableHours" method to
     * fetch the appointment* hours of the selected date.
     *
     * @param {Number} providerId The selected provider ID.
     * @param {Number} serviceId The selected service ID.
     * @param {String} selectedDateString Y-m-d value of the selected date.
     * @param {Number} [monthChangeStep] Whether to add or subtract months.
     * @param {Object} [options]
     * @param {Boolean} [options.preserveSelection] Do not auto-select the first enabled day.
     * @param {AbortSignal} [options.signal] Optional cancellation signal for assisted UI preparation.
     */
    function getUnavailableDates(providerId, serviceId, selectedDateString, monthChangeStep = 1, options = {}) {
        if (processingUnavailableDates) {
            return;
        }

        if (!providerId || !serviceId) {
            return;
        }

        const appointmentId = App.Pages.Booking.manageMode ? vars('appointment_data').id : null;

        const url = App.Utils.Url.siteUrl('booking/get_unavailable_dates');

        const data = {
            provider_id: providerId,
            service_id: serviceId,
            selected_date: encodeURIComponent(selectedDateString),
            csrf_token: vars('csrf_token'),
            manage_mode: Number(App.Pages.Booking.manageMode),
            appointment_id: appointmentId,
        };

        const request = $.ajax({
            url: url,
            type: 'GET',
            data: data,
            dataType: 'json',
        })
            .done((response) => {
                // In case the current month has no availability, the app will try the next one or the one after in order to
                // find a date that has at least one slot

                if (response.is_month_unavailable) {
                    if (options.preserveSelection) {
                        const selectedDateMoment = moment(selectedDateString);
                        const startOfMonthMoment = selectedDateMoment.clone().startOf('month');
                        const endOfMonthMoment = selectedDateMoment.clone().endOf('month');
                        const unavailableDates = [];

                        while (startOfMonthMoment.isSameOrBefore(endOfMonthMoment, 'day')) {
                            unavailableDates.push(startOfMonthMoment.format('YYYY-MM-DD'));
                            startOfMonthMoment.add(1, 'day');
                        }

                        // Assisted preparation must not search forward: doing so starts another request whose
                        // late response can mutate the calendar after the visible selection has changed.
                        applyUnavailableDates(unavailableDates, selectedDateString, false, {
                            preserveSelection: true,
                        });
                        return;
                    }

                    if (!searchedMonthStart) {
                        searchedMonthStart = selectedDateString;
                    }

                    if (searchedMonthCounter >= MONTH_SEARCH_LIMIT) {
                        // Need to mark the current month dates as unavailable
                        const selectedDateMoment = moment(searchedMonthStart);
                        const startOfMonthMoment = selectedDateMoment.clone().startOf('month');
                        const endOfMonthMoment = selectedDateMoment.clone().endOf('month');
                        const unavailableDates = [];

                        while (startOfMonthMoment.isSameOrBefore(endOfMonthMoment)) {
                            unavailableDates.push(startOfMonthMoment.format('YYYY-MM-DD'));
                            startOfMonthMoment.add(Math.abs(monthChangeStep), 'days'); // Move to the next day
                        }

                        applyUnavailableDates(unavailableDates, searchedMonthStart, !options.preserveSelection, {
                            preserveSelection: options.preserveSelection,
                        });
                        searchedMonthStart = undefined;
                        searchedMonthCounter = 0;

                        return; // Stop searching
                    }

                    searchedMonthCounter++;

                    const selectedDateMoment = moment(selectedDateString);
                    selectedDateMoment.add(1, 'month');

                    const nextSelectedDate = selectedDateMoment.format('YYYY-MM-DD');
                    // Route recursive searches through the exported method so Booking's request tracker can
                    // cancel the entire chain when assisted preparation supersedes it.
                    App.Http.Booking.getUnavailableDates(
                        providerId,
                        serviceId,
                        nextSelectedDate,
                        monthChangeStep,
                        options,
                    );

                    return;
                }

                unavailableDatesBackup = response;
                selectedDateStringBackup = selectedDateString;
                applyUnavailableDates(response, selectedDateString, !options.preserveSelection, {
                    preserveSelection: options.preserveSelection,
                });
            })
            .fail(() => {
                $selectDate.parent().fadeTo(400, 1);
            });

        return trackAvailabilityRequest(bindAbortSignal(request, options.signal));
    }

    function applyPreviousUnavailableDates() {
        applyUnavailableDates(unavailableDatesBackup, selectedDateStringBackup);
    }

    function applyUnavailableDates(unavailableDates, selectedDateString, setDate, options = {}) {
        setDate = setDate || false;

        $selectDate.parent().fadeTo(400, 1);

        processingUnavailableDates = true;

        // Select first enabled date.
        const selectedDateMoment = moment(selectedDateString);
        const selectedDate = selectedDateMoment.toDate();
        const numberOfDays = selectedDateMoment.daysInMonth();

        // If all the days are unavailable then hide the appointments hours.
        if (unavailableDates.length === numberOfDays) {
            renderNoAvailableHoursState();
        }

        // Grey out unavailable dates.
        $selectDate[0]._flatpickr.set(
            'disable',
            unavailableDates.map((unavailableDate) => new Date(unavailableDate + 'T00:00')),
        );

        if (setDate && !vars('manage_mode')) {
            for (let i = 1; i <= numberOfDays; i++) {
                const currentDate = new Date(selectedDate.getFullYear(), selectedDate.getMonth(), i);

                if (unavailableDates.indexOf(moment(currentDate).format('YYYY-MM-DD')) === -1) {
                    App.Utils.UI.setDateTimePickerValue($selectDate, currentDate);
                    App.Http.Booking.getAvailableHours(moment(currentDate).format('YYYY-MM-DD'));
                    break;
                }
            }
        }

        const dateQueryParam = App.Utils.Url.queryParam('date');

        if (dateQueryParam && !options.preserveSelection) {
            const dateQueryParamMoment = moment(dateQueryParam);

            if (
                dateQueryParamMoment.isValid() &&
                !unavailableDates.includes(dateQueryParam) &&
                dateQueryParamMoment.format('YYYY-MM') === selectedDateMoment.format('YYYY-MM')
            ) {
                App.Utils.UI.setDateTimePickerValue($selectDate, dateQueryParamMoment.toDate());
            }
        }

        searchedMonthStart = undefined;
        searchedMonthCounter = 0;
        processingUnavailableDates = false;
    }

    /**
     * Delete personal information.
     *
     * @param {Number} customerToken Customer unique token.
     */
    function deletePersonalInformation(customerToken) {
        const url = App.Utils.Url.siteUrl('privacy/delete_personal_information');

        const data = {
            csrf_token: vars('csrf_token'),
            customer_token: customerToken,
        };

        $.post(url, data).done(() => {
            window.location.href = vars('base_url');
        });
    }

    return {
        registerAppointment,
        getAvailableHours,
        queryAvailableHours,
        abortTrackedAvailabilityRequests,
        projectAvailableHour,
        getUnavailableDates,
        applyPreviousUnavailableDates,
        deletePersonalInformation,
    };
})();
