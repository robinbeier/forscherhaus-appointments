<?php

namespace Tests\Unit\Models;

use InvalidArgumentException;
use Services_model;
use Tests\TestCase;

class ServicesModelUpdateTest extends TestCase
{
    private Services_model $servicesModel;

    protected function setUp(): void
    {
        parent::setUp();

        $CI = &get_instance();
        $CI->load->model('services_model');
        $this->servicesModel = $CI->services_model;
    }

    public function test_update_without_buffer_fields_keeps_existing_buffer_values(): void
    {
        $service_id = $this->createService([
            'buffer_before' => 20,
            'buffer_after' => 15,
        ]);

        try {
            $existing_service = $this->servicesModel->find($service_id);

            $sparse_update = [
                'id' => $service_id,
                'name' => $existing_service['name'] . ' Updated',
                'duration' => $existing_service['duration'],
                'price' => $existing_service['price'],
                'currency' => $existing_service['currency'],
                'description' => $existing_service['description'],
                'location' => $existing_service['location'],
                'color' => $existing_service['color'],
                'availabilities_type' => $existing_service['availabilities_type'],
                'attendants_number' => $existing_service['attendants_number'],
                'is_private' => $existing_service['is_private'],
                'id_service_categories' => $existing_service['id_service_categories'],
            ];

            $merged_update = array_merge($existing_service, $sparse_update);
            $this->servicesModel->save($merged_update);

            $saved_service = $this->servicesModel->find($service_id);

            $this->assertSame(20, (int) $saved_service['buffer_before']);
            $this->assertSame(15, (int) $saved_service['buffer_after']);
        } finally {
            $this->servicesModel->delete($service_id);
        }
    }

    public function test_update_with_buffer_fields_persists_new_values(): void
    {
        $service_id = $this->createService([
            'buffer_before' => 5,
            'buffer_after' => 10,
        ]);

        try {
            $existing_service = $this->servicesModel->find($service_id);
            $existing_service['buffer_before'] = 25;
            $existing_service['buffer_after'] = 35;

            $this->servicesModel->save($existing_service);

            $saved_service = $this->servicesModel->find($service_id);

            $this->assertSame(25, (int) $saved_service['buffer_before']);
            $this->assertSame(35, (int) $saved_service['buffer_after']);
        } finally {
            $this->servicesModel->delete($service_id);
        }
    }

    public function test_create_without_buffer_fields_defaults_to_zero(): void
    {
        $service_id = $this->createService();

        try {
            $saved_service = $this->servicesModel->find($service_id);

            $this->assertSame(0, (int) $saved_service['buffer_before']);
            $this->assertSame(0, (int) $saved_service['buffer_after']);
        } finally {
            $this->servicesModel->delete($service_id);
        }
    }

    public function test_create_with_attendants_number_above_one_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only attendants_number=1 is currently supported.');

        $this->createService([
            'attendants_number' => 2,
        ]);
    }

    private function createService(array $overrides = []): int
    {
        $service = array_merge(
            [
                'name' => 'Buffer Regression ' . uniqid('', true),
                'duration' => EVENT_MINIMUM_DURATION,
                'price' => '0',
                'currency' => '',
                'description' => '',
                'location' => '',
                'color' => '#7cbae8',
                'availabilities_type' => AVAILABILITIES_TYPE_FLEXIBLE,
                'attendants_number' => 1,
                'is_private' => false,
                'id_service_categories' => null,
            ],
            $overrides,
        );

        return $this->servicesModel->save($service);
    }
}
