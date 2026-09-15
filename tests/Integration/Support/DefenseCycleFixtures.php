<?php

declare(strict_types=1);

namespace Tests\Integration\Support;

use RuntimeException;

/** Only used in the fresh, disposable Docker stack owned by run_defense_cycle.sh. */
final class DefenseCycleFixtures
{
    public readonly string $run;
    public readonly string $password;
    public int $actorId;
    public int $providerId;
    public int $customerId;
    public int $serviceId;
    /** @var array<string, int> */
    private array $secretaryWriteIds = [];
    private object $db;
    private array $users = [];
    private array $settings = [];
    /** @var array<string, int> */
    private array $ownedSettingIds = [];
    private array $baselineCounts = [];
    private array $providerWriteEmails = [];
    private array $adminWriteEmails = [];
    private array $customerWriteEmails = [];
    private array $blockedPeriodWriteNames = [];
    private ?array $providerHttpAuthSettings = null;
    private ?array $providerHttpAuthTokenSetting = null;

    public function __construct()
    {
        if (
            getenv('FH_DEFENSE_ISOLATED') !== '1' ||
            ENVIRONMENT !== 'testing' ||
            !is_file('/.dockerenv') ||
            \Config::DB_HOST !== 'mysql' ||
            \Config::DB_NAME !== 'easyappointments' ||
            \Config::DB_USERNAME !== 'user' ||
            \Config::DB_PASSWORD !== 'password'
        ) {
            throw new RuntimeException('Requires the explicitly owned fresh synthetic Docker stack.');
        }
        $ci = &\get_instance();
        $this->db = $ci->db;
        foreach (
            [
                'users',
                'services',
                'appointments',
                'user_settings',
                'services_providers',
                'secretaries_providers',
                'blocked_periods',
            ]
            as $table
        ) {
            $this->baselineCounts[$table] = $this->db->count_all($table);
        }
        $this->run = 'defense_' . bin2hex(random_bytes(8));
        $this->password = bin2hex(random_bytes(24));
    }

    public function create(): void
    {
        // These settings belong to the disposable seed, never to a live database.
        foreach (
            [
                'customer_notifications' => '0',
                'require_captcha' => '0',
                'book_advance_timeout' => '0',
                'disable_booking' => '0',
            ]
            as $key => $value
        ) {
            $this->settings[$key] = $this->db->get_where('settings', ['name' => $key])->row_array();
            if ($this->settings[$key]) {
                $this->db->update('settings', ['value' => $value], ['name' => $key]);
            } else {
                $this->db->insert('settings', ['name' => $key, 'value' => $value]);
            }
        }
        $this->actorId = $this->user('admin', 'actor');
        $this->providerId = $this->user('provider', 'provider');
        $this->customerId = $this->user('customer', 'customer');
        $this->db->insert('services', [
            'name' => $this->run,
            'duration' => 30,
            'price' => 0,
            'currency' => 'EUR',
            'description' => $this->run,
            'location' => 'Synthetic',
            'is_private' => 0,
            'attendants_number' => 1,
            'buffer_before' => 0,
            'buffer_after' => 0,
        ]);
        $this->serviceId = (int) $this->db->insert_id();
        $this->db->insert('services_providers', ['id_users' => $this->providerId, 'id_services' => $this->serviceId]);
    }

    private function user(string $role, string $suffix): int
    {
        $roleId = $this->db->get_where('roles', ['slug' => $role])->row_array()['id'] ?? null;
        if (!$roleId) {
            throw new RuntimeException('Required synthetic role is missing.');
        }
        $this->db->insert('users', [
            'first_name' => 'Synthetic',
            'last_name' => $suffix,
            'email' => $this->run . '_' . $suffix . '@synthetic.invalid',
            'phone_number' => '000000000',
            'notes' => $this->run,
            'timezone' => 'UTC',
            'language' => 'english',
            'id_roles' => $roleId,
            'is_private' => 0,
        ]);
        $id = (int) $this->db->insert_id();
        $this->users[] = $id;
        if ($role !== 'customer') {
            $salt = \generate_salt();
            $plan = array_fill_keys(
                ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
                ['start' => '08:00', 'end' => '18:00', 'breaks' => []],
            );
            $this->db->insert('user_settings', [
                'id_users' => $id,
                'username' => $this->run . '_' . $suffix,
                'password' => \hash_password($salt, $this->password),
                'salt' => $salt,
                'working_plan' => json_encode($plan),
                'notifications' => 0,
                'google_sync' => 0,
                'caldav_sync' => 0,
            ]);
        }
        return $id;
    }

