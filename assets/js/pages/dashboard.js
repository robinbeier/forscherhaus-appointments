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
    const $hideWithoutPlan = $('#dashboard-hide-without-plan');
    const $tableBody = $('#dashboard-table tbody');
    const $emptyRow = $('#dashboard-table-empty');
    const $error = $('#dashboard-error');
    const threshold = parseFloat(vars('dashboard_conflict_threshold')) || 0.75;

    let datePicker;
    let chart;
    let metrics = [];

    function initialize() {
        populateStatusOptions();
        App.Utils.UI.initializeDropdown($statuses, {
            placeholder: lang('statuses_filter_label'),
            width: '100%',
            allowClear: true,
        });

        App.Utils.UI.initializeDatePicker($dateRange, {mode: 'range'});
        datePicker = $dateRange[0]?._flatpickr;

        setDefaultRange();

        $filters.on('submit', onFiltersSubmit);
        $hideWithoutPlan.on('change', () => {
            renderChart();
            renderTable();
        });

        loadMetrics();
    }

    function populateStatusOptions() {
        const options = vars('appointment_status_options') || [];

        options.forEach((status) => {
            $('<option/>', {
                value: status,
                text: status,
            }).appendTo($statuses);
        });
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

    function loadMetrics() {
        const range = getSelectedRange();

        if (!range) {
            showError(lang('filter_period_required'));
            return;
        }

        hideError();

        const statuses = $statuses.val() || [];

        App.Http.Dashboard.fetch(range.start, range.end, statuses)
            .done((response) => {
                metrics = Array.isArray(response) ? response : [];
                renderChart();
                renderTable();
            })
            .fail((jqXHR) => {
                const message = jqXHR?.responseJSON?.message ?? lang('unexpected_issues');
                metrics = [];
                showError(message);
                renderChart();
                renderTable();
            });
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
        const open = visibleMetrics.map((item) => item.open);

        if (chart) {
            chart.destroy();
        }

        chart = new Chart(context, {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    {
                        label: lang('booked_slots'),
                        data: booked,
                        backgroundColor: '#0d6efd',
                        stack: 'total',
                        borderWidth: 0,
                    },
                    {
                        label: lang('open_slots'),
                        data: open,
                        backgroundColor: '#6c757d',
                        stack: 'total',
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
                                const datasetLabel = context.dataset.label ?? '';
                                const value = context.parsed.x;
                                const total = visibleMetrics[context.dataIndex]?.total ?? 0;
                                const percent = total > 0 ? ((value / total) * 100).toFixed(1) : '0.0';

                                return `${datasetLabel}: ${value} (${percent}%)`;
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
            const fillPercentage = item.total > 0 ? (item.fill_rate * 100).toFixed(1) : '0.0';
            const hasConflict = item.total > 0 && item.fill_rate < threshold;
            const $row = $('<tr/>', {
                'data-no-plan': item.has_plan ? 'false' : 'true',
            });

            $('<td/>', {text: item.provider_name}).appendTo($row);
            $('<td/>', {text: item.total}).appendTo($row);
            $('<td/>', {text: item.booked}).appendTo($row);
            $('<td/>', {text: item.open}).appendTo($row);
            $('<td/>', {text: `${fillPercentage}%`}).appendTo($row);

            const $statusCell = $('<td/>');

            if (!item.has_plan) {
                $('<span/>', {
                    class: 'text-muted',
                    text: lang('no_plan_in_period'),
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
        const hideWithoutPlan = $hideWithoutPlan.prop('checked');
        const filtered = hideWithoutPlan ? metrics.filter((item) => item.has_plan) : metrics.slice();

        return filtered;
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
