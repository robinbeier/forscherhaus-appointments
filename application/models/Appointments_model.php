<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.0.0
 * ---------------------------------------------------------------------------- */

/**
 * Appointments model.
 *
 * @package Models
 */
final class AppointmentApiUpdateException extends RuntimeException {}

class Appointments_model extends EA_Model
{
    public function __construct()
    {
        parent::__construct();

        $this->load->model('unavailabilities_model');
        $this->load->model('services_model');
        $this->load->model('providers_model');
    }

    /**
     * @var array
     */
    protected array $casts = [
        'id' => 'integer',
        'is_unavailability' => 'boolean',
        'id_users_provider' => 'integer',
        'id_users_customer' => 'integer',
        'id_services' => 'integer',
        'id_parent_appointment' => 'integer',
    ];

    /**
     * @var array
     */
    protected array $api_resource = [
        'id' => 'id',
        'book' => 'book_datetime',
        'start' => 'start_datetime',
        'end' => 'end_datetime',
        'location' => 'location',
        'color' => 'color',
        'status' => 'status',
        'notes' => 'notes',
        'hash' => 'hash',
        'serviceId' => 'id_services',
        'providerId' => 'id_users_provider',
        'customerId' => 'id_users_customer',
        'googleCalendarId' => 'id_google_calendar',
        'caldavCalendarId' => 'id_caldav_calendar',
        'parentAppointmentId' => 'id_parent_appointment',
    ];

    /**
     * Save (insert or update) an appointment.
     *
     * @param array $appointment Associative array with the appointment data.
     *
     * @return int Returns the appointment ID.
     *
     * @throws InvalidArgumentException
     */
    public function save(array $appointment): int
    {
        $this->validate($appointment);

        if (empty($appointment['id'])) {
            return $this->insert($appointment);
        } else {
            return $this->update($appointment);
        }
    }

    /**
     * Apply a sparse authenticated API update to an ordinary appointment.
     *
     * Discovery happens inside the transaction. Parent snapshots are used only
     * to establish the canonical parent-first lock set; the write is rebased on
     * the appointment row read under FOR UPDATE.
     *
     * @param array<string, mixed> $changes Sparse database-field changes.
     */
    public function update_api(int $appointment_id, array $changes): int
    {
        $allowed_fields = [
            'start_datetime',
            'end_datetime',
            'location',
            'color',
            'status',
            'notes',
            'id_users_customer',
            'id_users_provider',
            'id_services',
        ];

        if ($appointment_id <= 0) {
            throw new AppointmentApiUpdateException('The appointment was not found.', 404);
        }

        if ($changes === [] || array_diff(array_keys($changes), $allowed_fields) !== []) {
            throw new AppointmentApiUpdateException('The appointment update payload is invalid.', 400);
        }

        $owns_transaction = false;

        if (!$this->db->trans_active()) {
            if (!$this->db->trans_begin()) {
                throw new RuntimeException('Could not start appointment API update transaction.');
            }

            $owns_transaction = true;
        }

        try {
            $snapshot = $this->snapshot_api_update_appointment($appointment_id);

            if ($snapshot === []) {
                throw new AppointmentApiUpdateException('The appointment was not found.', 404);
            }

            $this->cast($snapshot);
            $requested = array_replace($snapshot, $changes);
            $requested['id'] = $appointment_id;
            $requested['is_unavailability'] = false;

            $user_role_expectations = $this->api_update_user_role_expectations($snapshot, $requested);
            $user_ids = $this->sorted_parent_ids(array_keys($user_role_expectations));
            $service_ids = $this->sorted_parent_ids([
                $snapshot['id_services'] ?? null,
                $requested['id_services'] ?? null,
            ]);
            $role_ids = $this->api_update_role_ids();
            $user_snapshot = $this->snapshot_api_update_users($user_ids);
            $service_snapshot = $this->snapshot_api_update_services($service_ids);

            $this->assert_static_api_update_relationships(
                $user_role_expectations,
                $role_ids,
                $user_snapshot,
                $service_ids,
                $service_snapshot,
            );

            try {
                $this->validate($requested);
            } catch (InvalidArgumentException $exception) {
                throw new AppointmentApiUpdateException($exception->getMessage(), 400, $exception);
            }

            $locked_users = $this->lock_api_update_users($user_ids);

            if ($this->api_update_parent_rows_changed($user_snapshot, $locked_users, ['id_roles'])) {
                throw new AppointmentApiUpdateException('Appointment user roles changed during the update.', 409);
            }

            $this->assert_locked_api_update_roles($user_role_expectations, $role_ids, $locked_users);

            $locked_services = $this->lock_api_update_services($service_ids);

            if ($this->api_update_parent_rows_changed($service_snapshot, $locked_services)) {
                throw new AppointmentApiUpdateException('Appointment services changed during the update.', 409);
            }

            $locked_appointment = $this->lock_api_update_appointment($appointment_id);

            if ($locked_appointment === []) {
                throw new AppointmentApiUpdateException('The appointment was not found.', 404);
            }

            $this->cast($locked_appointment);

            foreach (['id_users_customer', 'id_users_provider', 'id_services', 'is_unavailability'] as $field) {
                if (($snapshot[$field] ?? null) !== ($locked_appointment[$field] ?? null)) {
                    throw new AppointmentApiUpdateException('Appointment parents changed during the update.', 409);
                }
            }

            foreach (array_keys($changes) as $field) {
                if (in_array($field, ['id_users_customer', 'id_users_provider', 'id_services'], true)) {
                    continue;
                }

                if (($snapshot[$field] ?? null) !== ($locked_appointment[$field] ?? null)) {
                    throw new AppointmentApiUpdateException('Appointment fields changed during the update.', 409);
                }
            }

            $updated_appointment = array_replace($locked_appointment, $changes);
            $updated_appointment['id'] = $appointment_id;
            $updated_appointment['is_unavailability'] = false;

            try {
                $this->validate($updated_appointment);
            } catch (InvalidArgumentException $exception) {
                throw new AppointmentApiUpdateException(
                    'Appointment fields changed during the update.',
                    409,
                    $exception,
                );
            }

            $write = $changes;
            $write['update_datetime'] = date('Y-m-d H:i:s');

            if (!$this->db->update('appointments', $write, ['id' => $appointment_id])) {
                throw new RuntimeException('Could not update appointment record.');
            }

            $updated_appointment = array_replace($updated_appointment, $write);

            if ($this->should_sync_buffer_unavailabilities($locked_appointment, $updated_appointment)) {
                $this->sync_buffer_unavailabilities($updated_appointment);
            }

            if ($this->db->trans_status() === false) {
                throw new RuntimeException('Could not save appointment API update transaction.');
            }

            if ($owns_transaction && !$this->db->trans_commit()) {
                throw new RuntimeException('Could not commit appointment transaction.');
            }

            return $appointment_id;
        } catch (Throwable $exception) {
            if ($owns_transaction) {
                $this->db->trans_rollback();
            }

            throw $exception;
        }
    }