    public function appointment(bool $legacy = false): array
    {
        $ci = &\get_instance();
        $ci->load->model('appointments_model');
        $id = $ci->appointments_model->save([
            'start_datetime' => date('Y-m-d 10:00:00', strtotime('+14 days')),
            'end_datetime' => date('Y-m-d 10:30:00', strtotime('+14 days')),
            'notes' => $this->run,
            'is_unavailability' => false,
            'id_users_provider' => $this->providerId,
            'id_users_customer' => $this->customerId,
            'id_services' => $this->serviceId,
        ]);
        if ($legacy) {
            $this->db->update('appointments', ['hash' => substr(bin2hex(random_bytes(6)), 0, 12)], ['id' => $id]);
        }
        return $ci->appointments_model->find($id);
    }

    /**
     * Opt in to the local HTTP provider-auth fixture and return its synthetic credentials.
     *
     * @return array{admin_username:string,provider_username:string,password:string,token:string}
     */
    public function enableProviderHttpAuth(): array
    {
        if ($this->providerHttpAuthSettings !== null || !isset($this->providerId)) {
            throw new RuntimeException('Provider HTTP auth fixture was already enabled or is not created.');
        }

        $tokenRow = $this->db->get_where('settings', ['name' => 'api_token'])->row_array();
        $providerSettings = $this->db->get_where('user_settings', ['id_users' => $this->providerId])->row_array();
        if (!$tokenRow || !$providerSettings) {
            throw new RuntimeException('Provider HTTP auth fixture prerequisites are missing.');
        }

        $this->providerHttpAuthTokenSetting = $tokenRow;
        $this->providerHttpAuthSettings = [
            'google_token' => $providerSettings['google_token'] ?? null,
            'caldav_password' => $providerSettings['caldav_password'] ?? null,
        ];
        $token = bin2hex(random_bytes(32));
        $this->db->update('settings', ['value' => $token], ['name' => 'api_token']);
        $this->db->update(
            'user_settings',
            [
                'google_token' => $this->run . '_google_integration',
                'caldav_password' => $this->run . '_caldav_integration',
            ],
            ['id_users' => $this->providerId],
        );
        $stored = $this->db->get_where('user_settings', ['id_users' => $this->providerId])->row_array();
        if (
            ($stored['google_token'] ?? '') !== $this->run . '_google_integration' ||
            ($stored['caldav_password'] ?? '') !== $this->run . '_caldav_integration'
        ) {
            throw new RuntimeException('Provider HTTP auth integration fixture was not stored.');
        }

        $stored = $this->db->get_where('user_settings', ['id_users' => $this->providerId])->row_array();
        if (
            ($stored['google_token'] ?? null) !== $this->run . '_google_integration' ||
            ($stored['caldav_password'] ?? null) !== $this->run . '_caldav_integration'
        ) {
            throw new RuntimeException('Synthetic integration values were not stored.');
        }

        return [
            'admin_username' => $this->run . '_actor',
            'provider_username' => $this->run . '_provider',
            'password' => $this->password,
            'token' => $token,
        ];
    }

    /** Register an exact synthetic identity before sending a POST, independent of its response. */
    public function providerWritePayload(string $case): array
    {
        if (!isset($this->serviceId) || !preg_match('/^[a-z0-9_-]+$/D', $case)) {
            throw new RuntimeException('Invalid Provider write fixture case.');
        }
        $username = $this->run . '_write_' . $case;
        $email = $username . '@synthetic.invalid';
        if (isset($this->providerWriteEmails[$email])) {
            throw new RuntimeException('Provider write fixture identity is already registered.');
        }
        $this->providerWriteEmails[$email] = $username;
        return [
            'firstName' => 'Synthetic',
            'lastName' => 'HTTP ' . $case,
            'email' => $email,
            'phone' => '000000000',
            'notes' => $username,
            'services' => [$this->serviceId],
            'settings' => [
                'username' => $username,
                'password' => $this->password,
                'googleToken' => $username . '_google',
                'caldavPassword' => $username . '_caldav',
            ],
        ];
    }

