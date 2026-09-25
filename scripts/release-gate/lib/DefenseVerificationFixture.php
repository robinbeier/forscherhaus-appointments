<?php

declare(strict_types=1);

namespace ReleaseGate;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Root-only, disposable supplemental fixture for the ordinary-live run.
 *
 * This deliberately has its own journal: the ordinary fixture remains the
 * owner of its actor. A temporary empty API token is the only application
 * setting this class may change, with exact crash recovery in this journal.
 */
final class DefenseVerificationFixture
{
    private const SCHEMA = 'defense-verification-fixture.v1';
    private const MAX_TTL = 600;
    private const PROFILES = [
        'customer_boundary',
        'calendar_race',
        'services_api',
        'unavailabilities_api',
        'blocked_periods_api',
        'service_categories_api',
        'secretaries_api',
    ];
    private const ACTIVE_TRANSACTION_ERROR = 'Defense verification fixture cannot run inside an active database transaction.';

    private object $db;
    private string $directory;
    private string $stateFile;
    private string $temporaryStateFile;
    private string $lockFile;

    public function __construct(string $stateDirectory)
    {
        $this->assertRootCli();
        $this->directory = $this->prepareDirectory($stateDirectory);
        $this->stateFile = $this->directory . '/defense-verification.json';
        $this->temporaryStateFile = $this->stateFile . '.tmp';
        $this->lockFile = $this->directory . '/defense-verification.lock';
        $ci = &\get_instance();
        $this->db = $ci->db;
    }

    public function assertCleanBeforeActivation(): void
    {
        $this->withLock(function (): void {
            if (
                is_link($this->stateFile) ||
                is_file($this->stateFile) ||
                is_link($this->temporaryStateFile) ||
                is_file($this->temporaryStateFile) ||
                $this->hasDatabaseLeftovers()
            ) {
                throw new RuntimeException('A defense verification fixture already exists.');
            }
        });
    }

    /** @param array<string,mixed> $actorContext @return array<string,mixed> */
    public function activate(string $profile, array $actorContext): array
    {
        if (!in_array($profile, self::PROFILES, true)) {
            throw new InvalidArgumentException('Unsupported defense verification profile.');
        }

        return $this->withLock(function () use ($profile, $actorContext): array {
            if (
                is_link($this->stateFile) ||
                is_file($this->stateFile) ||
                is_link($this->temporaryStateFile) ||
                is_file($this->temporaryStateFile) ||
                $this->hasDatabaseLeftovers()
            ) {
                throw new RuntimeException('A defense verification fixture already exists.');
            }
            $actor = $this->assertActor(
                $actorContext,
                in_array(
                    $profile,
                    [
                        'customer_boundary',
                        'services_api',
                        'unavailabilities_api',
                        'blocked_periods_api',
                        'service_categories_api',
                        'secretaries_api',
                    ],
                    true,
                )
                    ? 'admin'
                    : 'provider',
            );
            $run = bin2hex(random_bytes(16));
            $marker = 'defense-verification:' . $run;
            $now = time();
            $state = [
                'schema' => self::SCHEMA,
                'profile' => $profile,
                'run_id' => $run,
                'marker' => $marker,
                'created_at' => $now,
                'expires_at' => $now + self::MAX_TTL,
                'phase' => 'prepared',
                'actor_id' => $actor['id'],
                'actor_role_id' => $actor['role_id'],
                'ids' => [],
                'links' => [],
                'intents' => [],
                'roles' => [],
                'usernames' => [],
                'search_marker' => $marker,
            ];
            // This is the first durable operation. Every subsequent insert has
            // an exact durable intent before it can reach the database.
            $this->writeState($state);
            try {
                $state =
                    $profile === 'customer_boundary'
                        ? $this->activateCustomerBoundary($state)
                        : ($profile === 'services_api'
                            ? $this->activateServicesApi($state)
                            : ($profile === 'unavailabilities_api'
                                ? $this->activateUnavailabilitiesApi($state)
                                : ($profile === 'blocked_periods_api'
                                    ? $this->activateBlockedPeriodsApi($state)
                                    : ($profile === 'service_categories_api'
                                        ? $this->activateServiceCategoriesApi($state)
                                        : ($profile === 'secretaries_api'
                                            ? $this->activateSecretariesApi($state)
                                            : $this->activateCalendarRace($state))))));
                $state['phase'] = 'active';
                $this->writeState($state);
                return $state;
            } catch (Throwable $e) {
                // Keep the prepared journal for an explicit, ownership-checked
                // recovery. Never make a failed activation look clean.
                throw $e;
            }
        });
    }

    /** @return array<string,mixed> */
    public function read(): array
    {
        $state = $this->readState();
        $this->validateState($state);
        if ($state['phase'] !== 'active') {
            throw new RuntimeException('Defense verification fixture is not active.');
        }
        $this->assertOwnership($state);
        return $state;
    }

    /** Add only the Basic-auth principal needed by the Appointments API probe. */
    public function prepareAppointmentsApi(): array
    {
        return $this->withLock(function (): array {
            $state = $this->readState();
            $this->validateState($state);
            if ($state['phase'] !== 'active' || $state['profile'] !== 'calendar_race') {
                throw new RuntimeException('Appointments API verification requires the active calendar-race graph.');
            }
            $this->assertOwnership($state);
            if (isset($state['ids']['api_admin']) || isset($state['api_credentials'])) {
                throw new RuntimeException('Appointments API verification principal already exists.');
            }
            $password = bin2hex(random_bytes(32));
            $state['roles']['admin'] = $this->role('admin');
            $this->insertUser($state, 'api_admin', $state['roles']['admin'], 'api-admin', $password);
            $state['api_credentials'] = [
                'username' => $state['usernames']['api_admin'],
                'password' => $password,
            ];
            $this->writeState($state);
            return $state;
        });
    }

    /** Non-secret snapshots for mutation-free Services API assertions. */
    public function servicesApiSnapshot(): array
    {
        return $this->withLock(function (): array {
            $state = $this->readState();
            $this->validateState($state);
            $this->assertOwnership($state);
            $snapshot = [];
            foreach (['a' => 'service_a', 'b' => 'service_b'] as $label => $key) {
                $id = (int) ($state['ids'][$key] ?? 0);
                $row = $id > 0 ? $this->db->get_where('services', ['id' => $id])->row_array() : [];
                if ($row !== []) {
                    $row['providers'] = $this->db
                        ->get_where('services_providers', ['id_services' => $id])
                        ->result_array();
                    $row['appointments'] = $this->db
                        ->select('id')
                        ->order_by('id', 'asc')
                        ->get_where('appointments', ['id_services' => $id])
                        ->result_array();
                }
                $snapshot[$label] = $row ?: [];
            }
            return $snapshot;
        });
    }

    /** Non-secret snapshots for the bounded Unavailabilities API probe. */
    public function unavailabilitiesApiSnapshot(): array
    {
        return $this->withLock(function (): array {
            $state = $this->readState();
            $this->validateState($state);
            $this->assertOwnership($state);
            $serviceId = (int) ($state['ids']['service'] ?? 0);
            $service = $serviceId > 0 ? $this->db->get_where('services', ['id' => $serviceId])->row_array() : [];
            if ((int) ($service['buffer_before'] ?? 0) !== 10 || (int) ($service['buffer_after'] ?? 0) !== 10) {
                throw new RuntimeException('Synthetic buffer configuration is not the bound 10/10 profile.');
            }
            $rows = [];
            foreach (
                ['a' => 'unavailability_a', 'b' => 'unavailability_b', 'ordinary' => 'appointment']
                as $label => $key
            ) {
                $id = (int) ($state['ids'][$key] ?? 0);
                $row = $id > 0 ? $this->db->get_where('appointments', ['id' => $id])->row_array() : [];
                if ($row !== []) {
                    $row['hash'] = hash('sha256', (string) ($row['hash'] ?? ''));
                }
                $rows[$label] = $row ?: [];
            }
            $parent = (int) ($state['ids']['appointment'] ?? 0);
            $rows['buffers'] =
                $parent > 0
                    ? array_map(
                        static function (array $row): array {
                            $row['hash'] = hash('sha256', (string) ($row['hash'] ?? ''));
                            return $row;
                        },
                        $this->db
                            ->order_by('id', 'asc')
                            ->get_where('appointments', ['id_parent_appointment' => $parent])
                            ->result_array(),
                    )
                    : [];
            if (count($rows['buffers']) !== 2) {
                throw new RuntimeException('Synthetic appointment must have exactly two generated buffers.');
            }
            return $rows;
        });
    }

    /** Non-secret snapshots for the bounded Blocked Periods API probe. */
    public function blockedPeriodsApiSnapshot(): array
    {
        return $this->withLock(function (): array {
            $state = $this->readState();
            $this->validateState($state);
            $this->assertOwnership($state);
            $rows = [];
            foreach (['a' => 'blocked_period_a', 'b' => 'blocked_period_b'] as $label => $key) {
                $id = (int) ($state['ids'][$key] ?? 0);
                $row = $id > 0 ? $this->db->get_where('blocked_periods', ['id' => $id])->row_array() : [];
                $intent = $state['intents']['blocked_periods'][$label] ?? null;
                if (!is_array($intent) || $row === []) {
                    throw new RuntimeException('Blocked period snapshot is missing an owned row.');
                }
                $allowedTimes = [[$intent['start'], $intent['end']]];
                if ($label === 'a') {
                    $allowedTimes[] = [
                        date('Y-m-d H:i:s', strtotime((string) $intent['start']) + 600),
                        date('Y-m-d H:i:s', strtotime((string) $intent['end']) + 600),
                    ];
                }
                if (
                    ($row['name'] ?? null) !== $intent['name'] ||
                    ($row['notes'] ?? null) !== $intent['notes'] ||
                    !in_array([$row['start_datetime'] ?? null, $row['end_datetime'] ?? null], $allowedTimes, true)
                ) {
                    throw new RuntimeException('Blocked period identity drift detected.');
                }
                $rows[$label] = $row ?: [];
            }
            return $rows;
        });
    }

    /** Non-secret snapshots for the bounded Service Categories API probe. */
    public function serviceCategoriesApiSnapshot(): array
    {
        return $this->withLock(function (): array {
            $state = $this->readState();
            $this->validateState($state);
            $this->assertOwnership($state);
            $rows = [];
            foreach (['a' => 'category_a', 'b' => 'category_b'] as $label => $key) {
                $id = (int) ($state['ids'][$key] ?? 0);
                $row = $id > 0 ? $this->db->get_where('service_categories', ['id' => $id])->row_array() : [];
                $intent = $state['intents']['service_categories'][$label] ?? null;
                if (!is_array($intent) || !is_array($row) || $row === []) {
                    throw new RuntimeException('Service category snapshot is missing an owned row.');
                }
                $allowed = [[$intent['name'], $intent['description']]];
                if (isset($intent['updated_name'], $intent['updated_description'])) {
                    $allowed[] = [$intent['updated_name'], $intent['updated_description']];
                }
                if (!in_array([$row['name'] ?? null, $row['description'] ?? null], $allowed, true)) {
                    throw new RuntimeException('Service category identity drift detected.');
                }
                $rows[$label] = $row;
            }
            return $rows;
        });
    }

    /** Non-secret snapshots for the bounded Secretaries API alias probe. */
    public function secretariesApiSnapshot(): array
    {
        return $this->withLock(function (): array {
            $state = $this->readState();
            $this->validateState($state);
            $this->assertOwnership($state);
            $rows = [];
            foreach (['target' => 'secretary_target', 'sentinel' => 'secretary_sentinel'] as $label => $key) {
                $id = (int) ($state['ids'][$key] ?? 0);
                $user = $id > 0 ? $this->db->get_where('users', ['id' => $id])->row_array() : [];
                $settings = $id > 0 ? $this->db->get_where('user_settings', ['id_users' => $id])->row_array() : [];
                $providers =
                    $id > 0
                        ? $this->db
                            ->order_by('id_users_provider', 'ASC')
                            ->get_where('secretaries_providers', ['id_users_secretary' => $id])
                            ->result_array()
                        : [];
                $rows[$label] = [
                    'user' => $user ?: [],
                    'settings' => $settings ?: [],
                    'providers' => $providers,
                ];
            }
            return $rows;
        });
    }

    public function beginSecretariesApiDestroyAlias(): void
    {
        $this->withLock(function (): void {
            $state = $this->readState();
            $this->validateState($state);
            if (($state['profile'] ?? null) !== 'secretaries_api') {
                throw new RuntimeException('Secretary alias stage requires its dedicated graph.');
            }
            $this->assertOwnership($state);
            $stage = $state['intents']['secretary_alias']['destroy_stage'] ?? null;
            if ($stage !== null && $stage !== 'complete') {
                throw new RuntimeException('Secretary destroy alias stage is already in progress.');
            }
            $state['intents']['secretary_alias']['destroy_stage'] = 'started';
            $this->writeState($state);
        });
    }

    public function completeSecretariesApiDestroyAlias(): void
    {
        $this->withLock(function (): void {
            $state = $this->readState();
            $this->validateState($state);
            if (($state['profile'] ?? null) !== 'secretaries_api') {
                throw new RuntimeException('Secretary alias stage requires its dedicated graph.');
            }
            if (($state['intents']['secretary_alias']['destroy_stage'] ?? null) !== 'started') {
                throw new RuntimeException('Secretary destroy alias stage is not active.');
            }
            $this->assertOwnership($state);
            $state['intents']['secretary_alias']['destroy_stage'] = 'complete';
            $this->writeState($state);
        });
    }

