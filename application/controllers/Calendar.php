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
 * Calendar controller.
 *
 * Handles calendar related operations.
 *
 * @package Controllers
 */
class Calendar extends EA_Controller
{
    private const CUSTOMER_READ_FIELDS = [
        'id',
        'first_name',
        'last_name',
        'email',
        'phone_number',
        'address',
        'city',
        'zip_code',
        'language',
        'timezone',
        'notes',
        'custom_field_1',
        'custom_field_2',
        'custom_field_3',
        'custom_field_4',
        'custom_field_5',
    ];

    private const APPOINTMENT_READ_FIELDS = [
        'id',
        'start_datetime',
        'end_datetime',
        'location',
        'notes',
        'color',
        'status',
        'id_users_provider',
        'id_users_customer',
        'id_services',
        'customer',
    ];

    private const CALENDAR_APPOINTMENT_READ_FIELDS = [
        'id',
        'start_datetime',
        'end_datetime',
        'location',
        'notes',
        'color',
        'status',
        'is_unavailability',
        'id_users_provider',
        'id_users_customer',
        'id_services',
        'provider',
        'service',
        'customer',
    ];

    private const CALENDAR_UNAVAILABILITY_READ_FIELDS = [
        'id',
        'start_datetime',
        'end_datetime',
        'notes',
        'is_unavailability',
        'id_users_provider',
        'id_parent_appointment',
        'provider',
    ];

    private const CALENDAR_PROVIDER_READ_FIELDS = [
        'id',
        'first_name',
        'last_name',
        'address',
        'city',
        'state',
        'zip_code',
        'timezone',
        'settings',
    ];

    private const CALENDAR_SERVICE_READ_FIELDS = ['id', 'name'];

    public array $allowed_customer_fields = [
        'id',
        'first_name',
        'last_name',
        'email',
        'phone_number',
        'address',
        'city',
        'state',
        'zip_code',
        'timezone',
        'language',
        'notes',
        'custom_field_1',
        'custom_field_2',
        'custom_field_3',
        'custom_field_4',
        'custom_field_5',
    ];

    public array $optional_customer_fields = [
        //
    ];

    public array $allowed_appointment_fields = [
        'id',
        'start_datetime',
        'end_datetime',
        'location',
        'notes',
        'color',
        'status',
        'is_unavailability',
        'id_users_provider',
        'id_users_customer',
        'id_services',
    ];

    public array $optional_appointment_fields = [
        //
    ];

    /**
     * Calendar constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->model('appointments_model');
        $this->load->model('unavailabilities_model');
        $this->load->model('blocked_periods_model');
        $this->load->model('customers_model');
        $this->load->model('services_model');
        $this->load->model('providers_model');
        $this->load->model('roles_model');

        $this->load->library('accounts');
        $this->load->library('permissions');
        $this->load->library('timezones');
    }

    private function requirePostForCalendarWrite(): bool
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST') {
            return true;
        }

        json_response(['success' => false, 'message' => 'Method Not Allowed'], 405, ['Allow: POST']);

        return false;
    }

    private function currentCalendarReadRole(int $user_id): string
    {
        if ($user_id <= 0) {
            throw new RuntimeException('You do not have the required permissions for this task.', 403);
        }

        $role_id = (int) $this->users_model->value($user_id, 'id_roles');
        $role_slug = (string) $this->roles_model->value($role_id, 'slug');
        $privileges = $this->roles_model->get_permissions_by_slug($role_slug);

        if (empty($privileges[PRIV_APPOINTMENTS]['view'])) {
            throw new RuntimeException('You do not have the required permissions for this task.', 403);
        }

        return $role_slug;
    }

    /**
     * Render the calendar page and display the selected appointment.
     *
     * This method will call the "index" callback to handle the page rendering.
     *
     * @param string $appointment_hash Appointment hash.
     */
    public function reschedule(string $appointment_hash): void
    {
        $this->index($appointment_hash);
    }

