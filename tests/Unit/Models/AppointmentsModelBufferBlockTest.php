<?php

namespace Tests\Unit\Models;

use Appointments_model;
use Tests\TestCase;

class AppointmentsModelBufferBlockTest extends TestCase
{
    private Appointments_model $appointmentsModel;

    protected function setUp(): void
    {
        parent::setUp();

        $CI = &get_instance();
        $CI->load->model('appointments_model');
        $this->appointmentsModel = $CI->appointments_model;
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
}