    /** Return the existing Bearer token, or temporarily fill one exact empty setting. */
    public function prepareAppointmentsApiBearerToken(): string
    {
        $this->assertNoActiveDatabaseTransaction();
        return $this->withLock(function (): string {
            $this->assertNoActiveDatabaseTransaction();
            $state = $this->readState();
            $this->validateState($state);
            if ($state['phase'] !== 'active' || $state['profile'] !== 'calendar_race') {
                throw new RuntimeException('Appointments API verification requires the active calendar-race graph.');
            }
            $this->assertOwnership($state);
            if (isset($state['intents']['api_token'])) {
                throw new RuntimeException('Appointments API bearer prerequisite is already prepared.');
            }
            if (!$this->db->trans_begin()) {
                throw new RuntimeException('Appointments API bearer prerequisite transaction could not start.');
            }
            try {
                $rows = $this->lockApiTokenRows();
                if (count($rows) !== 1 || (int) ($rows[0]['id'] ?? 0) < 1) {
                    throw new RuntimeException('Appointments API bearer prerequisite is missing or ambiguous.');
                }
                $value = $rows[0]['value'] ?? null;
                if (is_string($value) && $value !== '' && $value !== '0') {
                    if ($this->db->trans_status() === false || !$this->db->trans_commit()) {
                        throw new RuntimeException(
                            'Appointments API bearer prerequisite transaction could not commit.',
                        );
                    }
                    return $value;
                }
                if ($value !== '') {
                    throw new RuntimeException('Appointments API bearer prerequisite is not safely replaceable.');
                }

                $candidate = bin2hex(random_bytes(32));
                $settingId = (int) $rows[0]['id'];
                $state['intents']['api_token'] = [
                    'setting_id' => $settingId,
                    'initial_state' => 'empty',
                    'candidate_digest' => hash('sha256', $candidate),
                ];
                // The fsync-backed intent must be durable before the setting changes.
                $this->writeState($state);
                $this->applyTemporaryApiToken($settingId, $candidate);
                $current = $this->lockApiTokenRows();
                if (
                    count($current) !== 1 ||
                    (int) ($current[0]['id'] ?? 0) !== $settingId ||
                    !is_string($current[0]['value'] ?? null) ||
                    !hash_equals(
                        $state['intents']['api_token']['candidate_digest'],
                        hash('sha256', $current[0]['value']),
                    )
                ) {
                    throw new RuntimeException('Appointments API bearer prerequisite could not be verified.');
                }
                if ($this->db->trans_status() === false || !$this->db->trans_commit()) {
                    throw new RuntimeException('Appointments API bearer prerequisite transaction could not commit.');
                }
                return $candidate;
            } catch (Throwable $error) {
                $this->db->trans_rollback();
                throw $error;
            }
        });
    }

    /** Keep the candidate out of CI-rendered SQL, its query cache and application DB errors. */
    private function applyTemporaryApiToken(int $settingId, string $candidate): void
    {
        if (
            ($this->db->dbdriver ?? null) !== 'mysqli' ||
            !property_exists($this->db, 'conn_id') ||
            !($this->db->conn_id instanceof \mysqli)
        ) {
            throw new RuntimeException('Appointments API bearer prerequisite requires a native mysqli connection.');
        }
        $statement = null;
        try {
            $sql =
                'UPDATE `' .
                $this->db->dbprefix('settings') .
                '` SET value = ? WHERE id = ? AND name = ? AND value = ?';
            $statement = $this->db->conn_id->prepare($sql);
            if (!($statement instanceof \mysqli_stmt)) {
                throw new RuntimeException('prepare failed');
            }
            $name = 'api_token';
            $empty = '';
            if (
                !$statement->bind_param('siss', $candidate, $settingId, $name, $empty) ||
                !$statement->execute() ||
                $statement->affected_rows !== 1
            ) {
                throw new RuntimeException('execute failed');
            }
        } catch (Throwable) {
            // Do not retain the driver exception: it may contain statement data.
            throw new RuntimeException('Appointments API bearer prerequisite could not be applied.');
        } finally {
            if ($statement instanceof \mysqli_stmt) {
                $statement->close();
            }
        }
    }

    /** Journal the exact recoverable identity before one API POST. */
    public function prepareApiAppointment(string $case, array $window = []): array
    {
        $this->assertApiCase($case);
        $this->assertApiWindow($window);
        return $this->withLock(function () use ($case, $window): array {
            $state = $this->readState();
            $this->validateState($state);
            $this->assertApiReady($state);
            $this->assertOwnership($state);
            if (isset($state['intents']['api_appointments'][$case])) {
                throw new RuntimeException('Appointments API intent already exists.');
            }
            $offset = $case === 'basic' ? 35 : 36;
            $payload = [
                'start' => gmdate('Y-m-d 11:00:00', $state['created_at'] + 86400 * $offset),
                'end' => gmdate('Y-m-d 11:30:00', $state['created_at'] + 86400 * $offset),
                'location' => 'Synthetic API ' . ucfirst($case),
                'color' => '#6c757d',
                'status' => 'Booked',
                'notes' => $state['marker'] . ':api:' . $case,
                'customerId' => (int) $state['customer_id'],
                'providerId' => (int) $state['actor_id'],
                'serviceId' => (int) $state['service_id'],
            ];
            $payload = array_replace($payload, $window);
            $state['intents']['api_appointments'][$case] = ['stage' => 'create_prepared', 'create' => $payload];
            $this->writeState($state);
            return $payload;
        });
    }

    public function confirmApiAppointmentCreated(string $case, int $id): void
    {
        $this->assertApiCase($case);
        $this->withLock(function () use ($case, $id): void {
            $state = $this->readState();
            $this->validateState($state);
            $intent = $this->apiIntent($state, $case, 'create_prepared');
            $row = $this->apiAppointmentRow($id);
            $this->assertApiAppointment($row, $intent['create']);
            $hash = $this->assertApiHash($row);
            $state['ids']['api_appointment_' . $case] = $id;
            $state['intents']['api_appointments'][$case]['hash_digest'] = hash('sha256', $hash);
            $state['intents']['api_appointments'][$case]['stage'] = 'created';
            $this->writeState($state);
        });
    }

    public function prepareApiAppointmentUpdate(string $case, array $window = []): array
    {
        $this->assertApiCase($case);
        $this->assertApiWindow($window);
        return $this->withLock(function () use ($case, $window): array {
            $state = $this->readState();
            $this->validateState($state);
            $this->assertOwnership($state);
            $intent = $this->apiIntent($state, $case, 'created');
            $payload = $intent['create'];
            $payload['location'] = 'Updated Synthetic API ' . ucfirst($case);
            $payload['color'] = $case === 'basic' ? '#123456' : '#654321';
            $payload['status'] = 'Confirmed';
            $payload['notes'] = $state['marker'] . ':api:' . $case . ':updated';
            $payload = array_replace($payload, $window);
            $state['intents']['api_appointments'][$case]['update'] = $payload;
            $state['intents']['api_appointments'][$case]['stage'] = 'update_prepared';
            $this->writeState($state);
            return $payload;
        });
    }

    public function confirmApiAppointmentUpdated(string $case): void
    {
        $this->assertApiCase($case);
        $this->withLock(function () use ($case): void {
            $state = $this->readState();
            $this->validateState($state);
            $intent = $this->apiIntent($state, $case, 'update_prepared');
            $row = $this->apiAppointmentRow((int) $state['ids']['api_appointment_' . $case]);
            $this->assertApiAppointment($row, $intent['update']);
            $this->assertApiHashDigest($row, $intent['hash_digest']);
            $state['intents']['api_appointments'][$case]['stage'] = 'updated';
            $this->writeState($state);
        });
    }

    public function confirmApiAppointmentUnchanged(string $case): void
    {
        $this->assertApiCase($case);
        $this->withLock(function () use ($case): void {
            $state = $this->readState();
            $this->validateState($state);
            $intent = $this->apiIntent($state, $case, 'update_prepared');
            $row = $this->apiAppointmentRow((int) $state['ids']['api_appointment_' . $case]);
            $this->assertApiAppointment($row, $intent['create']);
            $this->assertApiHashDigest($row, $intent['hash_digest']);
            unset($state['intents']['api_appointments'][$case]['update']);
            $state['intents']['api_appointments'][$case]['stage'] = 'created';
            $this->writeState($state);
        });
    }

    public function prepareApiAppointmentDelete(string $case): int
    {
        $this->assertApiCase($case);
        return $this->withLock(function () use ($case): int {
            $state = $this->readState();
            $this->validateState($state);
            $this->assertOwnership($state);
            $this->apiIntent($state, $case, 'updated');
            $state['intents']['api_appointments'][$case]['stage'] = 'delete_prepared';
            $this->writeState($state);
            return (int) $state['ids']['api_appointment_' . $case];
        });
    }

    /**
     * Hold only the canonical parents and empty child range while an
     * independent HTTP connection performs the first positive DELETE.
     * The target appointment itself deliberately remains unlocked here.
     */
    public function guardApiAppointmentDelete(string $case, callable $delete): mixed
    {
        $this->assertApiCase($case);
        $this->assertNoActiveDatabaseTransaction();
        return $this->withLock(function () use ($case, $delete): mixed {
            $this->assertNoActiveDatabaseTransaction();
            $state = $this->readState();
            $this->validateState($state);
            $intent = $this->apiIntent($state, $case, 'delete_prepared');
            $targetId = (int) ($state['ids']['api_appointment_' . $case] ?? 0);
            $target = $this->apiAppointmentRow($targetId);
            $this->assertApiAppointment($target, $intent['update']);
            $this->assertApiHashDigest($target, $intent['hash_digest']);

            if (!$this->db->trans_begin()) {
                throw new RuntimeException('Appointments API delete guard transaction could not start.');
            }
            try {
                $userIds = [(int) $state['actor_id'], (int) $state['customer_id']];
                sort($userIds, SORT_NUMERIC);
                $userRows = $this->db
                    ->query(
                        'SELECT id FROM `' .
                            $this->db->dbprefix('users') .
                            '` WHERE id IN (?, ?) ORDER BY id ASC FOR UPDATE',
                        $userIds,
                    )
                    ->result_array();
                if (array_map(static fn(array $row): int => (int) $row['id'], $userRows) !== $userIds) {
                    throw new RuntimeException('Appointments API delete parent lock set is incomplete.');
                }
                $serviceRows = $this->db
                    ->query('SELECT id FROM `' . $this->db->dbprefix('services') . '` WHERE id = ? FOR UPDATE', [
                        (int) $state['service_id'],
                    ])
                    ->result_array();
                if (count($serviceRows) !== 1) {
                    throw new RuntimeException('Appointments API delete service parent lock is unavailable.');
                }
                // A compliant concurrent writer acquires these same parents
                // before changing the appointment. Re-read after the waits so
                // a row changed between the initial check and our locks can
                // never be passed to the HTTP DELETE.
                $target = $this->apiAppointmentRow($targetId);
                $this->assertApiAppointment($target, $intent['update']);
                $this->assertApiHashDigest($target, $intent['hash_digest']);
                $childrenSql =
                    'SELECT id FROM `' .
                    $this->db->dbprefix('appointments') .
                    '` WHERE id_parent_appointment = ? ORDER BY id ASC FOR UPDATE';
                if ($this->db->query($childrenSql, [$targetId])->result_array() !== []) {
                    throw new RuntimeException('Appointments API delete target has an unexpected child.');
                }

                $result = $delete();

                if ($this->db->query($childrenSql, [$targetId])->result_array() !== []) {
                    throw new RuntimeException('Appointments API delete target gained an unexpected child.');
                }
                if ($this->db->trans_status() === false || !$this->db->trans_commit()) {
                    throw new RuntimeException('Appointments API delete guard transaction could not commit.');
                }
                return $result;
            } catch (Throwable $error) {
                $this->db->trans_rollback();
                throw $error;
            }
        });
    }

    public function confirmApiAppointmentDeleted(string $case): void
    {
        $this->assertApiCase($case);
        $this->withLock(function () use ($case): void {
            $state = $this->readState();
            $this->validateState($state);
            $this->apiIntent($state, $case, 'delete_prepared');
            $id = (int) $state['ids']['api_appointment_' . $case];
            if ($this->db->get_where('appointments', ['id' => $id])->num_rows() !== 0) {
                throw new RuntimeException('Appointments API delete did not remove the owned row.');
            }
            $state['intents']['api_appointments'][$case]['stage'] = 'deleted';
            $this->writeState($state);
        });
    }

    /** Non-secret snapshot for mutation-free and URI-binding assertions. */
    public function apiSnapshot(): array
    {
        return $this->withLock(function (): array {
            $state = $this->readState();
            $this->validateState($state);
            $this->assertOwnership($state);
            $rows = [];
            foreach (
                ['sentinel' => 'appointment', 'basic' => 'api_appointment_basic', 'bearer' => 'api_appointment_bearer']
                as $case => $key
            ) {
                $id = (int) ($state['ids'][$key] ?? 0);
                $row = $id > 0 ? $this->db->get_where('appointments', ['id' => $id])->row_array() : [];
                if ($row) {
                    $row['hash'] = hash('sha256', (string) ($row['hash'] ?? ''));
                }
                $rows[$case] = $row ?: [];
            }
            return $rows;
        });
    }

    public function verify(): string
    {
        if (!file_exists($this->stateFile)) {
            return is_link($this->temporaryStateFile) ||
                is_file($this->temporaryStateFile) ||
                $this->hasDatabaseLeftovers()
                ? 'cleanup_pending'
                : 'clean';
        }
        $state = $this->readState();
        $this->validateState($state);
        if ($state['phase'] !== 'active') {
            return 'cleanup_pending';
        }
        try {
            $this->assertOwnership($state);
        } catch (Throwable) {
            return 'cleanup_pending';
        }
        return $state['expires_at'] > time() ? 'active' : 'expired';
    }

    public function retainForRecovery(string $reason): void
    {
        if ($reason !== 'calendar_request_termination_unconfirmed') {
            throw new InvalidArgumentException('Unsupported defense verification recovery reason.');
        }
        $this->withLock(function () use ($reason): void {
            $state = $this->readState();
            $this->validateState($state);
            if ($state['phase'] === 'recovery_required' && ($state['recovery_reason'] ?? null) === $reason) {
                return;
            }
            if ($state['phase'] !== 'active') {
                throw new RuntimeException('Only an active defense verification fixture can enter recovery state.');
            }
            $state['phase'] = 'recovery_required';
            $state['recovery_reason'] = $reason;
            $this->writeState($state);
        });
    }

