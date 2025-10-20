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
    /**
     * @var EA_Controller|CI_Controller
     */
    protected EA_Controller|CI_Controller $CI;

    protected Providers_model $providers_model;

    protected Appointments_model $appointments_model;

    protected Provider_utilization $provider_utilization;

    /**
     * Dashboard_metrics constructor.
     */
    public function __construct(
        ?Providers_model $providers_model = null,
        ?Appointments_model $appointments_model = null,
        ?Provider_utilization $provider_utilization = null,
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
        $threshold = isset($options['threshold']) ? (float) $options['threshold'] : 0.75;

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

        foreach ($filtered_providers as $provider) {
            $summary = $this->provider_utilization->calculate(
                $provider,
                $start->format('Y-m-d'),
                $end->format('Y-m-d'),
                $statuses,
                $service_id,
            );

            $booked_count = $this->countBookedAppointments((int) $provider['id'], $start, $end, $statuses, $service_id);
            $class_size_default = $this->extractClassSizeDefault($provider);

            [$target, $is_fallback] = $this->resolveTarget($provider, $summary, $class_size_default);

            $booked_for_metrics = $this->resolveBookedMetric($summary, $booked_count, $is_fallback);

            $metrics[] = $this->formatRow(
                $provider,
                $summary,
                $target,
                $booked_for_metrics,
                $threshold,
                $is_fallback,
                $class_size_default,
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
        $query = $this->appointments_model
            ->query()
            ->select('COUNT(*) AS aggregate')
            ->where('appointments.is_unavailability', false)
            ->where('appointments.id_users_provider', $provider_id)
            ->where('appointments.start_datetime <', $end->setTime(23, 59, 59)->format('Y-m-d H:i:s'))
            ->where('appointments.end_datetime >', $start->setTime(0, 0, 0)->format('Y-m-d H:i:s'));

        if (!empty($statuses)) {
            $query->where_in('appointments.status', $statuses);
        }

        if ($service_id !== null) {
            $query->where('appointments.id_services', $service_id);
        }

        $result = $query->get()->row_array();

        return (int) ($result['aggregate'] ?? 0);
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

    protected function formatRow(
        array $provider,
        array $summary,
        int $target,
        int $booked,
        float $threshold,
        bool $is_fallback,
        ?int $class_size_default,
    ): array {
        $open = $target > 0 ? max($target - $booked, 0) : 0;
        $fill_rate = $this->computeFillRate($booked, $target);
        $needs_attention = $target > 0 && $fill_rate < $threshold;
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
            'has_plan' => (bool) ($summary['has_plan'] ?? false),
            'is_target_fallback' => $is_fallback,
            'class_size_default' => $class_size_default,
            'has_explicit_target' => $class_size_default !== null,
        ];
    }
}
