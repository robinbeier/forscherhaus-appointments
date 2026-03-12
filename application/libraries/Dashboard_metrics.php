<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.8.0
 * ---------------------------------------------------------------------------- */

/**
 * Dashboard metrics library.
 *
 * Aggregates dashboard utilization KPIs for the configured filters.
 *
 * @package Libraries
 */
class Dashboard_metrics
{
    protected const AFTER_15_CUTOFF = '15:00';

    protected const AFTER_15_TARGET_RATIO = 0.3;

    public const STATUS_REASON_BOOKING_GOAL_MISSED = 'booking_goal_missed';

    public const STATUS_REASON_AFTER_15_GOAL_MISSED = 'after_15_goal_missed';

    public const STATUS_REASON_CAPACITY_GAP = 'capacity_gap';

    /**
     * @var EA_Controller|CI_Controller
     */
    protected EA_Controller|CI_Controller $CI;

    protected Providers_model $providers_model;

    protected Appointments_model $appointments_model;

    protected Provider_utilization $provider_utilization;

    protected Services_model $services_model;

    protected Booking_slot_analytics $booking_slot_analytics;

    /**
     * @var array<int, array|null>
     */
    protected array $service_cache = [];

    /**
     * Dashboard_metrics constructor.
     */
    public function __construct(
        ?Providers_model $providers_model = null,
        ?Appointments_model $appointments_model = null,
        ?Provider_utilization $provider_utilization = null,
        ?Services_model $services_model = null,
        ?Booking_slot_analytics $booking_slot_analytics = null,
    ) {
        $this->CI = &get_instance();

        if ($providers_model) {
            $this->providers_model = $providers_model;
        } else {
            $this->CI->load->model('providers_model');
            $this->providers_model = $this->CI->providers_model;
        }

        if ($appointments_model) {
            $this->appointments_model = $appointments_model;
        } else {
            $this->CI->load->model('appointments_model');
            $this->appointments_model = $this->CI->appointments_model;
        }

        if ($provider_utilization) {
            $this->provider_utilization = $provider_utilization;
        } else {
            $this->CI->load->library('provider_utilization');
            $this->provider_utilization = $this->CI->provider_utilization;
        }

        if ($services_model) {
            $this->services_model = $services_model;
        } else {
            $this->CI->load->model('services_model');
            $this->services_model = $this->CI->services_model;
        }

        if ($booking_slot_analytics) {
            $this->booking_slot_analytics = $booking_slot_analytics;
        } else {
            $this->CI->load->library('booking_slot_analytics');
            $this->booking_slot_analytics = $this->CI->booking_slot_analytics;
        }
    }

    /**
     * Build the dashboard metrics collection.
     *
     * @param DateTimeImmutable $start
     * @param DateTimeImmutable $end
     * @param array $options
     *
     * @return array
     */
    public function collect(DateTimeImmutable $start, DateTimeImmutable $end, array $options = []): array
    {
        $statuses = $this->normalizeStatuses($options['statuses'] ?? []);
        $service_id = $this->normalizeServiceId($options['service_id'] ?? null);
        $provider_ids = $this->normalizeProviderIds($options['provider_ids'] ?? []);
        $threshold = isset($options['threshold']) ? (float) $options['threshold'] : 0.9;

        $providers = $this->providers_model->get_available_providers(false);

        $filtered_providers = array_filter($providers, static function (array $provider) use (
            $provider_ids,
            $service_id,
        ) {
            if ($provider_ids && !in_array((int) $provider['id'], $provider_ids, true)) {
                return false;
            }

            if ($service_id !== null) {
                $services = array_map('intval', $provider['services'] ?? []);

                if (!in_array($service_id, $services, true)) {
                    return false;
                }
            }

            return true;
        });

        $metrics = [];
        $filtered_provider_ids = array_values(
            array_map(static fn(array $provider): int => (int) ($provider['id'] ?? 0), $filtered_providers),
        );
        $booked_counts = $this->countBookedAppointmentsByProvider(
            $filtered_provider_ids,
            $start,
            $end,
            $statuses,
            $service_id,
        );

        foreach ($filtered_providers as $provider) {
            $provider_id = (int) ($provider['id'] ?? 0);
            $summary = $this->provider_utilization->calculate(
                $provider,
                $start->format('Y-m-d'),
                $end->format('Y-m-d'),
                $statuses,
                $service_id,
            );

            $booked_count = $booked_counts[$provider_id] ?? 0;
            $class_size_default = $this->extractClassSizeDefault($provider);

            [$target, $is_fallback] = $this->resolveTarget($provider, $summary, $class_size_default);

            $booked_for_metrics = $this->resolveBookedMetric($summary, $booked_count, $is_fallback);
            $after_15_metrics = $this->calculateAfter15Metrics($provider, $start, $end, $service_id);

            $metrics[] = $this->formatRow(
                $provider,
                $summary,
                $target,
                $booked_for_metrics,
                $threshold,
                $is_fallback,
                $class_size_default,
                $after_15_metrics,
            );
        }

        usort($metrics, static fn($left, $right) => $left['fill_rate'] <=> $right['fill_rate']);

        return array_values($metrics);
    }

