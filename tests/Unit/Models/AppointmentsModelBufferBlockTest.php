<?php

namespace Tests\Unit\Models;

use Appointments_model;
use Providers_model;
use Services_model;
use Tests\TestCase;

class AppointmentsModelBufferBlockTest extends TestCase
{
    private Appointments_model $appointmentsModel;
    private Services_model $servicesModel;
    private Providers_model $providersModel;

    protected function setUp(): void
    {
        parent::setUp();

        $CI = &get_instance();
        $CI->load->model('appointments_model');
        $CI->load->model('services_model');
        $CI->load->model('providers_model');
        $this->appointmentsModel = $CI->appointments_model;
        $this->servicesModel = $CI->services_model;
        $this->providersModel = $CI->providers_model;
    }

    public function test_api_decode_ignores_client_parent_appointment_id(): void
    {
        $payload = [
            'start' => '2030-01-10 10:00:00',
            'end' => '2030-01-10 10:15:00',
            'providerId' => 1,
            'customerId' => 1,
            'serviceId' => 1,
            'parentAppointmentId' => 999999,
        ];

        $this->appointmentsModel->api_decode($payload);

        $this->assertArrayNotHasKey('id_parent_appointment', $payload);
        $this->assertSame(1, $payload['id_users_provider']);
        $this->assertSame(1, $payload['id_users_customer']);
        $this->assertSame(1, $payload['id_services']);
        $this->assertFalse($payload['is_unavailability']);
    }

    public function test_sync_service_buffer_unavailabilities_regenerates_future_blocks(): void
    {
        $provider_id = $this->findProviderId();
        $customer_id = $this->findCustomerId();

        if ($provider_id === null || $customer_id === null) {
            $this->markTestSkipped('Provider or customer record missing for buffer sync test.');
        }

        $service_id = $this->createService([
            'buffer_before' => 0,
            'buffer_after' => 0,
        ]);

        try {
            $slot = $this->findFutureSlotForProvider($provider_id, EVENT_MINIMUM_DURATION);

            if ($slot === null) {
                $this->markTestSkipped('No suitable future provider slot found for buffer sync test.');
            }

            $appointment_id = $this->appointmentsModel->save([
                'start_datetime' => $slot['start']->format('Y-m-d H:i:s'),
                'end_datetime' => $slot['end']->format('Y-m-d H:i:s'),
                'id_users_provider' => $provider_id,
                'id_users_customer' => $customer_id,
                'id_services' => $service_id,
                'location' => '',
                'notes' => 'Buffer sync test',
                'color' => '#7cbae8',
                'status' => '',
                'is_unavailability' => false,
            ]);

            try {
                $this->assertCount(0, $this->getBufferBlocks($appointment_id));

                $service = $this->servicesModel->find($service_id);
                $service['buffer_after'] = EVENT_MINIMUM_DURATION;
                $this->servicesModel->save($service);

                $this->appointmentsModel->sync_service_buffer_unavailabilities($service_id);

                $buffer_blocks = $this->getBufferBlocks($appointment_id);

                $this->assertCount(1, $buffer_blocks);
                $this->assertSame($slot['end']->format('Y-m-d H:i:s'), $buffer_blocks[0]['start_datetime']);
                $this->assertSame(
                    $slot['end']->add(new \DateInterval('PT' . EVENT_MINIMUM_DURATION . 'M'))->format('Y-m-d H:i:s'),
                    $buffer_blocks[0]['end_datetime'],
                );
            } finally {
                $this->appointmentsModel->delete($appointment_id);
            }
        } finally {
            $this->servicesModel->delete($service_id);
        }
    }