    public function providerWriteState(string $email): array
    {
        if (!isset($this->providerWriteEmails[$email])) {
            throw new RuntimeException('Unregistered Provider write identity.');
        }
        $rows = $this->db->get_where('users', ['email' => $email])->result_array();
        if (!$rows) {
            return [];
        }
        if (count($rows) !== 1) {
            throw new RuntimeException('Ambiguous Provider write identity.');
        }
        $id = (int) $rows[0]['id'];
        $services = $this->db
            ->order_by('id_services')
            ->get_where('services_providers', ['id_users' => $id])
            ->result_array();
        return [
            'user' => $rows[0],
            'settings' => $this->db->get_where('user_settings', ['id_users' => $id])->row_array() ?? [],
            'services' => array_map(static fn(array $row): int => (int) $row['id_services'], $services),
        ];
    }

    /** Register an exact synthetic Secretary identity before an HTTP write. */
    public function secretaryWritePayload(string $case, array $providers = []): array
    {
        if (!preg_match('/^[a-z0-9_-]+$/D', $case) || isset($this->secretaryWriteIds[$case])) {
            throw new RuntimeException('Invalid Secretary write fixture case.');
        }
        $username = $this->run . '_secretary_' . $case;
        $email = $username . '@synthetic.invalid';
        $this->secretaryWriteIds[$case] = 0;
        return [
            'firstName' => 'Synthetic',
            'lastName' => 'Secretary ' . $case,
            'email' => $email,
            'notes' => $username,
            'providers' => $providers,
            'settings' => ['username' => $username, 'password' => $this->password],
        ];
    }

    public function adminWritePayload(string $case): array
    {
        if (!preg_match('/^[a-z0-9_-]+$/D', $case) || isset($this->adminWriteEmails[$case])) {
            throw new RuntimeException('Invalid Admin write fixture case.');
        }
        $username = $this->run . '_admin_' . $case;
        $email = $username . '@synthetic.invalid';
        $this->adminWriteEmails[$case] = $email;
        return [
            'firstName' => 'Synthetic',
            'lastName' => 'Admin ' . $case,
            'email' => $email,
            'notes' => $username,
            'settings' => ['username' => $username, 'password' => $this->password],
        ];
    }

    /** Register exact identities before ordinary authenticated backoffice writes. */
    public function customerWritePayload(string $case): array
    {
        if (!preg_match('/^[a-z0-9_-]+$/D', $case)) {
            throw new RuntimeException('Invalid Customer write fixture case.');
        }
        $email = $this->run . '_customer_' . $case . '@synthetic.invalid';
        if (
            isset($this->customerWriteEmails[$email]) ||
            $this->db->get_where('users', ['email' => $email])->num_rows() !== 0
        ) {
            throw new RuntimeException('Customer write fixture identity is already registered.');
        }
        $this->customerWriteEmails[$email] = 0;
        return [
            'first_name' => 'Synthetic',
            'last_name' => 'Customer ' . $case,
            'email' => $email,
            'phone_number' => '0000000000',
            'notes' => $this->run,
        ];
    }

    public function blockedPeriodWritePayload(string $case): array
    {
        if (!preg_match('/^[a-z0-9_-]+$/D', $case)) {
            throw new RuntimeException('Invalid Blocked-period write fixture case.');
        }
        $name = $this->run . '_blocked_' . $case;
        if (
            isset($this->blockedPeriodWriteNames[$name]) ||
            $this->db->get_where('blocked_periods', ['name' => $name])->num_rows() !== 0
        ) {
            throw new RuntimeException('Blocked-period write fixture name is already registered.');
        }
        $this->blockedPeriodWriteNames[$name] = 0;
        return [
            'name' => $name,
            'start_datetime' => date('Y-m-d 12:00:00', strtotime('+21 days')),
            'end_datetime' => date('Y-m-d 13:00:00', strtotime('+21 days')),
            'notes' => $this->run,
        ];
    }

    public function secretaryWriteState(string $email): array
    {
        $row = $this->db->get_where('users', ['email' => $email])->row_array();
        if (!$row) {
            return [];
        }
        $id = (int) $row['id'];
        return [
            'user' => $row,
            'settings' => $this->db->get_where('user_settings', ['id_users' => $id])->row_array() ?? [],
            'providers' => array_map(
                static fn(array $connection): int => (int) $connection['id_users_provider'],
                $this->db
                    ->order_by('id_users_provider')
                    ->get_where('secretaries_providers', ['id_users_secretary' => $id])
                    ->result_array(),
            ),
        ];
    }

