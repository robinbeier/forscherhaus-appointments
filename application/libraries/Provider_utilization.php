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

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Provider utilization library.
 *
 * Calculates utilization metrics for providers within a specific date range.
 *
 * @package Libraries
 */
class Provider_utilization
{
    /**
     * @var EA_Controller|CI_Controller
     */
    protected EA_Controller|CI_Controller $CI;

    protected Appointments_model $appointments_model;

    protected Services_model $services_model;

    protected Unavailabilities_model $unavailabilities_model;

    protected Blocked_periods_model $blocked_periods_model;

    /**
     * Provider_utilization constructor.
     */
    public function __construct(
        ?Appointments_model $appointments_model = null,
        ?Services_model $services_model = null,
        ?Unavailabilities_model $unavailabilities_model = null,
        ?Blocked_periods_model $blocked_periods_model = null,
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

        if ($unavailabilities_model) {
            $this->unavailabilities_model = $unavailabilities_model;
        } else {
            $this->CI->load->model('unavailabilities_model');
            $this->unavailabilities_model = $this->CI->unavailabilities_model;
        }

        if ($blocked_periods_model) {
            $this->blocked_periods_model = $blocked_periods_model;
        } else {
            $this->CI->load->model('blocked_periods_model');
            $this->blocked_periods_model = $this->CI->blocked_periods_model;
        }
    }

    /**
     * Calculate utilization metrics for a provider.
     */
    public function calculate(array $provider, string $start_date, string $end_date, array $statuses = []): array
    {
        if (!array_key_exists('id', $provider)) {
            throw new InvalidArgumentException('Provider id is required.');
        }

        $start = $this->createDay($start_date);
        $end = $this->createDay($end_date);

        if ($start > $end) {
            throw new InvalidArgumentException('The start date must be before or equal to the end date.');
        }

        $slot_size = $this->determineSlotSize($provider);

        $statuses = array_values(array_filter(array_map('strval', $statuses), static fn ($value) => $value !== ''));

        $appointments = $this->fetchAppointments((int) $provider['id'], $start, $end, $statuses);

        $total_minutes = 0;
        $booked_minutes = 0;
        $has_plan = false;

        $day = $start;

        while ($day <= $end) {
            $daily = $this->buildDailySchedule($provider, $day);

            if ($daily['has_plan']) {
                $has_plan = true;
            }

            $daily_minutes = $daily['minutes'];

            if ($daily_minutes > 0) {
                $daily_booked = $this->calculateBookedMinutesForDay($appointments, $day);
                $daily_booked = min($daily_booked, $daily_minutes);

                $total_minutes += $daily_minutes;
                $booked_minutes += $daily_booked;
            }

            $day = $day->add(new DateInterval('P1D'));
        }

        if ($slot_size <= 0) {
            $total_slots = 0;
            $booked_slots = 0;
        } else {
            $total_slots = intdiv($total_minutes, $slot_size);
            $booked_slots = intdiv($booked_minutes, $slot_size);

            if ($booked_slots > $total_slots) {
                $booked_slots = $total_slots;
            }
        }

        $open_minutes = max(0, $total_minutes - $booked_minutes);

        $open_slots = max(0, $total_slots - $booked_slots);

        $fill_rate = $total_slots > 0 ? $booked_slots / $total_slots : 0.0;

        return [
            'total' => $total_slots,
            'booked' => $booked_slots,
            'open' => $open_slots,
            'fill_rate' => $fill_rate,
            'has_plan' => $has_plan,
        ];
    }

    protected function createDay(string $date): DateTimeImmutable
    {
        $day = DateTimeImmutable::createFromFormat('Y-m-d', $date);

        if (!$day || $day->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Invalid date format provided: ' . $date);
        }

        return $day;
    }

