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
 * Booking cancellation controller.
 *
 * Handles the booking cancellation related operations.
 *
 * @package Controllers
 */
class Booking_cancellation extends EA_Controller
{
    /**
     * Booking_cancellation constructor.
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
     * Cancel an existing appointment.
     *
     * This method removes an appointment from the company's schedule. In order for the appointment to be deleted, the
     * hash string must be provided. The customer can only cancel the appointment if the edit time period is not over
     * yet.
     *
     * @param string $appointment_hash This appointment hash identifier.
     */
    public function of(string $appointment_hash = ''): void
    {
        $transaction_open = false;

        try {
            $disable_booking = setting('disable_booking');

            if ($disable_booking) {
                abort(403);
            }

            if ($this->input->method() !== 'post') {
                abort(403, 'Forbidden');
            }

            if ($appointment_hash === '') {
                abort(404);

                return;
            }

            $occurrences = $this->appointments_model->get(['hash' => $appointment_hash]);

            if (empty($occurrences)) {
                html_vars([
                    'page_title' => lang('appointment_not_found'),
                    'company_color' => setting('company_color'),
                    'message_title' => lang('appointment_not_found'),
                    'message_text' => lang('appointment_does_not_exist_in_db'),
                    'message_icon' => base_url('assets/img/error.png'),
                    'google_analytics_code' => setting('google_analytics_code'),
                    'matomo_analytics_url' => setting('matomo_analytics_url'),
                    'matomo_analytics_site_id' => setting('matomo_analytics_site_id'),
                ]);

                $this->load->view('pages/booking_message');

                return;
            }

            if (!$this->db->trans_begin()) {
                throw new RuntimeException('Could not start cancellation transaction.');
            }
            $transaction_open = true;

            // A concurrent reschedule or hash change invalidates the earlier lookup.
            $appointment = $this->db
                ->query('SELECT * FROM `' . $this->db->dbprefix('appointments') . '` WHERE `id` = ? FOR UPDATE', [
                    (int) $occurrences[0]['id'],
                ])
                ->row_array();

            if (!$appointment) {
                $this->db->trans_rollback();
                $transaction_open = false;
                abort(404);

                return;
            }

            if (
                !hash_equals((string) $appointment['hash'], $appointment_hash) ||
                (bool) $appointment['is_unavailability']
            ) {
                $this->db->trans_rollback();
                $transaction_open = false;
                abort(403, 'Forbidden');

                return;
            }

            $advance_timeout = filter_var(setting('book_advance_timeout'), FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0],
            ]);
            $start = strtotime($appointment['start_datetime']);
            $limit = $advance_timeout === false ? false : strtotime('+' . $advance_timeout . ' minutes', time());
            if ($start === false || $limit === false) {
                throw new RuntimeException('Invalid cancellation deadline.');
            }

            // Preserve the existing booking-page cutoff: equality remains allowed.
            if ($start < $limit) {
                $this->db->trans_rollback();
                $transaction_open = false;
                abort(403, lang('appointment_locked'));

                return;
            }

            $provider = $this->providers_model->find($appointment['id_users_provider']);

            $customer = $this->customers_model->find($appointment['id_users_customer']);

            $service = $this->services_model->find($appointment['id_services']);

            $this->appointments_model->delete((int) $appointment['id']);
            if (!$this->db->trans_status() || !$this->db->trans_commit()) {
                throw new RuntimeException('Could not commit cancellation transaction.');
            }
            $transaction_open = false;
        } catch (Throwable $e) {
            if ($transaction_open) {
                $this->db->trans_rollback();
            }
            log_message('error', 'Booking Cancellation Exception: ' . $e->getMessage());
            abort(500, 'Unable to cancel appointment.');

            return;
        }

        html_vars([
            'page_title' => lang('appointment_cancelled_title'),
            'company_color' => setting('company_color'),
            'google_analytics_code' => setting('google_analytics_code'),
            'matomo_analytics_url' => setting('matomo_analytics_url'),
            'matomo_analytics_site_id' => setting('matomo_analytics_site_id'),
        ]);

        $this->load->view('pages/booking_cancellation');
    }
}
