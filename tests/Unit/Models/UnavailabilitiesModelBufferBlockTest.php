<?php

namespace Tests\Unit\Models;

use RuntimeException;
use Tests\TestCase;
use Unavailabilities_model;

class UnavailabilitiesModelBufferBlockTest extends TestCase
{
    private Unavailabilities_model $unavailabilitiesModel;

    protected function setUp(): void
    {
        parent::setUp();

        $CI = &get_instance();
        $CI->load->model('unavailabilities_model');
        $this->unavailabilitiesModel = $CI->unavailabilities_model;
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
}
