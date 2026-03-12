<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.4.0
 * ---------------------------------------------------------------------------- */

/**
 * Availability library.
 *
 * Handles availability related functionality.
 *
 * @package Libraries
 */
class Availability
{
    /**
     * @var EA_Controller|CI_Controller
     */
    protected EA_Controller|CI_Controller $CI;

    /**
     * Availability constructor.
     */
    public function __construct(
        ?Appointments_model $appointments_model = null,
        ?Unavailabilities_model $unavailabilities_model = null,
        ?Blocked_periods_model $blocked_periods_model = null,
    ) {
        $this->CI = &get_instance();

        $this->CI->load->model('admins_model');
        $this->CI->load->model('providers_model');
        $this->CI->load->model('secretaries_model');
        $this->CI->load->model('secretaries_model');
        $this->CI->load->model('settings_model');

        if ($appointments_model) {
            $this->CI->appointments_model = $appointments_model;
        } else {
            $this->CI->load->model('appointments_model');
        }

        if ($unavailabilities_model) {
            $this->CI->unavailabilities_model = $unavailabilities_model;
        } else {
            $this->CI->load->model('unavailabilities_model');
        }

        if ($blocked_periods_model) {
            $this->CI->blocked_periods_model = $blocked_periods_model;
        } else {
            $this->CI->load->model('blocked_periods_model');
        }

        $this->CI->load->library('ics_file');
    }

    /**
     * Get the available hours of a provider.
     *
     * @param string $date Selected date (Y-m-d).
     * @param array $service Service data.
     * @param array $provider Provider data.
     * @param int|null $exclude_appointment_id Exclude an appointment from the availability generation.
     *
     * @return array
     *
     * @throws Exception
     */
    public function get_available_hours(
        string $date,
        array $service,
        array $provider,
        ?int $exclude_appointment_id = null,
    ): array {
        $available_hours = $this->get_offered_hours_for_analysis($date, $service, $provider, $exclude_appointment_id);
        $available_hours = $this->consider_book_advance_timeout($date, $available_hours, $provider);

        return $this->consider_future_booking_limit($date, $available_hours, $provider);
    }

    /**
     * Get the offered hours for analytics without time-relative booking filters.
     *
     * @param string $date Selected date (Y-m-d).
     * @param array $service Service data.
     * @param array $provider Provider data.
     * @param int|null $exclude_appointment_id Exclude an appointment from the availability generation.
     *
     * @return array
     *
     * @throws Exception
     */
    public function get_offered_hours_for_analysis(
        string $date,
        array $service,
        array $provider,
        ?int $exclude_appointment_id = null,
    ): array {
        if ($this->CI->blocked_periods_model->is_entire_date_blocked($date)) {
            return [];
        }

        if ($service['attendants_number'] > 1) {
            $available_hours = $this->consider_multiple_attendants($date, $service, $provider, $exclude_appointment_id);
        } else {
            $available_periods = $this->get_available_periods($date, $provider, $exclude_appointment_id);

            $available_hours = $this->generate_available_hours($date, $service, $available_periods);
        }

        return array_values($available_hours);
    }