    /**
     * Display the main backend page.
     *
     * This method displays the main backend page. All login permission can view this page which displays a calendar
     * with the events of the selected provider or service. If a user has more privileges he will see more menus at the
     * top of the page.
     *
     * @param string $appointment_hash Appointment hash.
     */
    public function index(string $appointment_hash = ''): void
    {
        session([
            'dest_url' => site_url('calendar/index' . (!empty($appointment_hash) ? '/' . $appointment_hash : '')),
        ]);

        $user_id = session('user_id');

        if (!$user_id) {
            redirect('login');

            return;
        }

        $user = $this->users_model->find($user_id);
        $role_slug = $this->roles_model->value((int) $user['id_roles'], 'slug');
        $privileges = $this->roles_model->get_permissions_by_slug($role_slug);

        if (empty($privileges[PRIV_APPOINTMENTS]['view'])) {
            abort(403, 'Forbidden');

            return;
        }

        $secretary_providers = [];

        if ($role_slug === DB_SLUG_SECRETARY) {
            $secretary = $this->secretaries_model->find(session('user_id'));

            $secretary_providers = $secretary['providers'];
        }

        $edit_appointment = null;

        if (!empty($appointment_hash)) {
            $occurrences = $this->appointments_model->get(['hash' => $appointment_hash]);

            if (count($occurrences) > 1) {
                abort(404, 'Not Found');

                return;
            }

            if ($appointment_hash !== '' && !empty($occurrences)) {
                $edit_appointment = $occurrences[0];

                $provider_id = (int) ($edit_appointment['id_users_provider'] ?? 0);
                $customer_id = (int) ($edit_appointment['id_users_customer'] ?? 0);
                $provider_in_scope =
                    $role_slug === DB_SLUG_ADMIN ||
                    ($role_slug === DB_SLUG_PROVIDER && $provider_id === (int) $user_id) ||
                    ($role_slug === DB_SLUG_SECRETARY &&
                        in_array($provider_id, array_map('intval', $secretary_providers), true));

                if (
                    !$provider_in_scope ||
                    !$customer_id ||
                    empty($privileges[PRIV_CUSTOMERS]['view']) ||
                    !$this->permissions->has_customer_access((int) $user_id, $customer_id)
                ) {
                    abort(403, 'Forbidden');

                    return;
                }

                $this->appointments_model->load($edit_appointment, ['customer']);

                $customer = $edit_appointment['customer'];
                $this->customers_model->only($customer, self::CUSTOMER_READ_FIELDS);
                $edit_appointment['customer'] = $customer;
                $this->appointments_model->only($edit_appointment, self::APPOINTMENT_READ_FIELDS);
            }
        }

        $available_providers = $this->providers_model->get_available_providers();

        if ($role_slug === DB_SLUG_PROVIDER) {
            $available_providers = array_values(
                array_filter($available_providers, function ($available_provider) use ($user_id) {
                    return (int) $available_provider['id'] === (int) $user_id;
                }),
            );
        }

        if ($role_slug === DB_SLUG_SECRETARY) {
            $available_providers = array_values(
                array_filter($available_providers, function ($available_provider) use ($secretary_providers) {
                    return in_array($available_provider['id'], $secretary_providers);
                }),
            );
        }

        $available_providers = array_map($this->calendarProviderData(...), $available_providers);

        $available_services = $this->services_model->get_available_services();

        $recent_customers = [];

        if (!empty($privileges[PRIV_CUSTOMERS]['view'])) {
            if ($role_slug !== DB_SLUG_ADMIN && setting('limit_customer_access')) {
                $provider_ids = match ($role_slug) {
                    DB_SLUG_PROVIDER => [(int) $user_id],
                    DB_SLUG_SECRETARY => array_map('intval', $secretary_providers),
                    default => [],
                };
                $customer_role_id = $this->customers_model->get_customer_role_id();
                $customers = $provider_ids
                    ? $this->db
                        ->distinct()
                        ->select('users.*')
                        ->from('users')
                        ->join('appointments', 'appointments.id_users_customer = users.id')
                        ->where('users.id_roles', $customer_role_id)
                        ->where_in('appointments.id_users_provider', $provider_ids)
                        ->order_by('users.update_datetime', 'DESC')
                        ->limit(50)
                        ->get()
                        ->result_array()
                    : [];
            } else {
                $customers = $this->customers_model->get(null, 50, null, 'update_datetime DESC');
            }

            foreach ($customers as $customer) {
                $customer['id'] = (int) $customer['id'];
                $this->customers_model->only($customer, self::CUSTOMER_READ_FIELDS);
                $recent_customers[] = $customer;
            }
        }

        $calendar_view_request = $this->calendarRequestDtoFactory()->buildViewRequestDto(
            $user['settings']['calendar_view'],
        );
        $calendar_view = $calendar_view_request->calendarView;

        $appointment_status_options = setting('appointment_status_options');

        script_vars([
            'user_id' => $user_id,
            'role_slug' => $role_slug,
            'date_format' => setting('date_format'),
            'time_format' => setting('time_format'),
            'first_weekday' => setting('first_weekday'),
            'company_working_plan' => setting('company_working_plan'),
            'timezones' => $this->timezones->to_array(),
            'privileges' => $privileges,
            'calendar_view' => $calendar_view,
            'available_providers' => $available_providers,
            'available_services' => $available_services,
            'secretary_providers' => $secretary_providers,
            'edit_appointment' => $edit_appointment,
            'customers' => $recent_customers,
            'default_language' => setting('default_language'),
            'default_timezone' => setting('default_timezone'),
        ]);

        html_vars([
            'page_title' => lang('calendar'),
            'active_menu' => PRIV_APPOINTMENTS,
            'user_display_name' => $this->accounts->get_user_display_name($user_id),
            'timezone' => session('timezone'),
            'timezones' => $this->timezones->to_array(),
            'grouped_timezones' => $this->timezones->to_grouped_array(),
            'privileges' => $privileges,
            'role_slug' => $role_slug,
            'calendar_can_add' => !empty($privileges[PRIV_APPOINTMENTS]['add']),
            'calendar_can_edit_users' => !empty($privileges[PRIV_USERS]['edit']),
            'calendar_view' => $calendar_view,
            'available_providers' => $available_providers,
            'available_services' => $available_services,
            'secretary_providers' => $secretary_providers,
            'appointment_status_options' => json_decode($appointment_status_options, true) ?? [],
            'require_first_name' => setting('require_first_name'),
            'require_last_name' => setting('require_last_name'),
            'require_email' => setting('require_email'),
            'require_phone_number' => setting('require_phone_number'),
            'require_address' => setting('require_address'),
            'require_city' => setting('require_city'),
            'require_zip_code' => setting('require_zip_code'),
            'require_notes' => setting('require_notes'),
        ]);

        $this->load->view('pages/calendar');
    }

