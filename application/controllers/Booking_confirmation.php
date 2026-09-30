<?php defined('BASEPATH') or exit('No direct script access allowed');

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
 * Booking confirmation controller.
 *
 * Handles the booking confirmation related operations.
 *
 * @package Controllers
 */
class Booking_confirmation extends EA_Controller
{
    /**
     * Booking_confirmation constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->model('appointments_model');
        $this->load->model('providers_model');
        $this->load->model('services_model');
        $this->load->model('customers_model');
    }

    /**
     * Display the appointment registration success page.
     *
     * @throws Exception
     */
    public function of(): void
    {
        $appointment_hash = $this->uri->segment(3);

        if (!is_string($appointment_hash) || $appointment_hash === '') {
            redirect('appointments');

            return;
        }

        $occurrences = $this->appointments_model->get(['hash' => $appointment_hash]);

        if (count($occurrences) !== 1) {
            redirect('appointments'); // Missing or ambiguous public link.

            return;
        }

        $appointment = $occurrences[0];

        $this->load->helper('calendar');
        $this->load->helper('date');

        try {
            $provider = $this->providers_model->find((int) $appointment['id_users_provider']);
            $provider_timezone = new DateTimeZone((string) $provider['timezone']);
            $start_at = $this->parsePublicAppointmentDate($appointment['start_datetime'], $provider_timezone);
            $end_at = $this->parsePublicAppointmentDate($appointment['end_datetime'], $provider_timezone);
        } catch (Throwable $exception) {
            log_message(
                'error',
                'Booking confirmation failed to validate appointment dates: ' . $exception->getMessage(),
            );

            show_404();

            return;
        }

        // A public confirmation link is a capability to appointment data. Once the appointment
        // has ended, do not render the confirmation page or any of its embedded personal data.
        if ($end_at <= new DateTimeImmutable('now', $provider_timezone)) {
            redirect('appointments');

            return;
        }

        try {
            $service = $this->services_model->find((int) $appointment['id_services']);
            $customer = $this->customers_model->find((int) $appointment['id_users_customer']);
        } catch (InvalidArgumentException $exception) {
            log_message(
                'error',
                'Booking confirmation failed to resolve related entities: ' . $exception->getMessage(),
            );

            show_404();

            return;
        }

        $duration_minutes = (int) round(($end_at->getTimestamp() - $start_at->getTimestamp()) / 60);
        $display_duration_minutes = max($duration_minutes, 1);
        $language_code = config('language_code') ?: 'de';
        $locale = $this->resolveLocale($language_code);
        $appointment_datetime_start_label = $this->formatAppointmentStartLabel($start_at, $locale);
        $appointment_datetime_end_label = $this->formatAppointmentEndLabel($end_at, $locale);
        $appointment_datetime_full_label = $this->formatAppointmentFullLabel($start_at, $end_at, $locale);

        $booking_number = $this->buildBookingNumber($appointment, $start_at);

        $appointment_summary = [
            'title' => $service['name'] ?? '',
            'subtitle' => trim($provider['first_name'] . ' ' . $provider['last_name']),
            'room' => $provider['room'] ?? '',
            'datetime' => $appointment_datetime_start_label,
            'datetime_end' => $appointment_datetime_end_label,
            'datetime_full' => $appointment_datetime_full_label,
            'duration' =>
                $display_duration_minutes > 0 ? sprintf('%d %s', $display_duration_minutes, lang('minutes')) : '',
            'timezone' => '',
            'price' =>
                $service['price'] > 0 ? number_format((float) $service['price'], 2) . ' ' . $service['currency'] : '',
        ];

        $location_label = $this->build_location_label($appointment, $provider, $service);

        $calendar_end_at = $end_at;

        $manage_url = public_site_url('booking/reschedule/' . $appointment['hash']);

        // External calendar URLs are sent to third parties; keep the management capability and contact data local.
        $event = [
            'title' => $service['name'],
            'location' => $location_label,
            'start' => $start_at,
            'end' => $calendar_end_at,
            'timezone' => $provider['timezone'],
        ];

        $calendar_links = [
            'google' => build_google_calendar_link($event),
            'outlook' => build_outlook_calendar_link($event),
            'ics' => build_ics_download_url($appointment['hash']),
        ];

        $share_payload = $this->buildSharePayload(
            $appointment_summary,
            $manage_url,
            $location_label,
            $service,
            $provider,
            $duration_minutes,
            $start_at,
            $end_at,
            $locale,
        );

        $appointment_pdf_data = [
            'schoolName' => setting('company_name') ?: 'Forscherhaus',
            'title' => $service['name'] ?? '',
            'teacher' => trim($provider['first_name'] . ' ' . $provider['last_name']),
            'room' => $provider['room'] ?? '',
            'startISO' => $start_at->format(DateTimeInterface::ATOM),
            'endISO' => $end_at->format(DateTimeInterface::ATOM),
            'durationMin' => $duration_minutes,
            'manageUrl' => $manage_url,
            'locale' => $locale,
            'timezone' => $provider['timezone'],
            'appointmentId' => (string) ($appointment['id'] ?? ''),
            'bookingNumber' => $booking_number,
        ];

        $book_advance_timeout = (int) setting('book_advance_timeout');
        $manage_limit =
            $book_advance_timeout > 0
                ? (new DateTimeImmutable('now', $provider_timezone))->add(
                    new DateInterval('PT' . $book_advance_timeout . 'M'),
                )
                : null;

        $is_manageable = !isset($manage_limit) || $start_at >= $manage_limit;

        html_vars([
            'page_title' => lang('success'),
            'company_color' => setting('company_color'),
            'render_analytics' => false,
            'google_analytics_code' => setting('google_analytics_code'),
            'matomo_analytics_url' => setting('matomo_analytics_url'),
            'matomo_analytics_site_id' => setting('matomo_analytics_site_id'),
            'appointment_summary' => $appointment_summary,
            'calendar_links' => $calendar_links,
            'is_past_event' => false,
            'manage_url' => $manage_url,
            'is_manageable' => $is_manageable,
            'appointment_pdf_data' => $appointment_pdf_data,
            'share_payload' => $share_payload,
        ]);

        $this->output->set_header('Cache-Control: no-store');
        $this->load->view('pages/booking_confirmation');
    }

