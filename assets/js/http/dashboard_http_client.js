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
    /**
     * Fetch utilization metrics for the provided filters.
     *
     * @param {String} startDate
     * @param {String} endDate
     * @param {Array<String>} statuses
     *
     * @return {jqXHR}
     */
    function fetch(startDate, endDate, statuses) {
        const url = App.Utils.Url.siteUrl('dashboard/metrics');

        return $.post(url, {
            csrf_token: vars('csrf_token'),
            start_date: startDate,
            end_date: endDate,
            statuses: statuses,
        });
    }

    return {
        fetch,
    };
})();