    /** Only owned synthetic staff rows may be seeded with integration sentinels. */
    public function seedStaffIntegrationSecrets(int $id): void
    {
        $row = $this->row('users', $id);
        if (!$row || !str_starts_with((string) $row['email'], $this->run . '_')) {
            throw new RuntimeException('Not an owned staff fixture.');
        }
        if (
            !$this->db->update(
                'user_settings',
                [
                    'google_token' => $this->run . '_staff_google_' . $id,
                    'caldav_password' => $this->run . '_staff_caldav_' . $id,
                ],
                ['id_users' => $id],
            )
        ) {
            throw new RuntimeException('Could not seed staff integration values.');
        }
    }

    /** Complete deterministic snapshots cover both row counts and changed values in the owned seed. */
    public function providerWriteSnapshot(): array
    {
        $snapshot = [];
        foreach (['users', 'services', 'appointments', 'user_settings', 'services_providers'] as $table) {
            $rows = $this->db->get($table)->result_array();
            usort($rows, static fn(array $left, array $right): int => strcmp(json_encode($left), json_encode($right)));
            $snapshot[$table] = $rows;
        }
        return $snapshot;
    }

    private function cleanupProviderWrites(): void
    {
        if (!$this->providerWriteEmails) {
            return;
        }
        $providerRole = $this->db->get_where('roles', ['slug' => 'provider'])->row_array();
        foreach ($this->providerWriteEmails as $email => $username) {
            $state = $this->providerWriteState($email);
            if (!$state) {
                continue;
            }
            $id = (int) $state['user']['id'];
            if (
                !$providerRole ||
                (int) $state['user']['id_roles'] !== (int) $providerRole['id'] ||
                (isset($state['settings']['username']) && $state['settings']['username'] !== $username) ||
                array_diff($state['services'], [$this->serviceId]) ||
                $this->db->get_where('appointments', ['id_users_provider' => $id])->num_rows() !== 0
            ) {
                throw new RuntimeException('Unexpected Provider write fixture relationship; dispose owned stack.');
            }
            // Missing settings or service rows are allowed after a partial POST.
            $this->db->delete('services_providers', ['id_users' => $id, 'id_services' => $this->serviceId]);
            $this->db->delete('user_settings', ['id_users' => $id]);
            $this->db->delete('users', ['id' => $id, 'email' => $email]);
            if (
                $this->providerWriteState($email) ||
                $this->db->get_where('user_settings', ['id_users' => $id])->num_rows() !== 0 ||
                $this->db->get_where('services_providers', ['id_users' => $id])->num_rows() !== 0
            ) {
                throw new RuntimeException('Provider write cleanup was not confirmed.');
            }
        }
    }

    private function cleanupSecretaryWrites(): void
    {
        foreach ($this->secretaryWriteIds as $case => $_) {
            $email = $this->run . '_secretary_' . $case . '@synthetic.invalid';
            $state = $this->secretaryWriteState($email);
            if (!$state) {
                continue;
            }
            $role = $this->db->get_where('roles', ['slug' => 'secretary'])->row_array();
            if (!$role || (int) $state['user']['id_roles'] !== (int) $role['id']) {
                throw new RuntimeException('Unexpected Secretary write fixture identity.');
            }
            $id = (int) $state['user']['id'];
            $this->db->delete('secretaries_providers', ['id_users_secretary' => $id]);
            $this->db->delete('user_settings', ['id_users' => $id]);
            $this->db->delete('users', ['id' => $id, 'email' => $email]);
            if ($this->secretaryWriteState($email)) {
                throw new RuntimeException('Secretary write cleanup was not confirmed.');
            }
        }
    }

    private function cleanupAdminWrites(): void
    {
        foreach ($this->adminWriteEmails as $email) {
            $row = $this->db->get_where('users', ['email' => $email])->row_array();
            if (!$row) {
                continue;
            }
            $role = $this->db->get_where('roles', ['slug' => 'admin'])->row_array();
            if (!$role || (int) $row['id_roles'] !== (int) $role['id']) {
                throw new RuntimeException('Unexpected Admin write fixture identity.');
            }
            $id = (int) $row['id'];
            $this->db->delete('user_settings', ['id_users' => $id]);
            $this->db->delete('users', ['id' => $id, 'email' => $email]);
        }
    }