    /**
     * Get multiple attendants hours.
     *
     * This method will add the additional appointment hours whenever a service accepts multiple attendants.
     *
     * @param string $date Selected date (Y-m-d).
     * @param array $service Service data.
     * @param array $provider Provider data.
     * @param int|null $exclude_appointment_id Exclude an appointment from the availability generation.
     *
     * @return array Returns the available hours array.
     *
     * @throws Exception
     */
    protected function consider_multiple_attendants(
        string $date,
        array $service,
        array $provider,
        ?int $exclude_appointment_id = null,
    ): array {
        $unavailability_events = $this->CI->unavailabilities_model->get([
            'is_unavailability' => true,
            'DATE(start_datetime) <=' => $date,
            'DATE(end_datetime) >=' => $date,
            'id_users_provider' => $provider['id'],
        ]);

        $working_plan = json_decode($provider['settings']['working_plan'], true);

        $working_plan_exceptions = json_decode($provider['settings']['working_plan_exceptions'], true);

        $working_day = strtolower(date('l', strtotime($date)));

        $date_working_plan = $working_plan[$working_day] ?? null;

        // Search if the $date is a custom availability period added outside the normal working plan.
        if (array_key_exists($date, $working_plan_exceptions)) {
            $date_working_plan = $working_plan_exceptions[$date];
        }

        if (!$date_working_plan) {
            return [];
        }

        $periods = [
            [
                'start' => new DateTime($date . ' ' . $date_working_plan['start']),
                'end' => new DateTime($date . ' ' . $date_working_plan['end']),
            ],
        ];

        $blocked_periods = $this->CI->blocked_periods_model->get_for_period($date, $date);

        $periods = $this->remove_breaks($date, $periods, $date_working_plan['breaks']);
        $periods = $this->remove_unavailability_events($periods, $unavailability_events);
        $periods = $this->remove_unavailability_events($periods, $blocked_periods);

        $hours = [];

        $interval_value = $service['availabilities_type'] == AVAILABILITIES_TYPE_FIXED ? $service['duration'] : '15';
        $interval = new DateInterval('PT' . (int) $interval_value . 'M');
        $duration = new DateInterval('PT' . (int) $service['duration'] . 'M');

        foreach ($periods as $period) {
            $slot_start = clone $period['start'];
            $slot_end = clone $slot_start;
            $slot_end->add($duration);

            while ($slot_end <= $period['end']) {
                // Make sure there is no other service appointment for this time slot.
                $other_service_attendants_number = $this->CI->appointments_model->get_other_service_attendants_number(
                    $slot_start,
                    $slot_end,
                    $service['id'],
                    $provider['id'],
                    $exclude_appointment_id,
                );

                if ($other_service_attendants_number > 0) {
                    $slot_start->add($interval);
                    $slot_end->add($interval);
                    continue;
                }

                // Check reserved attendants for this time slot and see if current attendants fit.
                $appointment_attendants_number = $this->CI->appointments_model->get_attendants_number_for_period(
                    $slot_start,
                    $slot_end,
                    $service['id'],
                    $provider['id'],
                    $exclude_appointment_id,
                );

                if ($appointment_attendants_number < $service['attendants_number']) {
                    $hours[] = $slot_start->format('H:i');
                }

                $slot_start->add($interval);
                $slot_end->add($interval);
            }
        }

        return $hours;
    }

    /**
     * Remove breaks from available time periods.
     *
     * @param string $date Selected date (Y-m-d).
     * @param array $periods Empty periods.
     * @param array $breaks Array of breaks.
     *
     * @return array Returns the available time periods without the breaks.
     *
     * @throws Exception
     */
    public function remove_breaks(string $date, array $periods, array $breaks): array
    {
        if (!$breaks) {
            return $periods;
        }

        foreach ($breaks as $break) {
            $break_start = new DateTime($date . ' ' . $break['start']);

            $break_end = new DateTime($date . ' ' . $break['end']);

            foreach ($periods as &$period) {
                $period_start = $period['start'];

                $period_end = $period['end'];

                if ($break_start <= $period_start && $break_end >= $period_start && $break_end <= $period_end) {
                    // left
                    $period['start'] = $break_end;
                    continue;
                }

                if (
                    $break_start >= $period_start &&
                    $break_start <= $period_end &&
                    $break_end >= $period_start &&
                    $break_end <= $period_end
                ) {
                    // middle
                    $period['end'] = $break_start;
                    $periods[] = [
                        'start' => $break_end,
                        'end' => $period_end,
                    ];
                    continue;
                }

                if ($break_start >= $period_start && $break_start <= $period_end && $break_end >= $period_end) {
                    // right
                    $period['end'] = $break_start;
                    continue;
                }

                if ($break_start <= $period_start && $break_end >= $period_end) {
                    // break contains period
                    $period['start'] = $break_end;
                }
            }
        }

        return $periods;
    }