    public function test_sync_service_buffer_unavailabilities_ignores_stale_sibling_blocks(): void
    {
        $provider_id = $this->findProviderId();
        $customer_id = $this->findCustomerId();

        if ($provider_id === null || $customer_id === null) {
            $this->markTestSkipped('Provider or customer record missing for buffer sync test.');
        }

        $slot_pair = $this->findFutureSlotPairForBufferSwap($provider_id);

        if ($slot_pair === null) {
            $this->markTestSkipped('No suitable pair of future slots found for sibling buffer sync test.');
        }

        $service_id = $this->createService([
            'buffer_before' => EVENT_MINIMUM_DURATION,
            'buffer_after' => 0,
        ]);

        $appointment_ids = [];

        try {
            foreach ([$slot_pair['first'], $slot_pair['second']] as $slot) {
                $appointment_ids[] = $this->appointmentsModel->save([
                    'start_datetime' => $slot['start']->format('Y-m-d H:i:s'),
                    'end_datetime' => $slot['end']->format('Y-m-d H:i:s'),
                    'id_users_provider' => $provider_id,
                    'id_users_customer' => $customer_id,
                    'id_services' => $service_id,
                    'location' => '',
                    'notes' => 'Sibling buffer sync test',
                    'color' => '#7cbae8',
                    'status' => '',
                    'is_unavailability' => false,
                ]);
            }

            $service = $this->servicesModel->find($service_id);
            $service['buffer_before'] = 0;
            $service['buffer_after'] = EVENT_MINIMUM_DURATION;
            $this->servicesModel->save($service);

            $this->appointmentsModel->sync_service_buffer_unavailabilities($service_id);

            foreach ([0, 1] as $index) {
                $appointment_id = $appointment_ids[$index];
                $slot = $index === 0 ? $slot_pair['first'] : $slot_pair['second'];
                $buffer_blocks = $this->getBufferBlocks($appointment_id);

                $this->assertCount(1, $buffer_blocks);
                $this->assertSame($slot['end']->format('Y-m-d H:i:s'), $buffer_blocks[0]['start_datetime']);
                $this->assertSame(
                    $slot['end']->add(new \DateInterval('PT' . EVENT_MINIMUM_DURATION . 'M'))->format('Y-m-d H:i:s'),
                    $buffer_blocks[0]['end_datetime'],
                );
            }
        } finally {
            foreach (array_reverse($appointment_ids) as $appointment_id) {
                $this->appointmentsModel->delete($appointment_id);
            }

            $this->servicesModel->delete($service_id);
        }
    }

    public function test_sync_service_buffer_unavailabilities_updates_past_appointments_with_future_buffers(): void
    {
        $provider_id = $this->findProviderId();
        $customer_id = $this->findCustomerId();

        if ($provider_id === null || $customer_id === null) {
            $this->markTestSkipped('Provider or customer record missing for buffer sync test.');
        }

        $service_id = $this->createService([
            'buffer_before' => 0,
            'buffer_after' => EVENT_MINIMUM_DURATION,
        ]);

        try {
            $slot = $this->findFutureSlotForProvider($provider_id, EVENT_MINIMUM_DURATION);

            if ($slot === null) {
                $this->markTestSkipped('No suitable future provider slot found for trailing buffer sync test.');
            }

            $appointment_id = $this->appointmentsModel->save([
                'start_datetime' => $slot['start']->format('Y-m-d H:i:s'),
                'end_datetime' => $slot['end']->format('Y-m-d H:i:s'),
                'id_users_provider' => $provider_id,
                'id_users_customer' => $customer_id,
                'id_services' => $service_id,
                'location' => '',
                'notes' => 'Trailing buffer sync test',
                'color' => '#7cbae8',
                'status' => '',
                'is_unavailability' => false,
            ]);

            try {
                $buffer_blocks = $this->getBufferBlocks($appointment_id);
                $this->assertCount(1, $buffer_blocks);

                $now = new \DateTimeImmutable();
                $past_start = $now->sub(new \DateInterval('PT' . (EVENT_MINIMUM_DURATION + 1) . 'M'));
                $past_end = $now->sub(new \DateInterval('PT1M'));
                $future_buffer_end = $now->add(new \DateInterval('PT' . EVENT_MINIMUM_DURATION . 'M'));

                $CI = &get_instance();
                $CI->db->update(
                    'appointments',
                    [
                        'start_datetime' => $past_start->format('Y-m-d H:i:s'),
                        'end_datetime' => $past_end->format('Y-m-d H:i:s'),
                    ],
                    ['id' => $appointment_id],
                );

                $CI->db->update(
                    'appointments',
                    [
                        'start_datetime' => $past_end->format('Y-m-d H:i:s'),
                        'end_datetime' => $future_buffer_end->format('Y-m-d H:i:s'),
                    ],
                    ['id' => (int) $buffer_blocks[0]['id']],
                );

                $service = $this->servicesModel->find($service_id);
                $service['buffer_after'] = 0;
                $this->servicesModel->save($service);

                $this->appointmentsModel->sync_service_buffer_unavailabilities($service_id);

                $this->assertCount(0, $this->getBufferBlocks($appointment_id));
            } finally {
                $this->appointmentsModel->delete($appointment_id);
            }
        } finally {
            $this->servicesModel->delete($service_id);
        }
    }