    private function cleanupCustomerWrites(): void
    {
        $role = $this->db->get_where('roles', ['slug' => 'customer'])->row_array();
        foreach ($this->customerWriteEmails as $email => $_) {
            $rows = $this->db->get_where('users', ['email' => $email])->result_array();
            if (count($rows) > 1) {
                throw new RuntimeException('Ambiguous Customer write fixture identity.');
            }
            foreach ($rows as $row) {
                if (!$role || (int) $row['id_roles'] !== (int) $role['id']) {
                    throw new RuntimeException('Unexpected Customer write fixture identity.');
                }
                $id = (int) $row['id'];
                if ($this->db->get_where('appointments', ['id_users_customer' => $id])->num_rows() !== 0) {
                    throw new RuntimeException('Unexpected Customer write fixture appointment relationship.');
                }
                $this->db->delete('user_settings', ['id_users' => $id]);
                $this->db->delete('users', ['id' => $id, 'email' => $email]);
                if ($this->db->get_where('user_settings', ['id_users' => $id])->num_rows() !== 0) {
                    throw new RuntimeException('Customer write settings cleanup was not confirmed.');
                }
            }
            if ($this->db->get_where('users', ['email' => $email])->num_rows() !== 0) {
                throw new RuntimeException('Customer write cleanup was not confirmed.');
            }
        }
    }

    private function cleanupBlockedPeriodWrites(): void
    {
        foreach ($this->blockedPeriodWriteNames as $name => $_) {
            $rows = $this->db->get_where('blocked_periods', ['name' => $name])->result_array();
            if (count($rows) > 1) {
                throw new RuntimeException('Ambiguous Blocked-period write fixture identity.');
            }
            foreach ($rows as $row) {
                $this->db->delete('blocked_periods', ['id' => (int) $row['id'], 'name' => $name]);
            }
            if ($this->db->get_where('blocked_periods', ['name' => $name])->num_rows() !== 0) {
                throw new RuntimeException('Blocked-period write cleanup was not confirmed.');
            }
        }
    }

    public function row(string $table, int $id): array
    {
        if (!in_array($table, ['users', 'appointments', 'services'], true)) {
            throw new RuntimeException('Unsupported fixture table.');
        }
        return $this->db->get_where($table, ['id' => $id])->row_array() ?? [];
    }

    public function blockedPeriodRow(int $id): array
    {
        return $this->db->get_where('blocked_periods', ['id' => $id])->row_array() ?? [];
    }

    public function userSettingsRow(int $id): array
    {
        return $this->db->get_where('user_settings', ['id_users' => $id])->row_array() ?? [];
    }

    /** Create an owned setting only after proving its run-derived name is absent. */
    public function ownedSetting(string $suffix, string $value): array
    {
        if (!preg_match('/^[a-z0-9_-]+$/D', $suffix)) {
            throw new RuntimeException('Invalid owned setting suffix.');
        }
        $name = $this->run . '_setting_' . $suffix;
        if ($this->db->get_where('settings', ['name' => $name])->num_rows() !== 0) {
            throw new RuntimeException('Owned setting name already exists.');
        }
        $this->ownedSettingIds[$name] = 0;
        if (!$this->db->insert('settings', ['name' => $name, 'value' => $value])) {
            throw new RuntimeException('Could not create owned setting.');
        }
        $id = (int) $this->db->insert_id();
        $this->ownedSettingIds[$name] = $id;
        $row = $this->db->get_where('settings', ['id' => $id, 'name' => $name])->row_array();
        if (!$row) {
            throw new RuntimeException('Owned setting identity was not confirmed.');
        }
        return $row;
    }

    private function cleanupOwnedSettings(): void
    {
        foreach ($this->ownedSettingIds as $name => $id) {
            $row = $this->db->get_where('settings', ['id' => $id, 'name' => $name])->row_array();
            if ($row && !$this->db->delete('settings', ['id' => $id, 'name' => $name])) {
                throw new RuntimeException('Owned setting delete failed.');
            }
            if (
                $this->db->get_where('settings', ['id' => $id, 'name' => $name])->num_rows() !== 0 ||
                $this->db->get_where('settings', ['name' => $name])->num_rows() !== 0
            ) {
                throw new RuntimeException('Owned setting cleanup was not confirmed.');
            }
        }
    }

    public function settingRow(int $id): array
    {
        $name = array_search($id, $this->ownedSettingIds, true);
        if (!is_string($name)) {
            throw new RuntimeException('Setting ID is outside the owned fixture.');
        }
        return $this->db->get_where('settings', ['id' => $id, 'name' => $name])->row_array() ?? [];
    }