    protected function normalizeStatuses(array $statuses): array
    {
        $normalized = array_values(
            array_filter(
                array_map(static fn($value) => trim((string) $value), $statuses),
                static fn($value) => $value !== '',
            ),
        );

        if (empty($normalized)) {
            return ['Booked'];
        }

        return $normalized;
    }

    protected function normalizeServiceId($service_id): ?int
    {
        if ($service_id === null || $service_id === '') {
            return null;
        }

        $value = (int) $service_id;

        return $value > 0 ? $value : null;
    }

    protected function normalizeProviderIds($provider_ids): array
    {
        if (!is_array($provider_ids)) {
            $provider_ids = [$provider_ids];
        }

        $ids = array_map(static fn($value) => (int) $value, $provider_ids);

        $ids = array_filter($ids, static fn($value) => $value > 0);

        return array_values(array_unique($ids));
    }

    protected function countBookedAppointments(
        int $provider_id,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        array $statuses,
        ?int $service_id,
    ): int {
        $counts = $this->countBookedAppointmentsByProvider([$provider_id], $start, $end, $statuses, $service_id);

        return $counts[$provider_id] ?? 0;
    }

    /**
     * Count booked appointments per provider in a single grouped query.
     */
    protected function countBookedAppointmentsByProvider(
        array $provider_ids,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        array $statuses,
        ?int $service_id,
    ): array {
        $provider_ids = array_values(
            array_filter(array_map('intval', $provider_ids), static fn(int $id): bool => $id > 0),
        );

        if (empty($provider_ids)) {
            return [];
        }

        $query = $this->appointments_model
            ->query()
            ->select('appointments.id_users_provider, COUNT(*) AS aggregate')
            ->where('appointments.is_unavailability', false)
            ->where_in('appointments.id_users_provider', $provider_ids)
            ->where('appointments.start_datetime <', $end->setTime(23, 59, 59)->format('Y-m-d H:i:s'))
            ->where('appointments.end_datetime >', $start->setTime(0, 0, 0)->format('Y-m-d H:i:s'))
            ->group_by('appointments.id_users_provider');

        if (!empty($statuses)) {
            $query->where_in('appointments.status', $statuses);
        }

        if ($service_id !== null) {
            $query->where('appointments.id_services', $service_id);
        }

        $results = $query->get()->result_array();
        $counts = array_fill_keys($provider_ids, 0);

        foreach ($results as $row) {
            $provider_id = (int) ($row['id_users_provider'] ?? 0);

            if ($provider_id <= 0 || !array_key_exists($provider_id, $counts)) {
                continue;
            }

            $counts[$provider_id] = (int) ($row['aggregate'] ?? 0);
        }

        return $counts;
    }

    protected function extractClassSizeDefault(array $provider): ?int
    {
        if (!array_key_exists('class_size_default', $provider)) {
            return null;
        }

        $value = $provider['class_size_default'];

        if ($value === null || $value === '') {
            return null;
        }

        $int_value = (int) $value;

        return $int_value > 0 ? $int_value : null;
    }

    protected function resolveTarget(array $provider, array $summary, ?int $class_size_default = null): array
    {
        $class_size = $class_size_default ?? $this->extractClassSizeDefault($provider);

        if ($class_size !== null) {
            return [$class_size, false];
        }

        $fallback = (int) round($summary['total'] ?? 0);

        if ($fallback < 0) {
            $fallback = 0;
        }

        return [$fallback, true];
    }

