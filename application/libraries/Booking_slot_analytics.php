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
 * Booking slot analytics library.
 *
 * Shared booking-slot generation for analytical and backoffice consumers.
 *
 * @package Libraries
 */
class Booking_slot_analytics
{
    /**
     * @var EA_Controller|CI_Controller
     */
    protected EA_Controller|CI_Controller $CI;

    /**
     * Booking_slot_analytics constructor.
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
     * Get offered hours for each date in a selected range without time-relative booking filters.
     *
     * @param string $start_date Range start date (Y-m-d).
     * @param string $end_date Range end date (Y-m-d).
     * @param array $service Service data.
     * @param array $provider Provider data.
     * @param int|null $exclude_appointment_id Exclude an appointment from the availability generation.
     *
     * @return array<string, array<int, string>>
     *
     * @throws Exception
     */
    public function get_offered_hours_by_date_for_analysis(
        string $start_date,
        string $end_date,
        array $service,
        array $provider,
        ?int $exclude_appointment_id = null,
    ): array {
        $range_start = new DateTimeImmutable($start_date);
        $range_end = new DateTimeImmutable($end_date);

        if ($range_end < $range_start) {
            return [];
        }

        if (($service['attendants_number'] ?? 1) > 1) {
            return $this->get_offered_hours_by_date_using_daily_fallback(
                $range_start,
                $range_end,
                $service,
                $provider,
                $exclude_appointment_id,
            );
        }

        $appointments = array_merge(
            $this->get_provider_appointments_for_period(
                (int) ($provider['id'] ?? 0),
                $start_date,
                $end_date,
                $exclude_appointment_id,
            ),
            $this->get_provider_unavailabilities_for_period(
                (int) ($provider['id'] ?? 0),
                $start_date,
                $end_date,
                $exclude_appointment_id,
            ),
        );
        $blocked_periods = $this->get_blocked_periods_for_analysis_range($range_start, $range_end);
        $appointments_by_date = $this->index_events_by_date($appointments, $range_start, $range_end);
        $blocked_periods_by_date = $this->index_events_by_date($blocked_periods, $range_start, $range_end);
        $offered_hours_by_date = [];
        $day = $range_start;

        while ($day <= $range_end) {
            $date = $day->format('Y-m-d');

            $available_periods = $this->get_available_periods_from_events(
                $date,
                $provider,
                $appointments_by_date[$date] ?? [],
                $blocked_periods_by_date[$date] ?? [],
            );

            $offered_hours_by_date[$date] = array_values(
                $this->generate_available_hours($date, $service, $available_periods),
            );
            $day = $day->add(new DateInterval('P1D'));
        }

        return $offered_hours_by_date;
    }

    /**
     * Get planned hours for each date in a selected range without subtracting booked appointments.
     *
     * @param string $start_date Range start date (Y-m-d).
     * @param string $end_date Range end date (Y-m-d).
     * @param array $service Service data.
     * @param array $provider Provider data.
     * @param int|null $exclude_appointment_id Exclude an appointment from the availability generation.
     *
     * @return array<string, array<int, string>>
     *
     * @throws Exception
     */
    public function get_planned_hours_by_date_for_analysis(
        string $start_date,
        string $end_date,
        array $service,
        array $provider,
        ?int $exclude_appointment_id = null,
    ): array {
        $range_start = new DateTimeImmutable($start_date);
        $range_end = new DateTimeImmutable($end_date);

        if ($range_end < $range_start) {
            return [];
        }

        $unavailabilities = $this->get_provider_unavailabilities_for_period(
            (int) ($provider['id'] ?? 0),
            $start_date,
            $end_date,
            $exclude_appointment_id,
        );
        $unavailabilities = $this->exclude_buffer_blocks_from_planned_unavailabilities($unavailabilities);
        $blocked_periods = $this->get_blocked_periods_for_analysis_range($range_start, $range_end);
        $unavailabilities_by_date = $this->index_events_by_date($unavailabilities, $range_start, $range_end);
        $blocked_periods_by_date = $this->index_events_by_date($blocked_periods, $range_start, $range_end);
        $planned_hours_by_date = [];
        $day = $range_start;

        while ($day <= $range_end) {
            $date = $day->format('Y-m-d');
            $available_periods = $this->get_available_periods_from_events(
                $date,
                $provider,
                $unavailabilities_by_date[$date] ?? [],
                $blocked_periods_by_date[$date] ?? [],
            );

            $planned_hours_by_date[$date] = array_values(
                $this->generate_available_hours($date, $service, $available_periods),
            );
            $day = $day->add(new DateInterval('P1D'));
        }

        return $planned_hours_by_date;
    }

