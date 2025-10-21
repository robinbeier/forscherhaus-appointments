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

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use RuntimeException;

/**
 * Dashboard heatmap aggregation library.
 *
 * Aggregates confirmed bookings per weekday/time slot for the dashboard heatmap.
 *
 * @package Libraries
 */
class Dashboard_heatmap
{
    /**
     * @var EA_Controller|CI_Controller
     */
    protected EA_Controller|CI_Controller $CI;

    protected Appointments_model $appointments_model;

    protected Services_model $services_model;

    /**
     * @var CI_Cache|object
     */
    protected $cache;

    protected DateTimeZone $timezone;

    protected int $cache_ttl = 60;

    protected int $default_interval = 30;

    protected int $default_start_minute = 6 * 60;

    protected int $default_end_minute = 20 * 60;

    protected int $first_weekday = 1;

    protected int $last_weekday = 5;

    /**
     * Dashboard_heatmap constructor.
     */
    public function __construct(
        ?Appointments_model $appointments_model = null,
        ?Services_model $services_model = null,
        $cache = null,
        ?string $timezone = null
    ) {
        $this->CI = &get_instance();

        if ($appointments_model) {
            $this->appointments_model = $appointments_model;
        } else {
            $this->CI->load->model('appointments_model');
            $this->appointments_model = $this->CI->appointments_model;
        }

        if ($services_model) {
            $this->services_model = $services_model;
        } else {
            $this->CI->load->model('services_model');
            $this->services_model = $this->CI->services_model;
        }

        if ($cache) {
            $this->cache = $cache;
        } else {
            $this->CI->load->driver('cache', ['adapter' => 'file']);
            $this->cache = $this->CI->cache;
        }

        $this->timezone = $this->resolveTimezone($timezone);
    }

    /**
     * Build the heatmap aggregation response.
     */
    public function collect(DateTimeImmutable $start, DateTimeImmutable $end, array $options = []): array
    {
        $statuses = $this->normalizeStatuses($options['statuses'] ?? []);
        $service_id = $this->normalizeServiceId($options['service_id'] ?? null);
        $provider_ids = $this->normalizeProviderIds($options['provider_ids'] ?? []);
        $interval_minutes = $this->resolveIntervalMinutes($service_id);

        $cache_key = $this->buildCacheKey($start, $end, $statuses, $service_id, $provider_ids, $interval_minutes);
        $cached = $this->cache?->get($cache_key);

        if ($cached !== false && $cached !== null) {
            return $cached;
        }

        $booked_statuses = $this->filterBookedStatuses($statuses);

        $counts = [];
        for ($weekday = $this->first_weekday; $weekday <= $this->last_weekday; $weekday++) {
            $counts[$weekday] = [];
        }

        $total = 0;
        $min_minute = $this->default_start_minute;
        $max_minute_exclusive = $this->default_end_minute;

        if (!empty($booked_statuses)) {
            $appointments = $this->fetchAppointments($start, $end, $booked_statuses, $service_id, $provider_ids);

            foreach ($appointments as $appointment) {
                $start_at = $this->parseStart($appointment['start_datetime'] ?? null);

                if (!$start_at) {
                    continue;
                }

                $weekday = (int) $start_at->format('N');

                if ($weekday < $this->first_weekday || $weekday > $this->last_weekday) {
                    continue;
                }

                $minute_of_day = ((int) $start_at->format('H') * 60) + (int) $start_at->format('i');
                $aligned_minute = max($this->default_start_minute, $this->alignMinute($minute_of_day, $interval_minutes));
                $slot_key = $aligned_minute;

                $counts[$weekday][$slot_key] = ($counts[$weekday][$slot_key] ?? 0) + 1;
                $total++;

                $min_minute = min($min_minute, $slot_key);
                $slot_end = min($aligned_minute + $interval_minutes, 24 * 60);
                $max_minute_exclusive = max($max_minute_exclusive, $slot_end);
            }
        }

        [$range_start, $range_end] = $this->normalizeRange($min_minute, $max_minute_exclusive, $interval_minutes);
        $this->fillMissingSlots($counts, $range_start, $range_end, $interval_minutes);

        $slots = $this->formatSlots($counts, $range_start, $range_end, $interval_minutes, $total);
        $percentile = $this->calculatePercentile($counts, 0.95);

        $response = [
            'meta' => [
                'startDate' => $start->format('Y-m-d'),
                'endDate' => $end->format('Y-m-d'),
                'intervalMinutes' => $interval_minutes,
                'timezone' => $this->timezone->getName(),
                'total' => $total,
                'percentile95' => $percentile,
                'rangeLabel' => $this->formatRangeLabel($range_start, $range_end),
            ],
            'slots' => $slots,
        ];

        $this->cache?->save($cache_key, $response, $this->cache_ttl);

        return $response;
    }

    protected function buildCacheKey(
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        array $statuses,
        ?int $service_id,
        array $provider_ids,
        int $interval_minutes
    ): string {
        $payload = [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'statuses' => array_values($statuses),
            'service' => $service_id,
            'providers' => array_values($provider_ids),
            'interval' => $interval_minutes,
        ];

        return 'dashboard_heatmap:' . md5(json_encode($payload));
    }

    protected function normalizeStatuses(array $statuses): array
    {
        $normalized = array_values(
            array_filter(
                array_map(static fn($value) => trim((string) $value), $statuses),
                static fn($value) => $value !== ''
            )
        );

        if (empty($normalized)) {
            return ['Booked'];
        }

        return $normalized;
    }

