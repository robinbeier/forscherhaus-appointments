<?php

namespace Tests\Unit\Models;

use RuntimeException;
use Tests\TestCase;
use Throwable;
use Unavailabilities_model;

class UnavailabilitiesModelBufferBlockTest extends TestCase
{
    private Unavailabilities_model $unavailabilitiesModel;
    private int $ordinaryAppointmentId = 0;
    /** @var list<int> */
    private array $ownedBufferBlockIds = [];
    private string $fixtureMarker = '';

    protected function setUp(): void
    {
        parent::setUp();

        $CI = &get_instance();
        $CI->load->model('unavailabilities_model');
        $this->unavailabilitiesModel = $CI->unavailabilities_model;
    }

    protected function tearDown(): void
    {
        $this->cleanupOrdinaryAppointmentWithBuffers();
        parent::tearDown();
    }

    public function test_api_decode_ignores_client_parent_appointment_id(): void
    {
        $payload = [
            'start' => '2030-01-10 10:00:00',
            'end' => '2030-01-10 10:15:00',
            'notes' => 'Manual block',
            'providerId' => 1,
            'parentAppointmentId' => 999999,
        ];

        $this->unavailabilitiesModel->api_decode($payload);

        $this->assertArrayNotHasKey('id_parent_appointment', $payload);
        $this->assertSame(1, $payload['id_users_provider']);
        $this->assertTrue($payload['is_unavailability']);
    }

    public function test_cannot_update_generated_buffer_block(): void
    {
        $provider_id = $this->findProviderId();

        if ($provider_id === null) {
            $this->markTestSkipped('No provider record available for unavailability tests.');
        }

        $buffer_block_id = $this->createBufferBlock($provider_id);

        try {
            $buffer_block = $this->unavailabilitiesModel->find($buffer_block_id);
            $buffer_block['notes'] = 'Attempted manual edit';

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Buffer-generated unavailability blocks cannot be modified directly.');

            $this->unavailabilitiesModel->save($buffer_block);
        } finally {
            $this->forceDeleteUnavailability($buffer_block_id);
        }
    }

