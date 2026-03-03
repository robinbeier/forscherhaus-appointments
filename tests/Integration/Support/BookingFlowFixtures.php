<?php

namespace Tests\Integration\Support;

use DateInterval;
use DateTimeImmutable;
use RuntimeException;

class BookingFlowFixtures
{
    private object $CI;

    /**
     * @var array<int, array{table:string,id:int}>
     */
    private array $cleanupRegistry = [];

    /**
     * @var array<string, array{exists:bool,value:?string}>
     */
    private array $settingSnapshots = [];

    public function __construct()
    {
        $this->CI = &\get_instance();
    }

    /**
     * @param array<string> $keys
     */
    public function snapshotSettings(array $keys): void
    {
        $this->settingSnapshots = [];

        foreach ($keys as $key) {
            $row = $this->CI->db->get_where('settings', ['name' => $key])->row_array();

            $this->settingSnapshots[$key] = [
                'exists' => !empty($row),
                'value' => $row['value'] ?? null,
            ];
        }
    }

    public function setSetting(string $name, string $value): void
    {
        $row = $this->CI->db->get_where('settings', ['name' => $name])->row_array();

        if (empty($row)) {
            $this->CI->db->insert('settings', [
                'name' => $name,
                'value' => $value,
            ]);

            return;
        }

        $this->CI->db->update('settings', ['value' => $value], ['name' => $name]);
    }

    public function restoreSettings(): void
    {
        foreach ($this->settingSnapshots as $name => $snapshot) {
            if ($snapshot['exists']) {
                $this->CI->db->update('settings', ['value' => $snapshot['value']], ['name' => $name]);
                continue;
            }

            $this->CI->db->delete('settings', ['name' => $name]);
        }

        $this->settingSnapshots = [];
    }

    /**
     * @return array{provider_id:int,service_id:int}
     */
    public function resolveProviderServicePair(): array
    {
        $pair = $this->CI->db
            ->select('services_providers.id_users AS provider_id, services_providers.id_services AS service_id')
            ->from('services_providers')
            ->join('users', 'users.id = services_providers.id_users', 'inner')
            ->join('roles', 'roles.id = users.id_roles', 'inner')
            ->join('services', 'services.id = services_providers.id_services', 'inner')
            ->where('roles.slug', DB_SLUG_PROVIDER)
            ->order_by('services_providers.id_users ASC, services_providers.id_services ASC')
            ->limit(1)
            ->get()
            ->row_array();

        if (empty($pair['provider_id']) || empty($pair['service_id'])) {
            throw new RuntimeException('Could not resolve provider/service pair from deterministic seed data.');
        }

        return [
            'provider_id' => (int) $pair['provider_id'],
            'service_id' => (int) $pair['service_id'],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function createCustomer(array $overrides = []): int
    {
        $role = $this->CI->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])->row_array();

        if (empty($role['id'])) {
            throw new RuntimeException('Customer role missing in test database.');
        }

        $uniq = bin2hex(random_bytes(6));
        $now = date('Y-m-d H:i:s');
        $defaultTimezone = \setting('default_timezone') ?: 'UTC';
        $defaultLanguage = \setting('default_language') ?: 'english';

        $customer = array_merge(
            [
                'first_name' => 'Booking',
                'last_name' => 'Flow-' . $uniq,
                'email' => 'booking-flow-' . $uniq . '@example.test',
                'phone_number' => '',
                'address' => '',
                'city' => '',
                'zip_code' => '',
                'timezone' => $defaultTimezone,
                'language' => $defaultLanguage,
                'id_roles' => (int) $role['id'],
                'create_datetime' => $now,
                'update_datetime' => $now,
            ],
            $overrides,
        );

        $this->CI->db->insert('users', $customer);
        $customerId = (int) $this->CI->db->insert_id();

        $this->registerCleanup('users', $customerId);

        return $customerId;
    }

    public function createAppointment(
        int $providerId,
        int $customerId,
        int $serviceId,
        DateTimeImmutable $startAt,
        ?DateTimeImmutable $endAt = null,
        string $notes = 'Booking flow test appointment',
    ): int {
        $now = date('Y-m-d H:i:s');
        $resolvedEndAt = $endAt ?? $startAt->add(new DateInterval('PT' . EVENT_MINIMUM_DURATION . 'M'));

        $this->CI->db->insert('appointments', [
            'book_datetime' => $now,
            'start_datetime' => $startAt->format('Y-m-d H:i:s'),
            'end_datetime' => $resolvedEndAt->format('Y-m-d H:i:s'),
            'notes' => $notes,
            'hash' => 'flow-' . bin2hex(random_bytes(6)),
            'is_unavailability' => false,
            'id_users_provider' => $providerId,
            'id_users_customer' => $customerId,
            'id_services' => $serviceId,
            'create_datetime' => $now,
            'update_datetime' => $now,
        ]);

        $appointmentId = (int) $this->CI->db->insert_id();

        $this->registerCleanup('appointments', $appointmentId);

        return $appointmentId;
    }

    public function findAppointmentById(int $appointmentId): ?array
    {
        $row = $this->CI->db->get_where('appointments', ['id' => $appointmentId])->row_array();

        return empty($row) ? null : $row;
    }

    public function findAppointmentByHash(string $hash): ?array
    {
        $row = $this->CI->db->get_where('appointments', ['hash' => $hash])->row_array();

        return empty($row) ? null : $row;
    }

    public function countAppointmentsByHash(string $hash): int
    {
        return (int) $this->CI->db
            ->from('appointments')
            ->where('hash', $hash)
            ->count_all_results();
    }

    public function customerExistsByEmail(string $email): bool
    {
        $count = $this->CI->db
            ->from('users')
            ->join('roles', 'roles.id = users.id_roles', 'inner')
            ->where('roles.slug', DB_SLUG_CUSTOMER)
            ->where('users.email', $email)
            ->count_all_results();

        return $count > 0;
    }

    public function cleanup(): void
    {
        for ($index = count($this->cleanupRegistry) - 1; $index >= 0; $index--) {
            $entry = $this->cleanupRegistry[$index];
            $this->CI->db->delete($entry['table'], ['id' => $entry['id']]);
        }

        $this->cleanupRegistry = [];
    }

    public static function createNoopSynchronization(): object
    {
        return new class {
            public function sync_appointment_saved(...$args): void
            {
            }

            public function sync_appointment_deleted(...$args): void
            {
            }
        };
    }

    public static function createNoopNotifications(): object
    {
        return new class {
            public function notify_appointment_saved(...$args): void
            {
            }

            public function notify_appointment_deleted(...$args): void
            {
            }
        };
    }

    public static function createNoopWebhooksClient(): object
    {
        return new class {
            public function trigger(...$args): void
            {
            }
        };
    }

    private function registerCleanup(string $table, int $id): void
    {
        if ($id <= 0) {
            return;
        }

        $this->cleanupRegistry[] = [
            'table' => $table,
            'id' => $id,
        ];
    }
}