    protected function filterBookedStatuses(array $statuses): array
    {
        $booked = array_values(array_intersect($statuses, ['Booked']));

        if (empty($booked) && in_array('Booked', $statuses, true)) {
            return ['Booked'];
        }

        return $booked;
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

    protected function resolveIntervalMinutes(?int $service_id): int
    {
        if ($service_id === null) {
            return $this->default_interval;
        }

        try {
            $service = $this->services_model->find($service_id);
            $duration = (int) ($service['duration'] ?? 0);

            if ($duration >= $this->default_interval) {
                return $duration;
            }
        } catch (InvalidArgumentException $e) {
            log_message('error', 'Dashboard heatmap: invalid service filter ' . $service_id . ' - ' . $e->getMessage());
        }

        return $this->default_interval;
    }

    protected function fetchAppointments(
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        array $statuses,
        ?int $service_id,
        array $provider_ids
    ): array {
        $start_boundary = $start->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $end_boundary = $end->setTime(23, 59, 59)->format('Y-m-d H:i:s');

        $query = $this->appointments_model
            ->query()
            ->select('appointments.start_datetime')
            ->where('appointments.is_unavailability', false)
            ->where('appointments.start_datetime >=', $start_boundary)
            ->where('appointments.start_datetime <=', $end_boundary)
            ->order_by('appointments.start_datetime', 'ASC');

        if (!empty($statuses)) {
            $query->where_in('appointments.status', $statuses);
        }

        if ($service_id !== null) {
            $query->where('appointments.id_services', $service_id);
        }

        if (!empty($provider_ids)) {
            $query->where_in('appointments.id_users_provider', $provider_ids);
        }

        return $query->get()->result_array();
    }

    protected function parseStart(?string $value): ?DateTimeImmutable
    {
        if (!$value) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $this->timezone);

        if (!$date) {
            log_message('error', 'Dashboard heatmap: unable to parse start datetime "' . $value . '".');

            return null;
        }

        return $date;
    }

    protected function alignMinute(int $minute, int $interval): int
    {
        if ($interval <= 0) {
            throw new RuntimeException('Heatmap interval must be greater than zero.');
        }

        return intdiv($minute, $interval) * $interval;
    }

    protected function normalizeRange(int $min, int $max, int $interval): array
    {
        $min_aligned = max($this->default_start_minute, $this->alignMinute($min, $interval));
        $max_candidate = max($this->default_end_minute, $max);
        $max_aligned = $this->alignMinute(max($min_aligned + $interval, $max_candidate - 1), $interval) + $interval;
        $max_aligned = min($max_aligned, 24 * 60);

        return [$min_aligned, $max_aligned];
    }

    protected function fillMissingSlots(array &$counts, int $range_start, int $range_end, int $interval): void
    {
        for ($minute = $range_start; $minute < $range_end; $minute += $interval) {
            foreach ($counts as $weekday => &$slots) {
                if (!array_key_exists($minute, $slots)) {
                    $slots[$minute] = 0;
                }
            }
        }

        foreach ($counts as &$slots) {
            ksort($slots);
        }
    }

    protected function formatSlots(
        array $counts,
        int $range_start,
        int $range_end,
        int $interval,
        int $total
    ): array {
        $slots = [];

        foreach ($counts as $weekday => $day_counts) {
            foreach ($day_counts as $minute => $count) {
                if ($minute < $range_start || $minute >= $range_end) {
                    continue;
                }

                $slots[] = [
                    'weekday' => (int) $weekday,
                    'time' => $this->formatTime($minute),
                    'count' => (int) $count,
                    'percent' => $total > 0 ? round(($count / $total) * 100, 1) : 0.0,
                ];
            }
        }

        return $slots;
    }

    protected function calculatePercentile(array $counts, float $percentile): float
    {
        $values = [];

        foreach ($counts as $day_counts) {
            foreach ($day_counts as $value) {
                $values[] = (int) $value;
            }
        }

        if (empty($values)) {
            return 0.0;
        }

        sort($values);
        $last_index = count($values) - 1;

        if ($last_index <= 0) {
            return (float) $values[0];
        }

        $position = $percentile * $last_index;
        $lower_index = (int) floor($position);
        $upper_index = (int) ceil($position);
        $fraction = $position - $lower_index;

        $lower = $values[$lower_index];
        $upper = $values[$upper_index];

        $result = $lower + ($upper - $lower) * $fraction;

        return round($result, 2);
    }

    protected function formatTime(int $minute): string
    {
        $hours = intdiv($minute, 60);
        $minutes = $minute % 60;

        return sprintf('%02d:%02d', $hours, $minutes);
    }

    protected function formatRangeLabel(int $start, int $end): string
    {
        return $this->formatTime($start) . '–' . $this->formatTime($end);
    }

    protected function resolveTimezone(?string $preferred): DateTimeZone
    {
        $name = $preferred;

        if (!$name) {
            $name = setting('default_timezone', date_default_timezone_get()) ?: date_default_timezone_get();
        }

        try {
            return new DateTimeZone($name);
        } catch (Exception $e) {
            log_message('error', 'Dashboard heatmap: invalid timezone "' . $name . '", falling back to UTC.');

            return new DateTimeZone('UTC');
        }
    }
}
