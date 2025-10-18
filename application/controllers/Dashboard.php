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

use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Dashboard controller.
 *
 * Handles the admin utilization dashboard.
 *
 * @package Controllers
 */
class Dashboard extends EA_Controller
{
    /**
     * Dashboard constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->model('providers_model');
        $this->load->library('provider_utilization');
        $this->load->library('accounts');
    }

    /**
     * Render the dashboard view.
     */
    public function index(): void
    {
        session(['dest_url' => site_url('dashboard')]);

        $user_id = session('user_id');

        if (session('role_slug') !== DB_SLUG_ADMIN) {
            if ($user_id) {
                abort(403, 'Forbidden');
            }

            redirect('login');

            return;
        }

        $appointment_status_options = json_decode(setting('appointment_status_options', '[]'), true) ?? [];
        $threshold = (float) setting('dashboard_conflict_threshold', '0.75');

        script_vars([
            'appointment_status_options' => $appointment_status_options,
            'dashboard_conflict_threshold' => $threshold,
            'date_format' => setting('date_format'),
            'time_format' => setting('time_format'),
            'first_weekday' => setting('first_weekday'),
        ]);

        html_vars([
            'page_title' => lang('dashboard'),
            'active_menu' => 'dashboard',
            'user_display_name' => $this->accounts->get_user_display_name($user_id),
        ]);

        $this->load->view('pages/dashboard');
    }

    /**
     * Provide utilization metrics for the selected filters.
     */
    public function metrics(): void
    {
        try {
            if (session('role_slug') !== DB_SLUG_ADMIN) {
                abort(403, 'Forbidden');
            }

            $start_date = request('start_date');
            $end_date = request('end_date');
            $statuses = request('statuses', []);

            if ($statuses !== null && !is_array($statuses)) {
                $statuses = [$statuses];
            }

            if (!$start_date || !$end_date) {
                throw new InvalidArgumentException(lang('filter_period_required'));
            }

            $start = DateTimeImmutable::createFromFormat('Y-m-d', $start_date);
            $end = DateTimeImmutable::createFromFormat('Y-m-d', $end_date);

            if (!$start || $start->format('Y-m-d') !== $start_date || !$end || $end->format('Y-m-d') !== $end_date) {
                throw new InvalidArgumentException(lang('filter_period_required'));
            }

            if ($start > $end) {
                throw new InvalidArgumentException(lang('filter_period_required'));
            }

            $providers = $this->providers_model->get_available_providers(false);

            $metrics = [];

            foreach ($providers as $provider) {
                $summary = $this->provider_utilization->calculate($provider, $start_date, $end_date, $statuses);

                $display_name = trim(($provider['first_name'] ?? '') . ' ' . ($provider['last_name'] ?? ''));

                if ($display_name === '') {
                    $display_name = $provider['email'] ?? (string) $provider['id'];
                }

                $metrics[] = [
                    'provider_id' => (int) $provider['id'],
                    'provider_name' => $display_name,
                    'total' => (int) $summary['total'],
                    'booked' => (int) $summary['booked'],
                    'open' => (int) $summary['open'],
                    'fill_rate' => (float) $summary['fill_rate'],
                    'has_plan' => (bool) $summary['has_plan'],
                ];
            }

            usort($metrics, static fn ($a, $b) => $a['fill_rate'] <=> $b['fill_rate']);

            json_response($metrics);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }
}
