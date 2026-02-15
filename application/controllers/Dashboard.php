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
        $this->load->model('services_model');
        $this->load->library('dashboard_metrics');
        $this->load->library('dashboard_heatmap');
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
        $threshold = $this->getDashboardThreshold();
        $default_statuses = ['Booked'];
        $services = $this->services_model->get(null, null, null, 'name ASC');
        $service_options = array_map(static function (array $service): array {
            return [
                'id' => (int) $service['id'],
                'name' => $service['name'],
            ];
        }, $services);

        script_vars([
            'appointment_status_options' => $appointment_status_options,
            'dashboard_conflict_threshold' => $threshold,
            'dashboard_default_statuses' => $default_statuses,
            'dashboard_service_options' => $service_options,
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

            $service_id = request('service_id');
            $provider_ids = request('provider_ids', []);

            if ($provider_ids !== null && !is_array($provider_ids)) {
                $provider_ids = [$provider_ids];
            }

            $threshold = $this->getDashboardThreshold();

            $metrics = $this->dashboard_metrics->collect($start, $end, [
                'statuses' => $statuses,
                'service_id' => $service_id,
                'provider_ids' => $provider_ids ?? [],
                'threshold' => $threshold,
            ]);

            json_response($metrics);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Persist the dashboard conflict threshold.
     */
    public function threshold(): void
    {
        try {
            if (session('role_slug') !== DB_SLUG_ADMIN) {
                json_response(['success' => false, 'message' => 'Forbidden'], 403);

                return;
            }

            if (strtolower((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'post') {
                json_response(['success' => false, 'message' => 'Forbidden'], 403);

                return;
            }

            $threshold = $this->resolveThreshold($_POST['threshold'] ?? null);

            $this->persistThreshold($threshold);

            json_response([
                'success' => true,
                'threshold' => $threshold,
            ]);
        } catch (InvalidArgumentException $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Resolve and validate the dashboard threshold input.
     */
    protected function resolveThreshold(mixed $threshold_input): float
    {
        if (is_array($threshold_input) || !is_numeric($threshold_input)) {
            throw new InvalidArgumentException($this->getThresholdValidationMessage());
        }

        $threshold = (float) $threshold_input;

        if (!is_finite($threshold) || $threshold < 0 || $threshold > 1) {
            throw new InvalidArgumentException($this->getThresholdValidationMessage());
        }

        return $threshold;
    }

    /**
     * Persist a validated dashboard threshold for the current user session.
     */
    protected function persistThreshold(float $threshold): void
    {
        session(['dashboard_conflict_threshold' => (string) $threshold]);
    }

    /**
     * Resolve the dashboard threshold from session with a configured fallback.
     */
    protected function getDashboardThreshold(): float
    {
        $session_threshold = session('dashboard_conflict_threshold');

        if ($session_threshold === null || $session_threshold === '') {
            return $this->getConfiguredThreshold();
        }

        if (is_array($session_threshold) || !is_numeric($session_threshold)) {
            return $this->getConfiguredThreshold();
        }

        $threshold = (float) $session_threshold;

        if (!is_finite($threshold) || $threshold < 0 || $threshold > 1) {
            return $this->getConfiguredThreshold();
        }

        return $threshold;
    }

    /**
     * Return the globally configured dashboard threshold with a hard fallback.
     */
    protected function getConfiguredThreshold(): float
    {
        $configured = setting('dashboard_conflict_threshold', '0.90');

        if (!is_numeric($configured)) {
            return 0.9;
        }

        $threshold = (float) $configured;

        if (!is_finite($threshold) || $threshold < 0 || $threshold > 1) {
            return 0.9;
        }

        return $threshold;
    }

    /**
     * Resolve a localized validation message for threshold errors.
     */
    protected function getThresholdValidationMessage(): string
    {
        $message = trim((string) lang('dashboard_conflict_threshold_invalid'));

        return $message !== '' ? $message : 'Please provide a threshold between 0 and 1.';
    }

    /**
     * Provide heatmap utilization metrics for the selected filters.
     */
    public function heatmap(): void
    {
        try {
            if (session('role_slug') !== DB_SLUG_ADMIN) {
                json_response(['success' => false, 'message' => 'Forbidden'], 403);

                return;
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

            $service_id = request('service_id');
            $provider_ids = request('provider_ids', []);

            if ($provider_ids !== null && !is_array($provider_ids)) {
                $provider_ids = [$provider_ids];
            }

            $heatmap = $this->dashboard_heatmap->collect($start, $end, [
                'statuses' => $statuses,
                'service_id' => $service_id,
                'provider_ids' => $provider_ids ?? [],
            ]);

            json_response($heatmap);
        } catch (InvalidArgumentException $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }
}