    public function test_sync_service_buffer_unavailabilities_includes_recently_ended_appointments(): void
    {
        $provider_id = $this->findProviderId();
        $customer_id = $this->findCustomerId();

        if ($provider_id === null || $customer_id === null) {
            $this->markTestSkipped('Provider or customer record missing for buffer sync test.');
        }

        $service_id = $this->createService([
            'buffer_before' => 0,
            'buffer_after' => 0,
        ]);

        try {
            $slot = $this->findFutureSlotForProvider($provider_id, 0);

            if ($slot === null) {
                $this->markTestSkipped('No suitable future provider slot found for trailing buffer sync test.');
            }

            $appointment_id = $this->appointmentsModel->save([
                'start_datetime' => $slot['start']->format('Y-m-d H:i:s'),
                'end_datetime' => $slot['end']->format('Y-m-d H:i:s'),
                'id_users_provider' => $provider_id,
                'id_users_customer' => $customer_id,
                'id_services' => $service_id,
                'location' => '',
                'notes' => 'Recent appointment buffer sync test',
                'color' => '#7cbae8',
                'status' => '',
                'is_unavailability' => false,
            ]);

            try {
                $this->assertCount(0, $this->getBufferBlocks($appointment_id));

                $now = new \DateTimeImmutable();
                $past_start = $now->sub(new \DateInterval('PT' . (EVENT_MINIMUM_DURATION + 1) . 'M'));
                $past_end = $now->sub(new \DateInterval('PT1M'));
                $future_buffer_end = $past_end->add(new \DateInterval('PT' . EVENT_MINIMUM_DURATION . 'M'));

                if ($future_buffer_end->format('Y-m-d') !== $past_end->format('Y-m-d')) {
                    $this->markTestSkipped('Current time is too close to midnight for trailing buffer sync test.');
                }

                if ($this->hasProviderOverlap($provider_id, $past_start, $future_buffer_end)) {
                    $this->markTestSkipped(
                        'Provider has overlapping records near the current time for buffer sync test.',
                    );
                }

                $original_working_plan = $this->providersModel->get_setting($provider_id, 'working_plan');
                $this->providersModel->set_setting($provider_id, 'working_plan', '{}');

                try {
                    $CI = &get_instance();
                    $CI->db->update(
                        'appointments',
                        [
                            'start_datetime' => $past_start->format('Y-m-d H:i:s'),
                            'end_datetime' => $past_end->format('Y-m-d H:i:s'),
                        ],
                        ['id' => $appointment_id],
                    );

                    $service = $this->servicesModel->find($service_id);
                    $service['buffer_after'] = EVENT_MINIMUM_DURATION;
                    $this->servicesModel->save($service);

                    $this->appointmentsModel->sync_service_buffer_unavailabilities($service_id);

                    $buffer_blocks = $this->getBufferBlocks($appointment_id);

                    $this->assertCount(1, $buffer_blocks);
                    $this->assertSame($past_end->format('Y-m-d H:i:s'), $buffer_blocks[0]['start_datetime']);
                    $this->assertSame($future_buffer_end->format('Y-m-d H:i:s'), $buffer_blocks[0]['end_datetime']);
                } finally {
                    $this->providersModel->set_setting($provider_id, 'working_plan', $original_working_plan);
                }
            } finally {
                $this->appointmentsModel->delete($appointment_id);
            }
        } finally {
            $this->servicesModel->delete($service_id);
        }
    }

    public function test_manage_mode_excludes_linked_buffer_blocks_from_available_periods(): void
    {
        $provider_id = $this->findProviderId();
        $customer_id = $this->findCustomerId();

        if ($provider_id === null || $customer_id === null) {
            $this->markTestSkipped('Provider or customer record missing for availability test.');
        }

        $service_id = $this->createService([
            'buffer_before' => 0,
            'buffer_after' => EVENT_MINIMUM_DURATION,
        ]);

        try {
            $slot = $this->findFutureSlotForProvider($provider_id, EVENT_MINIMUM_DURATION);

            if ($slot === null) {
                $this->markTestSkipped('No suitable future provider slot found for availability test.');
            }

            $appointment_id = $this->appointmentsModel->save([
                'start_datetime' => $slot['start']->format('Y-m-d H:i:s'),
                'end_datetime' => $slot['end']->format('Y-m-d H:i:s'),
                'id_users_provider' => $provider_id,
                'id_users_customer' => $customer_id,
                'id_services' => $service_id,
                'location' => '',
                'notes' => 'Manage-mode availability test',
                'color' => '#7cbae8',
                'status' => '',
                'is_unavailability' => false,
            ]);

            try {
                $this->assertCount(1, $this->getBufferBlocks($appointment_id));

                $CI = &get_instance();
                $CI->load->library('availability');
                $provider = $this->providersModel->find($provider_id);

                $periods = $CI->availability->get_available_periods(
                    $slot['start']->format('Y-m-d'),
                    $provider,
                    $appointment_id,
                );

                $slot_start = $slot['start']->format('H:i');
                $slot_end = $slot['end']->format('H:i');
                $contains_original_slot = false;

                foreach ($periods as $period) {
                    if ($period['start'] <= $slot_start && $period['end'] >= $slot_end) {
                        $contains_original_slot = true;
                        break;
                    }
                }

                $this->assertTrue($contains_original_slot);
            } finally {
                $this->appointmentsModel->delete($appointment_id);
            }
        } finally {
            $this->servicesModel->delete($service_id);
        }
    }

