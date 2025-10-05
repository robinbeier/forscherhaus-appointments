<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * Helper for building calendar links and URLs.
 * ---------------------------------------------------------------------------- */

if (!function_exists('build_google_calendar_link')) {
    /**
     * Build an Add-to-Google Calendar link.
     *
     * @param array $event Event data.
     *
     * @return string
     */
    function build_google_calendar_link(array $event): string
    {
        $start = calendar_helper_to_datetime($event['start'] ?? null);
        $end = calendar_helper_to_datetime($event['end'] ?? null);

        if (!$start || !$end) {
            return '';
        }

        $dates = sprintf('%s/%s',
            calendar_helper_format_utc($start, 'Ymd\THis\Z'),
            calendar_helper_format_utc($end, 'Ymd\THis\Z'));

        $params = array_filter([
            'action' => 'TEMPLATE',
            'text' => calendar_helper_sanitize_text($event['title'] ?? ''),
            'dates' => $dates,
            'details' => calendar_helper_sanitize_text($event['description'] ?? ''),
            'location' => calendar_helper_sanitize_text($event['location'] ?? ''),
            'ctz' => $event['timezone'] ?? '',
        ], fn ($value) => $value !== '' && $value !== null);

        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        foreach ($event['attendees'] ?? [] as $attendee) {
            if (!empty($attendee)) {
                $query .= '&add=' . rawurlencode($attendee);
            }
        }

        return 'https://calendar.google.com/calendar/render?' . $query;
    }
}

if (!function_exists('build_outlook_calendar_link')) {
    /**
     * Build an Outlook add-to-calendar deeplink.
     *
     * @param array $event Event data.
     *
     * @return string
     */
    function build_outlook_calendar_link(array $event): string
    {
        $start = calendar_helper_to_datetime($event['start'] ?? null);
        $end = calendar_helper_to_datetime($event['end'] ?? null);

        if (!$start || !$end) {
            return '';
        }

        $params = array_filter([
            'path' => '/calendar/action/compose',
            'rru' => 'addevent',
            'startdt' => calendar_helper_format_utc($start, DateTimeInterface::ATOM),
            'enddt' => calendar_helper_format_utc($end, DateTimeInterface::ATOM),
            'subject' => calendar_helper_sanitize_text($event['title'] ?? ''),
            'body' => calendar_helper_sanitize_text($event['description'] ?? ''),
            'location' => calendar_helper_sanitize_text($event['location'] ?? ''),
        ], fn ($value) => $value !== '' && $value !== null);

        return 'https://outlook.office.com/calendar/0/deeplink/compose?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}

if (!function_exists('build_ics_download_url')) {
    /**
     * Build the ICS download URL for a given appointment hash.
     */
    function build_ics_download_url(string $appointment_hash): string
    {
        return site_url('appointments/ics/' . rawurlencode($appointment_hash));
    }
}

if (!function_exists('calendar_helper_to_datetime')) {
    /**
     * Cast an input value to a DateTimeImmutable instance.
     */
    function calendar_helper_to_datetime(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (is_string($value) && $value !== '') {
            try {
                return new DateTimeImmutable($value);
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }
}

if (!function_exists('calendar_helper_format_utc')) {
    /**
     * Convert a date to UTC and format it.
     */
    function calendar_helper_format_utc(DateTimeImmutable $date_time, string $format): string
    {
        return $date_time->setTimezone(new DateTimeZone('UTC'))->format($format);
    }
}

if (!function_exists('calendar_helper_sanitize_text')) {
    /**
     * Remove HTML and normalize whitespace for calendar payloads.
     */
    function calendar_helper_sanitize_text(string $value): string
    {
        $value = strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }
}