    /**
     * Ignore booking-created buffer blocks when measuring planned capacity.
     *
     * @param array<int, array<string, mixed>> $unavailabilities
     *
     * @return array<int, array<string, mixed>>
     */
    protected function exclude_buffer_blocks_from_planned_unavailabilities(array $unavailabilities): array
    {
        return array_values(
            array_filter(
                $unavailabilities,
                static fn(array $unavailability): bool => empty($unavailability['id_parent_appointment']),
            ),
        );
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
                    $period['start'] = $break_end;
                    continue;
                }

                if (
                    $break_start >= $period_start &&
                    $break_start <= $period_end &&
                    $break_end >= $period_start &&
                    $break_end <= $period_end
                ) {
                    $period['end'] = $break_start;
                    $periods[] = [
                        'start' => $break_end,
                        'end' => $period_end,
                    ];
                    continue;
                }

                if ($break_start >= $period_start && $break_start <= $period_end && $break_end >= $period_end) {
                    $period['end'] = $break_start;
                    continue;
                }

                if ($break_start <= $period_start && $break_end >= $period_end) {
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
                    $period['start'] = $unavailability_end;
                    continue;
                }

                if (
                    $unavailability_start >= $period_start &&
                    $unavailability_start <= $period_end &&
                    $unavailability_end >= $period_start &&
                    $unavailability_end <= $period_end
                ) {
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
                    $period['end'] = $unavailability_start;
                    continue;
                }

