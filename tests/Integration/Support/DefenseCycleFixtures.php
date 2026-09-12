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
    private object $db;
    private array $users = [];
    private array $settings = [];
    private array $baselineCounts = [];

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
        foreach (['users', 'services', 'appointments', 'user_settings', 'services_providers'] as $table) {
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

    public function row(string $table, int $id): array
    {
        if (!in_array($table, ['users', 'appointments', 'services'], true)) {
            throw new RuntimeException('Unsupported fixture table.');
        }
        return $this->db->get_where($table, ['id' => $id])->row_array() ?? [];
    }

    public function cleanup(): void
    {
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
