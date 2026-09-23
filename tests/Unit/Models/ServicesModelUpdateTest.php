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
            $expected_buffer_values = $existing_service;
            $existing_service['buffer_before'] = 25;
            $existing_service['buffer_after'] = 35;

            $this->saveServiceAtomically($existing_service, $expected_buffer_values);

            $saved_service = $this->servicesModel->find($service_id);

            $this->assertSame(25, (int) $saved_service['buffer_before']);
            $this->assertSame(35, (int) $saved_service['buffer_after']);
        } finally {
            $this->servicesModel->delete($service_id);
        }
    }

    public function test_stale_expected_buffers_abort_without_persisting_other_fields(): void
    {
        $service_id = $this->createService(['buffer_after' => 10]);
        try {
            $expected = $this->servicesModel->find($service_id);
            $current = $expected;
            $current['buffer_after'] = 20;
            $this->servicesModel->save($current, $expected);
            $persisted = $this->servicesModel->find($service_id);
            $stale = $expected;
            $stale['name'] = 'Must not persist';
            try {
                $this->servicesModel->save($stale, $expected);
                $this->fail('Expected buffer drift to abort before any write.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Service buffer values changed concurrently.', $exception->getMessage());
            }
            $this->assertFalse(get_instance()->db->trans_active());
            $this->assertSame($persisted, $this->servicesModel->find($service_id));
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

    public function test_update_with_null_buffer_fields_is_normalized_before_persisting(): void
    {
        $service_id = $this->createService([
            'buffer_before' => 20,
            'buffer_after' => 15,
        ]);

        $CI = &get_instance();
        $sql_mode_row = $CI->db->query('SELECT @@SESSION.sql_mode AS sql_mode')->row_array();
        $previous_sql_mode = $sql_mode_row['sql_mode'] ?? '';
        $strict_sql_mode = $previous_sql_mode;

        if (!str_contains($strict_sql_mode, 'STRICT_TRANS_TABLES')) {
            $strict_sql_mode = trim($strict_sql_mode . ',STRICT_TRANS_TABLES', ',');
        }

        $CI->db->query('SET SESSION sql_mode = ' . $CI->db->escape($strict_sql_mode));

        try {
            $existing_service = $this->servicesModel->find($service_id);
            $expected_buffer_values = $existing_service;
            $existing_service['buffer_before'] = null;
            $existing_service['buffer_after'] = null;

            $this->saveServiceAtomically($existing_service, $expected_buffer_values);

            $saved_service = $this->servicesModel->find($service_id);

            $this->assertSame(0, (int) $saved_service['buffer_before']);
            $this->assertSame(0, (int) $saved_service['buffer_after']);
        } finally {
            $CI->db->query('SET SESSION sql_mode = ' . $CI->db->escape($previous_sql_mode));
            $this->servicesModel->delete($service_id);
        }
    }

    public function test_create_with_sub_minimum_buffer_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->createService([
            'buffer_before' => EVENT_MINIMUM_DURATION - 1,
        ]);
    }

    public function test_create_with_zero_duration_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The service duration cannot be less than ' . EVENT_MINIMUM_DURATION . ' minutes long.',
        );

        $this->createService(['duration' => 0]);
    }

    public function test_create_without_duration_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The service duration cannot be less than ' . EVENT_MINIMUM_DURATION . ' minutes long.',
        );

        $this->servicesModel->save([
            'name' => 'Missing duration ' . uniqid('', true),
            'attendants_number' => 1,
        ]);
    }

    public function test_create_with_null_duration_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The service duration cannot be less than ' . EVENT_MINIMUM_DURATION . ' minutes long.',
        );

        $this->createService(['duration' => null]);
    }

    public function test_create_with_fractional_duration_is_rejected(): void
    {
        $this->expectException(\ServiceValidationException::class);

        $this->createService(['duration' => 5.5]);
    }

    public function test_create_with_precision_hidden_fractional_duration_is_rejected(): void
    {
        $this->expectException(\ServiceValidationException::class);

        $this->createService(['duration' => '30.0000000000000001']);
    }

    public function test_create_with_duration_above_signed_int_range_is_rejected(): void
    {
        $this->expectException(\ServiceValidationException::class);

        $this->createService(['duration' => 2147483648]);
    }

    public function test_create_with_maximum_signed_int_duration_is_accepted(): void
    {
        $service_id = $this->createService(['duration' => 2147483647]);

        try {
            $this->assertSame(2147483647, (int) $this->servicesModel->find($service_id)['duration']);
        } finally {
            $this->servicesModel->delete($service_id);
        }
    }

    public function test_create_with_positive_integer_duration_string_is_accepted(): void
    {
        $service_id = $this->createService(['duration' => '30']);

        try {
            $this->assertSame(30, (int) $this->servicesModel->find($service_id)['duration']);
        } finally {
            $this->servicesModel->delete($service_id);
        }
    }

    public function test_create_with_exact_scientific_integer_strings_is_accepted(): void
    {
        $service_id = $this->createService(['duration' => '3e1', 'attendants_number' => '1e0']);

        try {
            $stored = $this->servicesModel->find($service_id);
            $this->assertSame(30, (int) $stored['duration']);
            $this->assertSame(1, (int) $stored['attendants_number']);
        } finally {
            $this->servicesModel->delete($service_id);
        }
    }

    public function test_create_with_exact_negative_exponent_integer_string_is_accepted(): void
    {
        $service_id = $this->createService(['duration' => '300e-1']);

        try {
            $this->assertSame(30, (int) $this->servicesModel->find($service_id)['duration']);
        } finally {
            $this->servicesModel->delete($service_id);
        }
    }

    public function test_buffer_change_without_expected_values_is_rejected_atomically(): void
    {
        $service_id = $this->createService([
            'buffer_before' => 20,
            'buffer_after' => 15,
        ]);

        try {
            $existing_service = $this->servicesModel->find($service_id);
            $existing_service['buffer_after'] = 30;

            try {
                $this->servicesModel->save($existing_service);
                $this->fail('Expected a standalone buffer change to be rejected.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'Service buffer changes require expected values for atomic synchronization.',
                    $exception->getMessage(),
                );
            }

            $saved_service = $this->servicesModel->find($service_id);
            $this->assertSame(15, (int) $saved_service['buffer_after']);
        } finally {
            $this->servicesModel->delete($service_id);
        }
    }

    public function test_create_with_non_numeric_buffer_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->createService([
            'buffer_before' => 'abc',
        ]);
    }

    public function test_create_with_attendants_number_above_one_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only attendants_number=1 is currently supported.');

        $this->createService([
            'attendants_number' => 2,
        ]);
    }

    public function test_create_with_fractional_attendants_number_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only attendants_number=1 is currently supported.');

        $this->createService(['attendants_number' => 1.9]);
    }

    public function test_create_with_precision_hidden_fractional_attendants_number_is_rejected(): void
    {
        $this->expectException(\ServiceValidationException::class);

        $this->createService(['attendants_number' => '1.0000000000000001']);
    }

    public function test_create_with_string_one_attendants_number_is_accepted(): void
    {
        $service_id = $this->createService(['attendants_number' => '1']);

        try {
            $this->assertSame(1, (int) $this->servicesModel->find($service_id)['attendants_number']);
        } finally {
            $this->servicesModel->delete($service_id);
        }
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

    /**
     * @param array<string, mixed> $service
     * @param array<string, mixed> $expectedBufferValues
     */
    private function saveServiceAtomically(array $service, array $expectedBufferValues): void
    {
        $this->servicesModel->save($service, $expectedBufferValues);
    }
}