    /**
     * Remove the unavailability entries from the available time periods of the selected date.
     *
     * @param array $periods Available time periods.
     * @param array $unavailability_events Unavailability events of the current date.
     *
     * @return array Returns the available time periods without the unavailability events.
     *
     * @throws Exception
     */
    public function remove_unavailability_events(array $periods, array $unavailability_events): array
    {
        foreach ($unavailability_events as $unavailability_event) {
            $unavailability_start = new DateTime($unavailability_event['start_datetime']);

            $unavailability_end = new DateTime($unavailability_event['end_datetime']);

            foreach ($periods as &$period) {
                $period_start = $period['start'];

                $period_end = $period['end'];

                if (
                    $unavailability_start <= $period_start &&
                    $unavailability_end >= $period_start &&
                    $unavailability_end <= $period_end
                ) {
                    // Left
                    $period['start'] = $unavailability_end;
                    continue;
                }

                if (
                    $unavailability_start >= $period_start &&
                    $unavailability_start <= $period_end &&
                    $unavailability_end >= $period_start &&
                    $unavailability_end <= $period_end
                ) {
                    // Middle
                    $period['end'] = $unavailability_start;
                    $periods[] = [
                        'start' => $unavailability_end,
                        'end' => $period_end,
                    ];
                    continue;
                }

                if (
                    $unavailability_start >= $period_start &&
                    $unavailability_start <= $period_end &&
                    $unavailability_end >= $period_end
                ) {
                    // Right
                    $period['end'] = $unavailability_start;
                    continue;
                }

                if ($unavailability_start <= $period_start && $unavailability_end >= $period_end) {
                    // Unavailability contains period
                    $period['start'] = $unavailability_end;
                }
            }
        }

        return $periods;
    }

    /**
     * Get an array containing the free time periods (start - end) of a selected date.
     *
     * This method is very important because there are many cases where the system needs to know when a provider is
     * available for an appointment. It will return an array that belongs to the selected date and contains values that
     * have the start and the end time of an available time period.
     *
     * @param string $date Selected date (Y-m-d).
     * @param array $provider Provider data.
     * @param int|null $exclude_appointment_id Exclude an appointment from the availability generation.
     *
     * @return array Returns an array with the available time periods of the provider.
     *
     * @throws Exception
     */
    public function get_available_periods(string $date, array $provider, ?int $exclude_appointment_id = null): array
    {
        // Get the service, provider's working plan and provider appointments.
        $working_plan = json_decode($provider['settings']['working_plan'], true);

        // Get the provider's working plan exceptions.
        $working_plan_exceptions_json = $provider['settings']['working_plan_exceptions'];

        $working_plan_exceptions = $working_plan_exceptions_json
            ? json_decode($provider['settings']['working_plan_exceptions'], true)
            : [];

        $appointments = array_values(
            array_merge(
                $this->get_provider_appointments_for_date((int) $provider['id'], $date, $exclude_appointment_id),
                $this->get_provider_unavailabilities_for_date((int) $provider['id'], $date, $exclude_appointment_id),
                $this->CI->blocked_periods_model->get_for_period($date, $date),
            ),
        );

        // Find the empty spaces on the plan. The first split between the plan is due to a break (if any). After that
        // every reserved appointment is considered to be a taken space in the plan.
        $working_day = strtolower(date('l', strtotime($date)));

        $date_working_plan = $working_plan[$working_day] ?? null;

        // Search if the $date is a custom availability period added outside the normal working plan.
        if (array_key_exists($date, $working_plan_exceptions)) {
            $date_working_plan = $working_plan_exceptions[$date];
        }

        if (!$date_working_plan) {
            return [];
        }

        $periods = [];

        if (isset($date_working_plan['breaks'])) {
            $periods[] = [
                'start' => $date_working_plan['start'],
                'end' => $date_working_plan['end'],
            ];

            $day_start = new DateTime($date_working_plan['start']);
            $day_end = new DateTime($date_working_plan['end']);

            // Split the working plan to available time periods that do not contain the breaks in them.
            foreach ($date_working_plan['breaks'] as $break) {
                $break_start = new DateTime($break['start']);
                $break_end = new DateTime($break['end']);

                if ($break_start < $day_start) {
                    $break_start = $day_start;
                }

                if ($break_end > $day_end) {
                    $break_end = $day_end;
                }

                if ($break_start >= $break_end) {
                    continue;
                }

                foreach ($periods as $key => $period) {
                    $period_start = new DateTime($period['start']);
                    $period_end = new DateTime($period['end']);

                    $remove_current_period = false;

                    if ($break_start > $period_start && $break_start < $period_end && $break_end > $period_start) {
                        $periods[] = [
                            'start' => $period_start->format('H:i'),
                            'end' => $break_start->format('H:i'),
                        ];

                        $remove_current_period = true;
                    }

                    if ($break_start < $period_end && $break_end > $period_start && $break_end < $period_end) {
                        $periods[] = [
                            'start' => $break_end->format('H:i'),
                            'end' => $period_end->format('H:i'),
                        ];

                        $remove_current_period = true;
                    }

                    if ($break_start == $period_start && $break_end == $period_end) {
                        $remove_current_period = true;
                    }

                    if ($remove_current_period) {
                        unset($periods[$key]);
                    }
                }
            }
        }

        $day_start = new DateTimeImmutable($date . ' 00:00:00');
        $day_end = new DateTimeImmutable($date . ' 23:59:59');
        $period_ranges = $this->normalize_period_ranges($date, $periods);
        $appointment_ranges = $this->normalize_appointment_ranges($appointments, $day_start, $day_end);
        $period_ranges = $this->subtract_ranges($period_ranges, $appointment_ranges);

        return $this->format_period_ranges($period_ranges);
    }