    /**
     * Validate the appointment data.
     *
     * @param array $appointment Associative array with the appointment data.
     *
     * @throws InvalidArgumentException
     */
    public function validate(array $appointment): void
    {
        // If an appointment ID is provided then check whether the record really exists in the database.
        if (!empty($appointment['id'])) {
            $count = $this->db->get_where('appointments', ['id' => $appointment['id']])->num_rows();

            if (!$count) {
                throw new InvalidArgumentException(
                    'The provided appointment ID does not exist in the database: ' . $appointment['id'],
                );
            }
        }

        // Make sure all required fields are provided.

        $require_notes = filter_var(setting('require_notes'), FILTER_VALIDATE_BOOLEAN);

        if (
            empty($appointment['start_datetime']) ||
            empty($appointment['end_datetime']) ||
            empty($appointment['id_services']) ||
            empty($appointment['id_users_provider']) ||
            empty($appointment['id_users_customer']) ||
            (empty($appointment['notes']) && $require_notes)
        ) {
            throw new InvalidArgumentException(
                'Not all required fields are provided for the appointment record: start date time, end date time, service, provider, customer, and notes when required.',
            );
        }

        // Make sure that the provided appointment date time values are valid.
        if (!validate_datetime($appointment['start_datetime'])) {
            throw new InvalidArgumentException('The appointment start date time is invalid.');
        }

        if (!validate_datetime($appointment['end_datetime'])) {
            throw new InvalidArgumentException('The appointment end date time is invalid.');
        }

        // Make the appointment lasts longer than the minimum duration (in minutes).
        $diff = (strtotime($appointment['end_datetime']) - strtotime($appointment['start_datetime'])) / 60;

        if ($diff < EVENT_MINIMUM_DURATION) {
            throw new InvalidArgumentException(
                'The appointment duration cannot be less than ' . EVENT_MINIMUM_DURATION . ' minutes.',
            );
        }

        // Make sure the provider ID really exists in the database.
        $count = $this->db
            ->select()
            ->from('users')
            ->join('roles', 'roles.id = users.id_roles', 'inner')
            ->where('users.id', $appointment['id_users_provider'])
            ->where('roles.slug', DB_SLUG_PROVIDER)
            ->get()
            ->num_rows();

        if (!$count) {
            throw new InvalidArgumentException(
                'The appointment provider ID was not found in the database: ' . $appointment['id_users_provider'],
            );
        }

        if (!filter_var($appointment['is_unavailability'], FILTER_VALIDATE_BOOLEAN)) {
            // Make sure the customer ID really exists in the database.
            $count = $this->db
                ->select()
                ->from('users')
                ->join('roles', 'roles.id = users.id_roles', 'inner')
                ->where('users.id', $appointment['id_users_customer'])
                ->where('roles.slug', DB_SLUG_CUSTOMER)
                ->get()
                ->num_rows();

            if (!$count) {
                throw new InvalidArgumentException(
                    'The appointment customer ID was not found in the database: ' . $appointment['id_users_customer'],
                );
            }

            // Make sure the service ID really exists in the database.
            $count = $this->db->get_where('services', ['id' => $appointment['id_services']])->num_rows();

            if (!$count) {
                throw new InvalidArgumentException('Appointment service id is invalid.');
            }
        }
    }

    /**
     * Get all appointments that match the provided criteria.
     *
     * @param array|string|null $where Where conditions.
     * @param int|null $limit Record limit.
     * @param int|null $offset Record offset.
     * @param string|null $order_by Order by.
     *
     * @return array Returns an array of appointments.
     */
    public function get(
        array|string|null $where = null,
        ?int $limit = null,
        ?int $offset = null,
        ?string $order_by = null,
    ): array {
        if ($where !== null) {
            $this->db->where($where);
        }

        if ($order_by) {
            $this->db->order_by($this->quote_order_by($order_by));
        }

        $appointments = $this->db
            ->get_where('appointments', ['is_unavailability' => false], $limit, $offset)
            ->result_array();

        foreach ($appointments as &$appointment) {
            $this->cast($appointment);
        }

        return $appointments;
    }

