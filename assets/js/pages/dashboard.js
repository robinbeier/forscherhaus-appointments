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

App.Components = App.Components || {};

App.Components.DashboardHeatmap = (function () {
    const $card = $('#heatmap-utilization');
    const $loading = $card.find('.heatmap-loading');
    const $content = $('#dashboard-heatmap-content');
    const $error = $('#dashboard-heatmap-error');
    const $contextBadge = $('#dashboard-heatmap-context');
    const $canvasContainer = $card.find('.heatmap-canvas');
    const $canvas = $('#dashboard-heatmap-canvas');
    const $legend = $card.find('.heatmap-legend');
    const $legendMax = $('#dashboard-heatmap-legend-max');
    const $empty = $('#dashboard-heatmap-empty');
    const $accessibility = $('#dashboard-heatmap-accessibility');

    const weekdays = [1, 2, 3, 4, 5];
    const weekdayLabels = {
        1: lang('monday_short'),
        2: lang('tuesday_short'),
        3: lang('wednesday_short'),
        4: lang('thursday_short'),
        5: lang('friday_short'),
    };

    const locale = vars('language_code') || 'de-DE';
    const countFormatter = new Intl.NumberFormat(locale, {maximumFractionDigits: 0});
    const percentFormatter = new Intl.NumberFormat(locale, {minimumFractionDigits: 1, maximumFractionDigits: 1});

    let active = false;
    let chart = null;
    let lastFilters = null;

    function activate(filters) {
        if (!$card.length) {
            return;
        }

        active = true;
        $card.removeAttr('hidden');

        if (filters) {
            lastFilters = filters;
            load(filters);
        } else {
            showError(lang('filter_period_required'));
        }
    }

    function onFiltersUpdated(filters) {
        lastFilters = filters;

        if (!active || !filters) {
            return;
        }

        load(filters);
    }

    function load(filters) {
        if (!$card.length || !filters) {
            return;
        }

        showLoading();

        App.Http.Dashboard.fetchHeatmap(filters)
            .done((response) => {
                if (!response || typeof response !== 'object') {
                    showError(lang('unexpected_issues'));
                    return;
                }

                render(response);
            })
            .fail((jqXHR, textStatus) => {
                if (textStatus === 'abort' || jqXHR?.statusText === 'abort') {
                    return;
                }

                const message = jqXHR?.responseJSON?.message ?? lang('unexpected_issues');
                showError(message);
            });
    }

    function showLoading() {
        $card.removeAttr('hidden');
        $loading.removeAttr('hidden');
        $error.attr('hidden', true);
        $content.attr('hidden', true);
    }

    function showError(message) {
        $card.removeAttr('hidden');
        $loading.attr('hidden', true);
        $content.attr('hidden', true);
        $error.text(message).removeAttr('hidden');
    }

    function destroyChart() {
        if (chart) {
            chart.destroy();
            chart = null;
        }
    }

    function render(data) {
        const slots = Array.isArray(data.slots) ? data.slots : [];
        const meta = data.meta || {};
        const total = Number(meta.total ?? 0);
        const times = getUniqueTimes(slots);

        $loading.attr('hidden', true);
        $error.attr('hidden', true);
        $content.removeAttr('hidden');

        updateContext(meta);

        if (!slots.length || total === 0) {
            destroyChart();
            showEmptyState();
            updateLegend(0);
            renderAccessibility(slots, times, meta);

            return;
        }

        hideEmptyState();

        const normalizationMax = resolveNormalizationMax(slots, meta);

        drawChart(slots, times, normalizationMax, meta);
        updateLegend(normalizationMax);
        renderAccessibility(slots, times, meta);
    }

    function showEmptyState() {
        $canvasContainer.attr('hidden', true);
        $legend.attr('hidden', true);
        $empty.removeAttr('hidden');
    }

    function hideEmptyState() {
        $canvasContainer.removeAttr('hidden');
        $legend.removeAttr('hidden');
        $empty.attr('hidden', true);
    }

    function updateContext(meta) {
        if (!$contextBadge.length) {
            return;
        }

        if (!meta || !meta.rangeLabel) {
            $contextBadge.attr('hidden', true);

            return;
        }

        const interval = Number(meta.intervalMinutes ?? 0);
        const formattedInterval = interval > 0 ? countFormatter.format(interval) : '0';
        const label = lang('dashboard_heatmap_context_pattern')
            .replace('%range%', meta.rangeLabel)
            .replace('%minutes%', formattedInterval);

        $contextBadge.text(label).removeAttr('hidden');
    }

    function resolveNormalizationMax(slots, meta) {
        const counts = slots.map((slot) => Number(slot.count ?? slot.v ?? 0));
        const maxCount = counts.length ? Math.max(...counts) : 0;
        const percentile = Number(meta.percentile95 ?? 0);

        if (percentile > 0) {
            return percentile;
        }

        return maxCount;
    }

    function drawChart(slots, times, normalizationMax, meta) {
        const context = $canvas[0]?.getContext('2d');

        if (!context) {
            return;
        }

        destroyChart();

        const safeMax = normalizationMax > 0 ? normalizationMax : 1;
        const interval = Number(meta.intervalMinutes ?? 0) || 30;

        chart = new Chart(context, {
            type: 'matrix',
            data: {
                datasets: [
                    {
                        data: slots.map((slot) => ({
                            x: slot.time,
                            y: String(slot.weekday ?? slot.y ?? ''),
                            v: Number(slot.count ?? slot.v ?? 0),
                            percent: Number(slot.percent ?? 0),
                        })),
                        backgroundColor(ctx) {
                            const value = Number(ctx.raw?.v ?? 0);

                            return colorFor(value, safeMax);
                        },
                        borderColor: '#ffffff',
                        borderWidth: 1,
                        hoverBorderColor: '#0d6efd',
                        borderRadius: 4,
                        width(renderContext) {
                            const area = renderContext.chart.chartArea;

                            if (!area || !times.length) {
                                return 0;
                            }

                            return Math.max(area.width / times.length - 4, 0);
                        },
                        height(renderContext) {
                            const area = renderContext.chart.chartArea;

                            if (!area || !weekdays.length) {
                                return 0;
                            }

                            return Math.max(area.height / weekdays.length - 4, 0);
                        },
                    },
                ],
            },
            options: {
                maintainAspectRatio: false,
                responsive: true,
                animation: false,
                scales: {
                    x: {
                        type: 'category',
                        labels: times,
                        offset: true,
                        grid: {display: false},
                        ticks: {
                            maxRotation: 0,
                            autoSkip: false,
                        },
                    },
                    y: {
                        type: 'category',
                        labels: weekdays.map((day) => String(day)),
                        offset: true,
                        reverse: true,
                        grid: {display: false},
                        ticks: {
                            callback(value) {
                                const label = this.getLabelForValue(value);
                                const weekday = Number(label);

                                return weekdayLabels[weekday] ?? label;
                            },
                        },
                    },
                },
                plugins: {
                    legend: {display: false},
                    tooltip: {
                        callbacks: {
                            title(items) {
                                const item = items?.[0];

                                if (!item) {
                                    return '';
                                }

                                const raw = item.raw || {};
                                const weekday = Number(raw.y ?? raw.weekday);
                                const start = raw.x;
                                const end = computeEndTime(start, interval);
                                const prefix = weekdayLabels[weekday] ?? weekday;

                                if (!start || !end) {
                                    return prefix || '';
                                }

                                return `${prefix} ${start}–${end}`;
                            },
                            label(context) {
                                const value = Number(context.raw?.v ?? 0);
                                const formatted = countFormatter.format(value);

                                return `${lang('dashboard_heatmap_tooltip_bookings')}: ${formatted}`;
                            },
                            afterBody(items) {
                                const item = items?.[0];

                                if (!item) {
                                    return '';
                                }

                                const percentValue = Number(item.raw?.percent ?? 0);
                                const formatted = percentFormatter.format(percentValue);

                                return `${lang('dashboard_heatmap_tooltip_share')}: ${formatted} %`;
                            },
                        },
                    },
                },
                onHover(event, elements) {
                    if (!event?.native) {
                        return;
                    }

                    event.native.target.style.cursor = elements?.length ? 'pointer' : 'default';
                },
            },
        });
    }

    function getUniqueTimes(slots) {
        const times = [];
        const seen = new Set();

        slots.forEach((slot) => {
            const time = slot.time;

            if (!time || seen.has(time)) {
                return;
            }

            seen.add(time);
            times.push(time);
        });

        return times.sort((left, right) => timeToMinutes(left) - timeToMinutes(right));
    }

    function timeToMinutes(value) {
        const parts = String(value).split(':');

        if (parts.length < 2) {
            return 0;
        }

        const hours = Number(parts[0]);
        const minutes = Number(parts[1]);

        return hours * 60 + minutes;
    }

    function colorFor(value, max) {
        if (max <= 0) {
            return 'rgba(225, 241, 255, 1)';
        }

        const ratio = Math.min(Math.max(value, 0), max) / max;
        const start = [225, 241, 255];
        const end = [13, 110, 253];
        const red = Math.round(start[0] + (end[0] - start[0]) * ratio);
        const green = Math.round(start[1] + (end[1] - start[1]) * ratio);
        const blue = Math.round(start[2] + (end[2] - start[2]) * ratio);

        return `rgb(${red}, ${green}, ${blue})`;
    }

    function computeEndTime(time, interval) {
        if (!time) {
            return '';
        }

        const safeInterval = interval > 0 ? interval : 30;
        const parsed = moment(time, 'HH:mm');

        if (!parsed.isValid()) {
            return '';
        }

        return parsed.clone().add(safeInterval, 'minutes').format('HH:mm');
    }

    function updateLegend(value) {
        if (!$legendMax.length) {
            return;
        }

        const normalized = value > 0 ? Math.ceil(value) : 0;
        $legendMax.text(countFormatter.format(normalized));
    }

    function renderAccessibility(slots, times, meta) {
        if (!$accessibility.length) {
            return;
        }

        if (!times.length) {
            $accessibility.empty();

            return;
        }

        const interval = Number(meta.intervalMinutes ?? 0) || 30;
        const grouped = {};

        slots.forEach((slot) => {
            const weekday = Number(slot.weekday ?? slot.y);
            const time = slot.time;

            if (!grouped[weekday]) {
                grouped[weekday] = {};
            }

            grouped[weekday][time] = slot;
        });

        let html = '<table><thead><tr>';
        html += `<th scope="col">${lang('dashboard_heatmap_accessibility_weekday')}</th>`;
        times.forEach((time) => {
            html += `<th scope="col">${time}</th>`;
        });
        html += '</tr></thead><tbody>';

        weekdays.forEach((weekday) => {
            html += `<tr><th scope="row">${weekdayLabels[weekday] ?? weekday}</th>`;
            times.forEach((time) => {
                const slot = grouped[weekday]?.[time] ?? {count: 0, percent: 0};
                const ariaLabel = escapeAttr(buildAriaLabel(weekday, time, interval, slot));
                const displayCount = countFormatter.format(Number(slot.count ?? slot.v ?? 0));

                html += `<td tabindex="0" aria-label="${ariaLabel}">${displayCount}</td>`;
            });
            html += '</tr>';
        });

        html += '</tbody></table>';

        $accessibility.html(html);
    }

    function buildAriaLabel(weekday, time, interval, slot) {
        const prefix = weekdayLabels[weekday] ?? weekday;
        const end = computeEndTime(time, interval) || time;
        const bookings = countFormatter.format(Number(slot.count ?? slot.v ?? 0));
        const percentage = percentFormatter.format(Number(slot.percent ?? 0));

        return `${prefix} ${time}–${end}, ${lang('dashboard_heatmap_tooltip_bookings')}: ${bookings}, ${lang('dashboard_heatmap_tooltip_share')}: ${percentage} %`;
    }

    function escapeAttr(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    return {
        activate,
        onFiltersUpdated,
    };
})();

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
    const $exportTeachersList = $('#dashboard-export-teachers-list');
    const $exportTeachersEmpty = $('#dashboard-export-teachers-empty');
    const $exportSelectAll = $('#dashboard-export-select-all');
    const $exportSelectNone = $('#dashboard-export-select-none');
    const $heatmapToggle = $('#dashboard-show-heatmap');
    const thresholdModalElement = document.getElementById('dashboard-threshold-modal');

    const appointmentStatusOptions = vars('appointment_status_options') || [];
    const defaultStatuses = vars('dashboard_default_statuses') || [];
    const serviceOptions = vars('dashboard_service_options') || [];
    const heatmap = App.Components.DashboardHeatmap;
    const initialThreshold = parseFloat(vars('dashboard_conflict_threshold'));

    let threshold = Number.isFinite(initialThreshold) ? initialThreshold : 0.9;
    let thresholdModal;
    let datePicker;
    let chart;
    let metrics = [];
    let selectedTeacherProviderIds = [];
    let lastFilters = null;

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
        $exportSelectAll.on('click', onExportSelectAll);
        $exportSelectNone.on('click', onExportSelectNone);
        $exportTeachersList.on('change', 'input[type="checkbox"][data-provider-id]', onTeacherExportSelectionChange);
        $heatmapToggle.on('click', onHeatmapToggle);

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

    function onHeatmapToggle(event) {
        event.preventDefault();

        if (!heatmap) {
            return;
        }

        const filters = lastFilters || getFilters();

        heatmap.activate(filters);

        const toggleElement = document.getElementById('dashboard-options-toggle');
        const dropdown = toggleElement ? bootstrap.Dropdown.getInstance(toggleElement) : null;

        if (dropdown && typeof dropdown.hide === 'function') {
            dropdown.hide();
        }
    }

    function onHideToggleChange() {
        updateHiddenCounter();
        renderChart();
        renderTable();
    }

    function onDownloadTeacher(event) {
        event.preventDefault();

        const filters = getFilters();

        if (!filters) {
            showError(lang('filter_period_required'));
            return;
        }

        const providerIds = getSelectedTeacherProviderIds();

        if (!providerIds.length) {
            showError(lang('dashboard_export_select_teacher_required'));
            return;
        }

        hideError();

        App.Http.Dashboard.downloadTeacherExport({
            startDate: filters.startDate,
            endDate: filters.endDate,
            statuses: filters.statuses,
            serviceId: filters.serviceId,
            threshold,
            providerIds,
        });
    }

    function onDownloadPrincipal(event) {
        event.preventDefault();

        const filters = getFilters();

        if (!filters) {
            showError(lang('filter_period_required'));
            return;
        }

        hideError();

        App.Http.Dashboard.downloadPrincipalExport({
            startDate: filters.startDate,
            endDate: filters.endDate,
            statuses: filters.statuses,
            serviceId: filters.serviceId,
            threshold,
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

        heatmap?.onFiltersUpdated(filters);

        App.Http.Dashboard.fetch(filters)
            .done((response) => {
                metrics = Array.isArray(response) ? response : [];
                refreshTeacherExportSelection();
                updateHiddenCounter();
                renderChart();
                renderTable();
            })
            .fail((jqXHR) => {
                const message = jqXHR?.responseJSON?.message ?? lang('unexpected_issues');
                metrics = [];
                refreshTeacherExportSelection();
                showError(message);
                updateHiddenCounter();
                renderChart();
                renderTable();
            });
    }

    function onExportSelectAll(event) {
        event.preventDefault();
        setAllTeacherExportSelections(true);
    }

    function onExportSelectNone(event) {
        event.preventDefault();
        setAllTeacherExportSelections(false);
    }

    function onTeacherExportSelectionChange() {
        selectedTeacherProviderIds = getCheckedTeacherExportProviderIds();
    }

    function refreshTeacherExportSelection() {
        const providers = getTeacherExportProviders();
        selectedTeacherProviderIds = providers.map((provider) => provider.id);
        renderTeacherExportProviderList(providers);
    }

    function getTeacherExportProviders() {
        if (!Array.isArray(metrics) || !metrics.length) {
            return [];
        }

        const map = new Map();

        metrics.forEach((item) => {
            const providerId = Number(item?.provider_id);

            if (!Number.isInteger(providerId) || providerId <= 0 || map.has(providerId)) {
                return;
            }

            map.set(providerId, {
                id: providerId,
                name: String(item?.provider_name || ''),
            });
        });

        return Array.from(map.values()).sort((left, right) => left.name.localeCompare(right.name, 'de'));
    }

    function renderTeacherExportProviderList(providers) {
        if (!$exportTeachersList.length) {
            return;
        }

        $exportTeachersList.empty();

        if (!providers.length) {
            $exportTeachersEmpty.prop('hidden', false);
            return;
        }

        $exportTeachersEmpty.prop('hidden', true);

        providers.forEach((provider) => {
            const checkboxId = `dashboard-export-teacher-${provider.id}`;
            const $item = $('<div/>', {class: 'dashboard-export-teachers-item'});
            const $checkbox = $('<input/>', {
                type: 'checkbox',
                class: 'form-check-input',
                id: checkboxId,
                'data-provider-id': provider.id,
                checked: true,
            });
            const labelText = provider.name || `#${provider.id}`;
            const $label = $('<label/>', {
                for: checkboxId,
                text: labelText,
            });

            $item.append($checkbox, $label).appendTo($exportTeachersList);
        });
    }

    function setAllTeacherExportSelections(isSelected) {
        if (!$exportTeachersList.length) {
            return;
        }

        $exportTeachersList.find('input[type="checkbox"][data-provider-id]').prop('checked', isSelected);
        selectedTeacherProviderIds = isSelected ? getTeacherExportProviders().map((provider) => provider.id) : [];
    }

    function getCheckedTeacherExportProviderIds() {
        if (!$exportTeachersList.length) {
            return [];
        }

        const ids = [];

        $exportTeachersList.find('input[type="checkbox"][data-provider-id]:checked').each((index, element) => {
            const providerId = Number($(element).data('provider-id'));

            if (Number.isInteger(providerId) && providerId > 0) {
                ids.push(providerId);
            }
        });

        return Array.from(new Set(ids));
    }

    function getSelectedTeacherProviderIds() {
        return Array.from(new Set(selectedTeacherProviderIds)).filter(
            (providerId) => Number.isInteger(providerId) && providerId > 0,
        );
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

        const filters = {
            startDate: range.start,
            endDate: range.end,
            statuses,
            serviceId,
        };

        lastFilters = filters;

        return filters;
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
                'data-has-capacity-gap': item.has_capacity_gap ? 'true' : 'false',
                'data-provider-id': item.provider_id ?? '',
            });

            const $providerCell = $('<td/>', {class: 'dashboard-provider-cell'});

            $('<div/>', {
                class: 'dashboard-provider-name',
                text: item.provider_name,
            }).appendTo($providerCell);

            const $meta = $('<div/>', {class: 'dashboard-provider-meta'});

            $('<span/>', {
                class: 'dashboard-provider-slots',
                text: formatSlotsSummary(item),
            }).appendTo($meta);

            if (item.has_capacity_gap) {
                $('<span/>', {
                    class: 'badge bg-warning text-dark dashboard-capacity-gap-badge',
                    text: lang('dashboard_slots_gap_badge'),
                    title: lang('dashboard_slots_gap_hint'),
                }).appendTo($meta);
            }

            $meta.appendTo($providerCell);
            $providerCell.appendTo($row);

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

    function formatSlotsSummary(item) {
        const fallback = lang('dashboard_slots_summary_fallback') || 'Slots: —';
        const placeholder = '—';

        const planned = parseSlotValue(item?.slots_planned, {allowZero: true});
        const required = parseSlotValue(item?.slots_required);

        if (planned === null && required === null) {
            return fallback;
        }

        const plannedText = planned === null ? placeholder : planned;
        const requiredText = required === null ? placeholder : required;

        const pattern = lang('dashboard_slots_summary') || 'Slots: %planned% / %required%';

        return pattern.replace('%planned%', plannedText).replace('%required%', requiredText);
    }

    function parseSlotValue(value, options = {}) {
        const {allowZero = false} = options;

        if (value === null || value === undefined) {
            return null;
        }

        const numeric = Number(value);

        if (!Number.isFinite(numeric)) {
            return null;
        }

        if (numeric < 0) {
            return null;
        }

        if (numeric === 0 && !allowZero) {
            return null;
        }

        return Math.round(numeric);
    }

    function getVisibleMetrics() {
        const hideWithoutTarget = $hideWithoutTarget.prop('checked');
        const filtered = hideWithoutTarget ? metrics.filter((item) => item.has_explicit_target) : metrics.slice();

        return filtered;
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
