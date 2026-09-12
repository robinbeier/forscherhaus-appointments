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
 * owner of its actor and this class never changes application settings.
 */
final class DefenseVerificationFixture
{
    private const SCHEMA = 'defense-verification-fixture.v1';
    private const MAX_TTL = 600;
    private const PROFILES = ['customer_boundary', 'calendar_race'];

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
            $actor = $this->assertActor($actorContext, $profile === 'customer_boundary' ? 'admin' : 'provider');
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
                        : $this->activateCalendarRace($state);
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
        $this->withLock(function (): void {
            if (!file_exists($this->stateFile)) {
                return;
            }
            $state = $this->readState();
            $this->validateState($state);
            if ($state['phase'] === 'recovery_required') {
                throw new RuntimeException('Defense verification fixture requires explicit request recovery.');
            }
            $wasCleaning = $state['phase'] === 'cleaning';
            $wasPrepared = $state['phase'] === 'prepared' || ($state['cleanup_origin_phase'] ?? null) === 'prepared';
            $state = $this->recoverExactIds($state);
            if (isset($state['intents']['appointment']) && !isset($state['ids']['appointment'])) {
                throw new RuntimeException('Appointment intent could not be reconstructed; refusing cleanup.');
            }
            if (!$wasCleaning) {
                if ($state['phase'] === 'active') {
                    $this->assertOwnership($state);
                } else {
                    $this->assertPreparedOwnership($state);
                }
                if ($wasPrepared) {
                    $state['cleanup_origin_phase'] = 'prepared';
                }
                $state['phase'] = 'cleaning';
                $this->writeState($state);
            }
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
    private function insertUser(array &$state, string $key, int $role, string $label): int
    {
        $username = 'defense_verify_' . $state['run_id'] . '_' . $key;
        $email = $username . '@synthetic.invalid';
        $state['intents']['users'][$key] = [
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
        $this->insertExact('user_settings', [
            'id_users' => $id,
            'username' => $username,
            'password' => \hash_password($salt, bin2hex(random_bytes(32))),
            'salt' => $salt,
            'working_plan' => '{}',
            'working_plan_exceptions' => '{}',
            'notifications' => 0,
            'google_sync' => 0,
            'caldav_sync' => 0,
        ]);
        $state['ids'][$key] = $id;
        $state['usernames'][$key] = $username;
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
        $state['intents']['service'] = ['description' => $state['marker']];
        $this->journal($state);
        $this->insertExact('services', [
            'name' => 'Synthetic ' . $state['run_id'],
            'duration' => 30,
            'buffer_before' => 0,
            'buffer_after' => 0,
            'price' => 0,
            'currency' => 'EUR',
            'description' => $state['marker'],
            'location' => null,
            'color' => '#6c757d',
            'availabilities_type' => AVAILABILITIES_TYPE_FLEXIBLE,
            'attendants_number' => 1,
            'is_private' => 1,
            'id_service_categories' => null,
        ]);
        $id = (int) $this->db->insert_id();
        $state['ids']['service'] = $id;
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
    private function assertOwnership(array $state): void
    {
        foreach ($state['ids'] as $key => $id) {
            $id = (int) $id;
            if ($id < 1) {
                throw new RuntimeException('Invalid journaled fixture ID.');
            }
            $table = str_contains($key, 'service_link')
                ? 'services_providers'
                : ($key === 'service'
                    ? 'services'
                    : ($key === 'appointment'
                        ? 'appointments'
                        : 'users'));
            if ($this->db->get_where($table, ['id' => $id])->num_rows() !== 1) {
                throw new RuntimeException('Fixture ownership is missing or ambiguous.');
            }
        }
        foreach (
            [
                'provider_target',
                'admin_target',
                'foreign_provider',
                'customer_find_update',
                'customer_destroy',
                'calendar_customer',
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
                    : (in_array($key, ['admin_target'], true)
                        ? 'admin'
                        : 'customer');
                if ((int) ($user['id_roles'] ?? 0) !== (int) $state['roles'][$roleKey]) {
                    throw new RuntimeException('Fixture role drift detected.');
                }
                if (isset($state['usernames'][$key])) {
                    $settings = $this->db->get_where('user_settings', ['id_users' => $state['ids'][$key]])->row_array();
                    if (($settings['username'] ?? null) !== $state['usernames'][$key]) {
                        throw new RuntimeException('Fixture username drift detected.');
                    }
                } elseif (
                    $this->db->get_where('user_settings', ['id_users' => $state['ids'][$key]])->num_rows() !== 0
                ) {
                    throw new RuntimeException('Synthetic customer unexpectedly has login settings.');
                }
            }
        }
        if (isset($state['ids']['service'])) {
            $this->assertExactRow('services', (int) $state['ids']['service'], [
                'description' => $state['marker'],
                'attendants_number' => 1,
            ]);
        }
        if (isset($state['ids']['appointment'])) {
            $this->assertExactRow('appointments', (int) $state['ids']['appointment'], [
                'notes' => $state['marker'],
                'id_users_provider' => $state['actor_id'],
                'id_users_customer' => $state['ids']['calendar_customer'],
                'id_services' => $state['ids']['service'],
            ]);
        }
        foreach ($state['links'] ?? [] as $link) {
            if (
                !is_array($link) ||
                $this->db
                    ->get_where('services_providers', [
                        'id_users' => (int) ($link['id_users'] ?? 0),
                        'id_services' => (int) ($link['id_services'] ?? 0),
                    ])
                    ->num_rows() !== 1
            ) {
                throw new RuntimeException('Fixture service relationship drift detected.');
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
        foreach ($state['links'] ?? [] as $link) {
            if (!is_array($link)) {
                throw new RuntimeException('Prepared fixture service relationship is invalid.');
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

    /** @param array<string,mixed> $state */
    private function deleteOwned(array $state, bool $alreadyCleaning, bool $wasPrepared): void
    {
        $ids = $state['ids'];
        $this->lockFixtureUsers($state, $alreadyCleaning);
        $this->assertServiceDependencies($state, $alreadyCleaning, $wasPrepared);
        if (isset($ids['appointment'])) {
            $exists = $this->db->get_where('appointments', ['id' => (int) $ids['appointment']])->num_rows() !== 0;
            if ($exists || !$alreadyCleaning) {
                $this->assertExactRow('appointments', (int) $ids['appointment'], [
                    'notes' => $state['marker'],
                    'id_users_provider' => $state['actor_id'],
                    'id_users_customer' => $ids['calendar_customer'],
                ]);
                $this->db->delete('appointments', ['id' => $ids['appointment'], 'notes' => $state['marker']]);
            }
        }
        foreach ($state['links'] ?? [] as $link) {
            if (!is_array($link) || count($link) !== 2) {
                throw new RuntimeException('Fixture service relationship journal is invalid.');
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
                        'customer_find_update',
                        'customer_destroy',
                        'calendar_customer',
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

    /** Lock every synthetic user parent before service and relationship children. */
    private function lockFixtureUsers(array $state, bool $alreadyCleaning): void
    {
        $ids = [];
        foreach (
            [
                'provider_target',
                'admin_target',
                'foreign_provider',
                'customer_find_update',
                'customer_destroy',
                'calendar_customer',
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

    /** Lock and compare every child before deleting the synthetic service. */
    private function assertServiceDependencies(array $state, bool $alreadyCleaning, bool $prepared = false): void
    {
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
                    'SELECT id, id_parent_appointment, id_services FROM ' .
                        $this->db->dbprefix('appointments') .
                        ' WHERE id_parent_appointment = ? ORDER BY id FOR UPDATE',
                    [$parentId],
                )
                ->result_array();
            if ($generatedChildren !== []) {
                throw new RuntimeException('Unexpected generated appointment child; refusing cleanup.');
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
        if (!isset($state['ids']['service']) && isset($state['intents']['service'])) {
            $rows = $this->db->get_where('services', $state['intents']['service'])->result_array();
            if (count($rows) > 1) {
                throw new RuntimeException('Fixture service identity is ambiguous; refusing cleanup.');
            }
            if (count($rows) === 1) {
                $state['ids']['service'] = (int) $rows[0]['id'];
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
        return $state;
    }

    /** @return list<string> */
    private function remainingIds(array $state): array
    {
        $remaining = [];
        foreach ($state['ids'] as $key => $id) {
            $table = str_contains($key, 'service_link')
                ? 'services_providers'
                : ($key === 'service'
                    ? 'services'
                    : ($key === 'appointment'
                        ? 'appointments'
                        : 'users'));
            if ($this->db->get_where($table, ['id' => (int) $id])->num_rows() !== 0) {
                $remaining[] = $key;
            }
        }
        foreach ($state['links'] ?? [] as $key => $link) {
            if (
                is_array($link) &&
                $this->db
                    ->get_where('services_providers', [
                        'id_users' => (int) ($link['id_users'] ?? 0),
                        'id_services' => (int) ($link['id_services'] ?? 0),
                    ])
                    ->num_rows() !== 0
            ) {
                $remaining[] = 'link:' . $key;
            }
        }
        foreach (['provider_target', 'admin_target', 'foreign_provider'] as $key) {
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
            $this->db->like('notes', 'defense-verification:', 'after')->count_all_results('appointments') > 0;
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