    /**
     * Insert a new appointment into the database.
     *
     * @param array $appointment Associative array with the appointment data.
     *
     * @return int Returns the appointment ID.
     *
     * @throws RuntimeException
     */
    protected function insert(array $appointment): int
    {
        if (!$this->db->trans_begin()) {
            throw new RuntimeException('Could not start appointment transaction.');
        }

        try {
            $appointment['book_datetime'] = date('Y-m-d H:i:s');
            $appointment['create_datetime'] = date('Y-m-d H:i:s');
            $appointment['update_datetime'] = date('Y-m-d H:i:s');
            $appointment['hash'] = bin2hex(random_bytes(32));
            $this->lock_update_parents([], $appointment);

            if (!$this->db->insert('appointments', $appointment)) {
                throw new RuntimeException('Could not insert appointment.');
            }

            $appointment_id = $this->db->insert_id();
            $created_appointment = $this->find($appointment_id);
            $this->sync_buffer_unavailabilities($created_appointment);

            if (!$this->db->trans_commit()) {
                throw new RuntimeException('Could not commit appointment transaction.');
            }

            return $appointment_id;
        } catch (Throwable $exception) {
            $this->db->trans_rollback();

            throw $exception;
        }
    }

    /**
     * Update an existing appointment.
     *
     * @param array $appointment Associative array with the appointment data.
     *
     * @return int Returns the appointment ID.
     *
     * @throws RuntimeException
     */
    protected function update(array $appointment): int
    {
        if (!$this->db->trans_begin()) {
            throw new RuntimeException('Could not start appointment transaction.');
        }

        try {
            $original_appointment = $this->find((int) $appointment['id']);
            $this->lock_update_parents($original_appointment, $appointment);
            $appointment['update_datetime'] = date('Y-m-d H:i:s');

            if (!$this->db->update('appointments', $appointment, ['id' => $appointment['id']])) {
                throw new RuntimeException('Could not update appointment record.');
            }

            $updated_appointment = $this->find((int) $appointment['id']);

            if ($this->should_sync_buffer_unavailabilities($original_appointment, $updated_appointment)) {
                $this->sync_buffer_unavailabilities($updated_appointment);
            }

            if (!$this->db->trans_commit()) {
                throw new RuntimeException('Could not commit appointment transaction.');
            }

            return $appointment['id'];
        } catch (Throwable $exception) {
            $this->db->trans_rollback();

            throw $exception;
        }
    }

    /**
     * Lock the foreign-key parents used by an appointment update before the
     * appointment row can be locked or changed.
     *
     * @param array<string, mixed> $current_appointment
     * @param array<string, mixed> $requested_appointment
     */
    public function lock_update_parents(
        array $current_appointment,
        array $requested_appointment,
        array $additional_user_ids = [],
    ): void {
        $user_ids = $this->sorted_parent_ids([
            $current_appointment['id_users_customer'] ?? null,
            $current_appointment['id_users_provider'] ?? null,
            $requested_appointment['id_users_customer'] ?? null,
            $requested_appointment['id_users_provider'] ?? null,
            ...$additional_user_ids,
        ]);
        $service_ids = $this->sorted_parent_ids([
            $current_appointment['id_services'] ?? null,
            $requested_appointment['id_services'] ?? null,
        ]);

        $this->lock_parent_rows('users', 'id', $user_ids);
        $this->lock_parent_rows('services', 'id', $service_ids);
    }

    /**
     * @return array<string, mixed>
     */
    protected function snapshot_api_update_appointment(int $appointment_id): array
    {
        return $this->db
            ->query(
                'SELECT * FROM `' .
                    $this->db->dbprefix('appointments') .
                    '` WHERE `id` = ? AND `is_unavailability` = 0',
                [$appointment_id],
            )
            ->row_array() ?:
            [];
    }

    /**
     * @param list<int> $user_ids
     * @return list<array<string, mixed>>
     */
    protected function snapshot_api_update_users(array $user_ids): array
    {
        return $this->select_api_update_parent_rows('users', ['id', 'id_roles'], $user_ids, false);
    }

    /**
     * @param list<int> $service_ids
     * @return list<array<string, mixed>>
     */
    protected function snapshot_api_update_services(array $service_ids): array
    {
        return $this->select_api_update_parent_rows('services', ['id'], $service_ids, false);
    }

    /**
     * @param list<int> $user_ids
     * @return list<array<string, mixed>>
     */
    protected function lock_api_update_users(array $user_ids): array
    {
        return $this->select_api_update_parent_rows('users', ['id', 'id_roles'], $user_ids, true);
    }

