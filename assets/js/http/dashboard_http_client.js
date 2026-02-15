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
 * Dashboard HTTP client.
 *
 * This module implements the utilization dashboard related HTTP requests.
 */
App.Http.Dashboard = (function () {
    let heatmapRequest = null;

    /**
     * Fetch utilization metrics for the provided filters.
     *
     * @param {Object} filters
     * @param {String} filters.startDate
     * @param {String} filters.endDate
     * @param {Array<String>} [filters.statuses]
     * @param {Number|String|null} [filters.serviceId]
     * @param {Array<Number|String>} [filters.providerIds]
     *
     * @return {jqXHR}
     */
    function fetch(filters) {
        const url = App.Utils.Url.siteUrl('dashboard/metrics');
        const payload = {
            csrf_token: vars('csrf_token'),
            start_date: filters.startDate,
            end_date: filters.endDate,
        };

        if (Array.isArray(filters.statuses)) {
            payload.statuses = filters.statuses;
        }

        if (filters.serviceId) {
            payload.service_id = filters.serviceId;
        }

        if (Array.isArray(filters.providerIds) && filters.providerIds.length) {
            payload.provider_ids = filters.providerIds;
        }

        return $.post(url, payload);
    }

    /**
     * Fetch provider dashboard metrics for the provided period filters.
     *
     * @param {Object} filters
     * @param {String} filters.startDate
     * @param {String} filters.endDate
     *
     * @return {jqXHR}
     */
    function fetchProviderMetrics(filters) {
        const url = App.Utils.Url.siteUrl('dashboard/provider_metrics');
        const payload = {
            csrf_token: vars('csrf_token'),
            start_date: filters.startDate,
            end_date: filters.endDate,
        };

        return $.post(url, payload);
    }

    function fetchHeatmap(filters) {
        const url = App.Utils.Url.siteUrl('dashboard/heatmap');
        const payload = {
            csrf_token: vars('csrf_token'),
            start_date: filters.startDate,
            end_date: filters.endDate,
        };

        if (Array.isArray(filters.statuses)) {
            payload.statuses = filters.statuses;
        }

        if (filters.serviceId) {
            payload.service_id = filters.serviceId;
        }

        if (Array.isArray(filters.providerIds) && filters.providerIds.length) {
            payload.provider_ids = filters.providerIds;
        }

        if (heatmapRequest && typeof heatmapRequest.abort === 'function') {
            heatmapRequest.abort();
        }

        heatmapRequest = $.post(url, payload);

        heatmapRequest.always(() => {
            heatmapRequest = null;
        });

        return heatmapRequest;
    }

    /**
     * Persist the dashboard threshold.
     *
     * @param {Number} threshold
     *
     * @return {jqXHR}
     */
    function saveThreshold(threshold) {
        const url = App.Utils.Url.siteUrl('dashboard/threshold');

        const payload = {
            csrf_token: vars('csrf_token'),
            threshold,
        };

        return $.post(url, payload);
    }

    function downloadTeacherExport(filters = {}) {
        return triggerDownload('dashboard/export/teacher.zip', filters);
    }

    function downloadPrincipalExport(filters = {}) {
        return triggerDownload('dashboard/export/principal.pdf', filters);
    }

    function triggerDownload(path, filters) {
        const params = new URLSearchParams();

        if (filters.startDate) {
            params.set('start_date', filters.startDate);
        }

        if (filters.endDate) {
            params.set('end_date', filters.endDate);
        }

        if (filters.serviceId) {
            params.set('service_id', filters.serviceId);
        }

        if (typeof filters.threshold === 'number' && Number.isFinite(filters.threshold)) {
            params.set('threshold', String(filters.threshold));
        }

        const statuses = filters.statuses;

        if (Array.isArray(statuses)) {
            statuses.forEach((status) => {
                if (status !== null && status !== undefined && status !== '') {
                    params.append('statuses[]', status);
                }
            });
        } else if (typeof statuses === 'string' && statuses !== '') {
            params.set('statuses', statuses);
        }

        const providerIds = filters.providerIds;

        if (Array.isArray(providerIds)) {
            providerIds.forEach((id) => {
                if (id !== null && id !== undefined && id !== '') {
                    params.append('provider_ids[]', id);
                }
            });
        }

        const query = params.toString();
        const url = App.Utils.Url.siteUrl(query ? `${path}?${query}` : path);

        window.open(url, '_blank', 'noopener');

        return Promise.resolve();
    }

    return {
        fetch,
        fetchProviderMetrics,
        fetchHeatmap,
        saveThreshold,
        downloadTeacherExport,
        downloadPrincipalExport,
    };
})();
