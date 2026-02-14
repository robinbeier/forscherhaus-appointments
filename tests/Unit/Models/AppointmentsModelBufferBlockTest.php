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