    public function deactivate(): void
    {
        $this->assertNoActiveDatabaseTransaction();
        $this->withLock(function (): void {
            if (!file_exists($this->stateFile)) {
                return;
            }
            $this->assertNoActiveDatabaseTransaction();
            $state = $this->readState();
            $this->validateState($state);
            if ($state['phase'] === 'recovery_required') {
                throw new RuntimeException('Defense verification fixture requires explicit request recovery.');
            }
            $wasCleaning = $state['phase'] === 'cleaning';
            $wasPrepared = $state['phase'] === 'prepared' || ($state['cleanup_origin_phase'] ?? null) === 'prepared';
            $state = $this->recoverExactIds($state);
            $state = $this->reconcileSecretariesApiDestroyAlias($state);
            if (isset($state['intents']['appointment']) && !isset($state['ids']['appointment'])) {
                throw new RuntimeException('Appointment intent could not be reconstructed; refusing cleanup.');
            }
            if (!$wasCleaning) {
                if ($state['phase'] === 'active') {
                    $this->assertOwnership(
                        $state,
                        in_array(
                            $state['intents']['users']['api_admin']['stage'] ?? null,
                            ['prepared', 'settings_prepared'],
                            true,
                        ),
                        isset($state['intents']['api_token']),
                    );
                } else {
                    $this->assertPreparedOwnership($state);
                }
                if ($wasPrepared) {
                    $state['cleanup_origin_phase'] = 'prepared';
                }
                $state['phase'] = 'cleaning';
                $this->writeState($state);
            }
            $this->restoreTemporaryApiTokenCommitted($state);
            $this->assertNoActiveDatabaseTransaction();
            if (!$this->db->trans_begin()) {
                throw new RuntimeException('Defense verification cleanup transaction could not start.');
            }
            try {
                $this->deleteOwned($state, $wasCleaning, $wasPrepared);
                if ($this->remainingIds($state) !== []) {
                    throw new RuntimeException('Defense verification fixture rows remain after cleanup.');
                }
                if ($this->db->trans_status() === false || !$this->db->trans_commit()) {
                    throw new RuntimeException('Defense verification cleanup transaction could not commit.');
                }
            } catch (Throwable $error) {
                $this->db->trans_rollback();
                throw $error;
            }
            if (!unlink($this->stateFile)) {
                throw new RuntimeException('Defense verification state could not be removed.');
            }
            $this->syncDirectory();
        });
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function activateCustomerBoundary(array $state): array
    {
        $adminRole = $this->role('admin');
        $providerRole = $this->role('provider');
        $customerRole = $this->role('customer');
        $state['roles'] = ['admin' => $adminRole, 'provider' => $providerRole, 'customer' => $customerRole];
        $state['ids']['provider_target'] = $this->insertUser(
            $state,
            'provider_target',
            $providerRole,
            'provider-target',
        );
        $state['ids']['admin_target'] = $this->insertUser($state, 'admin_target', $adminRole, 'admin-target');
        $state['ids']['customer_find_update'] = $this->insertCustomer($state, 'find-update', $customerRole);
        $state['ids']['customer_destroy'] = $this->insertCustomer($state, 'destroy', $customerRole);
        $state['provider_target_id'] = $state['ids']['provider_target'];
        $state['admin_target_id'] = $state['ids']['admin_target'];
        $state['customer_update_id'] = $state['ids']['customer_find_update'];
        $state['customer_delete_id'] = $state['ids']['customer_destroy'];
        return $state;
    }

    /** Prepare two owned Secretaries and one provider relationship for alias checks. */
    private function activateSecretariesApi(array $state): array
    {
        $secretaryRole = $this->role('secretary');
        $providerRole = $this->role('provider');
        $state['roles'] = ['secretary' => $secretaryRole, 'provider' => $providerRole];
        $state['ids']['provider_target'] = $this->insertUser(
            $state,
            'provider_target',
            $providerRole,
            'secretary-api-provider',
        );
        foreach (['secretary_target', 'secretary_sentinel'] as $key) {
            $state['ids'][$key] = $this->insertUser($state, $key, $secretaryRole, 'secretary-api');
            $state['links'][$key] = [
                'id_users_secretary' => $state['ids'][$key],
                'id_users_provider' => $state['ids']['provider_target'],
            ];
            $state['intents']['secretary_links'][$key] = ['stage' => 'prepared'];
            $this->journal($state);
            $this->insertExact('secretaries_providers', $state['links'][$key]);
            $state['intents']['secretary_links'][$key]['stage'] = 'complete';
            $this->journal($state);
        }
        return $state;
    }

    /** Prepare two independently owned services for the Services API probe. */
    private function activateServicesApi(array $state): array
    {
        $adminRole = $this->role('admin');
        $providerRole = $this->role('provider');
        $state['roles'] = ['admin' => $adminRole];
        $state['roles']['provider'] = $providerRole;
        $state['ids']['provider_target'] = $this->insertUser(
            $state,
            'provider_target',
            $providerRole,
            'services-api-provider',
        );
        $state['ids']['service_a'] = $this->insertServiceForKey($state, 'service_a', 'A');
        $state['ids']['service_b'] = $this->insertServiceForKey($state, 'service_b', 'B');
        foreach (['service_a', 'service_b'] as $key) {
            $linkKey = 'provider_' . $key;
            $state['links'][$linkKey] = [
                'id_users' => $state['ids']['provider_target'],
                'id_services' => $state['ids'][$key],
            ];
            $this->journal($state);
            $this->insertExact('services_providers', $state['links'][$linkKey]);
        }
        return $state;
    }

    /** Prepare two manual unavailabilities and one buffered ordinary appointment. */
    private function activateUnavailabilitiesApi(array $state): array
    {
        $providerRole = $this->role('provider');
        $customerRole = $this->role('customer');
        $state['roles'] = ['admin' => $this->role('admin'), 'provider' => $providerRole, 'customer' => $customerRole];
        $state['ids']['provider_target'] = $this->insertUser(
            $state,
            'provider_target',
            $providerRole,
            'unavailability-provider',
        );
        $state['ids']['calendar_customer'] = $this->insertCustomer($state, 'calendar_customer', $customerRole);
        $state['ids']['service'] = $this->insertServiceForKey($state, 'service', 'buffered');
        $state['intents']['buffer_configuration'] = [
            'service_id' => $state['ids']['service'],
            'buffer_before' => 10,
            'buffer_after' => 10,
        ];
        $this->journal($state);
        if (
            !$this->db->update(
                'services',
                ['buffer_before' => 10, 'buffer_after' => 10],
                ['id' => $state['ids']['service']],
            )
        ) {
            throw new RuntimeException('Could not configure synthetic service buffers.');
        }
        $service = $this->db->get_where('services', ['id' => $state['ids']['service']])->row_array();
        if ((int) ($service['buffer_before'] ?? 0) !== 10 || (int) ($service['buffer_after'] ?? 0) !== 10) {
            throw new RuntimeException('Synthetic service buffer configuration was not confirmed.');
        }
        $state['links']['actor_service'] = [
            'id_users' => $state['ids']['provider_target'],
            'id_services' => $state['ids']['service'],
        ];
        $this->journal($state);
        $this->insertExact('services_providers', $state['links']['actor_service']);
        $ci = &\get_instance();
        $ci->load->model('unavailabilities_model');
        $ci->load->model('appointments_model');
        $base = time() + 86400 * 40;
        foreach (['a' => 9, 'b' => 12] as $key => $hour) {
            $start = gmdate('Y-m-d ' . sprintf('%02d:00:00', $hour), $base);
            $end = gmdate('Y-m-d ' . sprintf('%02d:30:00', $hour), $base);
            $state['intents']['unavailabilities'][$key] = [
                'marker' => $state['marker'] . ':manual:' . $key,
                'provider_id' => $state['ids']['provider_target'],
                'start' => $start,
                'end' => $end,
            ];
            $this->journal($state);
            $state['ids']['unavailability_' . $key] = (int) $ci->unavailabilities_model->save([
                'start_datetime' => $start,
                'end_datetime' => $end,
                'id_users_provider' => $state['ids']['provider_target'],
                'notes' => $state['intents']['unavailabilities'][$key]['marker'],
            ]);
            $this->journal($state);
        }
        $start = gmdate('Y-m-d 15:00:00', $base);
        $end = gmdate('Y-m-d 15:30:00', $base);
        $state['intents']['appointment'] = [
            'marker' => $state['marker'] . ':ordinary',
            'provider_id' => $state['ids']['provider_target'],
            'customer_id' => $state['ids']['calendar_customer'],
            'service_id' => $state['ids']['service'],
            'start' => $start,
            'end' => $end,
        ];
        $this->journal($state);
        $state['ids']['appointment'] = (int) $ci->appointments_model->save([
            'start_datetime' => $start,
            'end_datetime' => $end,
            'notes' => $state['intents']['appointment']['marker'],
            'is_unavailability' => 0,
            'id_users_provider' => $state['ids']['provider_target'],
            'id_users_customer' => $state['ids']['calendar_customer'],
            'id_services' => $state['ids']['service'],
        ]);
        $this->journal($state);
        $children = $this->db
            ->get_where('appointments', [
                'id_parent_appointment' => $state['ids']['appointment'],
                'is_unavailability' => 1,
            ])
            ->result_array();
        if (count($children) !== 2) {
            throw new RuntimeException('Synthetic appointment did not create exactly two buffers.');
        }
        return $state;
    }

    /** Prepare exactly two owned global blocked periods for the API probe. */
    private function activateBlockedPeriodsApi(array $state): array
    {
        foreach (['a', 'b'] as $key) {
            // Global blocked periods affect every provider. Keep the synthetic
            // windows unambiguously in the past so the live probe cannot close
            // a current or future booking slot.
            $base = time() - 86400 * 40 + ($key === 'b' ? 7200 : 0);
            $start = gmdate('Y-m-d H:i:s', $base);
            $end = gmdate('Y-m-d H:i:s', $base + 1800);
            $state['intents']['blocked_periods'][$key] = [
                'name' => $state['marker'] . ':blocked:' . $key,
                'start' => $start,
                'end' => $end,
                'notes' => $state['marker'] . ':blocked:' . $key,
            ];
            $this->journal($state);
            if (
                !$this->db->insert('blocked_periods', [
                    'name' => $state['intents']['blocked_periods'][$key]['name'],
                    'start_datetime' => $start,
                    'end_datetime' => $end,
                    'notes' => $state['intents']['blocked_periods'][$key]['notes'],
                    'create_datetime' => date('Y-m-d H:i:s'),
                    'update_datetime' => date('Y-m-d H:i:s'),
                ])
            ) {
                throw new RuntimeException('Could not insert synthetic blocked period.');
            }
            $state['ids']['blocked_period_' . $key] = (int) $this->db->insert_id();
            $this->journal($state);
        }
        return $state;
    }

    /** Prepare two independently owned global service categories for the API probe. */
    private function activateServiceCategoriesApi(array $state): array
    {
        $state['roles'] = ['admin' => $this->role('admin')];
        $state['intents']['service_categories'] = [];
        foreach (['a', 'b'] as $key) {
            $name = $state['marker'] . ':category:' . $key;
            $description = 'Synthetic ' . $state['run_id'] . ' ' . strtoupper($key);
            $state['intents']['service_categories'][$key] = [
                'name' => $name,
                'description' => $description,
                'updated_name' => $state['marker'] . ':category:' . $key . ':updated',
                'updated_description' => $description . ' updated',
            ];
            $this->journal($state);
            if (
                !$this->db->insert('service_categories', [
                    'name' => $name,
                    'description' => $description,
                    'create_datetime' => date('Y-m-d H:i:s'),
                    'update_datetime' => date('Y-m-d H:i:s'),
                ])
            ) {
                throw new RuntimeException('Could not insert synthetic service category.');
            }
            $state['ids']['category_' . $key] = (int) $this->db->insert_id();
            $this->journal($state);
        }
        return $state;
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function activateCalendarRace(array $state): array
    {
        $providerRole = $this->role('provider');
        $customerRole = $this->role('customer');
        $state['roles'] = ['provider' => $providerRole, 'customer' => $customerRole];
        $foreign = $this->insertUser($state, 'foreign_provider', $providerRole, 'foreign-provider');
        $customer = $this->insertCustomer($state, 'calendar-customer', $customerRole);
        $service = $this->insertService($state);
        $state['ids']['foreign_provider'] = $foreign;
        $state['ids']['calendar_customer'] = $customer;
        $state['ids']['service'] = $service;
        $state['links']['actor_service'] = ['id_users' => $state['actor_id'], 'id_services' => $service];
        $this->journal($state);
        $this->insertExact('services_providers', $state['links']['actor_service']);
        $state['links']['foreign_service'] = ['id_users' => $foreign, 'id_services' => $service];
        $this->journal($state);
        $this->insertExact('services_providers', $state['links']['foreign_service']);
        $ci = &\get_instance();
        $ci->load->model('appointments_model');
        $start = gmdate('Y-m-d H:i:s', time() + 86400 * 30);
        $end = gmdate('Y-m-d H:i:s', time() + 86400 * 30 + 1800);
        $state['intents']['appointment'] = [
            'marker' => $state['marker'],
            'provider_id' => $state['actor_id'],
            'customer_id' => $customer,
            'service_id' => $service,
            'start' => $start,
            'end' => $end,
        ];
        $this->journal($state);
        $appointment = $ci->appointments_model->save([
            'start_datetime' => $start,
            'end_datetime' => $end,
            'location' => null,
            'color' => '#6c757d',
            'status' => 'Booked',
            'notes' => $state['marker'],
            'is_unavailability' => 0,
            'id_users_provider' => $state['actor_id'],
            'id_users_customer' => $customer,
            'id_services' => $service,
            'id_parent_appointment' => null,
        ]);
        $state['ids']['appointment'] = $appointment;
        $state['appointment_id'] = $appointment;
        $state['foreign_provider_id'] = $foreign;
        $state['customer_id'] = $customer;
        $state['service_id'] = $service;
        $this->journal($state);
        return $state;
    }

    /** @param array<string,mixed> $state */
    private function insertUser(array &$state, string $key, int $role, string $label, ?string $password = null): int
    {
        $username = 'defense_verify_' . $state['run_id'] . '_' . $key;
        $email = $username . '@synthetic.invalid';
        $state['intents']['users'][$key] = [
            'stage' => 'prepared',
            'email' => $email,
            'username' => $username,
            'role_id' => $role,
            'marker' => $state['marker'],
        ];
        $this->journal($state);
        $this->insertExact('users', [
            'first_name' => 'Synthetic ' . $key,
            'last_name' => $label,
            'email' => $email,
            'phone_number' => '9' . sprintf('%08u', crc32($state['run_id'] . $key)),
            'notes' => $state['marker'],
            'timezone' => 'UTC',
            'language' => 'english',
            'id_roles' => $role,
            'is_private' => 1,
        ]);
        $id = (int) $this->db->insert_id();
        $salt = \generate_salt();
        $settings = [
            'id_users' => $id,
            'username' => $username,
            'password' => \hash_password($salt, $password ?? bin2hex(random_bytes(32))),
            'salt' => $salt,
            'working_plan' => '{}',
            'working_plan_exceptions' => '{}',
            'notifications' => 0,
            'google_sync' => 0,
            'google_token' => null,
            'google_calendar' => null,
            'sync_past_days' => 30,
            'sync_future_days' => 90,
            'calendar_view' => 'default',
            'caldav_sync' => 0,
            'caldav_url' => null,
            'caldav_username' => null,
            'caldav_password' => null,
            'dashboard_range_start' => null,
            'dashboard_range_end' => null,
        ];
        $state['ids'][$key] = $id;
        $state['usernames'][$key] = $username;
        $state['intents']['users'][$key]['settings_digest'] = $this->userSettingsDigest($settings);
        $state['intents']['users'][$key]['stage'] = 'settings_prepared';
        $this->journal($state);
        $this->insertExact('user_settings', $settings);
        $state['intents']['users'][$key]['stage'] = 'complete';
        $this->journal($state);
        return $id;
    }

    /** @param array<string,mixed> $state */
    private function insertCustomer(array &$state, string $key, int $role): int
    {
        $identity =
            $key === 'find-update'
                ? 'customer_find_update'
                : ($key === 'destroy'
                    ? 'customer_destroy'
                    : 'calendar_customer');
        $email = $state['run_id'] . '-' . $key . '@synthetic.invalid';
        $state['intents']['users'][$identity] = ['email' => $email, 'role_id' => $role, 'marker' => $state['marker']];
        $this->journal($state);
        $this->insertExact('users', [
            'first_name' => 'Synthetic',
            'last_name' => $key,
            'email' => $email,
            'phone_number' => '000000000',
            'notes' => $state['marker'],
            'timezone' => 'UTC',
            'language' => 'english',
            'id_roles' => $role,
            'is_private' => 1,
        ]);
        $id = (int) $this->db->insert_id();
        $state['ids'][
            $key === 'find-update'
                ? 'customer_find_update'
                : ($key === 'destroy'
                    ? 'customer_destroy'
                    : 'calendar_customer')
        ] = $id;
        $this->journal($state);
        return $id;
    }

    /** @param array<string,mixed> $state */
    private function insertService(array &$state): int
    {
        return $this->insertServiceForKey($state, 'service', '');
    }

    /** @param array<string,mixed> $state */
    private function insertServiceForKey(array &$state, string $key, string $suffix): int
    {
        $description = $key === 'service' ? $state['marker'] : $state['marker'] . ':' . $key;
        $state['intents'][$key] = ['description' => $description];
        $this->journal($state);
        $this->insertExact('services', [
            'name' => 'Synthetic ' . $state['run_id'] . ($suffix === '' ? '' : ' ' . $suffix),
            'duration' => 30,
            'buffer_before' => 0,
            'buffer_after' => 0,
            'price' => 0,
            'currency' => 'EUR',
            'description' => $description,
            'location' => null,
            'color' => '#6c757d',
            'availabilities_type' => AVAILABILITIES_TYPE_FLEXIBLE,
            'attendants_number' => 1,
            'is_private' => 1,
            'id_service_categories' => null,
        ]);
        $id = (int) $this->db->insert_id();
        $state['ids'][$key] = $id;
        $this->journal($state);
        return $id;
    }

    private function insertExact(string $table, array $row): void
    {
        if (!$this->db->insert($table, $row)) {
            throw new RuntimeException('Could not insert defense verification row into ' . $table . '.');
        }
    }

    /** @param array<string,mixed> $state */
    private function assertOwnership(
        array $state,
        bool $allowIncompleteApiAdmin = false,
        bool $allowRecoverableApiToken = false,
    ): void {
        $this->assertApiTokenOwnership($state, $allowRecoverableApiToken);
        $apiAdminStage = $state['intents']['users']['api_admin']['stage'] ?? null;
        if (in_array($apiAdminStage, ['prepared', 'settings_prepared'], true) && !$allowIncompleteApiAdmin) {
            throw new RuntimeException('Appointments API principal creation is incomplete.');
        }
        foreach ($state['ids'] as $key => $id) {
            if (str_starts_with($key, 'api_appointment_')) {
                continue;
            }
            $id = (int) $id;
            if ($id < 1) {
                throw new RuntimeException('Invalid journaled fixture ID.');
            }
            $table = str_starts_with($key, 'blocked_period_')
                ? 'blocked_periods'
                : (str_starts_with($key, 'category_')
                    ? 'service_categories'
                    : (str_contains($key, 'service_link')
                        ? 'services_providers'
                        : (in_array($key, ['service', 'service_a', 'service_b'], true)
                            ? 'services'
                            : ($key === 'appointment' ||
                            str_starts_with($key, 'api_appointment_') ||
                            str_starts_with($key, 'unavailability_')
                                ? 'appointments'
                                : 'users'))));
            if ($this->db->get_where($table, ['id' => $id])->num_rows() !== 1) {
                throw new RuntimeException('Fixture ownership is missing or ambiguous.');
            }
        }
        foreach (
            [
                'provider_target',
                'admin_target',
                'foreign_provider',
                'api_admin',
                'customer_find_update',
                'customer_destroy',
                'calendar_customer',
                'secretary_target',
                'secretary_sentinel',
            ]
            as $key
        ) {
            if (isset($state['ids'][$key])) {
                $user = $this->db->get_where('users', ['id' => $state['ids'][$key]])->row_array();
                if (($user['notes'] ?? null) !== $state['marker']) {
                    throw new RuntimeException('Fixture identity drift detected.');
                }
                $roleKey = in_array($key, ['provider_target', 'foreign_provider'], true)
                    ? 'provider'
                    : (in_array($key, ['admin_target', 'api_admin'], true)
                        ? 'admin'
                        : (in_array($key, ['secretary_target', 'secretary_sentinel'], true)
                            ? 'secretary'
                            : 'customer'));
                if ((int) ($user['id_roles'] ?? 0) !== (int) $state['roles'][$roleKey]) {
                    throw new RuntimeException('Fixture role drift detected.');
                }
                if (isset($state['usernames'][$key])) {
                    $settings = $this->db
                        ->get_where('user_settings', ['id_users' => $state['ids'][$key]])
                        ->result_array();
                    if ($allowIncompleteApiAdmin && $key === 'api_admin' && $apiAdminStage === 'prepared') {
                        if ($settings !== []) {
                            throw new RuntimeException('Prepared Appointments API principal has unexpected settings.');
                        }
                        continue;
                    }
                    if ($allowIncompleteApiAdmin && $key === 'api_admin' && $apiAdminStage === 'settings_prepared') {
                        if (
                            count($settings) > 1 ||
                            (count($settings) === 1 &&
                                !$this->settingsMatchDigest(
                                    $settings[0],
                                    $state['intents']['users']['api_admin']['settings_digest'] ?? null,
                                ))
                        ) {
                            throw new RuntimeException('Prepared Appointments API principal settings drifted.');
                        }
                        continue;
                    }
                    if (count($settings) !== 1 || ($settings[0]['username'] ?? null) !== $state['usernames'][$key]) {
                        throw new RuntimeException('Fixture username drift detected.');
                    }
                } elseif (
                    $this->db->get_where('user_settings', ['id_users' => $state['ids'][$key]])->num_rows() !== 0
                ) {
                    throw new RuntimeException('Synthetic customer unexpectedly has login settings.');
                }
            }
        }
        foreach (['service_a', 'service_b'] as $key) {
            if (isset($state['ids'][$key])) {
                $service = $this->db->get_where('services', ['id' => (int) $state['ids'][$key]])->row_array();
                if (($service['description'] ?? null) !== $state['marker'] . ':' . $key) {
                    throw new RuntimeException('Fixture service identity drift detected.');
                }
                if ((int) ($service['attendants_number'] ?? 0) !== 1) {
                    throw new RuntimeException('Fixture service contract drift detected.');
                }
            }
        }
        foreach (['service', 'service_a', 'service_b'] as $key) {
            if (!isset($state['ids'][$key])) {
                continue;
            }
            $description = $state['marker'] . ($key === 'service' ? '' : ':' . $key);
            $this->assertExactRow('services', (int) $state['ids'][$key], [
                'description' => $description,
                'attendants_number' => 1,
            ]);
        }
        if (isset($state['ids']['appointment'])) {
            $this->assertExactRow('appointments', (int) $state['ids']['appointment'], [
                'notes' => $state['intents']['appointment']['marker'] ?? $state['marker'],
                'id_users_provider' => (int) ($state['intents']['appointment']['provider_id'] ?? $state['actor_id']),
                'id_users_customer' => $state['ids']['calendar_customer'],
                'id_services' => $state['ids']['service'],
            ]);
        }
        foreach ($state['intents']['unavailabilities'] ?? [] as $key => $intent) {
            if (isset($state['ids']['unavailability_' . $key])) {
                $this->assertExactRow('appointments', (int) $state['ids']['unavailability_' . $key], [
                    'notes' => $intent['marker'],
                    'id_users_provider' => (int) $intent['provider_id'],
                    'is_unavailability' => 1,
                ]);
            }
        }
        foreach (['basic', 'bearer'] as $case) {
            $intent = $state['intents']['api_appointments'][$case] ?? null;
            if (!is_array($intent)) {
                continue;
            }
            $id = (int) ($state['ids']['api_appointment_' . $case] ?? 0);
            $stage = $intent['stage'] ?? null;
            $row = $id > 0 ? $this->db->get_where('appointments', ['id' => $id])->row_array() : [];
            if ($id === 0 && $stage === 'create_prepared') {
                continue;
            }
            if (in_array($stage, ['delete_prepared', 'deleted'], true) && !$row) {
                continue;
            }
            if (!$row) {
                throw new RuntimeException('Appointments API fixture row disappeared.');
            }
            if ($stage === 'update_prepared') {
                try {
                    $this->assertApiAppointment($row, $intent['create']);
                } catch (Throwable) {
                    $this->assertApiAppointment($row, $intent['update']);
                }
            } elseif (in_array($stage, ['updated', 'delete_prepared'], true)) {
                $this->assertApiAppointment($row, $intent['update']);
            } else {
                $this->assertApiAppointment($row, $intent['create']);
            }
            $this->assertApiHashDigest($row, $intent['hash_digest']);
        }
        foreach ($state['links'] ?? [] as $linkKey => $link) {
            if (!is_array($link)) {
                throw new RuntimeException('Fixture relationship journal is invalid.');
            }
            if (isset($link['id_users_secretary'], $link['id_users_provider'])) {
                $matches = $this->db
                    ->get_where('secretaries_providers', [
                        'id_users_secretary' => (int) $link['id_users_secretary'],
                        'id_users_provider' => (int) $link['id_users_provider'],
                    ])
                    ->num_rows();
            } else {
                $matches = $this->db
                    ->get_where('services_providers', [
                        'id_users' => (int) ($link['id_users'] ?? 0),
                        'id_services' => (int) ($link['id_services'] ?? 0),
                    ])
                    ->num_rows();
            }
            if ($matches !== 1) {
                throw new RuntimeException('Fixture relationship drift detected.');
            }
        }
    }

    /** Prepared activation may omit rows, but every row that exists must match its durable intent. */
    private function assertPreparedOwnership(array $state): void
    {
        foreach ($state['intents']['users'] ?? [] as $key => $intent) {
            if (!isset($state['ids'][$key])) {
                continue;
            }
            $this->assertExactRow('users', (int) $state['ids'][$key], [
                'email' => $intent['email'],
                'notes' => $intent['marker'],
                'id_roles' => (int) $intent['role_id'],
            ]);
            if (isset($intent['username'])) {
                $allSettings = $this->db
                    ->get_where('user_settings', [
                        'id_users' => (int) $state['ids'][$key],
                    ])
                    ->num_rows();
                $matchingSettings = $this->db
                    ->get_where('user_settings', [
                        'id_users' => (int) $state['ids'][$key],
                        'username' => $intent['username'],
                    ])
                    ->num_rows();
                if ($allSettings > 1 || $allSettings !== $matchingSettings) {
                    throw new RuntimeException('Prepared fixture settings are ambiguous; refusing cleanup.');
                }
            } elseif (
                $this->db->get_where('user_settings', ['id_users' => (int) $state['ids'][$key]])->num_rows() !== 0
            ) {
                throw new RuntimeException('Prepared synthetic customer unexpectedly has login settings.');
            }
        }
        if (isset($state['ids']['service'])) {
            $this->assertExactRow('services', (int) $state['ids']['service'], [
                'description' => $state['marker'],
                'attendants_number' => 1,
            ]);
        }
        if (isset($state['ids']['appointment'])) {
            $intent = $state['intents']['appointment'] ?? null;
            if (!is_array($intent)) {
                throw new RuntimeException('Prepared fixture appointment intent is missing.');
            }
            $this->assertExactRow('appointments', (int) $state['ids']['appointment'], [
                'notes' => $intent['marker'],
                'id_users_provider' => (int) $intent['provider_id'],
                'id_users_customer' => (int) $intent['customer_id'],
                'id_services' => (int) $intent['service_id'],
                'start_datetime' => $intent['start'],
                'end_datetime' => $intent['end'],
            ]);
        }
        foreach ($state['ids'] as $key => $id) {
            if (!str_starts_with($key, 'blocked_period_')) {
                continue;
            }
            $shortKey = substr($key, strlen('blocked_period_'));
            $intent = $state['intents']['blocked_periods'][$shortKey] ?? null;
            if (!is_array($intent)) {
                throw new RuntimeException('Prepared blocked period intent is missing.');
            }
            $this->assertExactRow('blocked_periods', (int) $id, [
                'name' => $intent['name'],
                'notes' => $intent['notes'],
                'start_datetime' => $intent['start'],
                'end_datetime' => $intent['end'],
            ]);
        }
        foreach ($state['ids'] as $key => $id) {
            if (!str_starts_with($key, 'category_')) {
                continue;
            }
            $shortKey = substr($key, strlen('category_'));
            $intent = $state['intents']['service_categories'][$shortKey] ?? null;
            if (!is_array($intent)) {
                throw new RuntimeException('Prepared service category intent is missing.');
            }
            $row = $this->db->get_where('service_categories', ['id' => (int) $id])->row_array();
            if ($row === []) {
                throw new RuntimeException('Prepared service category is missing.');
            }
            $allowed = [[$intent['name'], $intent['description']]];
            if (isset($intent['updated_name'], $intent['updated_description'])) {
                $allowed[] = [$intent['updated_name'], $intent['updated_description']];
            }
            if (!in_array([$row['name'] ?? null, $row['description'] ?? null], $allowed, true)) {
                throw new RuntimeException('Prepared service category identity drift detected.');
            }
        }
        foreach ($state['links'] ?? [] as $linkKey => $link) {
            if (!is_array($link)) {
                throw new RuntimeException('Prepared fixture service relationship is invalid.');
            }
            if (isset($link['id_users_secretary'], $link['id_users_provider'])) {
                $matches = $this->db
                    ->get_where('secretaries_providers', [
                        'id_users_secretary' => (int) $link['id_users_secretary'],
                        'id_users_provider' => (int) $link['id_users_provider'],
                    ])
                    ->num_rows();
                $stage = $state['intents']['secretary_links'][$linkKey]['stage'] ?? null;
                if ($stage === 'prepared' && $matches !== 0) {
                    throw new RuntimeException('Prepared secretary relationship provenance is unproven.');
                }
                if ($stage === 'complete' && $matches !== 1) {
                    throw new RuntimeException('Prepared secretary relationship is incomplete or ambiguous.');
                }
                continue;
            }
            $matches = $this->db
                ->get_where('services_providers', [
                    'id_users' => (int) ($link['id_users'] ?? 0),
                    'id_services' => (int) ($link['id_services'] ?? 0),
                ])
                ->num_rows();
            if ($matches > 1) {
                throw new RuntimeException('Prepared fixture service relationship is ambiguous.');
            }
        }
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function reconcileSecretariesApiDestroyAlias(array $state): array
    {
        if (($state['profile'] ?? null) !== 'secretaries_api') {
            return $state;
        }
        $stage = $state['intents']['secretary_alias']['destroy_stage'] ?? null;
        if ($stage === 'target_absent_reconciled') {
            $targetId = (int) ($state['intents']['secretary_alias']['reconciled_target_id'] ?? 0);
            if ($targetId < 1) {
                throw new RuntimeException('Secretary destroy alias reconciliation evidence is unavailable.');
            }
            if (
                $this->db->get_where('users', ['id' => $targetId])->num_rows() !== 0 ||
                $this->db->get_where('user_settings', ['id_users' => $targetId])->num_rows() !== 0 ||
                $this->db->get_where('secretaries_providers', ['id_users_secretary' => $targetId])->num_rows() !== 0
            ) {
                throw new RuntimeException('Secretary destroy alias reconciled absence no longer holds.');
            }
            return $state;
        }
        if ($stage !== 'started') {
            return $state;
        }
        $targetId = (int) ($state['ids']['secretary_target'] ?? 0);
        $intent = $state['intents']['users']['secretary_target'] ?? null;
        $link = $state['links']['secretary_target'] ?? null;
        if ($targetId < 1 || !is_array($intent) || !is_array($link)) {
            throw new RuntimeException('Secretary destroy alias reconciliation identity is unavailable.');
        }
        $user = $this->db->get_where('users', ['id' => $targetId])->row_array();
        $settings = $this->db->get_where('user_settings', ['id_users' => $targetId])->result_array();
        $links = $this->db->get_where('secretaries_providers', ['id_users_secretary' => $targetId])->result_array();
        if (is_array($user) && $user !== []) {
            if (
                ($user['notes'] ?? null) !== ($intent['marker'] ?? null) ||
                ($user['email'] ?? null) !== ($intent['email'] ?? null) ||
                (int) ($user['id_roles'] ?? 0) !== (int) ($intent['role_id'] ?? 0) ||
                count($settings) !== 1 ||
                ($settings[0]['username'] ?? null) !== ($intent['username'] ?? null) ||
                count($links) !== 1 ||
                (int) ($links[0]['id_users_secretary'] ?? 0) !== (int) ($link['id_users_secretary'] ?? 0) ||
                (int) ($links[0]['id_users_provider'] ?? 0) !== (int) ($link['id_users_provider'] ?? 0)
            ) {
                throw new RuntimeException('Secretary destroy alias target ownership drifted.');
            }
            return $state;
        }
        if ($settings !== [] || $links !== []) {
            throw new RuntimeException('Secretary destroy alias target suffered partial loss.');
        }
        $state['intents']['secretary_alias']['destroy_stage'] = 'target_absent_reconciled';
        $state['intents']['secretary_alias']['reconciled_target_id'] = $targetId;
        $state['intents']['secretary_alias']['reconciled_target_link'] = $link;
        unset($state['ids']['secretary_target'], $state['usernames']['secretary_target']);
        unset($state['links']['secretary_target'], $state['intents']['secretary_links']['secretary_target']);
        return $state;
    }

    /** @param array<string,mixed> $state */
    private function deleteOwned(array $state, bool $alreadyCleaning, bool $wasPrepared): void
    {
        $ids = $state['ids'];
        $this->lockFixtureUsers($state, $alreadyCleaning);
        $this->assertRecoverableApiAdminSettings($state);
        $this->assertServiceDependencies($state, $alreadyCleaning, $wasPrepared);
        if (($state['profile'] ?? null) === 'service_categories_api') {
            $idsToDelete = [];
            $categoryIntents = [];
            foreach (['a', 'b'] as $key) {
                $id = (int) ($ids['category_' . $key] ?? 0);
                $intent = $state['intents']['service_categories'][$key] ?? null;
                if ($id < 1 && $wasPrepared) {
                    continue;
                }
                if ($id < 1 || !is_array($intent)) {
                    throw new RuntimeException('Service category cleanup identity is missing.');
                }
                $idsToDelete[] = $id;
                $categoryIntents[$id] = $intent;
            }
            if (count(array_unique($idsToDelete)) !== count($idsToDelete)) {
                throw new RuntimeException('Service category cleanup IDs are ambiguous.');
            }
            sort($idsToDelete, SORT_NUMERIC);
            $lockedIds = [];
            foreach ($idsToDelete as $id) {
                $row = $this->db
                    ->query(
                        'SELECT * FROM `' . $this->db->dbprefix('service_categories') . '` WHERE id = ? FOR UPDATE',
                        [$id],
                    )
                    ->row_array();
                if (!is_array($row)) {
                    if (!$alreadyCleaning) {
                        throw new RuntimeException('Synthetic service category disappeared; refusing cleanup.');
                    }
                    continue;
                }
                $intent = $categoryIntents[$id];
                $allowed = [[$intent['name'], $intent['description']]];
                if (isset($intent['updated_name'], $intent['updated_description'])) {
                    $allowed[] = [$intent['updated_name'], $intent['updated_description']];
                }
                if (!in_array([$row['name'] ?? null, $row['description'] ?? null], $allowed, true)) {
                    throw new RuntimeException('Service category identity drift detected.');
                }
                $lockedIds[] = $id;
            }
            if ($lockedIds !== []) {
                $linkedServices = $this->db
                    ->query(
                        'SELECT id FROM `' .
                            $this->db->dbprefix('services') .
                            '` WHERE id_service_categories IN (' .
                            implode(', ', array_fill(0, count($lockedIds), '?')) .
                            ') ORDER BY id FOR UPDATE',
                        $lockedIds,
                    )
                    ->result_array();
                if ($linkedServices !== []) {
                    throw new RuntimeException(
                        'Service category cleanup found a linked service; refusing to unlink it.',
                    );
                }
            }
            foreach ($lockedIds as $id) {
                if (!$this->db->delete('service_categories', ['id' => $id])) {
                    throw new RuntimeException('Service category cleanup delete failed.');
                }
            }
        }
        if (($state['profile'] ?? null) === 'blocked_periods_api') {
            $blockedIds = [];
            foreach (['a', 'b'] as $key) {
                $id = (int) ($ids['blocked_period_' . $key] ?? 0);
                if ($id < 1) {
                    if ($wasPrepared) {
                        continue;
                    }
                    throw new RuntimeException('Blocked period cleanup identity is missing.');
                }
                $blockedIds[] = $id;
            }
            $journaledA = (int) ($ids['blocked_period_a'] ?? 0);
            $journaledB = (int) ($ids['blocked_period_b'] ?? 0);
            if ($journaledA > 0 && $journaledB > 0 && $journaledA === $journaledB) {
                throw new RuntimeException('Blocked period cleanup IDs are ambiguous.');
            }
            $blockedIds = array_values(array_unique($blockedIds));
            sort($blockedIds, SORT_NUMERIC);
            if ($blockedIds === []) {
                if (!$wasPrepared) {
                    throw new RuntimeException('Blocked period cleanup IDs are missing.');
                }
            } elseif (count($blockedIds) > 2) {
                throw new RuntimeException('Blocked period cleanup IDs are ambiguous.');
            }
            $lockedBlockedPeriods =
                $blockedIds === []
                    ? []
                    : $this->db
                        ->query(
                            'SELECT * FROM `' .
                                $this->db->dbprefix('blocked_periods') .
                                '` WHERE id IN (' .
                                implode(',', array_fill(0, count($blockedIds), '?')) .
                                ') ORDER BY id ASC FOR UPDATE',
                            $blockedIds,
                        )
                        ->result_array();
            $lockedById = [];
            foreach ($lockedBlockedPeriods as $row) {
                $lockedId = (int) ($row['id'] ?? 0);
                if ($lockedId < 1 || isset($lockedById[$lockedId])) {
                    throw new RuntimeException('Blocked period cleanup lock set is invalid.');
                }
                $lockedById[$lockedId] = $row;
            }
            foreach (['a', 'b'] as $key) {
                $id = (int) ($ids['blocked_period_' . $key] ?? 0);
                $intent = $state['intents']['blocked_periods'][$key] ?? null;
                if ($id < 1 && $wasPrepared) {
                    continue;
                }
                if ($id < 1 || !is_array($intent)) {
                    throw new RuntimeException('Blocked period cleanup identity is missing.');
                }
                $row = $lockedById[$id] ?? null;
                if (!is_array($row)) {
                    if (!$alreadyCleaning) {
                        throw new RuntimeException('Synthetic blocked period disappeared; refusing cleanup.');
                    }
                    continue;
                }
                $allowedTimes = [[$intent['start'], $intent['end']]];
                if ($key === 'a') {
                    $allowedTimes[] = [
                        date('Y-m-d H:i:s', strtotime((string) $intent['start']) + 600),
                        date('Y-m-d H:i:s', strtotime((string) $intent['end']) + 600),
                    ];
                }
                if (
                    ($row['name'] ?? null) !== $intent['name'] ||
                    ($row['notes'] ?? null) !== $intent['notes'] ||
                    !in_array([$row['start_datetime'] ?? null, $row['end_datetime'] ?? null], $allowedTimes, true)
                ) {
                    throw new RuntimeException('Blocked period identity drift detected.');
                }
            }
            foreach (['a', 'b'] as $key) {
                $id = (int) ($ids['blocked_period_' . $key] ?? 0);
                if ($id < 1) {
                    continue;
                }
                $intent = $state['intents']['blocked_periods'][$key];
                if (!$this->db->delete('blocked_periods', ['id' => $id, 'name' => $intent['name']])) {
                    throw new RuntimeException('Blocked period cleanup delete failed.');
                }
            }
        }
        $lockedUnavailabilities = $this->lockUnavailabilityRowsForCleanup($state);
        foreach (['a', 'b'] as $key) {
            $id = (int) ($ids['unavailability_' . $key] ?? 0);
            if ($id < 1 || !isset($lockedUnavailabilities[$id])) {
                continue;
            }
            $intent = $state['intents']['unavailabilities'][$key] ?? null;
            if (!is_array($intent)) {
                throw new RuntimeException('Unavailability cleanup intent is missing.');
            }
            $this->assertExactLockedRow($lockedUnavailabilities[$id], [
                'notes' => $intent['marker'],
                'id_users_provider' => $intent['provider_id'],
                'is_unavailability' => 1,
                'id_parent_appointment' => null,
                'id_users_customer' => null,
                'id_services' => null,
            ]);
            $allowedTimes = [[$intent['start'], $intent['end']]];
            if (($state['profile'] ?? null) === 'unavailabilities_api' && $key === 'a') {
                $allowedTimes[] = [
                    date('Y-m-d H:i:s', strtotime((string) $intent['start']) + 600),
                    date('Y-m-d H:i:s', strtotime((string) $intent['end']) + 600),
                ];
            }
            if (
                !in_array(
                    [$lockedUnavailabilities[$id]['start_datetime'], $lockedUnavailabilities[$id]['end_datetime']],
                    $allowedTimes,
                    true,
                )
            ) {
                throw new RuntimeException('Fixture identity drift detected.');
            }
        }
        if (($state['profile'] ?? null) === 'services_api') {
            foreach (['service_a', 'service_b'] as $key) {
                if (!isset($state['ids'][$key])) {
                    continue;
                }
                $id = (int) $state['ids'][$key];
                if ($this->db->get_where('services', ['id' => $id])->num_rows() === 0) {
                    if (!$alreadyCleaning) {
                        throw new RuntimeException('Synthetic Services API service disappeared; refusing cleanup.');
                    }
                    continue;
                }
                foreach ($state['links'] ?? [] as $link) {
                    if (is_array($link) && (int) ($link['id_services'] ?? 0) === $id) {
                        $this->db->delete('services_providers', [
                            'id_users' => (int) $link['id_users'],
                            'id_services' => $id,
                        ]);
                    }
                }
                $this->assertExactRow('services', $id, [
                    'description' => $state['marker'] . ':' . $key,
                    'attendants_number' => 1,
                ]);
                $this->db->delete('services', ['id' => $id, 'description' => $state['marker'] . ':' . $key]);
            }
        }
        foreach (['basic', 'bearer'] as $case) {
            $key = 'api_appointment_' . $case;
            if (!isset($ids[$key])) {
                continue;
            }
            $id = (int) $ids[$key];
            $row = $this->db->get_where('appointments', ['id' => $id])->row_array();
            if (!$row) {
                continue;
            }
            $intent = $state['intents']['api_appointments'][$case] ?? null;
            if (!is_array($intent)) {
                throw new RuntimeException('Appointments API cleanup intent is missing.');
            }
            $stage = $intent['stage'] ?? null;
            if ($stage === 'update_prepared') {
                try {
                    $this->assertApiAppointment($row, $intent['create']);
                } catch (Throwable) {
                    $this->assertApiAppointment($row, $intent['update']);
                }
            } elseif (in_array($stage, ['updated', 'delete_prepared', 'deleted'], true)) {
                $this->assertApiAppointment($row, $intent['update']);
            } else {
                $this->assertApiAppointment($row, $intent['create']);
            }
            if (isset($intent['hash_digest'])) {
                $this->assertApiHashDigest($row, $intent['hash_digest']);
            } else {
                $this->assertApiHash($row);
            }
            $this->db->delete('appointments', ['id' => $id]);
        }
        if (isset($ids['appointment'])) {
            if (($state['profile'] ?? null) === 'unavailabilities_api') {
                $children = $this->db
                    ->select('id')
                    ->get_where('appointments', [
                        'id_parent_appointment' => (int) $ids['appointment'],
                        'is_unavailability' => 1,
                    ])
                    ->result_array();
                foreach ($children as $child) {
                    $childId = (int) ($child['id'] ?? 0);
                    if ($childId < 1) {
                        throw new RuntimeException('Synthetic buffer child ID is invalid.');
                    }
                    $this->db->delete('appointments', [
                        'id' => $childId,
                        'id_parent_appointment' => (int) $ids['appointment'],
                        'is_unavailability' => 1,
                    ]);
                }
            }
            $exists = $this->db->get_where('appointments', ['id' => (int) $ids['appointment']])->num_rows() !== 0;
            if ($exists || !$alreadyCleaning) {
                $this->assertExactRow('appointments', (int) $ids['appointment'], [
                    'notes' => $state['intents']['appointment']['marker'] ?? $state['marker'],
                    'id_users_provider' =>
                        (int) ($state['intents']['appointment']['provider_id'] ?? $state['actor_id']),
                    'id_users_customer' => $ids['calendar_customer'],
                ]);
                $this->db->delete('appointments', ['id' => $ids['appointment']]);
            }
        }
        foreach (['a', 'b'] as $key) {
            $id = (int) ($ids['unavailability_' . $key] ?? 0);
            if ($id < 1 || !isset($lockedUnavailabilities[$id])) {
                continue;
            }
            $intent = $state['intents']['unavailabilities'][$key] ?? null;
            $this->db->delete('appointments', [
                'id' => $id,
                'notes' => $intent['marker'],
                'id_users_provider' => $intent['provider_id'],
                'is_unavailability' => 1,
                'id_parent_appointment' => null,
            ]);
            if ($this->db->affected_rows() !== 1) {
                throw new RuntimeException('Unavailability cleanup did not delete its bound row.');
            }
        }
        foreach ($state['links'] ?? [] as $link) {
            if (!is_array($link) || count($link) !== 2) {
                throw new RuntimeException('Fixture relationship journal is invalid.');
            }
            if (isset($link['id_users_secretary'], $link['id_users_provider'])) {
                $this->db->delete('secretaries_providers', [
                    'id_users_secretary' => (int) $link['id_users_secretary'],
                    'id_users_provider' => (int) $link['id_users_provider'],
                ]);
                continue;
            }
            $this->db->delete('services_providers', [
                'id_users' => (int) $link['id_users'],
                'id_services' => (int) $link['id_services'],
            ]);
        }
        if (isset($ids['service'])) {
            $exists = $this->db->get_where('services', ['id' => (int) $ids['service']])->num_rows() !== 0;
            if ($exists || !$alreadyCleaning) {
                $this->assertExactRow('services', (int) $ids['service'], [
                    'description' => $state['marker'],
                    'attendants_number' => 1,
                ]);
                $this->db->delete('services', ['id' => $ids['service'], 'description' => $state['marker']]);
            }
        }
        $users = array_reverse(array_keys($ids));
        foreach ($users as $key) {
            if (
                !in_array(
                    $key,
                    [
                        'provider_target',
                        'admin_target',
                        'foreign_provider',
                        'api_admin',
                        'customer_find_update',
                        'customer_destroy',
                        'calendar_customer',
                        'secretary_target',
                        'secretary_sentinel',
                    ],
                    true,
                )
            ) {
                continue;
            }
            $id = (int) $ids[$key];
            if ($alreadyCleaning && $this->db->get_where('users', ['id' => $id])->num_rows() === 0) {
                continue;
            }
            $this->assertExactRow('users', $id, ['notes' => $state['marker']]);
            if (
                $this->db->get_where('appointments', ['id_users_customer' => $id])->num_rows() ||
                $this->db->get_where('appointments', ['id_users_provider' => $id])->num_rows() ||
                $this->db->get_where('services_providers', ['id_users' => $id])->num_rows()
            ) {
                throw new RuntimeException('Unexpected fixture relationship; refusing cleanup.');
            }
            $this->assertNoSecretaryRelationships($id);
            $this->db->delete('user_settings', ['id_users' => $id, 'username' => $state['usernames'][$key] ?? '']);
            $this->db->delete('users', ['id' => $id, 'notes' => $state['marker']]);
        }
    }

    private function assertApiTokenOwnership(array $state, bool $allowEmpty): void
    {
        $intent = $this->apiTokenIntent($state);
        if ($intent === null) {
            return;
        }
        $rows = $this->db
            ->query('SELECT id, value FROM `' . $this->db->dbprefix('settings') . '` WHERE name = ? ORDER BY id ASC', [
                'api_token',
            ])
            ->result_array();
        if (count($rows) !== 1 || (int) ($rows[0]['id'] ?? 0) !== $intent['setting_id']) {
            throw new RuntimeException('Temporary Appointments API bearer prerequisite drift detected.');
        }
        $value = $rows[0]['value'] ?? null;
        if ($allowEmpty && $value === '') {
            return;
        }
        if (!is_string($value) || !hash_equals($intent['candidate_digest'], hash('sha256', $value))) {
            throw new RuntimeException('Temporary Appointments API bearer prerequisite drift detected.');
        }
    }

    /** Restore only the exact empty row journaled before the temporary token mutation. */
    private function restoreTemporaryApiToken(array $state): void
    {
        $intent = $this->apiTokenIntent($state);
        if ($intent === null) {
            return;
        }
        $rows = $this->lockApiTokenRows();
        if (count($rows) !== 1 || (int) ($rows[0]['id'] ?? 0) !== $intent['setting_id']) {
            throw new RuntimeException('Temporary Appointments API bearer prerequisite drift detected.');
        }
        $value = $rows[0]['value'] ?? null;
        if ($value === '') {
            return;
        }
        if (!is_string($value) || !hash_equals($intent['candidate_digest'], hash('sha256', $value))) {
            throw new RuntimeException('Temporary Appointments API bearer prerequisite drift detected.');
        }
        if (
            !$this->db->update('settings', ['value' => ''], ['id' => $intent['setting_id'], 'name' => 'api_token']) ||
            $this->db->affected_rows() !== 1
        ) {
            throw new RuntimeException('Temporary Appointments API bearer prerequisite could not be restored.');
        }
        $restored = $this->lockApiTokenRows();
        if (
            count($restored) !== 1 ||
            (int) ($restored[0]['id'] ?? 0) !== $intent['setting_id'] ||
            ($restored[0]['value'] ?? null) !== ''
        ) {
            throw new RuntimeException('Temporary Appointments API bearer prerequisite could not be restored.');
        }
    }

    /** Commit token recovery before any later fixture guard or mutation can roll back. */
    private function restoreTemporaryApiTokenCommitted(array $state): void
    {
        if ($this->apiTokenIntent($state) === null) {
            return;
        }
        $this->assertNoActiveDatabaseTransaction();
        if (!$this->db->trans_begin()) {
            throw new RuntimeException('Temporary Appointments API bearer recovery transaction could not start.');
        }
        try {
            $this->restoreTemporaryApiToken($state);
            if ($this->db->trans_status() === false || !$this->db->trans_commit()) {
                throw new RuntimeException('Temporary Appointments API bearer recovery transaction could not commit.');
            }
        } catch (Throwable $error) {
            $this->db->trans_rollback();
            throw $error;
        }
        $this->assertTemporaryApiTokenRestored($state);
    }

    /** Every fixture-owned commit must remain independent from caller state. */
    private function assertNoActiveDatabaseTransaction(): void
    {
        if ($this->db->trans_active()) {
            throw new RuntimeException(self::ACTIVE_TRANSACTION_ERROR);
        }
    }

    /** Verify the committed recovery without accepting a missing, duplicate or changed row. */
    private function assertTemporaryApiTokenRestored(array $state): void
    {
        $intent = $this->apiTokenIntent($state);
        if ($intent === null) {
            return;
        }
        $rows = $this->db
            ->query('SELECT id, value FROM `' . $this->db->dbprefix('settings') . '` WHERE name = ? ORDER BY id ASC', [
                'api_token',
            ])
            ->result_array();
        if (
            count($rows) !== 1 ||
            (int) ($rows[0]['id'] ?? 0) !== $intent['setting_id'] ||
            ($rows[0]['value'] ?? null) !== ''
        ) {
            throw new RuntimeException('Temporary Appointments API bearer recovery commit could not be verified.');
        }
    }

    /** @return array{setting_id:int,initial_state:string,candidate_digest:string}|null */
    private function apiTokenIntent(array $state): ?array
    {
        $intent = $state['intents']['api_token'] ?? null;
        if ($intent === null) {
            return null;
        }
        if (
            !is_array($intent) ||
            count($intent) !== 3 ||
            !is_int($intent['setting_id'] ?? null) ||
            $intent['setting_id'] < 1 ||
            ($intent['initial_state'] ?? null) !== 'empty' ||
            !is_string($intent['candidate_digest'] ?? null) ||
            preg_match('/^[a-f0-9]{64}$/D', $intent['candidate_digest']) !== 1
        ) {
            throw new RuntimeException('Temporary Appointments API bearer prerequisite journal is invalid.');
        }
        return $intent;
    }

    /** @return list<array<string,mixed>> */
    private function lockApiTokenRows(): array
    {
        return $this->db
            ->query(
                'SELECT id, value FROM `' .
                    $this->db->dbprefix('settings') .
                    '` WHERE name = ? ORDER BY id ASC FOR UPDATE',
                ['api_token'],
            )
            ->result_array();
    }

    /** Lock every synthetic user parent before service and relationship children. */
    private function lockFixtureUsers(array $state, bool $alreadyCleaning): void
    {
        $ids = [];
        foreach (
            [
                'provider_target',
                'admin_target',
                'foreign_provider',
                'api_admin',
                'customer_find_update',
                'customer_destroy',
                'calendar_customer',
                'secretary_target',
                'secretary_sentinel',
            ]
            as $key
        ) {
            if (isset($state['ids'][$key])) {
                $ids[] = (int) $state['ids'][$key];
            }
        }
        sort($ids, SORT_NUMERIC);
        if ($ids === []) {
            return;
        }
        $rows = $this->db
            ->query(
                'SELECT id FROM `' .
                    $this->db->dbprefix('users') .
                    '` WHERE id IN (' .
                    implode(',', array_fill(0, count($ids), '?')) .
                    ') ORDER BY id FOR UPDATE',
                $ids,
            )
            ->result_array();
        $actual = array_map(static fn(array $row): int => (int) $row['id'], $rows);
        if (!$alreadyCleaning && $actual !== $ids) {
            throw new RuntimeException('Synthetic fixture user disappeared before cleanup.');
        }
    }

    /** The locked parent prevents a new settings FK while recovery is checked and cleanup completes. */
    private function assertRecoverableApiAdminSettings(array $state): void
    {
        $intent = $state['intents']['users']['api_admin'] ?? null;
        $stage = is_array($intent) ? $intent['stage'] ?? null : null;
        if (!in_array($stage, ['prepared', 'settings_prepared'], true)) {
            return;
        }
        $userId = (int) ($state['ids']['api_admin'] ?? 0);
        if ($userId < 1) {
            if ($stage === 'settings_prepared') {
                throw new RuntimeException('Prepared Appointments API principal ID is unavailable.');
            }
            $email = $intent['email'] ?? null;
            if (!is_string($email) || $email === '') {
                throw new RuntimeException('Prepared Appointments API principal identity is unavailable.');
            }
            $unresolvedUsers = $this->db
                ->query('SELECT id FROM `' . $this->db->dbprefix('users') . '` WHERE email = ? FOR UPDATE', [$email])
                ->result_array();
            if ($unresolvedUsers === []) {
                return;
            }
            throw new RuntimeException('Prepared Appointments API principal user could not be resolved exactly.');
        }
        $settings = $this->db
            ->query('SELECT * FROM `' . $this->db->dbprefix('user_settings') . '` WHERE id_users = ? FOR UPDATE', [
                $userId,
            ])
            ->result_array();
        if ($stage === 'prepared' && $settings !== []) {
            throw new RuntimeException('Prepared Appointments API principal has unexpected settings.');
        }
        if (
            $stage === 'settings_prepared' &&
            (count($settings) > 1 ||
                (count($settings) === 1 &&
                    !$this->settingsMatchDigest($settings[0], $intent['settings_digest'] ?? null)))
        ) {
            throw new RuntimeException('Prepared Appointments API principal settings drifted.');
        }
    }

    private function settingsMatchDigest(array $settings, mixed $expectedDigest): bool
    {
        return is_string($expectedDigest) &&
            preg_match('/^[a-f0-9]{64}$/D', $expectedDigest) === 1 &&
            hash_equals($expectedDigest, $this->userSettingsDigest($settings));
    }

    /** Canonical secret-free proof of every field inserted for one synthetic principal. */
    private function userSettingsDigest(array $settings): string
    {
        $fields = [
            'id_users',
            'username',
            'password',
            'salt',
            'working_plan',
            'working_plan_exceptions',
            'notifications',
            'google_sync',
            'google_token',
            'google_calendar',
            'sync_past_days',
            'sync_future_days',
            'calendar_view',
            'caldav_sync',
            'caldav_url',
            'caldav_username',
            'caldav_password',
            'dashboard_range_start',
            'dashboard_range_end',
        ];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $settings)) {
                throw new RuntimeException('Appointments API principal settings proof is incomplete.');
            }
        }
        $nullableString = static fn(mixed $value): ?string => $value === null ? null : (string) $value;
        $canonical = [
            'id_users' => (int) $settings['id_users'],
            'username' => (string) $settings['username'],
            'password' => (string) $settings['password'],
            'salt' => (string) $settings['salt'],
            'working_plan' => (string) $settings['working_plan'],
            'working_plan_exceptions' => (string) $settings['working_plan_exceptions'],
            'notifications' => (int) $settings['notifications'],
            'google_sync' => (int) $settings['google_sync'],
            'google_token' => $nullableString($settings['google_token']),
            'google_calendar' => $nullableString($settings['google_calendar']),
            'sync_past_days' => (int) $settings['sync_past_days'],
            'sync_future_days' => (int) $settings['sync_future_days'],
            'calendar_view' => (string) $settings['calendar_view'],
            'caldav_sync' => (int) $settings['caldav_sync'],
            'caldav_url' => $nullableString($settings['caldav_url']),
            'caldav_username' => $nullableString($settings['caldav_username']),
            'caldav_password' => $nullableString($settings['caldav_password']),
            'dashboard_range_start' => $nullableString($settings['dashboard_range_start']),
            'dashboard_range_end' => $nullableString($settings['dashboard_range_end']),
        ];
        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** Lock and compare every child before deleting the synthetic service. */
    private function assertServiceDependencies(array $state, bool $alreadyCleaning, bool $prepared = false): void
    {
        if (($state['profile'] ?? null) === 'services_api') {
            foreach (['service_a', 'service_b'] as $key) {
                if (!isset($state['ids'][$key])) {
                    continue;
                }
                $serviceId = (int) $state['ids'][$key];
                $rows = $this->db
                    ->query('SELECT id FROM `' . $this->db->dbprefix('services') . '` WHERE id = ? FOR UPDATE', [
                        $serviceId,
                    ])
                    ->result_array();
                $expected = [];
                foreach ($state['links'] ?? [] as $link) {
                    if (is_array($link) && (int) ($link['id_services'] ?? 0) === $serviceId) {
                        $expected[] = ['id_users' => (int) $link['id_users'], 'id_services' => $serviceId];
                    }
                }
                $actual = $this->db
                    ->query(
                        'SELECT id_users, id_services FROM `' .
                            $this->db->dbprefix('services_providers') .
                            '` WHERE id_services = ? ORDER BY id_users FOR UPDATE',
                        [$serviceId],
                    )
                    ->result_array();
                $actual = array_map(
                    static fn(array $row): array => [
                        'id_users' => (int) $row['id_users'],
                        'id_services' => (int) $row['id_services'],
                    ],
                    $actual,
                );
                $appointments = $this->db
                    ->query(
                        'SELECT id FROM `' .
                            $this->db->dbprefix('appointments') .
                            '` WHERE id_services = ? ORDER BY id FOR UPDATE',
                        [$serviceId],
                    )
                    ->result_array();
                if ($appointments !== []) {
                    throw new RuntimeException(
                        'Synthetic Services API service gained an appointment; refusing cleanup.',
                    );
                }
                if ($rows === []) {
                    if (!$alreadyCleaning || $actual !== []) {
                        throw new RuntimeException('Synthetic Services API service disappeared with dependencies.');
                    }
                } elseif (
                    count($rows) !== 1 ||
                    (!$prepared && $actual !== $expected) ||
                    ($prepared &&
                        array_filter($actual, static fn(array $link): bool => !in_array($link, $expected, true)) !== [])
                ) {
                    throw new RuntimeException('Unexpected Services API service relationship; refusing cleanup.');
                }
            }
            return;
        }
        if (!isset($state['ids']['service'])) {
            return;
        }
        $serviceId = (int) $state['ids']['service'];
        $serviceRows = $this->db
            ->query('SELECT id FROM `' . $this->db->dbprefix('services') . '` WHERE id = ? FOR UPDATE', [$serviceId])
            ->result_array();
        $parentId = isset($state['ids']['appointment']) ? (int) $state['ids']['appointment'] : 0;
        if ($parentId > 0) {
            $parentRows = $this->db
                ->query(
                    'SELECT id, id_services FROM ' . $this->db->dbprefix('appointments') . ' WHERE id = ? FOR UPDATE',
                    [$parentId],
                )
                ->result_array();
            if ($parentRows === []) {
                if (!$alreadyCleaning) {
                    throw new RuntimeException('Synthetic appointment parent disappeared; refusing cleanup.');
                }
            } elseif (count($parentRows) !== 1 || (int) $parentRows[0]['id_services'] !== $serviceId) {
                throw new RuntimeException('Synthetic appointment parent drifted; refusing cleanup.');
            }
            $generatedChildren = $this->db
                ->query(
                    'SELECT id, id_parent_appointment, id_services, id_users_provider, id_users_customer, ' .
                        'is_unavailability, start_datetime, end_datetime, notes FROM ' .
                        $this->db->dbprefix('appointments') .
                        ' WHERE id_parent_appointment = ? ORDER BY id FOR UPDATE',
                    [$parentId],
                )
                ->result_array();
            if ($generatedChildren !== [] && ($state['profile'] ?? null) !== 'unavailabilities_api') {
                throw new RuntimeException('Unexpected generated appointment child; refusing cleanup.');
            }
            if (($state['profile'] ?? null) === 'unavailabilities_api') {
                $parent = $this->db->get_where('appointments', ['id' => $parentId])->row_array();
                $service = $this->db->get_where('services', ['id' => $serviceId])->row_array();
                $before = (int) ($service['buffer_before'] ?? 0);
                $after = (int) ($service['buffer_after'] ?? 0);
                if ($before !== 10 || $after !== 10) {
                    throw new RuntimeException('Synthetic service buffer configuration drifted; refusing cleanup.');
                }
                $expected = [
                    [
                        'start_datetime' => date('Y-m-d H:i:s', strtotime((string) $parent['start_datetime']) - 600),
                        'end_datetime' => $parent['start_datetime'],
                    ],
                    [
                        'start_datetime' => $parent['end_datetime'],
                        'end_datetime' => date('Y-m-d H:i:s', strtotime((string) $parent['end_datetime']) + 600),
                    ],
                ];
                if (count($generatedChildren) !== count($expected)) {
                    throw new RuntimeException('Synthetic buffer child count drifted.');
                }
                foreach ($generatedChildren as $index => $child) {
                    if (
                        (int) ($child['id_parent_appointment'] ?? 0) !== $parentId ||
                        (int) ($child['id_services'] ?? 0) !== 0 ||
                        (int) ($child['id_users_provider'] ?? 0) !== (int) $parent['id_users_provider'] ||
                        ($child['id_users_customer'] ?? null) !== null ||
                        (int) ($child['is_unavailability'] ?? 0) !== 1 ||
                        ($child['start_datetime'] ?? null) !== $expected[$index]['start_datetime'] ||
                        ($child['end_datetime'] ?? null) !== $expected[$index]['end_datetime'] ||
                        ($child['notes'] ?? null) !== lang('buffer_block_note')
                    ) {
                        throw new RuntimeException('Synthetic buffer child identity drifted.');
                    }
                }
            }
            foreach (['basic', 'bearer'] as $case) {
                $apiParentId = (int) ($state['ids']['api_appointment_' . $case] ?? 0);
                if ($apiParentId < 1) {
                    continue;
                }
                $apiChildren = $this->db
                    ->query(
                        'SELECT id FROM ' .
                            $this->db->dbprefix('appointments') .
                            ' WHERE id_parent_appointment = ? ORDER BY id FOR UPDATE',
                        [$apiParentId],
                    )
                    ->result_array();
                if ($apiChildren !== []) {
                    throw new RuntimeException('Unexpected generated appointment child; refusing cleanup.');
                }
            }
        } elseif (isset($state['actor_id'])) {
            // Production buffer blocks have NULL customer/service references.
            // Their parent link, provider and unavailability flag are the
            // persisted attribution available during an interrupted journal.
            $generatedChildren = $this->db
                ->query(
                    'SELECT id, id_parent_appointment, id_services FROM ' .
                        $this->db->dbprefix('appointments') .
                        ' WHERE id_parent_appointment IS NOT NULL AND is_unavailability = 1' .
                        ' AND id_users_provider = ? AND id_users_customer IS NULL AND id_services IS NULL' .
                        ' ORDER BY id FOR UPDATE',
                    [(int) $state['actor_id']],
                )
                ->result_array();
            if ($generatedChildren !== []) {
                throw new RuntimeException('Unexpected generated appointment child; refusing cleanup.');
            }
        }
        $actualLinks = $this->db
            ->query(
                'SELECT id_users, id_services FROM `' .
                    $this->db->dbprefix('services_providers') .
                    '` WHERE id_services = ? ORDER BY id_users FOR UPDATE',
                [$serviceId],
            )
            ->result_array();
        $expectedLinks = [];
        foreach ($state['links'] ?? [] as $link) {
            if (!is_array($link)) {
                throw new RuntimeException('Fixture service relationship journal is invalid.');
            }
            $expectedLinks[] = [
                'id_users' => (int) ($link['id_users'] ?? 0),
                'id_services' => $serviceId,
            ];
        }
        usort($expectedLinks, static fn(array $a, array $b): int => $a['id_users'] <=> $b['id_users']);
        $actualLinks = array_map(
            static fn(array $row): array => [
                'id_users' => (int) $row['id_users'],
                'id_services' => (int) $row['id_services'],
            ],
            $actualLinks,
        );
        if ($serviceRows === []) {
            if (!$alreadyCleaning || $actualLinks !== []) {
                throw new RuntimeException('Synthetic service disappeared with dependent rows; refusing cleanup.');
            }
        } elseif (
            count($serviceRows) !== 1 ||
            (!$prepared && $actualLinks !== $expectedLinks) ||
            ($prepared &&
                array_filter(
                    $actualLinks,
                    static fn(array $actual): bool => !in_array($actual, $expectedLinks, true),
                ) !== [])
        ) {
            throw new RuntimeException('Unexpected service provider relationship; refusing cleanup.');
        }

        $appointmentRows = $this->db
            ->query(
                'SELECT id FROM `' .
                    $this->db->dbprefix('appointments') .
                    '` WHERE id_services = ? ORDER BY id FOR UPDATE',
                [$serviceId],
            )
            ->result_array();
        $actualAppointmentIds = array_map(static fn(array $row): int => (int) $row['id'], $appointmentRows);
        if (!isset($state['ids']['appointment'])) {
            $expectedAppointmentIds = [];
        } else {
            $expectedAppointment = (int) $state['ids']['appointment'];
            $expectedAppointmentIds = $serviceRows === [] && $alreadyCleaning ? [] : [$expectedAppointment];
        }
        if (!($serviceRows === [] && $alreadyCleaning)) {
            foreach (['basic', 'bearer'] as $case) {
                $id = (int) ($state['ids']['api_appointment_' . $case] ?? 0);
                if ($id > 0 && $this->db->get_where('appointments', ['id' => $id])->num_rows() !== 0) {
                    $expectedAppointmentIds[] = $id;
                }
            }
            sort($expectedAppointmentIds, SORT_NUMERIC);
        }
        if ($actualAppointmentIds !== $expectedAppointmentIds) {
            throw new RuntimeException('Synthetic service appointment relationship drifted; refusing cleanup.');
        }
    }

    private function assertNoSecretaryRelationships(int $userId): void
    {
        $rows = $this->db
            ->query(
                'SELECT id_users_provider, id_users_secretary FROM `' .
                    $this->db->dbprefix('secretaries_providers') .
                    '` WHERE id_users_provider = ? OR id_users_secretary = ? FOR UPDATE',
                [$userId, $userId],
            )
            ->result_array();
        if ($rows !== []) {
            throw new RuntimeException('Unexpected secretary relationship; refusing cleanup.');
        }
    }

    /** Reconstruct only rows matching one exact, durable activation intent. */
    private function recoverExactIds(array $state): array
    {
        foreach ($state['intents']['users'] ?? [] as $key => $intent) {
            if (isset($state['ids'][$key])) {
                continue;
            }
            $rows = $this->db
                ->where('email', $intent['email'])
                ->where('notes', $intent['marker'])
                ->where('id_roles', (int) $intent['role_id'])
                ->get('users')
                ->result_array();
            if (count($rows) > 1) {
                throw new RuntimeException('Fixture user identity is ambiguous; refusing cleanup.');
            }
            if (count($rows) === 1) {
                $state['ids'][$key] = (int) $rows[0]['id'];
                if (isset($intent['username'])) {
                    $state['usernames'][$key] = $intent['username'];
                }
            }
        }
        foreach (['service', 'service_a', 'service_b'] as $key) {
            if (isset($state['ids'][$key]) || !isset($state['intents'][$key])) {
                continue;
            }
            $rows = $this->db->get_where('services', $state['intents'][$key])->result_array();
            if (count($rows) > 1) {
                throw new RuntimeException('Fixture service identity is ambiguous; refusing cleanup.');
            }
            if (count($rows) === 1) {
                $state['ids'][$key] = (int) $rows[0]['id'];
            }
        }
        if (!isset($state['ids']['appointment']) && isset($state['intents']['appointment'])) {
            $intent = $state['intents']['appointment'];
            $rows = $this->db
                ->where('notes', $intent['marker'])
                ->where('id_users_provider', (int) $intent['provider_id'])
                ->where('id_users_customer', (int) $intent['customer_id'])
                ->where('id_services', (int) $intent['service_id'])
                ->where('start_datetime', $intent['start'])
                ->where('end_datetime', $intent['end'])
                ->get('appointments')
                ->result_array();
            if (count($rows) > 1) {
                throw new RuntimeException('Fixture appointment identity is ambiguous; refusing cleanup.');
            }
            if (count($rows) === 1) {
                $state['ids']['appointment'] = (int) $rows[0]['id'];
            }
        }
        foreach ($state['intents']['unavailabilities'] ?? [] as $key => $intent) {
            $idKey = 'unavailability_' . $key;
            if (isset($state['ids'][$idKey]) || !is_array($intent)) {
                continue;
            }
            $rows = $this->db
                ->where('notes', $intent['marker'])
                ->where('id_users_provider', (int) $intent['provider_id'])
                ->where('start_datetime', $intent['start'])
                ->where('end_datetime', $intent['end'])
                ->where('is_unavailability', 1)
                ->get('appointments')
                ->result_array();
            if (count($rows) > 1) {
                throw new RuntimeException('Fixture unavailability identity is ambiguous; refusing cleanup.');
            }
            if (count($rows) === 1) {
                $state['ids'][$idKey] = (int) $rows[0]['id'];
            }
        }
        foreach ($state['intents']['blocked_periods'] ?? [] as $key => $intent) {
            $idKey = 'blocked_period_' . $key;
            if (isset($state['ids'][$idKey]) || !is_array($intent)) {
                continue;
            }
            $rows = $this->db
                ->query('SELECT * FROM `' . $this->db->dbprefix('blocked_periods') . '` WHERE name = ? OR notes = ?', [
                    $intent['name'],
                    $intent['notes'],
                ])
                ->result_array();
            if (count($rows) > 1) {
                throw new RuntimeException('Blocked period identity is ambiguous; refusing cleanup.');
            }
            if (count($rows) === 1) {
                if (
                    ($rows[0]['name'] ?? null) !== $intent['name'] ||
                    ($rows[0]['notes'] ?? null) !== $intent['notes'] ||
                    ($rows[0]['start_datetime'] ?? null) !== $intent['start'] ||
                    ($rows[0]['end_datetime'] ?? null) !== $intent['end']
                ) {
                    throw new RuntimeException('Blocked period identity drift detected; refusing cleanup.');
                }
                $state['ids'][$idKey] = (int) $rows[0]['id'];
            }
        }
        foreach ($state['intents']['service_categories'] ?? [] as $key => $intent) {
            $idKey = 'category_' . $key;
            if (isset($state['ids'][$idKey]) || !is_array($intent)) {
                continue;
            }
            $rows = $this->db
                ->get_where('service_categories', [
                    'name' => $intent['name'],
                    'description' => $intent['description'],
                ])
                ->result_array();
            if (count($rows) > 1) {
                throw new RuntimeException('Service category identity is ambiguous; refusing cleanup.');
            }
            if (count($rows) === 1) {
                $state['ids'][$idKey] = (int) $rows[0]['id'];
            }
        }
        foreach (['basic', 'bearer'] as $case) {
            $key = 'api_appointment_' . $case;
            $intent = $state['intents']['api_appointments'][$case] ?? null;
            if (isset($state['ids'][$key]) || !is_array($intent)) {
                continue;
            }
            $payload = $intent['create'];
            $rows = $this->db
                ->where('notes', $payload['notes'])
                ->where('id_users_provider', (int) $payload['providerId'])
                ->where('id_users_customer', (int) $payload['customerId'])
                ->where('id_services', (int) $payload['serviceId'])
                ->where('start_datetime', $payload['start'])
                ->where('end_datetime', $payload['end'])
                ->get('appointments')
                ->result_array();
            if (count($rows) > 1) {
                throw new RuntimeException('Appointments API fixture identity is ambiguous; refusing cleanup.');
            }
            if (count($rows) === 1) {
                $state['ids'][$key] = (int) $rows[0]['id'];
                $state['intents']['api_appointments'][$case]['hash_digest'] = hash(
                    'sha256',
                    $this->assertApiHash($rows[0]),
                );
            }
        }
        return $state;
    }

    private function assertApiCase(string $case): void
    {
        if (!in_array($case, ['basic', 'bearer'], true)) {
            throw new InvalidArgumentException('Unsupported Appointments API verification case.');
        }
    }

    /** @param array<string,mixed> $window */
    private function assertApiWindow(array $window): void
    {
        if ($window === []) {
            return;
        }
        if (
            array_keys($window) !== ['start', 'end'] ||
            !is_string($window['start']) ||
            !is_string($window['end']) ||
            !\validate_datetime($window['start']) ||
            !\validate_datetime($window['end']) ||
            $window['start'] >= $window['end']
        ) {
            throw new InvalidArgumentException('Invalid Appointments API verification window.');
        }
    }

    private function assertApiReady(array $state): void
    {
        if (
            $state['phase'] !== 'active' ||
            $state['profile'] !== 'calendar_race' ||
            !isset($state['api_credentials'], $state['ids']['api_admin'])
        ) {
            throw new RuntimeException('Appointments API verification fixture is incomplete.');
        }
    }

    private function apiIntent(array $state, string $case, string $stage): array
    {
        $intent = $state['intents']['api_appointments'][$case] ?? null;
        if (!is_array($intent) || ($intent['stage'] ?? null) !== $stage) {
            throw new RuntimeException('Appointments API fixture stage is invalid.');
        }
        return $intent;
    }

    private function apiAppointmentRow(int $id): array
    {
        if ($id < 1) {
            throw new RuntimeException('Appointments API fixture ID is invalid.');
        }
        $row = $this->db->get_where('appointments', ['id' => $id])->row_array();
        if (!is_array($row) || $row === []) {
            throw new RuntimeException('Appointments API fixture row is unavailable.');
        }
        return $row;
    }

    private function assertApiAppointment(array $row, array $payload): void
    {
        $expected = [
            'start_datetime' => $payload['start'],
            'end_datetime' => $payload['end'],
            'location' => $payload['location'],
            'color' => $payload['color'],
            'status' => $payload['status'],
            'notes' => $payload['notes'],
            'id_users_customer' => (int) $payload['customerId'],
            'id_users_provider' => (int) $payload['providerId'],
            'id_services' => (int) $payload['serviceId'],
            'is_unavailability' => 0,
            'id_parent_appointment' => null,
            'id_google_calendar' => null,
            'id_caldav_calendar' => null,
        ];
        foreach ($expected as $field => $value) {
            if (($row[$field] ?? null) != $value) {
                throw new RuntimeException('Appointments API fixture row drift detected.');
            }
        }
    }

    private function assertApiHash(array $row): string
    {
        $hash = $row['hash'] ?? null;
        if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
            throw new RuntimeException('Appointments API row has no server-generated hash.');
        }
        return $hash;
    }