    /**
     * @param list<int> $service_ids
     * @return list<array<string, mixed>>
     */
    protected function lock_api_update_services(array $service_ids): array
    {
        return $this->select_api_update_parent_rows('services', ['id'], $service_ids, true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function lock_api_update_appointment(int $appointment_id): array
    {
        return $this->db
            ->query('SELECT * FROM `' . $this->db->dbprefix('appointments') . '` WHERE `id` = ? FOR UPDATE', [
                $appointment_id,
            ])
            ->row_array() ?:
            [];
    }

    /**
     * @param list<string> $fields
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private function select_api_update_parent_rows(string $table, array $fields, array $ids, bool $lock): array
    {
        if ($ids === []) {
            return [];
        }

        $selected_fields = implode(', ', array_map(static fn(string $field): string => '`' . $field . '`', $fields));
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $sql =
            'SELECT ' .
            $selected_fields .
            ' FROM `' .
            $this->db->dbprefix($table) .
            '` WHERE `id` IN (' .
            $placeholders .
            ') ORDER BY `id` ASC';

        if ($lock) {
            $sql .= ' FOR UPDATE';
        }

        return $this->db->query($sql, $ids)->result_array();
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $requested
     * @return array<int, string>
     */
    private function api_update_user_role_expectations(array $snapshot, array $requested): array
    {
        $expectations = [];

        foreach (
            [
                [(int) ($snapshot['id_users_customer'] ?? 0), DB_SLUG_CUSTOMER],
                [(int) ($snapshot['id_users_provider'] ?? 0), DB_SLUG_PROVIDER],
                [(int) ($requested['id_users_customer'] ?? 0), DB_SLUG_CUSTOMER],
                [(int) ($requested['id_users_provider'] ?? 0), DB_SLUG_PROVIDER],
            ]
            as [$user_id, $role_slug]
        ) {
            if ($user_id <= 0 || (isset($expectations[$user_id]) && $expectations[$user_id] !== $role_slug)) {
                throw new AppointmentApiUpdateException('Appointment user relationship is invalid.', 400);
            }

            $expectations[$user_id] = $role_slug;
        }

        return $expectations;
    }

    /**
     * @return array<string, int>
     */
    private function api_update_role_ids(): array
    {
        $rows = $this->db
            ->query('SELECT `id`, `slug` FROM `' . $this->db->dbprefix('roles') . '` WHERE `slug` IN (?, ?)', [
                DB_SLUG_CUSTOMER,
                DB_SLUG_PROVIDER,
            ])
            ->result_array();
        $role_ids = [];

        foreach ($rows as $row) {
            $role_ids[(string) ($row['slug'] ?? '')] = (int) ($row['id'] ?? 0);
        }

        if (empty($role_ids[DB_SLUG_CUSTOMER]) || empty($role_ids[DB_SLUG_PROVIDER])) {
            throw new RuntimeException('Appointment role definitions were not found.');
        }

        return $role_ids;
    }

    /**
     * @param array<int, string> $user_role_expectations
     * @param array<string, int> $role_ids
     * @param list<array<string, mixed>> $users
     * @param list<int> $service_ids
     * @param list<array<string, mixed>> $services
     */
    private function assert_static_api_update_relationships(
        array $user_role_expectations,
        array $role_ids,
        array $users,
        array $service_ids,
        array $services,
    ): void {
        $users_by_id = $this->api_update_rows_by_id($users);

        foreach ($user_role_expectations as $user_id => $role_slug) {
            if (
                !isset($users_by_id[$user_id]) ||
                (int) ($users_by_id[$user_id]['id_roles'] ?? 0) !== (int) ($role_ids[$role_slug] ?? 0)
            ) {
                throw new AppointmentApiUpdateException('Appointment user relationship is invalid.', 400);
            }
        }

        if (count($this->api_update_rows_by_id($services)) !== count($service_ids)) {
            throw new AppointmentApiUpdateException('Appointment service relationship is invalid.', 400);
        }
    }

    /**
     * @param array<int, string> $user_role_expectations
     * @param array<string, int> $role_ids
     * @param list<array<string, mixed>> $locked_users
     */
    private function assert_locked_api_update_roles(
        array $user_role_expectations,
        array $role_ids,
        array $locked_users,
    ): void {
        $users_by_id = $this->api_update_rows_by_id($locked_users);

        foreach ($user_role_expectations as $user_id => $role_slug) {
            if (
                !isset($users_by_id[$user_id]) ||
                (int) ($users_by_id[$user_id]['id_roles'] ?? 0) !== (int) ($role_ids[$role_slug] ?? 0)
            ) {
                throw new AppointmentApiUpdateException('Appointment user roles changed during the update.', 409);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $snapshot
     * @param list<array<string, mixed>> $locked
     * @param list<string> $compared_fields
     */
    private function api_update_parent_rows_changed(array $snapshot, array $locked, array $compared_fields = []): bool
    {
        $snapshot_by_id = $this->api_update_rows_by_id($snapshot);
        $locked_by_id = $this->api_update_rows_by_id($locked);

        if (array_keys($snapshot_by_id) !== array_keys($locked_by_id)) {
            return true;
        }

        foreach ($snapshot_by_id as $id => $snapshot_row) {
            foreach ($compared_fields as $field) {
                if (($snapshot_row[$field] ?? null) !== ($locked_by_id[$id][$field] ?? null)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function api_update_rows_by_id(array $rows): array
    {
        $by_id = [];

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);

            if ($id > 0) {
                $by_id[$id] = $row;
            }
        }

        ksort($by_id, SORT_NUMERIC);

        return $by_id;
    }

    /**
     * @param array<int, mixed> $values
     * @return array<int, int>
     */
    private function sorted_parent_ids(array $values): array
    {
        $ids = array_values(
            array_unique(
                array_filter(
                    array_map(static fn($value): int => (int) $value, $values),
                    static fn(int $value): bool => $value > 0,
                ),
            ),
        );
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /**
     * @param array<int, int> $ids
     */
    private function lock_parent_rows(string $table, string $field, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $query = $this->db->query(
            'SELECT `' .
                $field .
                '` FROM `' .
                $this->db->dbprefix($table) .
                '` WHERE `' .
                $field .
                '` IN (' .
                $placeholders .
                ') ORDER BY `' .
                $field .
                '` ASC FOR UPDATE',
            $ids,
        );

        if ($query->num_rows() !== count($ids)) {
            throw new RuntimeException('Appointment parent record was not found.');
        }
    }

    /**
     * Get a specific appointment from the database.
     *
     * @param int $appointment_id The ID of the record to be returned.
     *
     * @return array Returns an array with the appointment data.
     *
     * @throws InvalidArgumentException
     */
    public function find(int $appointment_id): array
    {
        $appointment = $this->db->get_where('appointments', ['id' => $appointment_id])->row_array();

        if (!$appointment) {
            throw new InvalidArgumentException(
                'The provided appointment ID was not found in the database: ' . $appointment_id,
            );
        }

        $this->cast($appointment);

        return $appointment;
    }

    /**
     * Get a specific field value from the database.
     *
     * @param int $appointment_id Appointment ID.
     * @param string $field Name of the value to be returned.
     *
     * @return mixed Returns the selected appointment value from the database.
     *
     * @throws InvalidArgumentException
     */
    public function value(int $appointment_id, string $field): mixed
    {
        if (empty($field)) {
            throw new InvalidArgumentException('The field argument is cannot be empty.');
        }

        if (empty($appointment_id)) {
            throw new InvalidArgumentException('The appointment ID argument cannot be empty.');
        }

        // Check whether the appointment exists.
        $query = $this->db->get_where('appointments', ['id' => $appointment_id]);

        if (!$query->num_rows()) {
            throw new InvalidArgumentException(
                'The provided appointment ID was not found in the database: ' . $appointment_id,
            );
        }

        // Check if the required field is part of the appointment data.
        $appointment = $query->row_array();

        $this->cast($appointment);

        if (!array_key_exists($field, $appointment)) {
            throw new InvalidArgumentException('The requested field was not found in the appointment data: ' . $field);
        }

        return $appointment[$field];
    }

    /**
     * Remove an existing appointment from the database.
     *
     * @param int $appointment_id Appointment ID.
     *
     * @throws RuntimeException
     */
    public function delete(int $appointment_id): void
    {
        if (!$this->db->trans_begin()) {
            throw new RuntimeException('Could not start appointment transaction.');
        }

        try {
            $this->db->query(
                'SELECT `id` FROM `' . $this->db->dbprefix('appointments') . '` WHERE `id` = ? FOR UPDATE',
                [$appointment_id],
            );
            $this->db
                ->where('id_parent_appointment', $appointment_id)
                ->where('is_unavailability', true)
                ->delete('appointments');

            $this->db->delete('appointments', ['id' => $appointment_id]);

            if (!$this->db->trans_commit()) {
                throw new RuntimeException('Could not commit appointment transaction.');
            }
        } catch (Throwable $exception) {
            $this->db->trans_rollback();

            throw $exception;
        }
    }

    /**
     * Regenerate future buffer unavailability blocks for all appointments of a service.
     *
     * @param int $service_id Service ID.
     */
    public function sync_service_buffer_unavailabilities(int $service_id): void
    {
        if ($service_id <= 0) {
            throw new InvalidArgumentException('The service ID argument cannot be empty.');
        }

        if (!$this->db->trans_begin()) {
            throw new RuntimeException('Could not start service buffer sync transaction.');
        }

        try {
            $now = date('Y-m-d H:i:s');
            $service = $this->services_model->lock_buffer_sync_parents($service_id);
            $buffer_after = max(0, (int) ($service['buffer_after'] ?? 0));
            $resync_cutoff = (new DateTimeImmutable($now))
                ->sub(new DateInterval('PT' . $buffer_after . 'M'))
                ->format('Y-m-d H:i:s');

            $appointments_query = $this->db
                ->select('appointments.*')
                ->from('appointments')
                ->join(
                    'appointments AS buffer_blocks',
                    'buffer_blocks.id_parent_appointment = appointments.id AND ' .
                        'buffer_blocks.is_unavailability = 1 AND ' .
                        'buffer_blocks.end_datetime >= ' .
                        $this->db->escape($now),
                    'left',
                )
                ->where('appointments.id_services', $service_id)
                ->where('appointments.is_unavailability', false)
                ->group_start()
                ->where('appointments.end_datetime >=', $resync_cutoff)
                ->or_where('buffer_blocks.id IS NOT NULL', null, false)
                ->group_end()
                ->group_by('appointments.id')
                ->order_by('appointments.id', 'ASC')
                ->get_compiled_select();
            $appointments = $this->db->query($appointments_query . ' FOR UPDATE')->result_array();

            $appointment_ids = array_map(static fn(array $appointment): int => (int) $appointment['id'], $appointments);

            if (!empty($appointment_ids)) {
                $this->db
                    ->where_in('id_parent_appointment', $appointment_ids)
                    ->where('is_unavailability', true)
                    ->delete('appointments');
            }

            foreach ($appointments as $appointment) {
                $this->cast($appointment);
                $this->sync_buffer_unavailabilities($appointment);
            }

            if (!$this->db->trans_commit()) {
                throw new RuntimeException('Could not commit service buffer sync transaction.');
            }
        } catch (Throwable $exception) {
            $this->db->trans_rollback();

            throw $exception;
        }
    }

    protected function should_sync_buffer_unavailabilities(array $original, array $updated): bool
    {
        $is_unavailability = filter_var($updated['is_unavailability'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($is_unavailability || empty($updated['id_services'])) {
            return false;
        }

        if ($original['start_datetime'] !== $updated['start_datetime']) {
            return true;
        }

        if ($original['end_datetime'] !== $updated['end_datetime']) {
            return true;
        }

        if ((int) $original['id_users_provider'] !== (int) $updated['id_users_provider']) {
            return true;
        }

        if ((int) $original['id_services'] !== (int) $updated['id_services']) {
            return true;
        }

        return false;
    }

    protected function sync_buffer_unavailabilities(array $appointment): void
    {
        $is_unavailability = filter_var($appointment['is_unavailability'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($is_unavailability || empty($appointment['id']) || empty($appointment['id_services'])) {
            return;
        }

        $service = $this->db
            ->query('SELECT * FROM `' . $this->db->dbprefix('services') . '` WHERE `id` = ? FOR UPDATE', [
                (int) $appointment['id_services'],
            ])
            ->row_array();

        $buffer_before = max(0, (int) ($service['buffer_before'] ?? 0));
        $buffer_after = max(0, (int) ($service['buffer_after'] ?? 0));

        $this->db
            ->where('id_parent_appointment', $appointment['id'])
            ->where('is_unavailability', true)
            ->delete('appointments');

        if ($buffer_before === 0 && $buffer_after === 0) {
            return;
        }

        $appointment_start = new DateTimeImmutable($appointment['start_datetime']);
        $appointment_end = new DateTimeImmutable($appointment['end_datetime']);

        $buffers = [];

        $attendants_number = (int) ($service['attendants_number'] ?? 1);

        if ($buffer_before > 0) {
            $start = $appointment_start->sub(new DateInterval('PT' . $buffer_before . 'M'));
            $end = $appointment_start;

            $this->assert_buffer_window_is_valid($appointment, $start, $end, 'before', $attendants_number);

            $buffers[] = ['start' => $start, 'end' => $end];
        }

        if ($buffer_after > 0) {
            $start = $appointment_end;
            $end = $appointment_end->add(new DateInterval('PT' . $buffer_after . 'M'));

            $this->assert_buffer_window_is_valid($appointment, $start, $end, 'after', $attendants_number);

            $buffers[] = ['start' => $start, 'end' => $end];
        }

        foreach ($buffers as $buffer) {
            $this->create_buffer_block($appointment, $buffer['start'], $buffer['end']);
        }
    }

    protected function create_buffer_block(array $appointment, DateTimeImmutable $start, DateTimeImmutable $end): void
    {
        $this->unavailabilities_model->save([
            'start_datetime' => $start->format('Y-m-d H:i:s'),
            'end_datetime' => $end->format('Y-m-d H:i:s'),
            'id_users_provider' => $appointment['id_users_provider'],
            'id_parent_appointment' => $appointment['id'],
            'notes' => lang('buffer_block_note'),
        ]);
    }

    protected function assert_buffer_window_is_valid(
        array $appointment,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        string $position,
        int $attendants_number = 1,
    ): void {
        if ($end <= $start) {
            throw new RuntimeException(lang('buffer_invalid_window_error'));
        }

        $appointment_start = new DateTimeImmutable($appointment['start_datetime']);
        $appointment_end = new DateTimeImmutable($appointment['end_datetime']);

        if ($position === 'before' && $start->format('Y-m-d') !== $appointment_start->format('Y-m-d')) {
            throw new RuntimeException(lang('buffer_outside_schedule_error'));
        }

        if ($position === 'after' && $end->format('Y-m-d') !== $appointment_end->format('Y-m-d')) {
            throw new RuntimeException(lang('buffer_outside_schedule_error'));
        }

        $day_bounds_date = $position === 'after' ? $appointment_end : $appointment_start;
        $day_bounds = $this->get_provider_day_bounds($day_bounds_date, $appointment['id_users_provider']);

        if ($day_bounds) {
            if ($start < $day_bounds['start'] || $end > $day_bounds['end']) {
                throw new RuntimeException(lang('buffer_outside_schedule_error'));
            }
        }

        $builder = $this->db
            ->from('appointments')
            ->where('id_users_provider', $appointment['id_users_provider'])
            ->where('start_datetime <', $end->format('Y-m-d H:i:s'))
            ->where('end_datetime >', $start->format('Y-m-d H:i:s'));

        if (!empty($appointment['id'])) {
            $builder->where('id !=', $appointment['id']);
        }

        if ($attendants_number > 1) {
            $builder->where('id_parent_appointment IS NULL', null, false);
        } else {
            $builder
                ->group_start()
                ->where('id_parent_appointment IS NULL', null, false)
                ->or_where('id_parent_appointment !=', $appointment['id'])
                ->group_end();
        }

        $conflicts = $builder->count_all_results();

        if ($conflicts > 0) {
            throw new RuntimeException(lang('buffer_conflict_error'));
        }
    }

    protected function get_provider_day_bounds(DateTimeImmutable $date, int $provider_id): ?array
    {
        try {
            $provider = $this->providers_model->find($provider_id);
        } catch (Throwable) {
            return null;
        }

        if (empty($provider['settings']['working_plan'])) {
            return null;
        }

        $working_plan = json_decode($provider['settings']['working_plan'], true) ?: [];
        $working_plan_exceptions = [];

        if (!empty($provider['settings']['working_plan_exceptions'])) {
            $working_plan_exceptions = json_decode($provider['settings']['working_plan_exceptions'], true) ?: [];
        }

        $date_key = $date->format('Y-m-d');

        if (array_key_exists($date_key, $working_plan_exceptions)) {
            $plan = $working_plan_exceptions[$date_key];
        } else {
            $day_name = strtolower($date->format('l'));
            $plan = $working_plan[$day_name] ?? null;
        }

        if (empty($plan['start']) || empty($plan['end'])) {
            return null;
        }

        return [
            'start' => new DateTimeImmutable($date->format('Y-m-d') . ' ' . $plan['start']),
            'end' => new DateTimeImmutable($date->format('Y-m-d') . ' ' . $plan['end']),
        ];
    }

    /**
     * Get the attendants number for the requested period.
     *
     * @param DateTime $start Period start.
     * @param DateTime $end Period end.
     * @param int $service_id Service ID.
     * @param int $provider_id Provider ID.
     * @param int|null $exclude_appointment_id Exclude an appointment from the result set.
     *
     * @return int Returns the number of appointments that match the provided criteria.
     */
    public function get_attendants_number_for_period(
        DateTime $start,
        DateTime $end,
        int $service_id,
        int $provider_id,
        ?int $exclude_appointment_id = null,
    ): int {
        if ($exclude_appointment_id) {
            $this->db->where('id !=', $exclude_appointment_id);
        }

        $result = $this->db
            ->select('count(*) AS attendants_number')
            ->from('appointments')
            ->group_start()
            ->group_start()
            ->where('start_datetime <=', $start->format('Y-m-d H:i:s'))
            ->where('end_datetime >', $start->format('Y-m-d H:i:s'))
            ->group_end()
            ->or_group_start()
            ->where('start_datetime <', $end->format('Y-m-d H:i:s'))
            ->where('end_datetime >=', $end->format('Y-m-d H:i:s'))
            ->group_end()
            ->group_end()
            ->where('id_services', $service_id)
            ->where('id_users_provider', $provider_id)
            ->get()
            ->row_array();

        return $result['attendants_number'];
    }

    /**
     *
     * Returns the number of the other service attendants number for the provided time slot.
     *
     * @param DateTime $start Period start.
     * @param DateTime $end Period end.
     * @param int $service_id Service ID.
     * @param int $provider_id Provider ID.
     * @param int|null $exclude_appointment_id Exclude an appointment from the result set.
     *
     * @return int Returns the number of appointments that match the provided criteria.
     */
    public function get_other_service_attendants_number(
        DateTime $start,
        DateTime $end,
        int $service_id,
        int $provider_id,
        ?int $exclude_appointment_id = null,
    ): int {
        if ($exclude_appointment_id) {
            $this->db->where('id !=', $exclude_appointment_id);
        }

        $result = $this->db
            ->select('count(*) AS attendants_number')
            ->from('appointments')
            ->group_start()
            ->group_start()
            ->where('start_datetime <=', $start->format('Y-m-d H:i:s'))
            ->where('end_datetime >', $start->format('Y-m-d H:i:s'))
            ->group_end()
            ->or_group_start()
            ->where('start_datetime <', $end->format('Y-m-d H:i:s'))
            ->where('end_datetime >=', $end->format('Y-m-d H:i:s'))
            ->group_end()
            ->group_end()
            ->where('id_services !=', $service_id)
            ->where('id_users_provider', $provider_id)
            ->get()
            ->row_array();

        return $result['attendants_number'];
    }

    /**
     * Get the query builder interface, configured for use with the appointments table.
     *
     * @return CI_DB_query_builder
     */
    public function query(): CI_DB_query_builder
    {
        return $this->db->from('appointments AS appointments');
    }

    /**
     * Search appointments by the provided keyword.
     *
     * @param string $keyword Search keyword.
     * @param int|null $limit Record limit.
     * @param int|null $offset Record offset.
     * @param string|null $order_by Order by.
     *
     * @return array Returns an array of appointments.
     */
    public function search(string $keyword, ?int $limit = null, ?int $offset = null, ?string $order_by = null): array
    {
        $appointments = $this->db
            ->select('appointments.*')
            ->from('appointments')
            ->join('services', 'services.id = appointments.id_services', 'left')
            ->join('users AS providers', 'providers.id = appointments.id_users_provider', 'inner')
            ->join('users AS customers', 'customers.id = appointments.id_users_customer', 'left')
            ->where('is_unavailability', false)
            ->group_start()
            ->like('appointments.start_datetime', $keyword)
            ->or_like('appointments.end_datetime', $keyword)
            ->or_like('appointments.location', $keyword)
            ->or_like('appointments.hash', $keyword)
            ->or_like('appointments.notes', $keyword)
            ->or_like('services.name', $keyword)
            ->or_like('services.description', $keyword)
            ->or_like('providers.first_name', $keyword)
            ->or_like('providers.last_name', $keyword)
            ->or_like('providers.email', $keyword)
            ->or_like('providers.phone_number', $keyword)
            ->or_like('customers.first_name', $keyword)
            ->or_like('customers.last_name', $keyword)
            ->or_like('customers.email', $keyword)
            ->or_like('customers.phone_number', $keyword)
            ->group_end()
            ->limit($limit)
            ->offset($offset)
            ->order_by($this->quote_order_by($order_by))
            ->get()
            ->result_array();

        foreach ($appointments as &$appointment) {
            $this->cast($appointment);
        }

        return $appointments;
    }

    /**
     * Load related resources to an appointment.
     *
     * @param array $appointment Associative array with the appointment data.
     * @param array $resources Resource names to be attached ("service", "provider", "customer" supported).
     *
     * @throws InvalidArgumentException
     */
    public function load(array &$appointment, array $resources): void
    {
        if (empty($appointment) || empty($resources)) {
            return;
        }

        foreach ($resources as $resource) {
            switch ($resource) {
                case 'service':
                    $appointment['service'] = $this->db
                        ->get_where('services', [
                            'id' => $appointment['id_services'] ?? ($appointment['serviceId'] ?? null),
                        ])
                        ->row_array();
                    break;

                case 'provider':
                    $appointment['provider'] = $this->db
                        ->get_where('users', [
                            'id' => $appointment['id_users_provider'] ?? ($appointment['providerId'] ?? null),
                        ])
                        ->row_array();
                    break;

                case 'customer':
                    $appointment['customer'] = $this->db
                        ->get_where('users', [
                            'id' => $appointment['id_users_customer'] ?? ($appointment['customerId'] ?? null),
                        ])
                        ->row_array();
                    break;

                default:
                    throw new InvalidArgumentException(
                        'The requested appointment relation is not supported: ' . $resource,
                    );
            }
        }
    }

    /**
     * Convert the database appointment record to the equivalent API resource.
     *
     * @param array $appointment Appointment data.
     */
    public function api_encode(array &$appointment): void
    {
        $encoded_resource = [
            'id' => array_key_exists('id', $appointment) ? (int) $appointment['id'] : null,
            'book' => $appointment['book_datetime'],
            'start' => $appointment['start_datetime'],
            'end' => $appointment['end_datetime'],
            'hash' => $appointment['hash'],
            'color' => $appointment['color'],
            'status' => $appointment['status'],
            'location' => $appointment['location'],
            'notes' => $appointment['notes'],
            'customerId' => $appointment['id_users_customer'] !== null ? (int) $appointment['id_users_customer'] : null,
            'providerId' => $appointment['id_users_provider'] !== null ? (int) $appointment['id_users_provider'] : null,
            'serviceId' => $appointment['id_services'] !== null ? (int) $appointment['id_services'] : null,
            'googleCalendarId' =>
                $appointment['id_google_calendar'] !== null ? $appointment['id_google_calendar'] : null,
            'caldavCalendarId' =>
                $appointment['id_caldav_calendar'] !== null ? $appointment['id_caldav_calendar'] : null,
            'parentAppointmentId' =>
                $appointment['id_parent_appointment'] !== null ? (int) $appointment['id_parent_appointment'] : null,
        ];

        $appointment = $encoded_resource;
    }

    /**
     * Convert the API resource to the equivalent database appointment record.
     *
     * @param array $appointment API resource.
     * @param array|null $base Base appointment data to be overwritten with the provided values (useful for updates).
     */
    public function api_decode(array &$appointment, ?array $base = null): void
    {
        $decoded_request = $base ?: [];

        if (array_key_exists('id', $appointment)) {
            $decoded_request['id'] = $appointment['id'];
        }

        if (array_key_exists('book', $appointment)) {
            $decoded_request['book_datetime'] = $appointment['book'];
        }

        if (array_key_exists('start', $appointment)) {
            $decoded_request['start_datetime'] = $appointment['start'];
        }

        if (array_key_exists('end', $appointment)) {
            $decoded_request['end_datetime'] = $appointment['end'];
        }

        if (array_key_exists('hash', $appointment)) {
            $decoded_request['hash'] = $appointment['hash'];
        }

        if (array_key_exists('location', $appointment)) {
            $decoded_request['location'] = $appointment['location'];
        }

        if (array_key_exists('color', $appointment)) {
            $decoded_request['color'] = $appointment['color'];
        }

        if (array_key_exists('status', $appointment)) {
            $decoded_request['status'] = $appointment['status'];
        }

        if (array_key_exists('notes', $appointment)) {
            $decoded_request['notes'] = $appointment['notes'];
        }

        if (array_key_exists('customerId', $appointment)) {
            $decoded_request['id_users_customer'] = $appointment['customerId'];
        }

        if (array_key_exists('providerId', $appointment)) {
            $decoded_request['id_users_provider'] = $appointment['providerId'];
        }

        if (array_key_exists('serviceId', $appointment)) {
            $decoded_request['id_services'] = $appointment['serviceId'];
        }

        if (array_key_exists('googleCalendarId', $appointment)) {
            $decoded_request['id_google_calendar'] = $appointment['googleCalendarId'];
        }

        if (array_key_exists('caldavCalendarId', $appointment)) {
            $decoded_request['id_caldav_calendar'] = $appointment['caldavCalendarId'];
        }

        $decoded_request['is_unavailability'] = false;

        $appointment = $decoded_request;
    }

    /**
     * Convert only the fields supplied by an API update request.
     *
     * @param array<string, mixed> $appointment
     */
    public function api_decode_sparse(array &$appointment): void
    {
        $this->api_decode($appointment);
        unset($appointment['is_unavailability']);
    }

    /**
     * Calculate the end date time of an appointment based on the selected service.
     *
     * @param array $appointment Appointment data.
     *
     * @return string Returns the end date time value.
     *
     * @throws Exception
     */
    public function calculate_end_datetime(array $appointment): string
    {
        $duration = $this->db->get_where('services', ['id' => $appointment['id_services']])?->row()?->duration;

        $end_date_time_object = new DateTime($appointment['start_datetime']);

        $end_date_time_object->add(new DateInterval('PT' . $duration . 'M'));

        return $end_date_time_object->format('Y-m-d H:i:s');
    }
}