    public function test_cannot_delete_generated_buffer_block(): void
    {
        $provider_id = $this->findProviderId();

        if ($provider_id === null) {
            $this->markTestSkipped('No provider record available for unavailability tests.');
        }

        $buffer_block_id = $this->createBufferBlock($provider_id);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Buffer-generated unavailability blocks cannot be modified directly.');

            $this->unavailabilitiesModel->delete($buffer_block_id);
        } finally {
            $this->forceDeleteUnavailability($buffer_block_id);
        }
    }

    public function test_find_rejects_owned_ordinary_appointment(): void
    {
        $this->createOrdinaryAppointmentWithBuffers();
        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->unavailabilitiesModel->find($this->ordinaryAppointmentId);
        } finally {
            $this->cleanupOrdinaryAppointmentWithBuffers();
        }
    }

    public function test_cannot_update_owned_ordinary_appointment_or_buffers(): void
    {
        $this->createOrdinaryAppointmentWithBuffers();
        try {
            $before = $this->ordinaryAndBufferSnapshot();
            $ordinary = $this->ordinaryRow();
            $ordinary['notes'] = $this->fixtureMarker . '_unexpected_update';
            $error = null;

            try {
                $this->unavailabilitiesModel->save($ordinary);
            } catch (Throwable $caught) {
                $error = $caught;
            }

            $this->assertNotNull(
                $error,
                'Updating an ordinary appointment through Unavailabilities_model must reject.',
            );
            $this->assertSame($before, $this->ordinaryAndBufferSnapshot());
        } finally {
            $this->cleanupOrdinaryAppointmentWithBuffers();
        }
    }

    public function test_cannot_delete_owned_ordinary_appointment_or_buffers(): void
    {
        $this->createOrdinaryAppointmentWithBuffers();
        try {
            $before = $this->ordinaryAndBufferSnapshot();
            $error = null;

            try {
                $this->unavailabilitiesModel->delete($this->ordinaryAppointmentId);
            } catch (Throwable $caught) {
                $error = $caught;
            }

            $this->assertNotNull(
                $error,
                'Deleting an ordinary appointment through Unavailabilities_model must reject.',
            );
            $this->assertSame($before, $this->ordinaryAndBufferSnapshot());
        } finally {
            $this->cleanupOrdinaryAppointmentWithBuffers();
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

    private function createBufferBlock(int $provider_id): int
    {
        $start_at = new \DateTimeImmutable('2030-01-10 10:00:00');
        $end_at = $start_at->add(new \DateInterval('PT' . EVENT_MINIMUM_DURATION . 'M'));

        return $this->unavailabilitiesModel->save([
            'start_datetime' => $start_at->format('Y-m-d H:i:s'),
            'end_datetime' => $end_at->format('Y-m-d H:i:s'),
            'id_users_provider' => $provider_id,
            'id_parent_appointment' => 999999,
            'notes' => 'Service buffer',
        ]);
    }

    private function forceDeleteUnavailability(int $unavailability_id): void
    {
        $CI = &get_instance();

        $CI->db->delete('appointments', ['id' => $unavailability_id]);
    }

    private function createOrdinaryAppointmentWithBuffers(): void
    {
        $CI = &get_instance();
        $providerId = $this->findProviderId();
        if ($providerId === null) {
            $this->markTestSkipped('No provider record available for ordinary appointment regression.');
        }
        $service = $CI->db->get('services')->row_array();
        if (!$service) {
            $this->markTestSkipped('No service record available for ordinary appointment regression.');
        }
        $this->fixtureMarker = 'ordinary-unavailability-regression-' . bin2hex(random_bytes(8));
        try {
            $ordinary = [
                'book_datetime' => '2031-01-10 09:00:00',
                'start_datetime' => '2031-01-10 10:00:00',
                'end_datetime' => '2031-01-10 10:30:00',
                'notes' => $this->fixtureMarker,
                'is_unavailability' => 0,
                'id_users_provider' => $providerId,
                'id_users_customer' => null,
                'id_services' => (int) $service['id'],
                'hash' => $this->fixtureMarker,
                'create_datetime' => '2031-01-10 09:00:00',
                'update_datetime' => '2031-01-10 09:00:00',
            ];
            if (!$CI->db->insert('appointments', $ordinary)) {
                throw new RuntimeException('Could not create ordinary appointment regression fixture.');
            }
            $this->ordinaryAppointmentId = (int) $CI->db->insert_id();
            $this->insertOwnedBuffer($providerId, '09:50:00', '10:00:00');
            $this->insertOwnedBuffer($providerId, '10:30:00', '10:40:00');
        } catch (Throwable $error) {
            $this->cleanupOrdinaryAppointmentWithBuffers();
            throw $error;
        }
    }

    private function insertOwnedBuffer(int $providerId, string $start, string $end): void
    {
        $CI = &get_instance();
        if (
            !$CI->db->insert('appointments', [
                'book_datetime' => '2031-01-10 09:00:00',
                'start_datetime' => '2031-01-10 ' . $start,
                'end_datetime' => '2031-01-10 ' . $end,
                'notes' => $this->fixtureMarker . '_buffer',
                'is_unavailability' => 1,
                'id_users_provider' => $providerId,
                'id_parent_appointment' => $this->ordinaryAppointmentId,
                'hash' => $this->fixtureMarker . '_' . $start,
                'create_datetime' => '2031-01-10 09:00:00',
                'update_datetime' => '2031-01-10 09:00:00',
            ])
        ) {
            throw new RuntimeException('Could not create buffer regression fixture.');
        }
        $this->ownedBufferBlockIds[] = (int) $CI->db->insert_id();
    }

    private function ordinaryRow(): array
    {
        return get_instance()
            ->db->get_where('appointments', ['id' => $this->ordinaryAppointmentId])
            ->row_array() ?? [];
    }

    private function ordinaryAndBufferSnapshot(): array
    {
        $rows = get_instance()
            ->db->where('id_parent_appointment', $this->ordinaryAppointmentId)
            ->order_by('id', 'ASC')
            ->get('appointments')
            ->result_array();
        return ['ordinary' => $this->ordinaryRow(), 'buffers' => $rows];
    }

    private function cleanupOrdinaryAppointmentWithBuffers(): void
    {
        if ($this->ordinaryAppointmentId === 0 && $this->ownedBufferBlockIds === []) {
            return;
        }
        $CI = &get_instance();
        foreach ($this->ownedBufferBlockIds as $id) {
            $row = $CI->db->get_where('appointments', ['id' => $id])->row_array();
            if ($row && ($row['notes'] ?? null) !== $this->fixtureMarker . '_buffer') {
                throw new RuntimeException('Owned buffer identity changed outside regression fixture.');
            }
            $CI->db->delete('appointments', ['id' => $id, 'id_parent_appointment' => $this->ordinaryAppointmentId]);
        }
        if ($this->ordinaryAppointmentId > 0) {
            $row = $CI->db->get_where('appointments', ['id' => $this->ordinaryAppointmentId])->row_array();
            if ($row && ($row['notes'] ?? null) !== $this->fixtureMarker) {
                throw new RuntimeException('Owned ordinary appointment identity changed outside regression fixture.');
            }
            $CI->db->delete('appointments', ['id' => $this->ordinaryAppointmentId, 'notes' => $this->fixtureMarker]);
        }
        $this->ordinaryAppointmentId = 0;
        $this->ownedBufferBlockIds = [];
    }
}