    /**
     * Save appointment changes that are made from the backend calendar page.
     */
    public function save_appointment(): void
    {
        if (!$this->requirePostForCalendarWrite()) {
            return;
        }

        try {
            $request_dto = $this->calendarRequestDtoFactory()->buildSaveAppointmentRequestDto();
            $customer_data = $request_dto->customerData;
            $appointment_data = $request_dto->appointmentData;
            $manage_mode = !empty($appointment_data['id']);
            $stored_appointment = null;

            if ($manage_mode) {
                $stored_appointment = $this->appointments_model->find((int) $appointment_data['id']);
                $this->check_event_permissions((int) $stored_appointment['id_users_provider']);
            }

            $this->check_event_permissions((int) $appointment_data['id_users_provider']);

            foreach ([$customer_data['id'] ?? null, $appointment_data['id_users_customer'] ?? null] as $customer_id) {
                if (
                    !empty($customer_id) &&
                    !$this->permissions->has_customer_access((int) session('user_id'), $customer_id)
                ) {
                    throw new RuntimeException('You do not have the required permissions for this task.', 403);
                }
            }

            if (!$this->db->trans_begin()) {
                throw new RuntimeException('Could not start appointment transaction.');
            }

            try {
                $this->lock_calendar_update_parents($stored_appointment ?? [], $appointment_data, [
                    $customer_data['id'] ?? null,
                    (int) session('user_id'),
                ]);

                if ($manage_mode) {
                    if ($stored_appointment === null) {
                        throw new RuntimeException('The appointment state could not be loaded.');
                    }

                    $locked_appointment = $this->lock_appointment((int) $appointment_data['id']);

                    if (!$this->has_event_permissions((int) $locked_appointment['id_users_provider'])) {
                        throw new RuntimeException('You do not have the required permissions for this task.', 403);
                    }

                    if ($this->appointment_parent_ids_changed($stored_appointment, $locked_appointment)) {
                        throw new RuntimeException(lang('requested_hour_is_unavailable'));
                    }
                }

                if (!$this->has_event_permissions((int) $appointment_data['id_users_provider'])) {
                    throw new RuntimeException('You do not have the required permissions for this task.', 403);
                }

                foreach (
                    [$customer_data['id'] ?? null, $appointment_data['id_users_customer'] ?? null]
                    as $customer_id
                ) {
                    if (
                        !empty($customer_id) &&
                        !$this->permissions->has_customer_access((int) session('user_id'), $customer_id)
                    ) {
                        throw new RuntimeException('You do not have the required permissions for this task.', 403);
                    }
                }

                // Save customer changes to the database.
                if ($customer_data) {
                    $customer = $customer_data;

                    $required_permissions = !empty($customer['id'])
                        ? $this->currentCalendarCan('edit', PRIV_CUSTOMERS)
                        : $this->currentCalendarCan('add', PRIV_CUSTOMERS);

                    if (!$required_permissions) {
                        throw new RuntimeException('You do not have the required permissions for this task.', 403);
                    }

                    $this->customers_model->only($customer, $this->allowed_customer_fields);

                    $this->customers_model->optional($customer, $this->optional_customer_fields);

                    $customer['id'] = $this->customers_model->save($customer);
                }

                // Save appointment changes to the database.
                if ($appointment_data) {
                    $appointment = $appointment_data;

                    $required_permissions = !empty($appointment['id'])
                        ? $this->currentCalendarCan('edit', PRIV_APPOINTMENTS)
                        : $this->currentCalendarCan('add', PRIV_APPOINTMENTS);

                    if (!$required_permissions) {
                        throw new RuntimeException('You do not have the required permissions for this task.', 403);
                    }

                    // If the appointment does not contain the customer record id, then it means that is going to be inserted.

                    if (!isset($appointment['id_users_customer'])) {
                        $appointment['id_users_customer'] = $customer['id'] ?? $customer_data['id'];
                    }

                    $this->appointments_model->only($appointment, $this->allowed_appointment_fields);

                    $this->appointments_model->optional($appointment, $this->optional_appointment_fields);

                    $appointment['id'] = $this->appointments_model->save($appointment);
                }

                if (empty($appointment['id'])) {
                    throw new RuntimeException('The appointment ID is not available.');
                }

                if ($this->db->trans_status() === false) {
                    throw new RuntimeException('Could not save appointment transaction.');
                }

                $appointment = $this->appointments_model->find($appointment['id']);
                $provider = $this->providers_model->find($appointment['id_users_provider']);
                $customer = $this->customers_model->find($appointment['id_users_customer']);
                $service = $this->services_model->find($appointment['id_services']);

                if (!$this->db->trans_commit()) {
                    throw new RuntimeException('Could not commit appointment transaction.');
                }
            } catch (Throwable $e) {
                $this->db->trans_rollback();

                throw $e;
            }

            json_response([
                'success' => true,
            ]);
        } catch (Throwable $e) {
            $expected_status = $this->get_appointment_save_expected_error_status($e);

            if ($expected_status !== null) {
                json_response(
                    [
                        'success' => false,
                        'message' => $e->getMessage(),
                    ],
                    $expected_status,
                );

                return;
            }

            json_exception($e);
        }
    }