    private function assertApiHashDigest(array $row, mixed $digest): void
    {
        $hash = $this->assertApiHash($row);
        if (!is_string($digest) || $digest === '' || !hash_equals($digest, hash('sha256', $hash))) {
            throw new RuntimeException('Appointments API row hash drift detected.');
        }
    }

    /** @return list<string> */
    private function remainingIds(array $state): array
    {
        $remaining = [];
        foreach ($state['ids'] as $key => $id) {
            $table = str_starts_with($key, 'blocked_period_')
                ? 'blocked_periods'
                : (str_starts_with($key, 'category_')
                    ? 'service_categories'
                    : (str_contains($key, 'service_link')
                        ? 'services_providers'
                        : (in_array($key, ['service', 'service_a', 'service_b'], true)
                            ? 'services'
                            : ($key === 'appointment' ||
                            str_starts_with($key, 'api_appointment_') ||
                            str_starts_with($key, 'unavailability_')
                                ? 'appointments'
                                : 'users'))));
            if ($this->db->get_where($table, ['id' => (int) $id])->num_rows() !== 0) {
                $remaining[] = $key;
            }
        }
        foreach ($state['links'] ?? [] as $key => $link) {
            if (!is_array($link)) {
                continue;
            }
            $table = isset($link['id_users_secretary'], $link['id_users_provider'])
                ? 'secretaries_providers'
                : 'services_providers';
            $where =
                $table === 'secretaries_providers'
                    ? [
                        'id_users_secretary' => (int) $link['id_users_secretary'],
                        'id_users_provider' => (int) $link['id_users_provider'],
                    ]
                    : [
                        'id_users' => (int) ($link['id_users'] ?? 0),
                        'id_services' => (int) ($link['id_services'] ?? 0),
                    ];
            if ($this->db->get_where($table, $where)->num_rows() !== 0) {
                $remaining[] = 'link:' . $key;
            }
        }
        foreach (
            [
                'provider_target',
                'admin_target',
                'foreign_provider',
                'api_admin',
                'secretary_target',
                'secretary_sentinel',
            ]
            as $key
        ) {
            if (
                isset($state['ids'][$key]) &&
                $this->db->get_where('user_settings', ['id_users' => (int) $state['ids'][$key]])->num_rows() !== 0
            ) {
                $remaining[] = 'settings:' . $key;
            }
        }
        return $remaining;
    }

