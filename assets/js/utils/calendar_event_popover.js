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
 * Calendar event popover utility.
 *
 * This module implements the functionality of calendar event popovers.
 */
App.Utils.CalendarEventPopover = (function () {
    /**
     * Render a map icon that links to Google maps.
     *
     * Old Name: GeneralFunctions.renderMapIcon
     *
     * @param {Object} user Should have the address, city, etc properties.
     *
     * @return {String} The rendered HTML.
     */
    function renderMapIcon(user) {
        const data = [];

        if (user.address) {
            data.push(user.address);
        }

        if (user.city) {
            data.push(user.city);
        }

        if (user.state) {
            data.push(user.state);
        }

        if (user.zip_code) {
            data.push(user.zip_code);
        }

        if (!data.length) {
            return null;
        }

        return $('<div/>', {
            'html': [
                $('<a/>', {
                    'href': 'https://google.com/maps/place/' + data.join(','),
                    'target': '_blank',
                    'html': [
                        $('<span/>', {
                            'class': 'fas fa-map-marker-alt',
                        }),
                    ],
                }),
            ],
        }).html();
    }

    /**
     * Render a mail icon.
     *
     * Old Name: GeneralFunctions.renderMailIcon
     *
     * @param {String} email
     *
     * @return {String} The rendered HTML.
     */
    function renderMailIcon(email) {
        if (!email) {
            return null;
        }

        return $('<div/>', {
            'html': [
                $('<a/>', {
                    'href': 'mailto:' + email,
                    'target': '_blank',
                    'html': [
                        $('<span/>', {
                            'class': 'fas fa-envelope',
                        }),
                    ],
                }),
            ],
        }).html();
    }

    /**
     * Render a phone icon.
     *
     * Old Name: GeneralFunctions.renderPhoneIcon
     *
     * @param {String} phone
     *
     * @return {String} The rendered HTML.
     */
    function renderPhoneIcon(phone) {
        if (!phone) {
            return null;
        }

        return $('<div/>', {
            'html': [
                $('<a/>', {
                    'href': 'tel:' + phone,
                    'target': '_blank',
                    'html': [
                        $('<span/>', {
                            'class': 'fas fa-phone-alt',
                        }),
                    ],
                }),
            ],
        }).html();
    }

    /**
     * Render custom content into the popover of events.
     *
     * @param {Object} info The info object as passed from FullCalendar
     *
     * @return {Object|String|null} Return HTML string, a jQuery selector or null for nothing.
     */
    function renderCustomContent(info) {
        return null; // Default behavior
    }

    /**
     * Render a read-only blocked-period event. Blocked periods do not have a provider
     * and may omit notes when the current user is not authorized to view them.
     *
     * @param {Object} info The info object as passed from FullCalendar
     * @return {Object} The rendered jQuery content.
     */
    function renderBlockedPeriod(info) {
        const data = info.event.extendedProps.data || {};
        const start = data.start_datetime || info.event.start;
        const end = data.end_datetime || info.event.end || info.event.start;
        const rows = [
            $('<strong/>', {'class': 'd-inline-block me-2', text: lang('name')}),
            $('<span/>', {text: data.name || info.event.title || ''}),
            $('<br/>'),
            $('<strong/>', {'class': 'd-inline-block me-2', text: lang('start')}),
            $('<span/>', {
                text: App.Utils.Date.format(
                    moment(start).format('YYYY-MM-DD HH:mm:ss'),
                    vars('date_format'),
                    vars('time_format'),
                    true,
                ),
            }),
            $('<br/>'),
            $('<strong/>', {'class': 'd-inline-block me-2', text: lang('end')}),
            $('<span/>', {
                text: App.Utils.Date.format(
                    moment(end).format('YYYY-MM-DD HH:mm:ss'),
                    vars('date_format'),
                    vars('time_format'),
                    true,
                ),
            }),
            $('<br/>'),
        ];

        if (Object.prototype.hasOwnProperty.call(data, 'notes') && data.notes !== null) {
            rows.push(
                $('<strong/>', {'class': 'd-inline-block me-2', text: lang('notes')}),
                $('<span/>', {text: data.notes}),
                $('<br/>'),
            );
        }

        rows.push(
            $('<hr/>'),
            $('<div/>', {
                'class': 'd-flex justify-content-center',
                html: $('<button/>', {
                    'class': 'close-popover btn btn-outline-secondary',
                    html: [$('<i/>', {'class': 'fas fa-ban me-2'}), $('<span/>', {text: lang('close')})],
                }),
            }),
        );

        return $('<div/>', {html: rows});
    }

    return {
        renderPhoneIcon,
        renderMapIcon,
        renderMailIcon,
        renderCustomContent,
        renderBlockedPeriod,
    };
})();
