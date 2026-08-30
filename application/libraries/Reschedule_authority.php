<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * ---------------------------------------------------------------------------- */

final class RescheduleAuthorityException extends RuntimeException {}

final class RescheduleAuthorityClaim
{
    public function __construct(
        public readonly int $appointmentId,
        public readonly int $customerId,
        public readonly string $snapshotDigest,
    ) {}
}

final class RescheduleAuthorityState
{
    public function __construct(
        public readonly int $appointmentId,
        public readonly int $customerId,
        public readonly int $providerId,
        public readonly int $serviceId,
        public readonly string $snapshotDigest,
    ) {}
}

/**
 * Server-side, appointment-bound authority for the public reschedule write path.
 *
 * The raw authority remains in the server-side session. Only its digest is
 * persisted, and a successful claim is committed before any business data can
 * be changed.
 */
class Reschedule_authority
{
    private const TABLE = 'reschedule_authorities';

    private const SESSION_TOKEN_KEY = 'public_reschedule_authority';

    private const SESSION_CONTEXT_KEY = 'public_reschedule_authority_context';

    private const LIFETIME_SECONDS = 600;

    private const CREATION_IDENTITY_LOCK_PREFIX = 'ea:public-create:';

    private const CREATION_IDENTITY_LOCK_TIMEOUT_SECONDS = 10;

    /**
     * Conservative, versioned fail-closed snapshot contract. These lists are
     * intentionally centralized because adding or removing a field changes
     * which concurrent edits invalidate a pending reschedule authority.
     */
    private const SNAPSHOT_CONTRACT_VERSION = 1;

    private const APPOINTMENT_SNAPSHOT_FIELDS = [
        'id',
        'hash',
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
        'update_datetime',
    ];

    private const CUSTOMER_SNAPSHOT_FIELDS = [
        'id',
        'first_name',
        'last_name',
        'email',
        'mobile_number',
        'phone_number',
        'address',
        'city',
        'state',
        'zip_code',
        'notes',
        'timezone',
        'language',
        'custom_field_1',
        'custom_field_2',
        'custom_field_3',
        'custom_field_4',
        'custom_field_5',
        'id_roles',
        'update_datetime',
    ];

    private const PROVIDER_SNAPSHOT_FIELDS = [
        'id',
        'first_name',
        'last_name',
        'email',
        'phone_number',
        'timezone',
        'language',
        'room',
        'class_size_default',
        'id_roles',
        'update_datetime',
    ];

    private const SERVICE_SNAPSHOT_FIELDS = [
        'id',
        'name',
        'duration',
        'location',
        'color',
        'availabilities_type',
        'attendants_number',
        'buffer_before',
        'buffer_after',
        'is_private',
        'id_service_categories',
        'update_datetime',
    ];

    private object $db;

    public function __construct()
    {
        $CI = &get_instance();
        $this->db = $CI->db;
    }

    /**
     * Issue one short-lived authority for the canonical appointment.
     */
    public function issue(int $appointment_id): void
    {
        if (!$this->db->trans_begin()) {
            throw new RuntimeException('Could not start reschedule authority transaction.');
        }

        try {
            $state = $this->loadState($appointment_id, true);
            $token = $this->randomToken();
            $context = $this->sessionContext();
            $now = date('Y-m-d H:i:s');

            $this->db->delete(self::TABLE, ['appointment_id' => $state->appointmentId]);

            $inserted = $this->db->insert(self::TABLE, [
                'appointment_id' => $state->appointmentId,
                'customer_id' => $state->customerId,
                'token_digest' => $this->digest($token),
                'context_digest' => $this->digest($context),
                'snapshot_digest' => $state->snapshotDigest,
                'expires_at' => date('Y-m-d H:i:s', time() + self::LIFETIME_SECONDS),
                'consumed_at' => null,
                'create_datetime' => $now,
                'update_datetime' => $now,
            ]);

            if (!$inserted || !$this->db->trans_commit()) {
                throw new RuntimeException('Could not persist reschedule authority.');
            }

            session([self::SESSION_TOKEN_KEY => $token]);
        } catch (Throwable $exception) {
            $this->db->trans_rollback();
            session([self::SESSION_TOKEN_KEY => null]);

            throw $exception;
        }
    }

