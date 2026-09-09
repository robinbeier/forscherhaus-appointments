<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.0.0
 * ---------------------------------------------------------------------------- */

/**
 * Appointments controller.
 *
 * Serves calendar-file downloads and redirects legacy booking links.
 *
 * Notice: This file used to have the booking page related code which since v1.5 has now moved to the Booking.php
 * controller for improved consistency.
 *
 * @package Controllers
 */
class Appointments extends EA_Controller
{
    /**
     * Appointments constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->model('appointments_model');
    }

    /**
     * Support backwards compatibility for appointment links that still point to this URL.
     *
     * @param string $appointment_hash
     *
     * @deprecated Since 1.5
     */
    public function index(string $appointment_hash = ''): void
    {
        redirect('booking/' . $appointment_hash);
    }

    /**
     * Download an appointment as an ICS file.
     */
    public function ics(string $appointment_hash): void
    {
        $occurrences = $this->appointments_model->get(['hash' => $appointment_hash]);

        if (!$occurrences) {
            show_404();

            return;
        }

        $appointment = $occurrences[0];

        $this->load->model('services_model');
        $this->load->model('providers_model');
        $this->load->model('customers_model');

        try {
            $service = $this->services_model->find((int) $appointment['id_services']);
            $provider = $this->providers_model->find((int) $appointment['id_users_provider']);
            $customer = $this->customers_model->find((int) $appointment['id_users_customer']);
        } catch (InvalidArgumentException $exception) {
            log_message('error', 'ICS download failed to resolve related entities: ' . $exception->getMessage());

            show_404();

            return;
        }

        $this->load->library('ics_file');

        $provider_timezone = new DateTimeZone($provider['timezone']);
        $calendar_start = new DateTimeImmutable($appointment['start_datetime'], $provider_timezone);
        $calendar_end = new DateTimeImmutable($appointment['end_datetime'], $provider_timezone);

        $calendar_appointment = $appointment;
        $calendar_appointment['start_datetime'] = $calendar_start->format('Y-m-d H:i:s');
        $calendar_appointment['end_datetime'] = $calendar_end->format('Y-m-d H:i:s');

        try {
            $ics_stream = $this->ics_file->get_stream($calendar_appointment, $service, $provider, $customer);
        } catch (Throwable $exception) {
            log_message('error', 'ICS download failed to generate stream: ' . $exception->getMessage());

            show_error('Unable to generate calendar file.', 500);

            return;
        }

        $filename = sprintf('appointment-%s.ics', $appointment['hash'] ?? $appointment['id']);

        $this->output
            ->set_content_type('text/calendar', 'utf-8')
            ->set_header('Content-Disposition: attachment; filename="' . $filename . '"')
            ->set_header('Cache-Control: no-store')
            ->set_output($ics_stream);
    }
}
