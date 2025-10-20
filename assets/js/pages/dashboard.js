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
 * Dashboard page.
 *
 * This module implements the functionality of the utilization dashboard page.
 */
App.Pages.Dashboard = (function () {
    const $filters = $('#dashboard-filters');
    const $dateRange = $('#dashboard-date-range');
    const $statuses = $('#dashboard-statuses');
    const $service = $('#dashboard-service');
    const $hideWithoutTarget = $('#dashboard-hide-without-target');
    const $hiddenCounter = $('#dashboard-hidden-counter');
    const $tableBody = $('#dashboard-table tbody');
    const $emptyRow = $('#dashboard-table-empty');
    const $error = $('#dashboard-error');
    const $downloadTeacher = $('#dashboard-download-teacher');
    const $downloadPrincipal = $('#dashboard-download-principal');
    const $thresholdButton = $('#dashboard-threshold-button');
    const $thresholdDisplay = $('#dashboard-threshold-display');
    const $thresholdForm = $('#dashboard-threshold-form');
    const $thresholdInput = $('#dashboard-threshold-input');
    const thresholdModalElement = document.getElementById('dashboard-threshold-modal');

    const appointmentStatusOptions = vars('appointment_status_options') || [];
    const defaultStatuses = vars('dashboard_default_statuses') || [];
    const serviceOptions = vars('dashboard_service_options') || [];

    let threshold = parseFloat(vars('dashboard_conflict_threshold')) || 0.75;
    let thresholdModal;
    let datePicker;
    let chart;
    let metrics = [];

    function initialize() {
        populateStatusOptions();
        populateServiceOptions();

        App.Utils.UI.initializeDropdown($statuses, {
            placeholder: lang('statuses_filter_label'),
            width: '100%',
            allowClear: true,
        });

        App.Utils.UI.initializeDropdown($service, {
            placeholder: lang('dashboard_all_services_option'),
            width: '100%',
            allowClear: true,
        });

        setDefaultStatuses();
        App.Utils.UI.initializeDatePicker($dateRange, {mode: 'range'});
        datePicker = $dateRange[0]?._flatpickr;

        setDefaultRange();
        updateThresholdDisplay();

        $filters.on('submit', onFiltersSubmit);
        $hideWithoutTarget.on('change', onHideToggleChange);
        $downloadTeacher.on('click', onDownloadTeacher);
        $downloadPrincipal.on('click', onDownloadPrincipal);
        $thresholdButton.on('click', onThresholdButtonClick);
        $thresholdForm.on('submit', onThresholdFormSubmit);

        updateHiddenCounter();
        loadMetrics();
    }

    function populateStatusOptions() {
        appointmentStatusOptions.forEach((status) => {
            $('<option/>', {
                value: status,
                text: status,
            }).appendTo($statuses);
        });
    }

    function populateServiceOptions() {
        $('<option/>', {
            value: '',
            text: lang('dashboard_all_services_option'),
        }).appendTo($service);

        serviceOptions.forEach((service) => {
            $('<option/>', {
                value: service.id,
                text: service.name,
            }).appendTo($service);
        });
    }

    function setDefaultStatuses() {
        if (!defaultStatuses.length) {
            return;
        }

        const available = appointmentStatusOptions.filter((status) => defaultStatuses.includes(status));

        if (!available.length) {
            return;
        }

        $statuses.val(available).trigger('change');
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

    function onFiltersSubmit(event) {
        event.preventDefault();
        loadMetrics();
    }

    function onHideToggleChange() {
        updateHiddenCounter();
        renderChart();
        renderTable();
    }

    function onDownloadTeacher(event) {
        event.preventDefault();
        triggerDownload(App.Http.Dashboard.downloadTeacherExport);
    }

    function onDownloadPrincipal(event) {
        event.preventDefault();
        triggerDownload(App.Http.Dashboard.downloadPrincipalExport);
    }

    function triggerDownload(downloadFn) {
        const filters = getFilters();

        if (!filters) {
            showError(lang('filter_period_required'));
            return;
        }

        hideError();

        downloadFn({
            startDate: filters.startDate,
            endDate: filters.endDate,
            statuses: filters.statuses,
            serviceId: filters.serviceId,
            providerIds: getDownloadProviderIds(),
        });
    }

    function onThresholdButtonClick(event) {
        event.preventDefault();

        if (!thresholdModalElement) {
            return;
        }

        if (!thresholdModal) {
            thresholdModal = new bootstrap.Modal(thresholdModalElement);
        }

        $thresholdInput.val(threshold.toFixed(2));
        $thresholdInput.removeClass('is-invalid');
        thresholdModal.show();
    }

    function onThresholdFormSubmit(event) {
        event.preventDefault();

        const value = parseFloat($thresholdInput.val());

        if (Number.isNaN(value) || value < 0 || value > 1) {
            $thresholdInput.addClass('is-invalid');
            return;
        }

        threshold = value;
        $thresholdInput.removeClass('is-invalid');
        updateThresholdDisplay();
        renderChart();
        renderTable();

        if (thresholdModal) {
            thresholdModal.hide();
        }
    }

    function loadMetrics() {
        const filters = getFilters();

        if (!filters) {
            showError(lang('filter_period_required'));
            return;
        }

        hideError();

        App.Http.Dashboard.fetch(filters)
            .done((response) => {
                metrics = Array.isArray(response) ? response : [];
                updateHiddenCounter();
                renderChart();
                renderTable();
            })
            .fail((jqXHR) => {
                const message = jqXHR?.responseJSON?.message ?? lang('unexpected_issues');
                metrics = [];
                showError(message);
                updateHiddenCounter();
                renderChart();
                renderTable();
            });
    }

    function getFilters() {
        const range = getSelectedRange();

        if (!range) {
            return null;
        }

        const selectedStatuses = $statuses.val();
        const statuses =
            Array.isArray(selectedStatuses) && selectedStatuses.length ? selectedStatuses : defaultStatuses;
        const serviceId = $service.val() || null;

        return {
            startDate: range.start,
            endDate: range.end,
            statuses,
            serviceId,
        };
    }

    function getSelectedRange() {
        if (!datePicker || !Array.isArray(datePicker.selectedDates) || datePicker.selectedDates.length < 2) {
            return null;
        }

        const sorted = [...datePicker.selectedDates].sort((a, b) => a - b);
        const start = moment(sorted[0]).format('YYYY-MM-DD');
        const end = moment(sorted[sorted.length - 1]).format('YYYY-MM-DD');

        return {
            start,
            end,
        };
    }

    function renderChart() {
        const canvas = document.getElementById('utilization-chart');

        if (!canvas) {
            return;
        }

        const context = canvas.getContext('2d');

        if (!context) {
            return;
        }

        const visibleMetrics = getVisibleMetrics();
        const labels = visibleMetrics.map((item) => item.provider_name);
        const booked = visibleMetrics.map((item) => item.booked);
        const remaining = visibleMetrics.map((item) => (item.target > 0 ? item.open : 0));

        if (chart) {
            chart.destroy();
        }

        chart = new Chart(context, {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    {
                        label: lang('dashboard_chart_booked'),
                        data: booked,
                        backgroundColor: '#0d6efd',
                        stack: 'target',
                        borderWidth: 0,
                    },
                    {
                        label: lang('dashboard_chart_open'),
                        data: remaining,
                        backgroundColor: '#6c757d',
                        stack: 'target',
                        borderWidth: 0,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                scales: {
                    x: {
                        stacked: true,
                        ticks: {
                            precision: 0,
                        },
                    },
                    y: {
                        stacked: true,
                    },
                },
                plugins: {
                    legend: {
                        position: 'bottom',
                    },
                    tooltip: {
                        callbacks: {
                            label(context) {
                                const metric = visibleMetrics[context.dataIndex] || {};
                                const target = metric.target ?? 0;
                                const bookedValue = metric.booked ?? 0;
                                const remainingValue = metric.target > 0 ? metric.open : 0;

                                if (context.datasetIndex === 0) {
                                    const percent = target > 0 ? ((bookedValue / target) * 100).toFixed(1) : '0.0';
                                    const targetLabel = target > 0 ? target : '—';

                                    return `${context.dataset.label}: ${bookedValue}/${targetLabel} (${percent}%)`;
                                }

                                return `${context.dataset.label}: ${remainingValue}`;
                            },
                        },
                    },
                },
            },
        });
    }

    function renderTable() {
        const visibleMetrics = getVisibleMetrics();

        $tableBody.find('tr').not($emptyRow).remove();

        if (!visibleMetrics.length) {
            $emptyRow.show();
            return;
        }

        $emptyRow.hide();

        visibleMetrics.forEach((item) => {
            const fillPercentage = item.target > 0 ? (item.fill_rate * 100).toFixed(1) : '0.0';
            const hasConflict = item.has_explicit_target && item.target > 0 && item.fill_rate < threshold;
            const remaining = item.target > 0 ? item.open : '—';
            const explicitTarget =
                typeof item.class_size_default === 'number' && item.class_size_default > 0
                    ? item.class_size_default
                    : null;
            const $row = $('<tr/>', {
                'data-has-plan': item.has_plan ? 'true' : 'false',
                'data-has-explicit-target': item.has_explicit_target ? 'true' : 'false',
                'data-provider-id': item.provider_id ?? '',
            });

            $('<td/>', {text: item.provider_name}).appendTo($row);

            const $targetCell = $('<td/>');

            if (explicitTarget !== null) {
                $('<span/>', {text: explicitTarget}).appendTo($targetCell);
            } else if (item.target > 0) {
                $('<span/>', {text: item.target}).appendTo($targetCell);
            } else {
                $('<span/>', {
                    class: 'text-muted',
                    text: lang('dashboard_no_target'),
                }).appendTo($targetCell);
            }

            if (item.is_target_fallback) {
                $('<span/>', {
                    class: 'badge bg-secondary ms-2',
                    text: lang('dashboard_target_fallback_badge'),
                    title: lang('dashboard_target_fallback_hint'),
                }).appendTo($targetCell);
            }

            $targetCell.appendTo($row);

            $('<td/>', {text: item.booked}).appendTo($row);
            $('<td/>', {text: remaining}).appendTo($row);

            const $fillCell = $('<td/>');

            if (item.target > 0) {
                $('<span/>', {text: `${fillPercentage}%`}).appendTo($fillCell);
            } else {
                $('<span/>', {class: 'text-muted', text: '—'}).appendTo($fillCell);
            }

            $fillCell.appendTo($row);

            const $statusCell = $('<td/>');

            if (!item.has_plan) {
                $('<span/>', {
                    class: 'text-muted',
                    text: lang('no_plan_in_period'),
                }).appendTo($statusCell);
            } else if (!item.has_explicit_target) {
                $('<span/>', {
                    class: 'badge bg-secondary utilization-badge',
                    text: lang('dashboard_no_target'),
                }).appendTo($statusCell);
            } else if (hasConflict) {
                $('<span/>', {
                    class: 'badge bg-danger utilization-badge',
                    text: lang('expectation_conflict'),
                }).appendTo($statusCell);
            }

            $statusCell.appendTo($row);
            $row.appendTo($tableBody);
        });
    }

    function getVisibleMetrics() {
        const hideWithoutTarget = $hideWithoutTarget.prop('checked');
        const filtered = hideWithoutTarget ? metrics.filter((item) => item.has_explicit_target) : metrics.slice();

        return filtered;
    }

    function getDownloadProviderIds() {
        if (!Array.isArray(metrics) || !metrics.length) {
            return [];
        }

        const ids = metrics
            .map((item) => item?.provider_id)
            .filter((value) => Number.isInteger(value) || (typeof value === 'number' && !Number.isNaN(value)));

        return Array.from(new Set(ids));
    }

    function updateHiddenCounter() {
        if (!$hiddenCounter.length) {
            return;
        }

        const hideWithoutTarget = $hideWithoutTarget.prop('checked');
        const withoutTargetCount = metrics.filter((item) => !item.has_explicit_target).length;
        const hiddenCount = hideWithoutTarget ? withoutTargetCount : 0;
        const pattern = $hiddenCounter.data('pattern') || '(%d hidden)';

        $hiddenCounter.text(pattern.replace('%d', hiddenCount));
    }

    function updateThresholdDisplay() {
        if (!$thresholdDisplay.length) {
            return;
        }

        const percent = Math.round(threshold * 100);
        $thresholdDisplay.text(`${percent}%`);
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