    /**
     * Atomically consume the current session authority and bind it to the IDs
     * carried by the attempted update.
     */
    public function claim(?int $appointment_id, ?int $customer_id): RescheduleAuthorityClaim
    {
        $token = session(self::SESSION_TOKEN_KEY);
        session([self::SESSION_TOKEN_KEY => null]);

        if (!is_string($token) || !preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            throw new RescheduleAuthorityException('missing-session-authority');
        }

        $context = session(self::SESSION_CONTEXT_KEY);

        if (!is_string($context) || !preg_match('/^[A-Za-z0-9_-]{43}$/', $context)) {
            throw new RescheduleAuthorityException('missing-session-context');
        }

        if (!$this->db->trans_begin()) {
            throw new RuntimeException('Could not start reschedule authority claim transaction.');
        }

        $transaction_open = true;

        try {
            $table = $this->table(self::TABLE);
            $row = $this->db
                ->query('SELECT * FROM `' . $table . '` WHERE `token_digest` = ? FOR UPDATE', [$this->digest($token)])
                ->row_array();

            if (
                empty($row) ||
                !empty($row['consumed_at']) ||
                strtotime((string) $row['expires_at']) <= time() ||
                !hash_equals((string) $row['context_digest'], $this->digest($context))
            ) {
                $this->db->trans_rollback();

                throw new RescheduleAuthorityException('inactive-authority');
            }

            $now = date('Y-m-d H:i:s');
            $updated = $this->db->query(
                'UPDATE `' .
                    $table .
                    '` SET `consumed_at` = ?, `update_datetime` = ? ' .
                    'WHERE `id` = ? AND `consumed_at` IS NULL',
                [$now, $now, (int) $row['id']],
            );

            if (!$updated || $this->db->affected_rows() !== 1 || !$this->db->trans_commit()) {
                throw new RuntimeException('Could not consume reschedule authority.');
            }

            $transaction_open = false;

            if (
                $appointment_id === null ||
                $customer_id === null ||
                $appointment_id !== (int) $row['appointment_id'] ||
                $customer_id !== (int) $row['customer_id']
            ) {
                throw new RescheduleAuthorityException('authority-identity-mismatch');
            }

            return new RescheduleAuthorityClaim(
                (int) $row['appointment_id'],
                (int) $row['customer_id'],
                (string) $row['snapshot_digest'],
            );
        } catch (Throwable $exception) {
            if ($transaction_open) {
                $this->db->trans_rollback();
            }

            throw $exception;
        }
    }

    /**
     * Lock and verify the issuance state plus the selected target scheduling
     * context. Must be called inside the outer business-data transaction.
     */
    public function verifyLockedState(
        RescheduleAuthorityClaim $claim,
        int $target_provider_id,
        int $target_service_id,
    ): RescheduleAuthorityState {
        $appointment = $this->lockedAppointment($claim->appointmentId);

        if (
            (int) ($appointment['id_users_customer'] ?? 0) !== $claim->customerId ||
            !empty($appointment['is_unavailability'])
        ) {
            throw new RescheduleAuthorityException('canonical-identity-mismatch');
        }

        $provider_ids = array_values(array_unique([(int) $appointment['id_users_provider'], $target_provider_id]));
        $service_ids = array_values(array_unique([(int) $appointment['id_services'], $target_service_id]));

        sort($provider_ids, SORT_NUMERIC);
        sort($service_ids, SORT_NUMERIC);

        $user_ids = array_values(array_unique(array_merge([$claim->customerId], $provider_ids)));
        sort($user_ids, SORT_NUMERIC);

        $this->lockRowsByIds('users', 'id', $user_ids);
        $this->lockRowsByIds('services', 'id', $service_ids);
        $this->lockRowsByIds('user_settings', 'id_users', $provider_ids);

        foreach ($provider_ids as $provider_id) {
            $this->lockProviderServiceAssignments($provider_id);
        }

        if (!$this->providerOffersService($target_provider_id, $target_service_id)) {
            throw new RescheduleAuthorityException('target-assignment-mismatch');
        }

        $state = $this->loadStateFromAppointment($appointment);

        if (!hash_equals($claim->snapshotDigest, $state->snapshotDigest)) {
            throw new RescheduleAuthorityException('canonical-state-drift');
        }

        return $state;
    }