    protected function determineSlotSize(array $provider): int
    {
        $service_ids = $provider['services'] ?? [];
        $durations = [];

        foreach ($service_ids as $service_id) {
            $service = $this->getServiceById((int) $service_id);

            if (!$service) {
                continue;
            }

            $duration = (int) ($service['duration'] ?? 0);

            if ($duration > 0) {
                $durations[] = $duration;
            }

            if (($service['availabilities_type'] ?? null) === AVAILABILITIES_TYPE_FLEXIBLE) {
                $durations[] = 15;
            }
        }

        if (empty($durations)) {
            return 15;
        }

        $slot_size = array_shift($durations);

        foreach ($durations as $duration) {
            $slot_size = $this->gcd($slot_size, $duration);
        }

        return max(1, $slot_size);
    }

    protected function getServiceById(int $service_id): ?array
    {
        if ($service_id <= 0) {
            return null;
        }

        $services = $this->services_model->get(['id' => $service_id], 1);

        return $services[0] ?? null;
    }

    protected function gcd(int $a, int $b): int
    {
        $a = abs($a);
        $b = abs($b);

        if ($b === 0) {
            return $a;
        }

        return $this->gcd($b, $a % $b);
    }

    protected function fetchAppointments(
        int $provider_id,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        array $statuses,
    ): array {
        $query = $this->appointments_model
            ->query()
            ->select('appointments.start_datetime, appointments.end_datetime')
            ->where('id_users_provider', $provider_id)
            ->where('is_unavailability', false)
            ->where('start_datetime <', $end->setTime(23, 59, 59)->format('Y-m-d H:i:s'))
            ->where('end_datetime >', $start->setTime(0, 0, 0)->format('Y-m-d H:i:s'));

        if ($statuses) {
            $query->where_in('status', $statuses);
        }

        $records = $query->get()->result_array();

        $appointments = [];

        foreach ($records as $record) {
            $appointment_start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $record['start_datetime']);
            $appointment_end = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $record['end_datetime']);

            if (!$appointment_start || !$appointment_end || $appointment_end <= $appointment_start) {
                continue;
            }