    private function assertExactRow(string $table, int $id, array $where): void
    {
        $row = $this->db->get_where($table, ['id' => $id])->row_array();
        $this->assertExactLockedRow($row, $where);
    }

    /** @return array<int,array<string,mixed>> */
    private function lockUnavailabilityRowsForCleanup(array $state): array
    {
        $ids = [];
        foreach (['a', 'b'] as $key) {
            $id = (int) ($state['ids']['unavailability_' . $key] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        if ($ids === []) {
            return [];
        }

        $rows = $this->db
            ->query(
                'SELECT * FROM `' .
                    $this->db->dbprefix('appointments') .
                    '` WHERE id IN (' .
                    implode(',', array_fill(0, count($ids), '?')) .
                    ') ORDER BY id ASC FOR UPDATE',
                $ids,
            )
            ->result_array();
        $locked = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1 || isset($locked[$id])) {
                throw new RuntimeException('Unavailability cleanup lock set is invalid.');
            }
            $locked[$id] = $row;
        }
        return $locked;
    }

    /** @param array<string,mixed>|null $row @param array<string,mixed> $where */
    private function assertExactLockedRow(?array $row, array $where): void
    {
        if ($row === null) {
            throw new RuntimeException('Fixture identity drift detected.');
        }
        foreach ($where as $key => $value) {
            if (($row[$key] ?? null) != $value) {
                throw new RuntimeException('Fixture identity drift detected.');
            }
        }
    }

