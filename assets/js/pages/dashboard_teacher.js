/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     Easy!Appointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.8.0
 * ---------------------------------------------------------------------------- */

/**
 * Provider dashboard page.
 *
 * This module implements the mobile-first dashboard for providers.
 */
App.Pages.DashboardTeacher = (function () {
    const $filters = $('#dashboard-teacher-filters');
    const $dateRange = $('#dashboard-teacher-date-range');
    const $error = $('#dashboard-teacher-error');
    const $progressBooked = $('#dashboard-teacher-progress-booked');
    const $progressOpen = $('#dashboard-teacher-progress-open');
    const $progressBookedValue = $('#dashboard-teacher-progress-booked-value');
    const $progressOpenValue = $('#dashboard-teacher-progress-open-value');
    const $slotInfo = $('#dashboard-teacher-slot-info');
    const $classSize = $('#dashboard-teacher-class-size');
    const $booked = $('#dashboard-teacher-booked');
    const $open = $('#dashboard-teacher-open');
    const $slots = $('#dashboard-teacher-slots');
    const $empty = $('#dashboard-teacher-empty');
    const $mobileList = $('#dashboard-teacher-mobile-list');
    const $tableWrapper = $('#dashboard-teacher-table-wrapper');
    const $tableBody = $('#dashboard-teacher-table-body');

    const savedRangeStart = vars('dashboard_saved_range_start') || '';
    const savedRangeEnd = vars('dashboard_saved_range_end') || '';

    let datePicker;

    function initialize() {
        App.Utils.UI.initializeDatePicker($dateRange, {mode: 'range'});
        datePicker = $dateRange[0]?._flatpickr;

        applyInitialRange();

        $filters.on('submit', onFiltersSubmit);

        loadMetrics();
    }

    function onFiltersSubmit(event) {
        event.preventDefault();
        loadMetrics();
    }

    function applyInitialRange() {
        if (!datePicker) {
            return;
        }

        const savedStart = moment(savedRangeStart, 'YYYY-MM-DD', true);
        const savedEnd = moment(savedRangeEnd, 'YYYY-MM-DD', true);

        if (savedStart.isValid() && savedEnd.isValid() && !savedStart.isAfter(savedEnd)) {
            datePicker.setDate([savedStart.toDate(), savedEnd.toDate()], true);
            return;
        }

        setDefaultRange();
    }

    function setDefaultRange() {
        if (!datePicker) {
            return;
        }

        const firstWeekdayId = App.Utils.Date.getWeekdayId(vars('first_weekday'));
        const today = moment();
        const start = moment(today);

        while (start.day() !== firstWeekdayId) {
            start.subtract(1, 'day');
        }

        const end = moment(start).add(6, 'days');

        datePicker.setDate([start.toDate(), end.toDate()], true);
    }

    function loadMetrics() {
        const filters = getFilters();

        if (!filters) {
            showError(lang('filter_period_required'));
            return;
        }

        hideError();

        App.Http.Dashboard.fetchProviderMetrics(filters)
            .done((response) => {
                if (!response || typeof response !== 'object') {
                    showError(lang('unexpected_issues'));
                    return;
                }

                renderPayload(response);
            })
            .fail((jqXHR) => {
                const message = jqXHR?.responseJSON?.message ?? lang('unexpected_issues');
                showError(message);
            });
    }

    function getFilters() {
        if (!datePicker || !Array.isArray(datePicker.selectedDates) || datePicker.selectedDates.length < 2) {
            return null;
        }

        const sorted = [...datePicker.selectedDates].sort((a, b) => a - b);

        return {
            startDate: moment(sorted[0]).format('YYYY-MM-DD'),
            endDate: moment(sorted[sorted.length - 1]).format('YYYY-MM-DD'),
        };
    }

    function renderPayload(payload) {
        const metrics = payload.metrics || {};
        const progress = payload.progress || {};
        const appointments = Array.isArray(payload.appointments) ? payload.appointments : [];

        renderProgress(progress, metrics);
        renderMetrics(metrics);
        renderAppointments(appointments);
        hideError();
    }

    function renderProgress(progress, metrics) {
        const bookedPercent = clampPercent(progress.booked_percent);
        let openPercent = clampPercent(progress.open_percent);

        if (bookedPercent + openPercent > 100) {
            openPercent = Math.max(0, 100 - bookedPercent);
        }

        $progressBooked.css('width', `${bookedPercent}%`);
        $progressOpen.css('width', `${openPercent}%`);
        $progressBookedValue.text(metrics.booked_formatted || '0');
        $progressOpenValue.text(metrics.open_formatted || '0');
        $slotInfo.text(progress.slot_info_text || '');
    }

    function renderMetrics(metrics) {
        const slotsPlanned = metrics.slots_planned_formatted || '0';
        const slotsRequired = metrics.slots_required_formatted || '0';

        $classSize.text(metrics.class_size_formatted || '—');
        $booked.text(metrics.booked_formatted || '0');
        $open.text(metrics.open_formatted || '0');
        $slots.text(`${slotsPlanned} / ${slotsRequired}`);
    }

    function renderAppointments(appointments) {
        $tableBody.empty();
        $mobileList.empty();

        if (!appointments.length) {
            $empty.prop('hidden', false);
            $tableWrapper.prop('hidden', true);
            return;
        }

        $empty.prop('hidden', true);
        $tableWrapper.prop('hidden', false);

        appointments.forEach((appointment) => {
            const parentLastname = appointment.parent_lastname || '—';
            const dateValue = appointment.date || '';
            const startValue = appointment.start || '';
            const endValue = appointment.end || '';

            const $row = $('<tr/>');
            $('<td/>', {text: parentLastname}).appendTo($row);
            $('<td/>', {text: dateValue}).appendTo($row);
            $('<td/>', {text: startValue}).appendTo($row);
            $('<td/>', {text: endValue}).appendTo($row);
            $row.appendTo($tableBody);

            const $card = $('<article/>', {class: 'dashboard-teacher-appointment-card'});
            $('<h3/>', {
                class: 'dashboard-teacher-appointment-parent',
                text: parentLastname,
            }).appendTo($card);

            const $meta = $('<div/>', {class: 'dashboard-teacher-appointment-meta'});
            $('<span/>', {
                text: `${lang('dashboard_teacher_pdf_table_date')}: ${dateValue || '—'}`,
            }).appendTo($meta);
            $('<span/>', {
                text: `${lang('dashboard_teacher_mobile_time_label')}: ${startValue || '—'} - ${endValue || '—'}`,
            }).appendTo($meta);

            $meta.appendTo($card);
            $card.appendTo($mobileList);
        });
    }

    function clampPercent(value) {
        const numeric = Number(value);

        if (!Number.isFinite(numeric)) {
            return 0;
        }

        return Math.max(0, Math.min(100, Math.round(numeric)));
    }

    function showError(message) {
        $error.text(message).prop('hidden', false);
    }

    function hideError() {
        $error.text('').prop('hidden', true);
    }

    document.addEventListener('DOMContentLoaded', initialize);

    return {};
})();
