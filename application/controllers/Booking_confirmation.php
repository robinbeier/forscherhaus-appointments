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

        $occurrences = $this->appointments_model->get(['hash' => $appointment_hash]);

        if (empty($occurrences)) {
            redirect('appointments'); // The appointment does not exist.

            return;
        }

        $appointment = $occurrences[0];

        $this->load->helper('calendar');
        $this->load->helper('date');

        try {
            $service = $this->services_model->find((int) $appointment['id_services']);
            $provider = $this->providers_model->find((int) $appointment['id_users_provider']);
            $customer = $this->customers_model->find((int) $appointment['id_users_customer']);
        } catch (InvalidArgumentException $exception) {
            log_message('error', 'Booking confirmation failed to resolve related entities: ' . $exception->getMessage());

            show_404();

            return;
        }

        $provider_timezone = new DateTimeZone($provider['timezone']);

        $start_at = new DateTimeImmutable($appointment['start_datetime'], $provider_timezone);
        $end_at = new DateTimeImmutable($appointment['end_datetime'], $provider_timezone);

        $duration_minutes = (int) round(($end_at->getTimestamp() - $start_at->getTimestamp()) / 60);
        $display_duration_minutes = max($duration_minutes - 5, 1);

        $appointment_summary = [
            'title' => $service['name'] ?? '',
            'subtitle' => trim($provider['first_name'] . ' ' . $provider['last_name']),
            'room' => $provider['room'] ?? '',
            'datetime' => trim(format_date($start_at) . ' ' . format_time($start_at)),
            'duration' => $display_duration_minutes > 0 ? sprintf('%d %s', $display_duration_minutes, lang('minutes')) : '',
            'timezone' => '',
            'price' => $service['price'] > 0 ? number_format((float) $service['price'], 2) . ' ' . $service['currency'] : '',
        ];

        $location_label = $this->build_location_label($appointment, $provider, $service);

        $calendar_end_at = $end_at->sub(new DateInterval('PT5M'));

        if ($calendar_end_at <= $start_at) {
            $calendar_end_at = $start_at->add(new DateInterval('PT1M'));
        }

        $event = [
            'title' => $service['name'],
            'description' => site_url('booking/reschedule/' . $appointment['hash']),
            'location' => $location_label,
            'start' => $start_at,
            'end' => $calendar_end_at,
            'timezone' => $provider['timezone'],
            'attendees' => array_values(array_filter([
                $provider['email'] ?? null,
                $customer['email'] ?? null,
            ])),
        ];

        $calendar_links = [
            'google' => build_google_calendar_link($event),
            'outlook' => build_outlook_calendar_link($event),
            'ics' => build_ics_download_url($appointment['hash']),
        ];

        $is_past_event = $end_at <= new DateTimeImmutable('now', $provider_timezone);

        html_vars([
            'page_title' => lang('success'),
            'company_color' => setting('company_color'),
            'google_analytics_code' => setting('google_analytics_code'),
            'matomo_analytics_url' => setting('matomo_analytics_url'),
            'matomo_analytics_site_id' => setting('matomo_analytics_site_id'),
            'appointment_summary' => $appointment_summary,
            'calendar_links' => $calendar_links,
            'is_past_event' => $is_past_event,
        ]);

        $this->load->view('pages/booking_confirmation');
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