    /** @return array{id:int,role_id:int} */
    private function assertActor(array $context, string $requiredRole): array
    {
        if (isset($context['ordinary']) && is_array($context['ordinary'])) {
            $context = $context['ordinary'];
        } elseif (isset($context['actor']) && is_array($context['actor'])) {
            $context = $context['actor'];
        }
        $id = (int) ($context['user_id'] ?? ($context['id'] ?? ($context['actor_id'] ?? 0)));
        if ($id < 1) {
            throw new InvalidArgumentException('Active ordinary actor is required.');
        }
        $row = $this->db
            ->select('users.id, users.notes, users.id_roles, roles.slug, user_settings.username')
            ->from('users')
            ->join('roles', 'roles.id = users.id_roles')
            ->join('user_settings', 'user_settings.id_users = users.id')
            ->where('users.id', $id)
            ->get()
            ->row_array();
        if (!$row || !str_starts_with((string) $row['notes'], 'ordinary-live:') || $row['slug'] !== $requiredRole) {
            throw new RuntimeException('Actor is not the active synthetic ordinary actor.');
        }
        if (isset($context['marker']) && $context['marker'] !== $row['notes']) {
            throw new RuntimeException('Actor marker drift detected.');
        }
        return ['id' => $id, 'role_id' => (int) $row['id_roles']];
    }