    protected function resolveBookedMetric(array $summary, int $appointment_count, bool $is_fallback): int
    {
        if ($is_fallback) {
            $booked_slots = $summary['booked'] ?? null;

            if (is_numeric($booked_slots)) {
                $booked_slots = (int) round($booked_slots);

                if ($booked_slots < 0) {
                    $booked_slots = 0;
                }

                return $booked_slots;
            }
        }

        return $appointment_count;
    }

    protected function computeFillRate(int $booked, int $target): float
    {
        if ($target <= 0) {
            return 0.0;
        }

        return $booked / $target;
    }

    protected function calculateAfter15Metrics(
        array $provider,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        ?int $selected_service_id,
    ): array {
        $service = $this->resolveDashboardService($provider, $selected_service_id);

        if (!$service) {
            return $this->buildNeutralAfter15Metrics();
        }

        $total_offered_slots = 0;
        $after_15_slots = 0;
        $day = $start;

        try {
            $offered_hours_by_date = $this->booking_slot_analytics->get_offered_hours_by_date_for_analysis(
                $start->format('Y-m-d'),
                $end->format('Y-m-d'),
                $service,
                $provider,
            );

            while ($day <= $end) {
                $offered_hours = $offered_hours_by_date[$day->format('Y-m-d')] ?? [];

                foreach ($offered_hours as $offered_hour) {
                    if (!is_string($offered_hour) || !preg_match('/^\d{2}:\d{2}$/', $offered_hour)) {
                        continue;
                    }

                    $total_offered_slots++;

                    if ($offered_hour >= self::AFTER_15_CUTOFF) {
                        $after_15_slots++;
                    }
                }

                $day = $day->add(new DateInterval('P1D'));
            }
        } catch (Throwable $exception) {
            $this->logAfter15MetricsFailure($provider, $service, $exception);

            return $this->buildNeutralAfter15Metrics();
        }

        if ($total_offered_slots === 0) {
            return [
                'after_15_slots' => 0,
                'total_offered_slots' => 0,
                'after_15_ratio' => null,
                'after_15_percent' => null,
                'after_15_target_met' => null,
                'after_15_evaluable' => false,
            ];
        }

        $after_15_ratio = $after_15_slots / $total_offered_slots;

        return [
            'after_15_slots' => $after_15_slots,
            'total_offered_slots' => $total_offered_slots,
            'after_15_ratio' => $after_15_ratio,
            'after_15_percent' => round($after_15_ratio * 100, 1),
            'after_15_target_met' => $after_15_ratio >= self::AFTER_15_TARGET_RATIO,
            'after_15_evaluable' => true,
        ];
    }

    protected function resolveDashboardService(array $provider, ?int $selected_service_id): ?array
    {
        if ($selected_service_id !== null) {
            return $this->getServiceById($selected_service_id);
        }

        $provider_service_ids = array_values(
            array_unique(
                array_filter(
                    array_map('intval', $provider['services'] ?? []),
                    static fn(int $service_id): bool => $service_id > 0,
                ),
            ),
        );

        if (count($provider_service_ids) !== 1) {
            return null;
        }

        return $this->getServiceById($provider_service_ids[0]);
    }

    protected function getServiceById(int $service_id): ?array
    {
        if ($service_id <= 0) {
            return null;
        }

        if (array_key_exists($service_id, $this->service_cache)) {
            return $this->service_cache[$service_id];
        }

        $services = $this->services_model->get(['id' => $service_id], 1);
        $service = $services[0] ?? null;
        $this->service_cache[$service_id] = $service;

        return $service;
    }

    protected function buildNeutralAfter15Metrics(): array
    {
        return [
            'after_15_slots' => null,
            'total_offered_slots' => null,
            'after_15_ratio' => null,
            'after_15_percent' => null,
            'after_15_target_met' => null,
            'after_15_evaluable' => false,
        ];
    }