    /**
     * Serialize a normal public creation against other public writes for the
     * same target provider and verify the provider-service assignment.
     */
    public function lockCreationTarget(int $provider_id, int $service_id, ?int $customer_id = null): void
    {
        $user_ids = [$provider_id];

        if ($customer_id !== null) {
            $user_ids[] = $customer_id;
        }

        $user_ids = array_values(array_unique($user_ids));
        sort($user_ids, SORT_NUMERIC);

        $this->lockRowsByIds('users', 'id', $user_ids);
        $this->lockRowsByIds('services', 'id', [$service_id]);
        $this->lockRowsByIds('user_settings', 'id_users', [$provider_id]);
        $this->lockProviderServiceAssignments($provider_id);

        if (!$this->providerOffersService($provider_id, $service_id)) {
            throw new RescheduleAuthorityException('Public booking target rejected.');
        }
    }

    /**
     * Serialize normal public creates for the same customer identity before
     * the customer lookup. The opaque lock name never contains the email.
     */
    public function acquireCreationIdentityLock(?string $email): ?string
    {
        $normalized_email = strtolower(trim((string) $email));

        if ($normalized_email === '') {
            return null;
        }

        $database_name = (string) ($this->db->database ?? '');
        $identity_digest = hash('sha256', $database_name . "\0" . $normalized_email);
        $lock_name = self::CREATION_IDENTITY_LOCK_PREFIX . substr($identity_digest, 0, 40);
        $row = $this->db
            ->query('SELECT GET_LOCK(?, ?) AS acquired', [$lock_name, self::CREATION_IDENTITY_LOCK_TIMEOUT_SECONDS])
            ->row_array();

        if ((int) ($row['acquired'] ?? 0) !== 1) {
            throw new RuntimeException('Public booking identity lock could not be acquired.');
        }

        return $lock_name;
    }

    /**
     * Release a normal-create identity lock without masking the booking result.
     * Non-persistent DB connections also release any remaining lock on close.
     */
    public function releaseCreationIdentityLock(?string $lock_name): void
    {
        if ($lock_name === null) {
            return;
        }

        try {
            $this->db->query('SELECT RELEASE_LOCK(?)', [$lock_name]);
        } catch (Throwable) {
            // The non-persistent connection closing also releases the lock.
        }
    }

    /**
     * Re-run the existing customer containment-overlap rule as a locking read
     * after the customer row lock. This observes a preceding public write even
     * when the outer transaction began before that write committed.
     */
    public function customerHasOverlap(
        int $customer_id,
        string $start_datetime,
        string $end_datetime,
        ?int $exclude_appointment_id,
    ): bool {
        $table = $this->table('appointments');
        $sql =
            'SELECT `id` FROM `' .
            $table .
            '` WHERE `id_users_customer` = ? AND `start_datetime` <= ? AND `end_datetime` >= ?';
        $bindings = [$customer_id, $start_datetime, $end_datetime];

        if ($exclude_appointment_id !== null) {
            $sql .= ' AND `id` != ?';
            $bindings[] = $exclude_appointment_id;
        }

        $sql .= ' FOR UPDATE';

        return $this->db->query($sql, $bindings)->num_rows() > 0;
    }

    private function loadState(int $appointment_id, bool $lock): RescheduleAuthorityState
    {
        $appointment = $lock
            ? $this->lockedAppointment($appointment_id)
            : $this->selectOne('appointments', 'id', $appointment_id);

        if (empty($appointment) || !empty($appointment['is_unavailability'])) {
            throw new RescheduleAuthorityException('Public reschedule authority rejected.');
        }

        if ($lock) {
            $customer_id = (int) $appointment['id_users_customer'];
            $provider_id = (int) $appointment['id_users_provider'];
            $service_id = (int) $appointment['id_services'];
            $user_ids = array_values(array_unique([$customer_id, $provider_id]));
            sort($user_ids, SORT_NUMERIC);

            $this->lockRowsByIds('users', 'id', $user_ids);
            $this->lockRowsByIds('services', 'id', [$service_id]);
            $this->lockRowsByIds('user_settings', 'id_users', [$provider_id]);
            $this->lockProviderServiceAssignments($provider_id);
        }

        return $this->loadStateFromAppointment($appointment);
    }