    private function role(string $slug): int
    {
        $row = $this->db->get_where('roles', ['slug' => $slug])->row_array();
        if (!$row || (int) $row['id'] < 1) {
            throw new RuntimeException('Required role is missing.');
        }
        return (int) $row['id'];
    }

    /** @param array<string,mixed> $state */
    private function journal(array &$state): void
    {
        $this->writeState($state);
    }

    /** @param array<string,mixed> $state */
    private function writeState(array $state): void
    {
        $tmp = $this->temporaryStateFile;
        $handle = @fopen($tmp, 'x');
        if ($handle === false) {
            throw new RuntimeException('Fixture temporary state already exists or cannot be created.');
        }
        $created = fstat($handle);
        $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        try {
            if (
                !chmod($tmp, 0600) ||
                fwrite($handle, $json) !== strlen($json) ||
                !fflush($handle) ||
                !function_exists('fsync') ||
                !fsync($handle)
            ) {
                throw new RuntimeException('Fixture state could not be durably written.');
            }
            if (!rename($tmp, $this->stateFile)) {
                throw new RuntimeException('Fixture state could not be atomically replaced.');
            }
        } catch (Throwable $error) {
            clearstatcache(true, $tmp);
            $current = @lstat($tmp);
            if (
                is_array($created) &&
                is_array($current) &&
                !is_link($tmp) &&
                is_file($tmp) &&
                $current['dev'] === $created['dev'] &&
                $current['ino'] === $created['ino'] &&
                $current['nlink'] === 1
            ) {
                unlink($tmp);
                $this->syncDirectory();
            }
            throw $error;
        } finally {
            fclose($handle);
        }
        $this->syncDirectory();
        $this->assertOwnedFile($this->stateFile, 0600);
    }

