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
 * Dashboard controller.
 *
 * Handles the admin utilization dashboard.
 *
 * @package Controllers
 */
class Dashboard extends EA_Controller
{
    protected ?bool $provider_dashboard_range_columns_available = null;

    /**
     * Dashboard constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->model('providers_model');
        $this->load->model('services_model');
        $this->load->model('appointments_model');
        $this->load->library('dashboard_metrics');
        $this->load->library('dashboard_heatmap');
        $this->load->library('accounts');
        $this->load->helper('date');
    }

    /**
     * Render the dashboard view.
     */
    public function index(): void
    {
        session(['dest_url' => site_url('dashboard')]);

        $user_id = session('user_id');
        $role_slug = session('role_slug');

        if (!in_array($role_slug, [DB_SLUG_ADMIN, DB_SLUG_PROVIDER], true)) {
            if ($user_id) {
                abort(403, 'Forbidden');
            }

            redirect('login');

            return;
        }

        if ($role_slug === DB_SLUG_PROVIDER) {
            $saved_range = $this->getStoredProviderDashboardRange((int) $user_id);

            script_vars([
                'dashboard_saved_range_start' => $saved_range['start_date'],
                'dashboard_saved_range_end' => $saved_range['end_date'],
                'date_format' => setting('date_format'),
                'time_format' => setting('time_format'),
                'first_weekday' => setting('first_weekday'),
            ]);

            html_vars([
                'page_title' => lang('dashboard_teacher_dashboard_title') ?: lang('dashboard'),
                'active_menu' => 'dashboard',
                'user_display_name' => $this->accounts->get_user_display_name($user_id),
            ]);

            $this->load->view('pages/dashboard_teacher');

            return;
        }

        $saved_range = $this->getStoredProviderDashboardRange((int) $user_id);
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
            'dashboard_saved_range_start' => $saved_range['start_date'],
            'dashboard_saved_range_end' => $saved_range['end_date'],
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
     * Provide provider dashboard metrics for the selected period.
     */
    public function provider_metrics(): void
    {
        try {
            if (session('role_slug') !== DB_SLUG_PROVIDER) {
                json_response(['success' => false, 'message' => 'Forbidden'], 403);

                return;
            }

            if (strtolower((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'post') {
                json_response(['success' => false, 'message' => 'Forbidden'], 403);

                return;
            }

            $provider_id = (int) session('user_id');

            if ($provider_id <= 0) {
                json_response(['success' => false, 'message' => 'Forbidden'], 403);

                return;
            }

            $period = $this->resolvePeriod((string) request('start_date'), (string) request('end_date'));

            $payload = $this->buildProviderDashboardPayload($provider_id, $period['start'], $period['end']);

            $this->persistProviderDashboardRange($provider_id, $period['start'], $period['end']);

            json_response($payload);
        } catch (InvalidArgumentException $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Provide utilization metrics for the selected filters.
     */
    public function metrics(): void
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

            $this->persistProviderDashboardRange((int) session('user_id'), $start, $end);

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

    /**
     * Validate and normalize period input.
     *
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable}
     */
    protected function resolvePeriod(?string $start_input, ?string $end_input): array
    {
        if (!$start_input || !$end_input) {
            throw new InvalidArgumentException(lang('filter_period_required'));
        }

        $start = DateTimeImmutable::createFromFormat('Y-m-d', $start_input);
        $end = DateTimeImmutable::createFromFormat('Y-m-d', $end_input);

        if (!$start || $start->format('Y-m-d') !== $start_input || !$end || $end->format('Y-m-d') !== $end_input) {
            throw new InvalidArgumentException(lang('filter_period_required'));
        }

        if ($start > $end) {
            throw new InvalidArgumentException(lang('filter_period_required'));
        }

        return [
            'start' => $start,
            'end' => $end,
        ];
    }

    /**
     * Assemble provider dashboard payload.
     */
    protected function buildProviderDashboardPayload(
        int $provider_id,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
    ): array {
        $provider = $this->providers_model->find($provider_id);
        $provider_name = $this->resolveProviderDisplayName($provider, $provider_id);

        $metrics = $this->collectProviderMetrics($provider_id, $start, $end);
        $metric = $metrics[0] ?? null;

        $class_size = $this->resolveClassSize($metric, $provider);
        $target = $this->resolveTarget($metric, $class_size);
        $booked = is_array($metric) && is_numeric($metric['booked'] ?? null) ? max(0, (int) $metric['booked']) : 0;
        $open =
            is_array($metric) && is_numeric($metric['open'] ?? null)
                ? max(0, (int) $metric['open'])
                : max($target - $booked, 0);

        $slots_planned = $this->normalizeMetricInt(is_array($metric) ? $metric['slots_planned'] ?? 0 : 0);
        $slots_required = $this->normalizeMetricInt(is_array($metric) ? $metric['slots_required'] ?? $target : $target);
        $has_capacity_gap = $slots_required > 0 && $slots_planned < $slots_required;

        $progress_base = max($target, 1);
        $booked_percent = $progress_base > 0 ? max(0, min(100, (int) round(($booked / $progress_base) * 100))) : 0;
        $open_percent =
            $progress_base > 0 ? max(0, min(100 - $booked_percent, (int) round(($open / $progress_base) * 100))) : 0;

        $slot_info_with_target = lang('dashboard_teacher_pdf_slot_info_with_target') ?: '%s von %s Terminen gebucht';
        $slot_info_without_target = lang('dashboard_teacher_pdf_slot_info_without_target') ?: '%s Termine gebucht';

        return [
            'provider_id' => $provider_id,
            'provider_name' => $provider_name,
            'period' => [
                'start_date' => $start->format('Y-m-d'),
                'end_date' => $end->format('Y-m-d'),
            ],
            'progress' => [
                'booked_percent' => $booked_percent,
                'open_percent' => $open_percent,
                'slot_info_text' =>
                    $target > 0
                        ? sprintf($slot_info_with_target, $this->formatNumber($booked), $this->formatNumber($target))
                        : sprintf($slot_info_without_target, $this->formatNumber($booked)),
            ],
            'metrics' => [
                'class_size' => $class_size,
                'class_size_formatted' => $class_size !== null ? $this->formatNumber($class_size) : '—',
                'booked' => $booked,
                'booked_formatted' => $this->formatNumber($booked),
                'open' => $open,
                'open_formatted' => $this->formatNumber($open),
                'slots_planned' => $slots_planned,
                'slots_planned_formatted' => $this->formatNumber($slots_planned),
                'slots_required' => $slots_required,
                'slots_required_formatted' => $this->formatNumber($slots_required),
                'has_capacity_gap' => $has_capacity_gap,
            ],
            'appointments' => $this->loadProviderAppointments($provider_id, $start, $end),
        ];
    }

    /**
     * Collect provider metrics with fixed Booked status scope.
     */
    protected function collectProviderMetrics(int $provider_id, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        return $this->dashboard_metrics->collect($start, $end, [
            'statuses' => ['Booked'],
            'provider_ids' => [$provider_id],
        ]);
    }

    /**
     * Persist the provider dashboard range in user settings.
     */
    protected function persistProviderDashboardRange(
        int $provider_id,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
    ): void {
        if ($provider_id <= 0 || !$this->hasProviderDashboardRangeColumns()) {
            return;
        }

        $start_date = $start->format('Y-m-d');
        $end_date = $end->format('Y-m-d');
        $row = $this->db
            ->select(['dashboard_range_start', 'dashboard_range_end'])
            ->from('user_settings')
            ->where('id_users', $provider_id)
            ->get()
            ->row_array();

        $stored_start = is_array($row) ? trim((string) ($row['dashboard_range_start'] ?? '')) : '';
        $stored_end = is_array($row) ? trim((string) ($row['dashboard_range_end'] ?? '')) : '';

        if ($stored_start === $start_date && $stored_end === $end_date) {
            return;
        }

        if (!$row) {
            $created = $this->db->insert('user_settings', [
                'id_users' => $provider_id,
                'dashboard_range_start' => $start_date,
                'dashboard_range_end' => $end_date,
            ]);

            if (!$created) {
                throw new RuntimeException('Could not persist provider dashboard period.');
            }

            return;
        }

        $saved = $this->db->update(
            'user_settings',
            [
                'dashboard_range_start' => $start_date,
                'dashboard_range_end' => $end_date,
            ],
            ['id_users' => $provider_id],
        );

        if (!$saved) {
            throw new RuntimeException('Could not persist provider dashboard period.');
        }
    }

    /**
     * Resolve the stored provider dashboard period from user settings.
     *
     * @return array{start_date: ?string, end_date: ?string}
     */
    protected function getStoredProviderDashboardRange(int $provider_id): array
    {
        $empty = [
            'start_date' => null,
            'end_date' => null,
        ];

        if ($provider_id <= 0 || !$this->hasProviderDashboardRangeColumns()) {
            return $empty;
        }

        $row = $this->db
            ->select(['dashboard_range_start', 'dashboard_range_end'])
            ->from('user_settings')
            ->where('id_users', $provider_id)
            ->get()
            ->row_array();

        $start_date = is_array($row) ? trim((string) ($row['dashboard_range_start'] ?? '')) : '';
        $end_date = is_array($row) ? trim((string) ($row['dashboard_range_end'] ?? '')) : '';

        if (!$this->isValidPeriodDate($start_date) || !$this->isValidPeriodDate($end_date)) {
            return $empty;
        }

        if ($start_date > $end_date) {
            return $empty;
        }

        return [
            'start_date' => $start_date,
            'end_date' => $end_date,
        ];
    }

    /**
     * Check whether provider dashboard range columns are available.
     */
    protected function hasProviderDashboardRangeColumns(): bool
    {
        if ($this->provider_dashboard_range_columns_available !== null) {
            return $this->provider_dashboard_range_columns_available;
        }

        $this->provider_dashboard_range_columns_available =
            $this->db->field_exists('dashboard_range_start', 'user_settings') &&
            $this->db->field_exists('dashboard_range_end', 'user_settings');

        return $this->provider_dashboard_range_columns_available;
    }

    /**
     * Determine whether a value is a valid Y-m-d date.
     */
    protected function isValidPeriodDate(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /**
     * Resolve a provider display name with fallback.
     */
    protected function resolveProviderDisplayName(array $provider, int $provider_id): string
    {
        $name = trim(($provider['first_name'] ?? '') . ' ' . ($provider['last_name'] ?? ''));

        if ($name !== '') {
            return $name;
        }

        $email = trim((string) ($provider['email'] ?? ''));

        if ($email !== '') {
            return $email;
        }

        return (string) $provider_id;
    }

    /**
     * Resolve explicit class size from provider metric data.
     */
    protected function resolveClassSize(?array $metric, array $provider): ?int
    {
        $candidate = is_array($metric) ? $metric['class_size_default'] ?? null : null;

        if ($candidate === null || $candidate === '') {
            $candidate = $provider['class_size_default'] ?? null;
        }

        if ($candidate === null || $candidate === '') {
            return null;
        }

        $class_size = (int) $candidate;

        return $class_size > 0 ? $class_size : null;
    }

    /**
     * Resolve target size from metric fallback logic.
     */
    protected function resolveTarget(?array $metric, ?int $class_size): int
    {
        if ($class_size !== null) {
            return $class_size;
        }

        $target = is_array($metric) ? $metric['target'] ?? 0 : 0;

        return max(0, (int) $target);
    }

    /**
     * Normalize metric integer values.
     */
    protected function normalizeMetricInt(mixed $value): int
    {
        if (!is_numeric($value)) {
            return 0;
        }

        return max(0, (int) round((float) $value));
    }

    /**
     * Format number values for dashboard display.
     */
    protected function formatNumber(int $value): string
    {
        return (string) $value;
    }

    /**
     * Load booked appointments for a provider and period.
     *
     * @return array<int, array{parent_lastname: string, date: string, start: string, end: string}>
     */
    protected function loadProviderAppointments(
        int $provider_id,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
    ): array {
        $rows = $this->appointments_model
            ->query()
            ->select([
                'appointments.start_datetime',
                'appointments.end_datetime',
                'customers.first_name AS customer_first_name',
                'customers.last_name AS customer_last_name',
                'customers.email AS customer_email',
                'customers.phone_number AS customer_phone_number',
            ])
            ->join('users AS customers', 'customers.id = appointments.id_users_customer', 'left')
            ->where('appointments.is_unavailability', false)
            ->where('appointments.id_users_provider', $provider_id)
            ->where('appointments.status', 'Booked')
            ->where('appointments.start_datetime <', $end->setTime(23, 59, 59)->format('Y-m-d H:i:s'))
            ->where('appointments.end_datetime >', $start->setTime(0, 0, 0)->format('Y-m-d H:i:s'))
            ->order_by('appointments.start_datetime', 'ASC')
            ->get()
            ->result_array();

        return array_map(function (array $row): array {
            return [
                'parent_lastname' => $this->resolveCustomerLastName($row),
                'date' => $this->formatDateValue((string) ($row['start_datetime'] ?? '')),
                'start' => $this->formatTimeValue((string) ($row['start_datetime'] ?? '')),
                'end' => $this->formatTimeValue((string) ($row['end_datetime'] ?? '')),
            ];
        }, $rows);
    }

    /**
     * Resolve customer name fallback for provider dashboard appointments.
     */
    protected function resolveCustomerLastName(array $appointment): string
    {
        $last_name = trim((string) ($appointment['customer_last_name'] ?? ''));

        if ($last_name !== '') {
            return $last_name;
        }

        $first_name = trim((string) ($appointment['customer_first_name'] ?? ''));

        if ($first_name !== '') {
            return $first_name;
        }

        $email = trim((string) ($appointment['customer_email'] ?? ''));

        if ($email !== '') {
            return $email;
        }

        $phone = trim((string) ($appointment['customer_phone_number'] ?? ''));

        if ($phone !== '') {
            return $phone;
        }

        return '—';
    }

    /**
     * Format date value according to the installation setting.
     */
    protected function formatDateValue(string $value): string
    {
        try {
            return format_date($value);
        } catch (Throwable $e) {
            log_message('error', 'Could not format provider dashboard appointment date: ' . $e->getMessage());

            return '';
        }
    }

    /**
     * Format time value according to the installation setting.
     */
    protected function formatTimeValue(string $value): string
    {
        try {
            return format_time($value);
        } catch (Throwable $e) {
            log_message('error', 'Could not format provider dashboard appointment time: ' . $e->getMessage());

            return '';
        }
    }
}