    /**
     * Load provider appointments that overlap a selected date.
     *
     * @param int $provider_id Provider ID.
     * @param string $date Selected date (Y-m-d).
     * @param int|null $exclude_appointment_id Appointment ID to exclude from the result set.
     *
     * @return array
     */
    protected function get_provider_appointments_for_date(
        int $provider_id,
        string $date,
        ?int $exclude_appointment_id = null,
    ): array {
        if ($provider_id <= 0) {
            return [];
        }

        $day_start = $date . ' 00:00:00';
        $day_end = $date . ' 23:59:59';

        $query = $this->CI->appointments_model
            ->query()
            ->where('appointments.is_unavailability', false)
            ->where('appointments.id_users_provider', $provider_id)
            ->where('appointments.start_datetime <=', $day_end)
            ->where('appointments.end_datetime >=', $day_start);

        if ($exclude_appointment_id) {
            $parent_condition =
                '(appointments.id_parent_appointment IS NULL OR appointments.id_parent_appointment != ' .
                (int) $exclude_appointment_id .
                ')';

            $query->where('appointments.id !=', $exclude_appointment_id)->where($parent_condition, null, false);
        }

        $appointments = $query->get()->result_array();

        foreach ($appointments as &$appointment) {
            $this->CI->appointments_model->cast($appointment);
        }

        return $appointments;
    }

    /**
     * Load provider unavailabilities that overlap a selected date.
     *
     * @param int $provider_id Provider ID.
     * @param string $date Selected date (Y-m-d).
     * @param int|null $exclude_appointment_id Appointment ID to exclude from the result set.
     *
     * @return array
     */
    protected function get_provider_unavailabilities_for_date(
        int $provider_id,
        string $date,
        ?int $exclude_appointment_id = null,
    ): array {
        if ($provider_id <= 0) {
            return [];
        }

        $day_start = $date . ' 00:00:00';
        $day_end = $date . ' 23:59:59';

        $query = $this->CI->unavailabilities_model
            ->query()
            ->where('is_unavailability', true)
            ->where('id_users_provider', $provider_id)
            ->where('start_datetime <=', $day_end)
            ->where('end_datetime >=', $day_start);

        if ($exclude_appointment_id) {
            $parent_condition =
                '(id_parent_appointment IS NULL OR id_parent_appointment != ' . (int) $exclude_appointment_id . ')';

            $query->where('id !=', $exclude_appointment_id)->where($parent_condition, null, false);
        }

        $unavailabilities = $query->get()->result_array();

        foreach ($unavailabilities as &$unavailability) {
            $this->CI->unavailabilities_model->cast($unavailability);
        }

        return $unavailabilities;
    }

    /**
     * Normalize period rows into sorted datetime ranges.
     *
     * @param string $date Selected date.
     * @param array $periods Period entries containing H:i start/end values.
     *
     * @return array<int, array{start: DateTimeImmutable, end: DateTimeImmutable}>
     */
    protected function normalize_period_ranges(string $date, array $periods): array
    {
        $ranges = [];

        foreach ($periods as $period) {
            $start = DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . ($period['start'] ?? ''));
            $end = DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . ($period['end'] ?? ''));