    private function syncDirectory(): void
    {
        $handle = fopen($this->directory, 'r');
        if ($handle === false) {
            throw new RuntimeException('Fixture directory is unavailable.');
        }
        try {
            if (!fsync($handle)) {
                throw new RuntimeException('Fixture directory synchronization failed.');
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return array<string,mixed> */
    private function readState(): array
    {
        $this->assertOwnedFile($this->stateFile, 0600);
        $value = json_decode((string) file_get_contents($this->stateFile), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new RuntimeException('Fixture state is invalid.');
        }
        return $value;
    }

    /** @param array<string,mixed> $state */
    private function validateState(array $state): void
    {
        $schema = $state['schema'] ?? null;
        $profile = $state['profile'] ?? null;
        $runId = $state['run_id'] ?? null;
        $created = $state['created_at'] ?? null;
        $expires = $state['expires_at'] ?? null;
        $phase = $state['phase'] ?? null;
        if (
            $schema !== self::SCHEMA ||
            !in_array($profile, self::PROFILES, true) ||
            !is_string($runId) ||
            !preg_match('/^[0-9a-f]{32}$/', $runId) ||
            ($state['marker'] ?? null) !== 'defense-verification:' . $runId ||
            !is_int($created) ||
            !is_int($expires) ||
            $expires - $created !== self::MAX_TTL ||
            !in_array($phase, ['prepared', 'active', 'cleaning', 'recovery_required'], true) ||
            !is_array($state['ids'] ?? null)
        ) {
            throw new RuntimeException('Fixture state identity is invalid.');
        }
        if (
            $phase === 'recovery_required' &&
            ($state['recovery_reason'] ?? null) !== 'calendar_request_termination_unconfirmed'
        ) {
            throw new RuntimeException('Fixture recovery reason is invalid.');
        }
        if (
            isset($state['cleanup_origin_phase']) &&
            ($phase !== 'cleaning' || $state['cleanup_origin_phase'] !== 'prepared')
        ) {
            throw new RuntimeException('Fixture cleanup origin is invalid.');
        }
    }

    private function hasDatabaseLeftovers(): bool
    {
        return $this->db->like('notes', 'defense-verification:', 'after')->count_all_results('users') > 0 ||
            $this->db->like('username', 'defense_verify_', 'after')->count_all_results('user_settings') > 0 ||
            $this->db->like('description', 'defense-verification:', 'after')->count_all_results('services') > 0 ||
            $this->db->like('name', 'defense-verification:', 'after')->count_all_results('service_categories') > 0 ||
            $this->db->like('notes', 'defense-verification:', 'after')->count_all_results('appointments') > 0 ||
            $this->db->like('notes', 'defense-verification:', 'after')->count_all_results('blocked_periods') > 0;
    }

    /** @return mixed */
    private function withLock(callable $callback): mixed
    {
        if (is_link($this->lockFile)) {
            throw new RuntimeException('Fixture lifecycle lock cannot be a symlink.');
        }
        if (file_exists($this->lockFile)) {
            $this->assertOwnedFile($this->lockFile, 0600);
        }
        $handle = fopen($this->lockFile, 'c');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new RuntimeException('Fixture lifecycle lock unavailable.');
        }
        chmod($this->lockFile, 0600);
        $this->assertOwnedFile($this->lockFile, 0600);
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function prepareDirectory(string $path): string
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('State directory must be absolute.');
        }
        $path = rtrim($path, '/');
        if (is_dir($path)) {
            $stat = lstat($path);
            if (is_link($path) || !is_array($stat) || ($stat['mode'] & 0777) !== 0700) {
                throw new RuntimeException('State directory must be private.');
            }
        } elseif (!mkdir($path, 0700) && !is_dir($path)) {
            throw new RuntimeException('State directory could not be created.');
        }
        $this->assertOwnedFile($path, 0700);
        return realpath($path) ?: throw new RuntimeException('State directory is not canonical.');
    }

    private function assertOwnedFile(string $path, int $mode): void
    {
        $stat = lstat($path);
        if (
            $stat === false ||
            is_link($path) ||
            (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid()) ||
            ($stat['mode'] & 0777) !== $mode
        ) {
            throw new RuntimeException('Fixture path ownership or permissions are unsafe.');
        }
    }

    private function assertRootCli(): void
    {
        if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new RuntimeException('Defense verification fixture requires root CLI execution.');
        }
    }
}