    private function createService(array $overrides = []): int
    {
        $service = array_merge(
            [
                'name' => 'Buffer Sync ' . uniqid('', true),
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

    private function findCustomerId(): ?int
    {
        $CI = &get_instance();

        $customer = $CI->db
            ->select('users.id')
            ->from('users')
            ->join('roles', 'roles.id = users.id_roles', 'inner')
            ->where('roles.slug', DB_SLUG_CUSTOMER)
            ->limit(1)
            ->get()
            ->row_array();

        return $customer ? (int) $customer['id'] : null;
    }

    private function findFutureSlotForProvider(int $provider_id, int $buffer_after_minutes): ?array
    {
        $provider = $this->providersModel->find($provider_id);
        $working_plan = json_decode($provider['settings']['working_plan'] ?? '{}', true) ?: [];
        $provider_timezone = new \DateTimeZone($provider['timezone'] ?? date_default_timezone_get());
        $search_start = new \DateTimeImmutable('2099-01-01 00:00:00', $provider_timezone);

        for ($day_offset = 0; $day_offset < 370; $day_offset++) {
            $date = $search_start->add(new \DateInterval('P' . $day_offset . 'D'));
            $day_name = strtolower($date->format('l'));
            $plan = $working_plan[$day_name] ?? null;

            if (empty($plan['start']) || empty($plan['end'])) {
                continue;
            }

            $start = new \DateTimeImmutable($date->format('Y-m-d') . ' ' . $plan['start'], $provider_timezone);
            $end = $start->add(new \DateInterval('PT' . EVENT_MINIMUM_DURATION . 'M'));
            $buffer_end = $end->add(new \DateInterval('PT' . $buffer_after_minutes . 'M'));
            $day_end = new \DateTimeImmutable($date->format('Y-m-d') . ' ' . $plan['end'], $provider_timezone);

            if ($buffer_end > $day_end) {
                continue;
            }

            if ($this->hasProviderOverlap($provider_id, $start, $buffer_end)) {
                continue;
            }

            return ['start' => $start, 'end' => $end];
        }

        return null;
    }

    private function findFutureSlotPairForBufferSwap(int $provider_id): ?array
    {
        $provider = $this->providersModel->find($provider_id);
        $working_plan = json_decode($provider['settings']['working_plan'] ?? '{}', true) ?: [];
        $provider_timezone = new \DateTimeZone($provider['timezone'] ?? date_default_timezone_get());
        $search_start = new \DateTimeImmutable('2099-01-01 00:00:00', $provider_timezone);
        $duration_interval = new \DateInterval('PT' . EVENT_MINIMUM_DURATION . 'M');

        for ($day_offset = 0; $day_offset < 370; $day_offset++) {
            $date = $search_start->add(new \DateInterval('P' . $day_offset . 'D'));
            $day_name = strtolower($date->format('l'));
            $plan = $working_plan[$day_name] ?? null;

            if (empty($plan['start']) || empty($plan['end'])) {
                continue;
            }

            $day_start = new \DateTimeImmutable($date->format('Y-m-d') . ' ' . $plan['start'], $provider_timezone);
            $day_end = new \DateTimeImmutable($date->format('Y-m-d') . ' ' . $plan['end'], $provider_timezone);

            $first_start = $day_start->add($duration_interval);
            $first_end = $first_start->add($duration_interval);
            $second_start = $first_end->add($duration_interval);
            $second_end = $second_start->add($duration_interval);
            $second_after_end = $second_end->add($duration_interval);

            if ($second_after_end > $day_end) {
                continue;
            }

            if ($this->hasProviderOverlap($provider_id, $day_start, $second_after_end)) {
                continue;
            }

            return [
                'first' => ['start' => $first_start, 'end' => $first_end],
                'second' => ['start' => $second_start, 'end' => $second_end],
            ];
        }

        return null;
    }

    private function hasProviderOverlap(int $provider_id, \DateTimeImmutable $start, \DateTimeImmutable $end): bool
    {
        $CI = &get_instance();

        $count = $CI->db
            ->from('appointments')
            ->where('id_users_provider', $provider_id)
            ->where('start_datetime <', $end->format('Y-m-d H:i:s'))
            ->where('end_datetime >', $start->format('Y-m-d H:i:s'))
            ->count_all_results();

        return $count > 0;
    }

    private function getBufferBlocks(int $appointment_id): array
    {
        $CI = &get_instance();

        return $CI->db
            ->from('appointments')
            ->where('id_parent_appointment', $appointment_id)
            ->where('is_unavailability', true)
            ->order_by('start_datetime', 'ASC')
            ->get()
            ->result_array();
    }
}