    /** @return list<string> */
    public function seededStaffSecretValues(): array
    {
        $ids = [$this->actorId, $this->providerId];
        $emails = array_values($this->adminWriteEmails);
        foreach (array_keys($this->secretaryWriteIds) as $case) {
            $emails[] = $this->run . '_secretary_' . $case . '@synthetic.invalid';
        }
        foreach ($emails as $email) {
            $row = $this->db->get_where('users', ['email' => $email])->row_array();
            if ($row) {
                $ids[] = (int) $row['id'];
            }
        }
        $values = [$this->password];
        foreach (array_unique($ids) as $id) {
            $settings = $this->userSettingsRow($id);
            foreach (['password', 'salt', 'google_token', 'caldav_password'] as $key) {
                if (is_string($settings[$key] ?? null) && $settings[$key] !== '') {
                    $values[] = $settings[$key];
                }
            }
        }
        return array_values(array_unique($values));
    }

    public function cleanup(): void
    {
        $this->cleanupOwnedSettings();
        $this->cleanupCustomerWrites();
        $this->cleanupBlockedPeriodWrites();
        $this->cleanupProviderWrites();
        $this->cleanupSecretaryWrites();
        $this->cleanupAdminWrites();
        if ($this->providerHttpAuthSettings !== null && isset($this->providerId)) {
            $this->db->update('user_settings', $this->providerHttpAuthSettings, ['id_users' => $this->providerId]);
            $restored = $this->db->get_where('user_settings', ['id_users' => $this->providerId])->row_array();
            if (
                ($restored['google_token'] ?? null) !== $this->providerHttpAuthSettings['google_token'] ||
                ($restored['caldav_password'] ?? null) !== $this->providerHttpAuthSettings['caldav_password']
            ) {
                throw new RuntimeException('Provider HTTP auth fixture settings were not restored.');
            }
            $this->providerHttpAuthSettings = null;
        }
        if ($this->providerHttpAuthTokenSetting !== null) {
            $this->db->update(
                'settings',
                ['value' => $this->providerHttpAuthTokenSetting['value']],
                ['name' => 'api_token'],
            );
            $restored = $this->db->get_where('settings', ['name' => 'api_token'])->row_array();
            if (($restored['value'] ?? null) !== $this->providerHttpAuthTokenSetting['value']) {
                throw new RuntimeException('Provider HTTP auth token was not restored.');
            }
            $this->providerHttpAuthTokenSetting = null;
        }
        if (isset($this->serviceId)) {
            // A new relationship outside this exact fixture must not be swept away.
            $rows = $this->db->get_where('appointments', ['id_services' => $this->serviceId])->result_array();
            foreach ($rows as $row) {
                if (
                    (int) $row['id_users_provider'] !== $this->providerId ||
                    (int) $row['id_users_customer'] !== $this->customerId
                ) {
                    throw new RuntimeException(
                        'Unexpected fixture relationship; dispose owned stack, do not sweep rows.',
                    );
                }
                $this->db->delete('reschedule_authorities', ['appointment_id' => $row['id']]);
                $this->db->delete('appointments', ['id' => $row['id']]);
            }
            $this->db->delete('services_providers', [
                'id_users' => $this->providerId,
                'id_services' => $this->serviceId,
            ]);
            $this->db->delete('services', ['id' => $this->serviceId, 'description' => $this->run]);
            if ($this->row('services', $this->serviceId)) {
                throw new RuntimeException('Service cleanup was not confirmed.');
            }
        }
        foreach (array_reverse($this->users) as $id) {
            if (!$this->row('users', $id)) {
                continue;
            }
            if (
                ($this->row('users', $id)['email'] ?? '') !==
                $this->run .
                    '_' .
                    ($id === ($this->actorId ?? null)
                        ? 'actor'
                        : ($id === ($this->providerId ?? null)
                            ? 'provider'
                            : 'customer')) .
                    '@synthetic.invalid'
            ) {
                throw new RuntimeException('Fixture user identity changed.');
            }
            $this->db->delete('user_settings', ['id_users' => $id]);
            $this->db->delete('users', ['id' => $id]);
            if ($this->row('users', $id)) {
                throw new RuntimeException('User cleanup was not confirmed.');
            }
        }
        $this->users = [];
        foreach ($this->settings as $name => $row) {
            if ($row) {
                $this->db->update('settings', ['value' => $row['value']], ['name' => $name]);
            } else {
                $this->db->delete('settings', ['name' => $name]);
            }
        }
        $this->settings = [];
        foreach ($this->baselineCounts as $table => $count) {
            if ($this->db->count_all($table) !== $count) {
                throw new RuntimeException('Fixture cleanup count mismatch for ' . $table);
            }
        }
    }
}