    protected function logAfter15MetricsFailure(array $provider, array $service, Throwable $exception): void
    {
        if (!function_exists('log_message')) {
            return;
        }

        $provider_id = (int) ($provider['id'] ?? 0);
        $service_id = (int) ($service['id'] ?? 0);

        log_message(
            'error',
            sprintf(
                'Dashboard after-15 metric skipped for provider %d and service %d: %s',
                $provider_id,
                $service_id,
                $exception->getMessage(),
            ),
        );
    }

    protected function formatRow(
        array $provider,
        array $summary,
        int $target,
        int $booked,
        float $threshold,
        bool $is_fallback,
        ?int $class_size_default,
        array $after_15_metrics,
    ): array {
        $open = $target > 0 ? max($target - $booked, 0) : 0;
        $fill_rate = $this->computeFillRate($booked, $target);
        $needs_attention = $target > 0 && $fill_rate < $threshold;
        $slots_required = max($target, 0);
        $slots_planned = $this->resolvePlannedSlots($after_15_metrics);
        $has_capacity_gap = $slots_planned !== null && $slots_required > 0 && $slots_planned < $slots_required;
        $has_plan = (bool) ($summary['has_plan'] ?? false);
        $has_explicit_target = $class_size_default !== null;
        $status_reasons = $this->buildStatusReasons(
            $has_plan,
            $has_explicit_target,
            $needs_attention,
            $after_15_metrics,
            $has_capacity_gap,
        );
        $display_name = trim(($provider['first_name'] ?? '') . ' ' . ($provider['last_name'] ?? ''));

        if ($display_name === '') {
            $display_name = $provider['email'] ?? (string) ($provider['id'] ?? '');
        }

        return [
            'provider_id' => (int) $provider['id'],
            'provider_name' => $display_name,
            'target' => $target,
            'booked' => $booked,
            'open' => $open,
            'fill_rate' => $fill_rate,
            'needs_attention' => $needs_attention,
            'has_plan' => $has_plan,
            'slots_planned' => $slots_planned,
            'slots_required' => $slots_required,
            'has_capacity_gap' => $has_capacity_gap,
            'is_target_fallback' => $is_fallback,
            'class_size_default' => $class_size_default,
            'has_explicit_target' => $has_explicit_target,
            'status_reasons' => $status_reasons,
            'after_15_slots' => $after_15_metrics['after_15_slots'] ?? null,
            'total_offered_slots' => $after_15_metrics['total_offered_slots'] ?? null,
            'after_15_ratio' => $after_15_metrics['after_15_ratio'] ?? null,
            'after_15_percent' => $after_15_metrics['after_15_percent'] ?? null,
            'after_15_target_met' => $after_15_metrics['after_15_target_met'] ?? null,
            'after_15_evaluable' => (bool) ($after_15_metrics['after_15_evaluable'] ?? false),
        ];
    }

    /**
     * Build the ordered status reasons shared by dashboard consumers.
     */
    protected function buildStatusReasons(
        bool $has_plan,
        bool $has_explicit_target,
        bool $needs_attention,
        array $after_15_metrics,
        bool $has_capacity_gap,
    ): array {
        if (!$has_plan) {
            return [];
        }

        $status_reasons = [];

        if ($has_explicit_target && $needs_attention) {
            $status_reasons[] = self::STATUS_REASON_BOOKING_GOAL_MISSED;
        }

        $after_15_evaluable = (bool) ($after_15_metrics['after_15_evaluable'] ?? false);
        $after_15_target_met = $after_15_metrics['after_15_target_met'] ?? null;

        if ($after_15_evaluable && $after_15_target_met === false) {
            $status_reasons[] = self::STATUS_REASON_AFTER_15_GOAL_MISSED;
        }

        if ($has_capacity_gap) {
            $status_reasons[] = self::STATUS_REASON_CAPACITY_GAP;
        }

        return $status_reasons;
    }

    /**
     * Use the same booking-offered slot count that backs the after-15 KPI.
     */
    protected function resolvePlannedSlots(array $after_15_metrics): ?int
    {
        if (!array_key_exists('total_offered_slots', $after_15_metrics)) {
            return null;
        }

        $total_offered_slots = $after_15_metrics['total_offered_slots'];

        if ($total_offered_slots === null || !is_numeric($total_offered_slots)) {
            return null;
        }

        $planned_slots = (int) round((float) $total_offered_slots);

        return $planned_slots >= 0 ? $planned_slots : null;
    }
}