    protected function get_appointment_save_expected_error_status(Throwable $e): ?int
    {
        if ($e->getCode() === 403 && $e->getMessage() === 'You do not have the required permissions for this task.') {
            return 403;
        }

        $expected_conflict_messages = [
            lang('buffer_conflict_error'),
            lang('buffer_outside_schedule_error'),
            lang('requested_hour_is_unavailable'),
        ];

        return in_array($e->getMessage(), $expected_conflict_messages, true) ? 409 : null;
    }

    private function check_event_permissions(int $provider_id): void
    {
        if (!$this->has_event_permissions($provider_id)) {
            abort(403);
        }
    }

    private function has_event_permissions(int $provider_id): bool
    {
        $user_id = (int) session('user_id');
        if ($user_id <= 0) {
            return false;
        }

        $CI = &get_instance();
        $CI->load->model('users_model');
        $CI->load->model('roles_model');
        $role_id = (int) $CI->users_model->value($user_id, 'id_roles');
        $role_slug = (string) $CI->roles_model->value($role_id, 'slug');

        return match ($role_slug) {
            DB_SLUG_ADMIN => true,
            DB_SLUG_PROVIDER => $user_id === $provider_id,
            DB_SLUG_SECRETARY => $this->secretaries_model->is_provider_supported($user_id, $provider_id),
            default => false,
        };
    }

    private function currentCalendarCan(string $action, string $resource): bool
    {
        $user_id = (int) session('user_id');

        return $user_id > 0 && can($action, $resource, $user_id);
    }

    protected function lock_calendar_update_parents(
        array $current_appointment,
        array $requested_appointment,
        array $additional_user_ids = [],
    ): void {
        $this->appointments_model->lock_update_parents(
            $current_appointment,
            $requested_appointment,
            $additional_user_ids,
        );
    }

    protected function lock_appointment(int $appointment_id): array
    {
        $appointment = $this->db
            ->query('SELECT * FROM `' . $this->db->dbprefix('appointments') . '` WHERE `id` = ? FOR UPDATE', [
                $appointment_id,
            ])
            ->row_array();

        if (!$appointment) {
            throw new InvalidArgumentException(
                'The provided appointment ID was not found in the database: ' . $appointment_id,
            );
        }

        return $appointment;
    }

