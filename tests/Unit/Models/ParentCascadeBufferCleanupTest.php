<?php

namespace Tests\Unit\Models;

use Customers_model;
use Services_model;
use Tests\TestCase;

class ParentCascadeBufferCleanupTest extends TestCase
{
    private Customers_model $customersModel;
    private Services_model $servicesModel;

    protected function setUp(): void
    {
        parent::setUp();

        $CI = &get_instance();
        $CI->load->model('customers_model');
        $CI->load->model('services_model');
        $this->customersModel = $CI->customers_model;
        $this->servicesModel = $CI->services_model;
    }

    public function test_deleting_customer_removes_linked_buffer_blocks(): void
    {
        $provider_id = $this->findProviderId();

        if ($provider_id === null) {
            $this->markTestSkipped('No provider record available for customer delete cascade test.');
        }

        $customer_id = $this->createCustomer();
        $service_id = $this->createService();
        $appointment_ids = [];

        try {
            $appointment_id = $this->createParentAppointment($provider_id, $customer_id, $service_id);
            $buffer_block_id = $this->createBufferBlock($provider_id, $appointment_id);
            $appointment_ids = [$appointment_id, $buffer_block_id];

            $this->assertTrue($this->recordExists('appointments', $appointment_id));
            $this->assertTrue($this->recordExists('appointments', $buffer_block_id));

            $this->customersModel->delete($customer_id);

            $this->assertFalse($this->recordExists('users', $customer_id));
            $this->assertFalse($this->recordExists('appointments', $appointment_id));
            $this->assertFalse($this->recordExists('appointments', $buffer_block_id));
            $appointment_ids = [];
        } finally {
            $this->forceDeleteRecords('appointments', $appointment_ids);
            $this->forceDeleteRecords('services', [$service_id]);
            $this->forceDeleteRecords('users', [$customer_id]);
        }
    }

    public function test_deleting_service_removes_linked_buffer_blocks(): void
    {
        $provider_id = $this->findProviderId();

        if ($provider_id === null) {
            $this->markTestSkipped('No provider record available for service delete cascade test.');
        }

        $customer_id = $this->createCustomer();
        $service_id = $this->createService();
        $appointment_ids = [];

        try {
            $appointment_id = $this->createParentAppointment($provider_id, $customer_id, $service_id);
            $buffer_block_id = $this->createBufferBlock($provider_id, $appointment_id);
            $appointment_ids = [$appointment_id, $buffer_block_id];

            $this->assertTrue($this->recordExists('appointments', $appointment_id));
            $this->assertTrue($this->recordExists('appointments', $buffer_block_id));

            $this->servicesModel->delete($service_id);

            $this->assertFalse($this->recordExists('services', $service_id));
            $this->assertFalse($this->recordExists('appointments', $appointment_id));
            $this->assertFalse($this->recordExists('appointments', $buffer_block_id));
            $appointment_ids = [];
        } finally {
            $this->forceDeleteRecords('appointments', $appointment_ids);
            $this->forceDeleteRecords('services', [$service_id]);
            $this->forceDeleteRecords('users', [$customer_id]);
        }
    }

    private function findProviderId(): ?int
    {
        $CI = &get_instance();

        $provider = $CI->db
            ->select('users.id')
            ->from('users')
            ->join('roles', 'roles.id = users.id_roles', 'inner')
            ->where('roles.slug', DB_SLUG_PROVIDER)
            ->limit(1)
            ->get()
            ->row_array();

        return $provider ? (int) $provider['id'] : null;
    }

    private function findCustomerRoleId(): int
    {
        $CI = &get_instance();

        $role = $CI->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])->row_array();

        if (empty($role['id'])) {
            throw new \RuntimeException('Customer role missing in test database.');
        }

        return (int) $role['id'];
    }

    private function createCustomer(): int
    {
        $CI = &get_instance();
        $now = date('Y-m-d H:i:s');
        $uniq = bin2hex(random_bytes(6));

        $CI->db->insert('users', [
            'first_name' => 'Parent',
            'last_name' => 'Cascade-' . $uniq,
            'email' => 'parent-cascade-' . $uniq . '@example.test',
            'timezone' => setting('default_timezone') ?: 'UTC',
            'language' => setting('default_language') ?: 'english',
            'id_roles' => $this->findCustomerRoleId(),
            'create_datetime' => $now,
            'update_datetime' => $now,
        ]);

        return (int) $CI->db->insert_id();
    }

    private function createService(): int
    {
        $CI = &get_instance();
        $now = date('Y-m-d H:i:s');
        $uniq = bin2hex(random_bytes(6));

        $CI->db->insert('services', [
            'name' => 'Cascade Cleanup ' . $uniq,
            'duration' => EVENT_MINIMUM_DURATION,
            'attendants_number' => 1,
            'buffer_before' => 0,
            'buffer_after' => 0,
            'create_datetime' => $now,
            'update_datetime' => $now,
        ]);

        return (int) $CI->db->insert_id();
    }

    private function createParentAppointment(int $provider_id, int $customer_id, int $service_id): int
    {
        $CI = &get_instance();
        $now = date('Y-m-d H:i:s');
        $start = new \DateTimeImmutable('2099-01-10 08:30:00');
        $end = $start->add(new \DateInterval('PT' . EVENT_MINIMUM_DURATION . 'M'));

        $CI->db->insert('appointments', [
            'book_datetime' => $now,
            'start_datetime' => $start->format('Y-m-d H:i:s'),
            'end_datetime' => $end->format('Y-m-d H:i:s'),
            'notes' => 'Cascade cleanup parent appointment',
            'hash' => 'parent-' . bin2hex(random_bytes(6)),
            'is_unavailability' => false,
            'id_users_provider' => $provider_id,
            'id_users_customer' => $customer_id,
            'id_services' => $service_id,
            'create_datetime' => $now,
            'update_datetime' => $now,
        ]);

        return (int) $CI->db->insert_id();
    }

    private function createBufferBlock(int $provider_id, int $parent_appointment_id): int
    {
        $CI = &get_instance();
        $now = date('Y-m-d H:i:s');
        $start = new \DateTimeImmutable('2099-01-10 08:25:00');
        $end = $start->add(new \DateInterval('PT' . EVENT_MINIMUM_DURATION . 'M'));

        $CI->db->insert('appointments', [
            'book_datetime' => $now,
            'start_datetime' => $start->format('Y-m-d H:i:s'),
            'end_datetime' => $end->format('Y-m-d H:i:s'),
            'notes' => 'Service buffer',
            'hash' => 'buffer-' . bin2hex(random_bytes(6)),
            'is_unavailability' => true,
            'id_users_provider' => $provider_id,
            'id_parent_appointment' => $parent_appointment_id,
            'create_datetime' => $now,
            'update_datetime' => $now,
        ]);

        return (int) $CI->db->insert_id();
    }

    private function recordExists(string $table, int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $CI = &get_instance();

        return $CI->db
            ->from($table)
            ->where('id', $id)
            ->count_all_results() > 0;
    }

    /**
     * @param array<int> $ids
     */
    private function forceDeleteRecords(string $table, array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        $CI = &get_instance();

        foreach ($ids as $id) {
            if ($id > 0) {
                $CI->db->delete($table, ['id' => $id]);
            }
        }
    }
}