    private function loadStateFromAppointment(array $appointment): RescheduleAuthorityState
    {
        $customer_id = (int) ($appointment['id_users_customer'] ?? 0);
        $provider_id = (int) ($appointment['id_users_provider'] ?? 0);
        $service_id = (int) ($appointment['id_services'] ?? 0);
        $customer = $this->selectOne('users', 'id', $customer_id);
        $provider = $this->selectOne('users', 'id', $provider_id);
        $service = $this->selectOne('services', 'id', $service_id);
        $provider_settings = $this->selectOne('user_settings', 'id_users', $provider_id);

        if (
            empty($customer) ||
            empty($provider) ||
            empty($service) ||
            empty($provider_settings) ||
            !$this->providerOffersService($provider_id, $service_id)
        ) {
            throw new RescheduleAuthorityException('Public reschedule authority rejected.');
        }

        $snapshot = [
            'contract_version' => self::SNAPSHOT_CONTRACT_VERSION,
            'appointment' => $this->only($appointment, self::APPOINTMENT_SNAPSHOT_FIELDS),
            'customer' => $this->only($customer, self::CUSTOMER_SNAPSHOT_FIELDS),
            'provider' => $this->only($provider, self::PROVIDER_SNAPSHOT_FIELDS),
            'provider_schedule' => [
                'working_plan' => $this->decodeJsonValue($provider_settings['working_plan'] ?? null),
                'working_plan_exceptions' => $this->decodeJsonValue(
                    $provider_settings['working_plan_exceptions'] ?? null,
                ),
                'service_ids' => $this->providerServiceIds($provider_id),
            ],
            'service' => $this->only($service, self::SERVICE_SNAPSHOT_FIELDS),
        ];

        $canonical_snapshot = $this->canonicalize($snapshot);
        $encoded_snapshot = json_encode(
            $canonical_snapshot,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return new RescheduleAuthorityState(
            (int) $appointment['id'],
            $customer_id,
            $provider_id,
            $service_id,
            hash('sha256', $encoded_snapshot),
        );
    }

    private function lockedAppointment(int $appointment_id): array
    {
        $table = $this->table('appointments');

        return $this->db
            ->query('SELECT * FROM `' . $table . '` WHERE `id` = ? FOR UPDATE', [$appointment_id])
            ->row_array();
    }

    /**
     * @param array<int, int> $ids
     */
    private function lockRowsByIds(string $table_name, string $id_field, array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        $table = $this->table($table_name);
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $query = $this->db->query(
            'SELECT `' .
                $id_field .
                '` FROM `' .
                $table .
                '` WHERE `' .
                $id_field .
                '` IN (' .
                $placeholders .
                ') ' .
                'ORDER BY `' .
                $id_field .
                '` FOR UPDATE',
            $ids,
        );

        if ($query->num_rows() !== count($ids)) {
            throw new RescheduleAuthorityException('Public booking identity rejected.');
        }
    }

    private function lockProviderServiceAssignments(int $provider_id): void
    {
        $table = $this->table('services_providers');
        $this->db->query(
            'SELECT `id_services` FROM `' . $table . '` WHERE `id_users` = ? ORDER BY `id_services` FOR UPDATE',
            [$provider_id],
        );
    }

    private function providerOffersService(int $provider_id, int $service_id): bool
    {
        return $this->db
            ->get_where('services_providers', [
                'id_users' => $provider_id,
                'id_services' => $service_id,
            ])
            ->num_rows() === 1;
    }

    /**
     * @return array<int, int>
     */
    private function providerServiceIds(int $provider_id): array
    {
        $rows = $this->db
            ->select('id_services')
            ->from('services_providers')
            ->where('id_users', $provider_id)
            ->order_by('id_services', 'ASC')
            ->get()
            ->result_array();

        return array_map(static fn(array $row): int => (int) $row['id_services'], $rows);
    }

    private function selectOne(string $table, string $field, int $value): array
    {
        return $this->db->get_where($table, [$field => $value])->row_array();
    }

    /**
     * @param array<int, string> $fields
     */
    private function only(array $source, array $fields): array
    {
        return array_intersect_key($source, array_flip($fields));
    }

    private function decodeJsonValue(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $value;
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn(mixed $entry): mixed => $this->canonicalize($entry), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $entry) {
            $value[$key] = $this->canonicalize($entry);
        }

        return $value;
    }

    private function sessionContext(): string
    {
        $context = session(self::SESSION_CONTEXT_KEY);

        if (is_string($context) && preg_match('/^[A-Za-z0-9_-]{43}$/', $context)) {
            return $context;
        }

        $context = $this->randomToken();
        session([self::SESSION_CONTEXT_KEY => $context]);

        return $context;
    }

    private function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function digest(string $value): string
    {
        return hash('sha256', $value);
    }

    private function table(string $table): string
    {
        return $this->db->dbprefix($table);
    }
}