    private function appointment_parent_ids_changed(array $stored_appointment, array $locked_appointment): bool
    {
        foreach (['id_users_customer', 'id_users_provider', 'id_services'] as $field) {
            if ((int) ($stored_appointment[$field] ?? 0) !== (int) ($locked_appointment[$field] ?? 0)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Delete appointment from the database.
     *
     * This method deletes an existing appointment from the database. Once this action is finished it cannot be undone.
     * Appointment changes are persisted through the calendar write path.
     */
    public function delete_appointment(): void
    {
        if (!$this->requirePostForCalendarWrite()) {
            return;
        }

        try {
            if (!$this->currentCalendarCan('delete', PRIV_APPOINTMENTS)) {
                throw new RuntimeException('You do not have the required permissions for this task.', 403);
            }

            $request_dto = $this->calendarRequestDtoFactory()->buildDeleteAppointmentRequestDto();
            $appointment_id = $request_dto->appointmentId;

            if (empty($appointment_id)) {
                throw new InvalidArgumentException('No appointment id provided.');
            }

            // Store appointment data for later use in this method.
            $appointment = $this->appointments_model->find($appointment_id);

            $this->check_event_permissions((int) $appointment['id_users_provider']);

            if (!$this->db->trans_begin()) {
                throw new RuntimeException('Could not start appointment transaction.');
            }

            try {
                $this->lock_calendar_update_parents($appointment, [], [(int) session('user_id')]);
                $locked_appointment = $this->lock_appointment($appointment_id);

                if ($this->appointment_parent_ids_changed($appointment, $locked_appointment)) {
                    throw new RuntimeException('You do not have the required permissions for this task.', 403);
                }

                if (
                    !$this->currentCalendarCan('delete', PRIV_APPOINTMENTS) ||
                    !$this->has_event_permissions((int) $locked_appointment['id_users_provider'])
                ) {
                    throw new RuntimeException('You do not have the required permissions for this task.', 403);
                }

                $this->providers_model->find($locked_appointment['id_users_provider']);
                $this->customers_model->find($locked_appointment['id_users_customer']);
                $this->services_model->find($locked_appointment['id_services']);

                // The model's nested transaction retains the parent and appointment locks.
                $this->appointments_model->delete($appointment_id);

                if (!$this->db->trans_commit()) {
                    throw new RuntimeException('Could not commit appointment transaction.');
                }
            } catch (Throwable $e) {
                $this->db->trans_rollback();

                throw $e;
            }

            json_response([
                'success' => true,
            ]);
        } catch (Throwable $e) {
            if (
                $e->getCode() === 403 &&
                $e->getMessage() === 'You do not have the required permissions for this task.'
            ) {
                json_response(['success' => false, 'message' => $e->getMessage()], 403);
            } else {
                json_exception($e);
            }
        }
    }

    /**
     * Insert of update unavailability to database.
     */
    public function save_unavailability(): void
    {
        if (!$this->requirePostForCalendarWrite()) {
            return;
        }

        try {
            $warnings = [];

            // Check privileges
            $request_dto = $this->calendarRequestDtoFactory()->buildUnavailabilityRequestDto();
            $unavailability = $request_dto->unavailability;

            $this->validate_calendar_unavailability_payload($unavailability);

            $this->unavailabilities_model->only($unavailability, [
                'id',
                'start_datetime',
                'end_datetime',
                'notes',
                'id_users_provider',
                'location',
                'color',
                'status',
            ]);

            $required_action = empty($unavailability['id']) ? 'add' : 'edit';
            $required_permissions = $this->currentCalendarCan($required_action, PRIV_APPOINTMENTS);

            if (!$required_permissions) {
                throw new RuntimeException('You do not have the required permissions for this task.');
            }

            $stored_unavailability = null;

            if (!empty($unavailability['id'])) {
                $stored_unavailability = $this->unavailabilities_model->find((int) $unavailability['id']);
                $this->check_event_permissions((int) $stored_unavailability['id_users_provider']);
            }

            $provider_id = (int) $unavailability['id_users_provider'];

            $this->check_event_permissions($provider_id);

            if (!$this->db->trans_begin()) {
                throw new RuntimeException('Could not start unavailability transaction.');
            }

            try {
                $this->lock_calendar_update_parents($stored_unavailability ?? [], $unavailability, [
                    (int) session('user_id'),
                ]);

                if ($stored_unavailability !== null) {
                    $locked_unavailability = $this->lock_manual_unavailability((int) $unavailability['id']);

                    if (
                        (int) $locked_unavailability['id_users_provider'] !==
                        (int) $stored_unavailability['id_users_provider']
                    ) {
                        throw new RuntimeException('The unavailability provider changed during this request.', 403);
                    }

                    if (!$this->has_event_permissions((int) $locked_unavailability['id_users_provider'])) {
                        throw new RuntimeException('You do not have the required permissions for this task.', 403);
                    }
                }

                if (!$this->currentCalendarCan($required_action, PRIV_APPOINTMENTS)) {
                    throw new RuntimeException('You do not have the required permissions for this task.', 403);
                }

                if (!$this->has_event_permissions($provider_id)) {
                    throw new RuntimeException('You do not have the required permissions for this task.', 403);
                }

                $this->providers_model->find($provider_id);

                $unavailability_id = $this->unavailabilities_model->save($unavailability);

                if ($this->db->trans_status() === false || !$this->db->trans_commit()) {
                    throw new RuntimeException('Could not commit unavailability transaction.');
                }
            } catch (Throwable $e) {
                $this->db->trans_rollback();

                throw $e;
            }

            $unavailability = $this->unavailabilities_model->find($unavailability_id);

            json_response([
                'success' => true,
                'warnings' => $warnings,
            ]);
        } catch (Throwable $e) {
            if ($e->getCode() === 403) {
                json_response(['success' => false, 'message' => $e->getMessage()], 403);
            } else {
                json_exception($e);
            }
        }
    }

    /**
     * Delete an unavailability from database.
     */
    public function delete_unavailability(): void
    {
        if (!$this->requirePostForCalendarWrite()) {
            return;
        }

        try {
            if (!$this->currentCalendarCan('delete', PRIV_APPOINTMENTS)) {
                throw new RuntimeException('You do not have the required permissions for this task.');
            }

            $request_dto = $this->calendarRequestDtoFactory()->buildEntityIdRequestDto('unavailability_id');
            $unavailability_id = $request_dto->id;

            $unavailability = $this->unavailabilities_model->find($unavailability_id);

            $this->check_event_permissions((int) $unavailability['id_users_provider']);

            $provider = $this->providers_model->find($unavailability['id_users_provider']);

            if (!$this->db->trans_begin()) {
                throw new RuntimeException('Could not start unavailability transaction.');
            }

            try {
                $this->lock_calendar_update_parents($unavailability, $unavailability, [(int) session('user_id')]);

                $locked_unavailability = $this->lock_manual_unavailability($unavailability_id);

                if (
                    (int) $locked_unavailability['id_users_provider'] !== (int) $unavailability['id_users_provider'] ||
                    !$this->currentCalendarCan('delete', PRIV_APPOINTMENTS) ||
                    !$this->has_event_permissions((int) $locked_unavailability['id_users_provider'])
                ) {
                    throw new RuntimeException('You do not have the required permissions for this task.', 403);
                }

                $this->unavailabilities_model->delete($unavailability_id);

                if ($this->db->trans_status() === false || !$this->db->trans_commit()) {
                    throw new RuntimeException('Could not commit unavailability transaction.');
                }
            } catch (Throwable $e) {
                $this->db->trans_rollback();

                throw $e;
            }

            json_response([
                'success' => true,
            ]);
        } catch (Throwable $e) {
            if ($e->getCode() === 403) {
                json_response(['success' => false, 'message' => $e->getMessage()], 403);
            } else {
                json_exception($e);
            }
        }
    }

    private function validate_calendar_unavailability_payload(array $unavailability): void
    {
        if (
            (array_key_exists('is_unavailability', $unavailability) &&
                !in_array($unavailability['is_unavailability'], [true, 1, '1'], true)) ||
            (array_key_exists('id_parent_appointment', $unavailability) &&
                $unavailability['id_parent_appointment'] !== null)
        ) {
            throw new InvalidArgumentException('Protected unavailability fields cannot be changed.');
        }
    }

    protected function lock_manual_unavailability(int $unavailability_id): array
    {
        $unavailability = $this->db
            ->query(
                'SELECT * FROM `' .
                    $this->db->dbprefix('appointments') .
                    '` WHERE `id` = ? AND `is_unavailability` = 1 AND `id_parent_appointment` IS NULL FOR UPDATE',
                [$unavailability_id],
            )
            ->row_array();

        if (!$unavailability) {
            throw new InvalidArgumentException('Manual unavailability no longer exists.');
        }

        return $unavailability;
    }

    /**
     * Insert of update working plan exceptions to database.
     */
    public function save_working_plan_exception(): void
    {
        if (!$this->requirePostForCalendarWrite()) {
            return;
        }

        try {
            if (!$this->currentCalendarCan('edit', PRIV_USERS)) {
                throw new RuntimeException('You do not have the required permissions for this task.');
            }

            $request_dto = $this->calendarRequestDtoFactory()->buildWorkingPlanExceptionRequestDto();
            $date = $request_dto->date;
            $original_date = $request_dto->originalDate;
            $working_plan_exception = $request_dto->workingPlanException;

            if (!$working_plan_exception) {
                $working_plan_exception = null;
            }

            $provider_id = $request_dto->providerId;

            $this->providers_model->save_working_plan_exception($provider_id, $date, $working_plan_exception);

            if ($original_date && $date !== $original_date) {
                $this->providers_model->delete_working_plan_exception($provider_id, $original_date);
            }

            json_response([
                'success' => true,
            ]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Delete a working plan exceptions time period to database.
     */
    public function delete_working_plan_exception(): void
    {
        if (!$this->requirePostForCalendarWrite()) {
            return;
        }

        try {
            if (!$this->currentCalendarCan('edit', PRIV_USERS)) {
                throw new RuntimeException('You do not have the required permissions for this task.');
            }

            $request_dto = $this->calendarRequestDtoFactory()->buildWorkingPlanExceptionRequestDto();
            $date = $request_dto->date;
            $provider_id = $request_dto->providerId;

            $this->providers_model->delete_working_plan_exception($provider_id, $date);

            json_response([
                'success' => true,
            ]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Get Calendar Events
     *
     * This method will return all the calendar events within a specified period.
     */
    public function get_calendar_appointments_for_table_view(): void
    {
        try {
            $user_id = (int) session('user_id');
            $role_slug = $this->currentCalendarReadRole($user_id);
            $can_view_customers = can('view', PRIV_CUSTOMERS, $user_id);

            $range_request = $this->calendarRequestDtoFactory()->buildRangeRequestDto();
            $range_start_date = (string) $range_request->startDate;
            $range_end_date = (string) $range_request->endDate;
            $start_date = $range_start_date . ' 00:00:00';
            $end_date = $range_end_date . ' 23:59:59';

            $response = [
                'appointments' => $this->appointments_model->get([
                    'start_datetime >=' => $start_date,
                    'end_datetime <=' => $end_date,
                ]),
                'unavailabilities' => $this->unavailabilities_model->get([
                    'start_datetime >=' => $start_date,
                    'end_datetime <=' => $end_date,
                ]),
            ];

            // If the current user is a provider he must only see his own appointments.
            if ($role_slug === DB_SLUG_PROVIDER) {
                foreach ($response['appointments'] as $index => $appointment) {
                    if ((int) $appointment['id_users_provider'] !== (int) $user_id) {
                        unset($response['appointments'][$index]);
                    }
                }

                $response['appointments'] = array_values($response['appointments']);

                foreach ($response['unavailabilities'] as $index => $unavailability) {
                    if ((int) $unavailability['id_users_provider'] !== (int) $user_id) {
                        unset($response['unavailabilities'][$index]);
                    }
                }

                $response['unavailabilities'] = array_values($response['unavailabilities']);
            }

            // If the current user is a secretary he must only see the appointments of his providers.
            if ($role_slug === DB_SLUG_SECRETARY) {
                $providers = $this->secretaries_model->find($user_id)['providers'];

                foreach ($response['appointments'] as $index => $appointment) {
                    if (!in_array((int) $appointment['id_users_provider'], $providers)) {
                        unset($response['appointments'][$index]);
                    }
                }

                $response['appointments'] = array_values($response['appointments']);

                foreach ($response['unavailabilities'] as $index => $unavailability) {
                    if (!in_array((int) $unavailability['id_users_provider'], $providers)) {
                        unset($response['unavailabilities'][$index]);
                    }
                }

                $response['unavailabilities'] = array_values($response['unavailabilities']);
            }

            $response['appointments'] = array_map(
                fn(array $appointment): array => $this->calendarAppointmentData($appointment, $can_view_customers),
                $response['appointments'],
            );
            $response['unavailabilities'] = array_map(
                $this->calendarUnavailabilityData(...),
                $response['unavailabilities'],
            );

            // Add blocked periods to the response.
            $response['blocked_periods'] = $this->calendarBlockedPeriods($range_start_date, $range_end_date, $user_id);

            json_response($response);
        } catch (CalendarRangeValidationException $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Get the registered appointments for the given date period and record.
     *
     * This method returns the database appointments and unavailability periods for the user selected date period and
     * record type (provider or service).
     */
    public function get_calendar_appointments(): void
    {
        try {
            $user_id = (int) session('user_id');
            $role_slug = $this->currentCalendarReadRole($user_id);
            $can_view_customers = can('view', PRIV_CUSTOMERS, $user_id);

            $filter_request = $this->calendarRequestDtoFactory()->buildFilterRequestDto();
            $record_id = $filter_request->recordId;
            $is_all = $record_id === FILTER_TYPE_ALL;
            $filter_type = $filter_request->filterType;

            if (!$filter_type && !$is_all) {
                json_response([
                    'appointments' => [],
                    'unavailabilities' => [],
                ]);

                return;
            }

            $record_id = $this->db->escape($record_id);

            if ($filter_type == FILTER_TYPE_PROVIDER) {
                $where_id = 'id_users_provider';
            } elseif ($filter_type === FILTER_TYPE_SERVICE) {
                $where_id = 'id_services';
            } else {
                $where_id = $record_id;
            }

            // Get appointments
            $range_request = $this->calendarRequestDtoFactory()->buildRangeRequestDto();
            $range_start_date = (string) $range_request->startDate;
            $range_end_date = (string) $range_request->endDate;
            $start_date = $this->db->escape($range_start_date);
            $end_date = $this->db->escape(date('Y-m-d', strtotime($range_end_date . ' +1 day')));

            $where_clause =
                $where_id .
                ' = ' .
                $record_id .
                '
                AND ((start_datetime > ' .
                $start_date .
                ' AND start_datetime < ' .
                $end_date .
                ') 
                or (end_datetime > ' .
                $start_date .
                ' AND end_datetime < ' .
                $end_date .
                ') 
                or (start_datetime <= ' .
                $start_date .
                ' AND end_datetime >= ' .
                $end_date .
                ')) 
                AND is_unavailability = 0
            ';

            $response['appointments'] = $this->appointments_model->get($where_clause);

            // Get unavailability periods (only for provider).
            $response['unavailabilities'] = [];

            if ($filter_type == FILTER_TYPE_PROVIDER || $is_all) {
                $where_clause =
                    $where_id .
                    ' = ' .
                    $record_id .
                    '
                    AND ((start_datetime > ' .
                    $start_date .
                    ' AND start_datetime < ' .
                    $end_date .
                    ') 
                    or (end_datetime > ' .
                    $start_date .
                    ' AND end_datetime < ' .
                    $end_date .
                    ') 
                    or (start_datetime <= ' .
                    $start_date .
                    ' AND end_datetime >= ' .
                    $end_date .
                    ')) 
                    AND is_unavailability = 1
                ';

                $response['unavailabilities'] = $this->unavailabilities_model->get($where_clause);
            }

            // If the current user is a provider he must only see his own appointments.
            if ($role_slug === DB_SLUG_PROVIDER) {
                foreach ($response['appointments'] as $index => $appointment) {
                    if ((int) $appointment['id_users_provider'] !== (int) $user_id) {
                        unset($response['appointments'][$index]);
                    }
                }

                $response['appointments'] = array_values($response['appointments']);

                foreach ($response['unavailabilities'] as $index => $unavailability) {
                    if ((int) $unavailability['id_users_provider'] !== (int) $user_id) {
                        unset($response['unavailabilities'][$index]);
                    }
                }

                unset($unavailability);

                $response['unavailabilities'] = array_values($response['unavailabilities']);
            }

            // If the current user is a secretary he must only see the appointments of his providers.
            if ($role_slug === DB_SLUG_SECRETARY) {
                $providers = $this->secretaries_model->find($user_id)['providers'];

                foreach ($response['appointments'] as $index => $appointment) {
                    if (!in_array((int) $appointment['id_users_provider'], $providers)) {
                        unset($response['appointments'][$index]);
                    }
                }

                $response['appointments'] = array_values($response['appointments']);

                foreach ($response['unavailabilities'] as $index => $unavailability) {
                    if (!in_array((int) $unavailability['id_users_provider'], $providers)) {
                        unset($response['unavailabilities'][$index]);
                    }
                }

                $response['unavailabilities'] = array_values($response['unavailabilities']);
            }

            $response['appointments'] = array_map(
                fn(array $appointment): array => $this->calendarAppointmentData($appointment, $can_view_customers),
                $response['appointments'],
            );
            $response['unavailabilities'] = array_map(
                $this->calendarUnavailabilityData(...),
                $response['unavailabilities'],
            );

            // Add blocked periods to the response.
            $response['blocked_periods'] = $this->calendarBlockedPeriods($range_start_date, $range_end_date, $user_id);

            json_response($response);
        } catch (CalendarRangeValidationException $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /** Keep calendar JSON event data separate from model records and public management capabilities. */
    private function calendarAppointmentData(array $appointment, bool $can_view_customers): array
    {
        $provider = $this->calendarProviderData($this->providers_model->find((int) $appointment['id_users_provider']));
        $this->providers_model->only($provider, self::CALENDAR_PROVIDER_READ_FIELDS);
        $appointment['provider'] = $provider;

        $service = $this->services_model->find((int) $appointment['id_services']);
        $this->services_model->only($service, self::CALENDAR_SERVICE_READ_FIELDS);
        $appointment['service'] = $service;

        if ($can_view_customers) {
            $customer = $this->customers_model->find((int) $appointment['id_users_customer']);
            $this->customers_model->only($customer, [...self::CUSTOMER_READ_FIELDS, 'state']);
            $appointment['customer'] = $customer;
        } else {
            $appointment['customer'] = [];
        }

        $this->appointments_model->only($appointment, self::CALENDAR_APPOINTMENT_READ_FIELDS);

        return $appointment;
    }

    /** Preserve the generated-buffer relationship without sending unavailability model internals. */
    private function calendarUnavailabilityData(array $unavailability): array
    {
        $provider = $this->calendarProviderData(
            $this->providers_model->find((int) $unavailability['id_users_provider']),
        );
        $this->providers_model->only($provider, self::CALENDAR_PROVIDER_READ_FIELDS);
        $unavailability['provider'] = $provider;
        $this->unavailabilities_model->only($unavailability, self::CALENDAR_UNAVAILABILITY_READ_FIELDS);

        return $unavailability;
    }

    /** Keep provider configuration sent to the calendar limited to its working-plan needs. */
    private function calendarProviderData(array $provider): array
    {
        $provider['settings'] = array_intersect_key(
            (array) ($provider['settings'] ?? []),
            array_flip(['working_plan', 'working_plan_exceptions']),
        );

        return $provider;
    }

    /** Calendar visibility does not grant access to private blocked-period notes. */
    private function calendarBlockedPeriods(string $startDate, string $endDate, int $userId): array
    {
        $periods = $this->blocked_periods_model->get_for_period($startDate, $endDate);

        if (cannot('view', PRIV_BLOCKED_PERIODS, $userId)) {
            foreach ($periods as &$period) {
                unset($period['notes']);
            }
            unset($period);
        }

        return $periods;
    }

    private function calendarRequestDtoFactory(): Calendar_request_dto_factory
    {
        if (
            isset($this->calendar_request_dto_factory) &&
            $this->calendar_request_dto_factory instanceof Calendar_request_dto_factory
        ) {
            return $this->calendar_request_dto_factory;
        }

        /** @var EA_Controller|CI_Controller $CI */
        $CI = &get_instance();

        if (
            !isset($CI->calendar_request_dto_factory) ||
            !$CI->calendar_request_dto_factory instanceof Calendar_request_dto_factory
        ) {
            $CI->load->library('calendar_request_dto_factory');
        }

        $this->calendar_request_dto_factory = $CI->calendar_request_dto_factory;

        return $this->calendar_request_dto_factory;
    }
}