            $appointments[] = [
                'start' => $appointment_start,
                'end' => $appointment_end,
            ];
        }

        return $appointments;
    }

    protected function buildDailySchedule(array $provider, DateTimeImmutable $day): array
    {
        $working_plan = [];

        if (!empty($provider['settings']['working_plan'])) {
            $working_plan = json_decode($provider['settings']['working_plan'], true) ?: [];
        }

        $working_plan_exceptions = [];

        if (!empty($provider['settings']['working_plan_exceptions'])) {
            $working_plan_exceptions = json_decode($provider['settings']['working_plan_exceptions'], true) ?: [];
        }

        $date_key = $day->format('Y-m-d');

        $plan = $working_plan_exceptions[$date_key] ?? $working_plan[strtolower($day->format('l'))] ?? null;

        if (!$plan || empty($plan['start']) || empty($plan['end'])) {
            return [
                'has_plan' => false,
                'minutes' => 0,
            ];
        }

        $periods = $this->createInitialPeriods($plan, $day);

        $periods = $this->applyBreaks($periods, $plan['breaks'] ?? [], $day);

        $periods = $this->applyUnavailability($provider, $periods, $day);

        $minutes = $this->calculateMinutes($periods);

        return [
            'has_plan' => true,
            'minutes' => $minutes,
        ];
    }

    protected function createInitialPeriods(array $plan, DateTimeImmutable $day): array
    {
        $start = DateTimeImmutable::createFromFormat('Y-m-d H:i', $day->format('Y-m-d') . ' ' . $plan['start']);
        $end = DateTimeImmutable::createFromFormat('Y-m-d H:i', $day->format('Y-m-d') . ' ' . $plan['end']);

        if (!$start || !$end || $end <= $start) {
            return [];
        }

        return [
            [
                'start' => $start,
                'end' => $end,
            ],
        ];
    }

    protected function applyBreaks(array $periods, array $breaks, DateTimeImmutable $day): array
    {
        foreach ($breaks as $break) {
            if (empty($break['start']) || empty($break['end'])) {
                continue;
            }

            $break_start = DateTimeImmutable::createFromFormat('Y-m-d H:i', $day->format('Y-m-d') . ' ' . $break['start']);
            $break_end = DateTimeImmutable::createFromFormat('Y-m-d H:i', $day->format('Y-m-d') . ' ' . $break['end']);

            if (!$break_start || !$break_end || $break_end <= $break_start) {
                continue;
            }

            $periods = $this->subtractPeriod($periods, $break_start, $break_end);
        }

        return $periods;
    }

    protected function applyUnavailability(array $provider, array $periods, DateTimeImmutable $day): array
    {
        if (!$periods) {
            return [];
        }

        $date = $day->format('Y-m-d');

        $unavailability_events = $this->unavailabilities_model->get([
            'id_users_provider' => $provider['id'],
            'DATE(start_datetime) <=' => $date,
            'DATE(end_datetime) >=' => $date,
        ]);

        foreach ($unavailability_events as $event) {
            $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $event['start_datetime']);
            $end = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $event['end_datetime']);

            if (!$start || !$end || $end <= $start) {
                continue;
            }

            $periods = $this->subtractPeriod($periods, $start, $end);

            if (!$periods) {
                return [];
            }
        }

        $blocked_periods = $this->blocked_periods_model->get_for_period($date, $date);

        foreach ($blocked_periods as $blocked_period) {
            $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $blocked_period['start_datetime']);
            $end = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $blocked_period['end_datetime']);

            if (!$start || !$end || $end <= $start) {
                continue;
            }

            $periods = $this->subtractPeriod($periods, $start, $end);

            if (!$periods) {
                return [];
            }
        }

        return $periods;
    }

    protected function subtractPeriod(array $periods, DateTimeImmutable $block_start, DateTimeImmutable $block_end): array
    {
        $result = [];

        foreach ($periods as $period) {
            $period_start = $period['start'];
            $period_end = $period['end'];

            if ($block_end <= $period_start || $block_start >= $period_end) {
                $result[] = $period;
                continue;
            }

            if ($block_start <= $period_start && $block_end >= $period_end) {
                continue;
            }

            if ($block_start > $period_start) {
                $first_end = $block_start < $period_end ? $block_start : $period_end;

                $result[] = [
                    'start' => $period_start,
                    'end' => $first_end,
                ];
            }

            if ($block_end < $period_end) {
                $second_start = $block_end > $period_start ? $block_end : $period_start;

                $result[] = [
                    'start' => $second_start,
                    'end' => $period_end,
                ];
            }
        }

        return array_values(array_filter($result, static fn ($period) => $period['end'] > $period['start']));
    }

    protected function calculateMinutes(array $periods): int
    {
        $minutes = 0;

        foreach ($periods as $period) {
            $diff = $period['end']->getTimestamp() - $period['start']->getTimestamp();

            if ($diff > 0) {
                $minutes += intdiv((int) $diff, 60);
            }
        }

        return $minutes;
    }

    protected function calculateBookedMinutesForDay(array $appointments, DateTimeImmutable $day): int
    {
        if (!$appointments) {
            return 0;
        }

        $start_of_day = $day->setTime(0, 0, 0);
        $end_of_day = $day->setTime(23, 59, 59);

        $minutes = 0;

        foreach ($appointments as $appointment) {
            $start = $appointment['start'];
            $end = $appointment['end'];

            if ($end <= $start_of_day || $start >= $end_of_day) {
                continue;
            }

            $overlap_start = $start > $start_of_day ? $start : $start_of_day;
            $overlap_end = $end < $end_of_day ? $end : $end_of_day;

            $diff = $overlap_end->getTimestamp() - $overlap_start->getTimestamp();

            if ($diff > 0) {
                $minutes += intdiv((int) $diff, 60);
            }
        }

        return $minutes;
    }
}