    protected function resolveLocale(string $language_code): string
    {
        if ($language_code === 'de') {
            return 'de-DE';
        }

        if ($language_code === 'en') {
            return 'en-US';
        }

        return $language_code;
    }

    private function parsePublicAppointmentDate(mixed $value, DateTimeZone $timezone): DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$/D', $value)) {
            throw new InvalidArgumentException('Appointment datetime is not canonical.');
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $parsed === false ||
            ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) ||
            $parsed->format('Y-m-d H:i:s') !== $value
        ) {
            throw new InvalidArgumentException('Appointment datetime is invalid.');
        }

        // A stored wall time has no UTC offset. During fall-back it can name two
        // instants, so choosing either one could extend a public link's lifetime.
        $offsets = [$parsed->getOffset()];
        $transitions = $timezone->getTransitions($parsed->getTimestamp() - 604800, $parsed->getTimestamp() + 604800);
        if (is_array($transitions)) {
            foreach ($transitions as $transition) {
                $offsets[] = (int) $transition['offset'];
            }
        }

        $wall_as_utc = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        $matching_instants = 0;
        foreach (array_unique($offsets) as $offset) {
            $instant = $wall_as_utc->getTimestamp() - $offset;
            if ((new DateTimeImmutable('@' . $instant))->setTimezone($timezone)->format('Y-m-d H:i:s') === $value) {
                $matching_instants++;
            }
        }
        if ($matching_instants !== 1) {
            throw new InvalidArgumentException('Appointment datetime is ambiguous.');
        }

        return $parsed;
    }

    protected function formatAppointmentStartLabel(DateTimeInterface $start_at, string $locale): string
    {
        if (class_exists('\IntlDateFormatter')) {
            $timezone = $start_at->getTimezone() ?: new DateTimeZone(date_default_timezone_get());
            $formatter = new \IntlDateFormatter(
                $locale,
                \IntlDateFormatter::NONE,
                \IntlDateFormatter::NONE,
                $timezone->getName(),
                \IntlDateFormatter::GREGORIAN,
                'EEE, d. MMM y, HH:mm',
            );

            $formatted = $formatter->format($start_at);

            if ($formatted !== false) {
                return $formatted;
            }
        }

        return sprintf('%s, %s', format_date($start_at), format_time($start_at));
    }

    protected function formatAppointmentEndLabel(DateTimeInterface $end_at, string $locale): string
    {
        if (class_exists('\IntlDateFormatter')) {
            $timezone = $end_at->getTimezone() ?: new DateTimeZone(date_default_timezone_get());
            $formatter = new \IntlDateFormatter(
                $locale,
                \IntlDateFormatter::NONE,
                \IntlDateFormatter::NONE,
                $timezone->getName(),
                \IntlDateFormatter::GREGORIAN,
                'HH:mm',
            );

            $formatted = $formatter->format($end_at);

            if ($formatted !== false) {
                return $formatted;
            }
        }

        return format_time($end_at);
    }

    protected function formatAppointmentFullLabel(
        DateTimeInterface $start_at,
        DateTimeInterface $end_at,
        string $locale,
    ): string {
        if (class_exists('\IntlDateFormatter')) {
            $timezone = $start_at->getTimezone() ?: new DateTimeZone(date_default_timezone_get());
            $startFormatter = new \IntlDateFormatter(
                $locale,
                \IntlDateFormatter::NONE,
                \IntlDateFormatter::NONE,
                $timezone->getName(),
                \IntlDateFormatter::GREGORIAN,
                'EEE, d. MMM y, HH:mm',
            );

            $endFormatter = new \IntlDateFormatter(
                $locale,
                \IntlDateFormatter::NONE,
                \IntlDateFormatter::NONE,
                $timezone->getName(),
                \IntlDateFormatter::GREGORIAN,
                'HH:mm',
            );

            $startFormatted = $startFormatter->format($start_at);
            $endFormatted = $endFormatter->format($end_at);

            if ($startFormatted !== false && $endFormatted !== false) {
                return $startFormatted . '–' . $endFormatted;
            }
        }

        return sprintf('%s, %s–%s', format_date($start_at), format_time($start_at), format_time($end_at));
    }

    protected function buildBookingNumber(array $appointment, DateTimeInterface $start_at): string
    {
        $prefix = 'FH';
        $date_fragment = $start_at->format('Ymd-His');

        if (!empty($appointment['id'])) {
            $identifier = str_pad((string) $appointment['id'], 6, '0', STR_PAD_LEFT);
        } elseif (!empty($appointment['hash'])) {
            $identifier = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $appointment['hash']), 0, 6));
        } else {
            $identifier = strtoupper(substr(hash('crc32b', json_encode($appointment)), 0, 6));
        }

        return sprintf('%s-%s-%s', $prefix, $date_fragment, $identifier);
    }

    protected function buildSharePayload(
        array $appointment_summary,
        string $manage_url,
        string $location_label,
        array $service,
        array $provider,
        int $duration_minutes,
        DateTimeInterface $start_at,
        DateTimeInterface $end_at,
        string $locale,
    ): array {
        $title_parts = [];

        if (!empty($service['name'])) {
            $title_parts[] = $service['name'];
        }

        $provider_name = trim(($provider['first_name'] ?? '') . ' ' . ($provider['last_name'] ?? ''));

        if ($provider_name !== '') {
            $title_parts[] = $provider_name;
        }

        $share_title = implode(' – ', array_filter($title_parts));

        if ($share_title === '') {
            $fallback_title = lang('share_link_title');

            if (!is_string($fallback_title) || $fallback_title === '') {
                $fallback_title = 'Appointment details';
            }

            $share_title = $fallback_title;
        }

        $share_lines = [];

        $minutes_label = lang('minutes');

        if (!is_string($minutes_label) || trim($minutes_label) === '') {
            $minutes_label = 'minutes';
        } else {
            $minutes_label = rtrim($minutes_label, " \t\n\r\0\x0B.:");
        }

        $datetime_line = $this->formatAppointmentFullLabel($start_at, $end_at, $locale);

        if ($datetime_line === '') {
            $datetime_line = $appointment_summary['datetime_full'] ?? '';

            if ($datetime_line === '' && !empty($appointment_summary['datetime'])) {
                $datetime_line = $appointment_summary['datetime'];

                if (!empty($appointment_summary['datetime_end'])) {
                    $datetime_line .= '–' . $appointment_summary['datetime_end'];
                }
            }
        }

        if ($datetime_line !== '') {
            if ($duration_minutes > 0) {
                $datetime_line .= sprintf(' (%d %s)', $duration_minutes, $minutes_label);
            }

            $share_lines[] = $datetime_line;
        }

        if ($location_label !== '') {
            $share_lines[] = $location_label;
        }

        $share_link_hint = lang('share_link_text');

        if (is_string($share_link_hint) && $share_link_hint !== '') {
            if (strpos($share_link_hint, '%s') !== false) {
                $share_lines[] = sprintf($share_link_hint, $manage_url);
            } else {
                $share_lines[] = trim($share_link_hint . ' ' . $manage_url);
            }
        } else {
            $link_label = lang('manage_link_label');

            if (!is_string($link_label) || $link_label === '') {
                $link_label = 'Booking link';
            }

            $share_lines[] = $link_label . ': ' . $manage_url;
        }

        $share_lines = array_values(
            array_filter($share_lines, static fn($line) => is_string($line) && trim($line) !== ''),
        );

        return [
            'title' => $share_title,
            'text' => implode("\n", $share_lines),
            'url' => $manage_url,
        ];
    }

    protected function build_location_label(array $appointment, array $provider, array $service): string
    {
        $location_parts = [];

        if (!empty($appointment['location'])) {
            $location_parts[] = $appointment['location'];
        } elseif (!empty($service['location'])) {
            $location_parts[] = $service['location'];
        } else {
            $location_parts[] = setting('company_name');
        }

        if (!empty($provider['room'])) {
            $room_label = lang('room');

            if ($room_label === false || $room_label === null || $room_label === '') {
                $room_label = 'Room';
            }

            $location_parts[] = $room_label . ' ' . $provider['room'];
        }

        return implode('; ', array_filter($location_parts));
    }
}