            if (!$start || !$end || $end <= $start) {
                continue;
            }

            $ranges[] = [
                'start' => $start,
                'end' => $end,
            ];
        }

        usort(
            $ranges,
            static fn(array $left, array $right): int => $left['start']->getTimestamp() <=>
                $right['start']->getTimestamp(),
        );

        return $ranges;
    }

    /**
     * Normalize and day-clip appointment rows into sorted datetime ranges.
     *
     * @param array $appointments Appointment/unavailability rows with start/end datetime fields.
     * @param DateTimeImmutable $day_start Day start boundary.
     * @param DateTimeImmutable $day_end Day end boundary.
     *
     * @return array<int, array{start: DateTimeImmutable, end: DateTimeImmutable}>
     */
    protected function normalize_appointment_ranges(
        array $appointments,
        DateTimeImmutable $day_start,
        DateTimeImmutable $day_end,
    ): array {
        $ranges = [];

        foreach ($appointments as $appointment) {
            $start = DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                (string) ($appointment['start_datetime'] ?? ''),
            );
            $end = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($appointment['end_datetime'] ?? ''));

            if (!$start || !$end || $end <= $start) {
                continue;
            }

            if ($start < $day_start) {
                $start = $day_start;
            }

            if ($end > $day_end) {
                $end = $day_end;
            }

            if ($end <= $start) {
                continue;
            }

            $ranges[] = [
                'start' => $start,
                'end' => $end,
            ];
        }

        usort(
            $ranges,
            static fn(array $left, array $right): int => $left['start']->getTimestamp() <=>
                $right['start']->getTimestamp(),
        );

        return $ranges;
    }

    /**
     * Subtract blocked ranges from available ranges.
     *
     * @param array<int, array{start: DateTimeImmutable, end: DateTimeImmutable}> $available_ranges
     * @param array<int, array{start: DateTimeImmutable, end: DateTimeImmutable}> $blocked_ranges
     *
     * @return array<int, array{start: DateTimeImmutable, end: DateTimeImmutable}>
     */
    protected function subtract_ranges(array $available_ranges, array $blocked_ranges): array
    {
        if (empty($available_ranges) || empty($blocked_ranges)) {
            return $available_ranges;
        }

        foreach ($blocked_ranges as $blocked_range) {
            $updated_ranges = [];

            foreach ($available_ranges as $available_range) {
                if (
                    $blocked_range['end'] <= $available_range['start'] ||
                    $blocked_range['start'] >= $available_range['end']
                ) {
                    $updated_ranges[] = $available_range;
                    continue;
                }

                if ($blocked_range['start'] > $available_range['start']) {
                    $left_end =
                        $blocked_range['start'] < $available_range['end']
                            ? $blocked_range['start']
                            : $available_range['end'];

                    if ($left_end > $available_range['start']) {
                        $updated_ranges[] = [
                            'start' => $available_range['start'],
                            'end' => $left_end,
                        ];
                    }
                }

                if ($blocked_range['end'] < $available_range['end']) {
                    $right_start =
                        $blocked_range['end'] > $available_range['start']
                            ? $blocked_range['end']
                            : $available_range['start'];

                    if ($available_range['end'] > $right_start) {
                        $updated_ranges[] = [
                            'start' => $right_start,
                            'end' => $available_range['end'],
                        ];
                    }
                }
            }

            $available_ranges = $updated_ranges;

            if (empty($available_ranges)) {
                break;
            }
        }

        return $available_ranges;
    }

    /**
     * Convert datetime ranges back to the expected H:i period shape.
     *
     * @param array<int, array{start: DateTimeImmutable, end: DateTimeImmutable}> $ranges
     *
     * @return array<int, array{start: string, end: string}>
     */
    protected function format_period_ranges(array $ranges): array
    {
        $periods = [];

        foreach ($ranges as $range) {
            if ($range['end'] <= $range['start']) {
                continue;
            }

            $periods[] = [
                'start' => $range['start']->format('H:i'),
                'end' => $range['end']->format('H:i'),
            ];
        }

        return array_values($periods);
    }

    /**
     * Calculate the available appointment hours.
     *
     * Calculate the available appointment hours for the given date. The empty spaces are broken down to 15 min and if
     * the service fit in each quarter then a new available hour is added to the "$available_hours" array.
     *
     * @param string $date Selected date (Y-m-d).
     * @param array $service Service data.
     * @param array $empty_periods Empty periods array.
     *
     * @return array Returns an array with the available hours for the appointment.
     *
     * @throws Exception
     */
    protected function generate_available_hours(string $date, array $service, array $empty_periods): array
    {
        $available_hours = [];

        $buffer_before = max(0, (int) ($service['buffer_before'] ?? 0));
        $buffer_after = max(0, (int) ($service['buffer_after'] ?? 0));
        $duration = (int) $service['duration'];
        $fixed_slot_interval = $duration + $buffer_before + $buffer_after;
        $interval_minutes = $service['availabilities_type'] === AVAILABILITIES_TYPE_FIXED ? $fixed_slot_interval : 15;
        $duration_interval = new DateInterval('PT' . $duration . 'M');
        $slot_interval = new DateInterval('PT' . $interval_minutes . 'M');
        $buffer_before_interval = new DateInterval('PT' . $buffer_before . 'M');
        $buffer_after_interval = new DateInterval('PT' . $buffer_after . 'M');

        foreach ($empty_periods as $period) {
            $period_start = new DateTimeImmutable($date . ' ' . $period['start']);
            $period_end = new DateTimeImmutable($date . ' ' . $period['end']);

            $current_start =
                $service['availabilities_type'] === AVAILABILITIES_TYPE_FIXED
                    ? $period_start->add($buffer_before_interval)
                    : $period_start;
            $latest_start = $period_end->sub($buffer_after_interval)->sub($duration_interval);

            if ($latest_start < $current_start) {
                continue;
            }

            while ($current_start <= $latest_start) {
                $window_start = $buffer_before > 0 ? $current_start->sub($buffer_before_interval) : $current_start;
                $window_end = $current_start->add($duration_interval);
                $window_end = $buffer_after > 0 ? $window_end->add($buffer_after_interval) : $window_end;

                if ($window_start < $period_start || $window_end > $period_end) {
                    $current_start = $current_start->add($slot_interval);
                    continue;
                }

                $available_hours[] = $current_start->format('H:i');
                $current_start = $current_start->add($slot_interval);
            }
        }

        return $available_hours;
    }

    /**
     * Consider the book advance timeout and remove available hours that have passed the threshold.
     *
     * If the selected date is today, remove past hours. It is important  include the timeout before booking
     * that is set in the back-office the system. Normally we might want the customer to book an appointment
     * that is at least half or one hour from now. The setting is stored in minutes.
     *
     * @param string $date The selected date.
     * @param array $available_hours Already generated available hours.
     * @param array $provider Provider information.
     *
     * @return array Returns the updated available hours.
     *
     * @throws Exception
     */
    protected function consider_book_advance_timeout(string $date, array $available_hours, array $provider): array
    {
        $provider_timezone = new DateTimeZone($provider['timezone']);

        $book_advance_timeout = setting('book_advance_timeout');

        $threshold = new DateTime('+' . $book_advance_timeout . ' minutes', $provider_timezone);

        foreach ($available_hours as $index => $value) {
            $available_hour = new DateTime($date . ' ' . $value, $provider_timezone);

            if ($available_hour->getTimestamp() <= $threshold->getTimestamp()) {
                unset($available_hours[$index]);
            }
        }

        $available_hours = array_values($available_hours);

        sort($available_hours, SORT_STRING);

        return array_values($available_hours);
    }

    /**
     * Remove times if succeed the future booking limit.
     *
     * @param string $selected_date
     * @param array $available_hours
     * @param array $provider
     *
     * @return array
     *
     * @throws Exception
     */
    protected function consider_future_booking_limit(
        string $selected_date,
        array $available_hours,
        array $provider,
    ): array {
        $provider_timezone = new DateTimeZone($provider['timezone']);

        $future_booking_limit = setting('future_booking_limit'); // in days

        $threshold = new DateTime('+' . $future_booking_limit . ' days', $provider_timezone);

        $selected_date_time = new DateTime($selected_date);

        if ($threshold < $selected_date_time) {
            return [];
        }

        return $threshold > $selected_date_time ? $available_hours : [];
    }
}