                if ($unavailability_start <= $period_start && $unavailability_end >= $period_end) {
                    $period['start'] = $unavailability_end;
                }
            }
        }

        return $periods;
    }

    /**
     * Get an array containing the free time periods (start - end) of a selected date.
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
        $appointments = array_values(
            array_merge(
                $this->get_provider_appointments_for_date((int) $provider['id'], $date, $exclude_appointment_id),
                $this->get_provider_unavailabilities_for_date((int) $provider['id'], $date, $exclude_appointment_id),
            ),
        );
        $blocked_periods = $this->CI->blocked_periods_model->get_for_period($date, $date);

        return $this->get_available_periods_from_events($date, $provider, $appointments, $blocked_periods);
    }

    /**
     * Build available periods for a date from already loaded overlapping events.
     *
     * @param string $date Selected date (Y-m-d).
     * @param array $provider Provider data.
     * @param array $appointments Appointment and unavailability rows overlapping the selected date.
     * @param array $blocked_periods Blocked-period rows overlapping the selected date.
     *
     * @return array<int, array{start: string, end: string}>
     */
    protected function get_available_periods_from_events(
        string $date,
        array $provider,
        array $appointments,
        array $blocked_periods,
    ): array {
        $working_plan = json_decode($provider['settings']['working_plan'], true);
        $working_plan_exceptions_json = $provider['settings']['working_plan_exceptions'];
        $working_plan_exceptions = $working_plan_exceptions_json
            ? json_decode($provider['settings']['working_plan_exceptions'], true)
            : [];

        $working_day = strtolower(date('l', strtotime($date)));
        $date_working_plan = $working_plan[$working_day] ?? null;

        if (array_key_exists($date, $working_plan_exceptions)) {
            $date_working_plan = $working_plan_exceptions[$date];
        }

        if (
            !$date_working_plan ||
            !is_array($date_working_plan) ||
            empty($date_working_plan['start']) ||
            empty($date_working_plan['end'])
        ) {
            return [];
        }

        $periods = [
            [
                'start' => $date_working_plan['start'],
                'end' => $date_working_plan['end'],
            ],
        ];
        $day_start = new DateTime($date_working_plan['start']);
        $day_end = new DateTime($date_working_plan['end']);

        foreach ($date_working_plan['breaks'] ?? [] as $break) {
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

        $day_start = new DateTimeImmutable($date . ' 00:00:00');
        $day_end = new DateTimeImmutable($date . ' 23:59:59');
        $period_ranges = $this->normalize_period_ranges($date, $periods);
        $appointment_ranges = $this->normalize_appointment_ranges($appointments, $day_start, $day_end);
        $blocked_period_ranges = $this->normalize_appointment_ranges($blocked_periods, $day_start, $day_end);
        $period_ranges = $this->subtract_ranges($period_ranges, $appointment_ranges);
        $period_ranges = $this->subtract_ranges($period_ranges, $blocked_period_ranges);

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
        return $this->get_provider_appointments_for_period($provider_id, $date, $date, $exclude_appointment_id);
    }

    /**
     * Load provider appointments that overlap a selected date range.
     *
     * @param int $provider_id Provider ID.
     * @param string $start_date Range start date (Y-m-d).
     * @param string $end_date Range end date (Y-m-d).
     * @param int|null $exclude_appointment_id Appointment ID to exclude from the result set.
     *
     * @return array
     */
    protected function get_provider_appointments_for_period(
        int $provider_id,
        string $start_date,
        string $end_date,
        ?int $exclude_appointment_id = null,
    ): array {
        if ($provider_id <= 0) {
            return [];
        }

        $day_start = $start_date . ' 00:00:00';
        $day_end = $end_date . ' 23:59:59';

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
        return $this->get_provider_unavailabilities_for_period($provider_id, $date, $date, $exclude_appointment_id);
    }

    /**
     * Load provider unavailabilities that overlap a selected date range.
     *
     * @param int $provider_id Provider ID.
     * @param string $start_date Range start date (Y-m-d).
     * @param string $end_date Range end date (Y-m-d).
     * @param int|null $exclude_appointment_id Appointment ID to exclude from the result set.
     *
     * @return array
     */
    protected function get_provider_unavailabilities_for_period(
        int $provider_id,
        string $start_date,
        string $end_date,
        ?int $exclude_appointment_id = null,
    ): array {
        if ($provider_id <= 0) {
            return [];
        }

        $day_start = $start_date . ' 00:00:00';
        $day_end = $end_date . ' 23:59:59';

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
     * Group overlapping events by date for reuse across analytical range calculations.
     *
     * @param array $events Event rows containing start/end datetime fields.
     * @param DateTimeImmutable|null $range_start Inclusive clip start for analytical windows.
     * @param DateTimeImmutable|null $range_end Inclusive clip end for analytical windows.
     *
     * @return array<string, array<int, array>>
     */
    protected function index_events_by_date(
        array $events,
        ?DateTimeImmutable $range_start = null,
        ?DateTimeImmutable $range_end = null,
    ): array {
        $events_by_date = [];

        foreach ($events as $event) {
            $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($event['start_datetime'] ?? ''));
            $end = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($event['end_datetime'] ?? ''));

            if (!$start || !$end || $end <= $start) {
                continue;
            }

            $day = $start->setTime(0, 0, 0);
            $last_day = $end->setTime(0, 0, 0);

            if ($range_start) {
                $clipped_start = $range_start->setTime(0, 0, 0);
                if ($day < $clipped_start) {
                    $day = $clipped_start;
                }
            }

            if ($range_end) {
                $clipped_end = $range_end->setTime(0, 0, 0);
                if ($last_day > $clipped_end) {
                    $last_day = $clipped_end;
                }
            }

            if ($last_day < $day) {
                continue;
            }

            while ($day <= $last_day) {
                $events_by_date[$day->format('Y-m-d')][] = $event;
                $day = $day->add(new DateInterval('P1D'));
            }
        }

        return $events_by_date;
    }

    /**
     * Reuse the daily path for multi-attendant services until that product scope changes.
     *
     * @param DateTimeImmutable $range_start
     * @param DateTimeImmutable $range_end
     * @param array $service
     * @param array $provider
     * @param int|null $exclude_appointment_id
     *
     * @return array<string, array<int, string>>
     *
     * @throws Exception
     */
    protected function get_offered_hours_by_date_using_daily_fallback(
        DateTimeImmutable $range_start,
        DateTimeImmutable $range_end,
        array $service,
        array $provider,
        ?int $exclude_appointment_id = null,
    ): array {
        $offered_hours_by_date = [];
        $day = $range_start;

        while ($day <= $range_end) {
            $date = $day->format('Y-m-d');
            $offered_hours_by_date[$date] = $this->get_offered_hours_for_analysis(
                $date,
                $service,
                $provider,
                $exclude_appointment_id,
            );
            $day = $day->add(new DateInterval('P1D'));
        }

        return $offered_hours_by_date;
    }

    /**
     * Expand blocked-period loading by one day on each side so range analytics keep the daily overlap semantics.
     *
     * @return array
     */
    protected function get_blocked_periods_for_analysis_range(
        DateTimeImmutable $range_start,
        DateTimeImmutable $range_end,
    ): array {
        $query_start = $range_start->sub(new DateInterval('P1D'))->format('Y-m-d');
        $query_end = $range_end->add(new DateInterval('P1D'))->format('Y-m-d');

        return $this->CI->blocked_periods_model->get_for_period($query_start, $query_end);
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
}
