<?php

namespace Tests\Unit\Models;

use Appointments_model;
use Tests\TestCase;

class AppointmentsModelQueryAliasTest extends TestCase
{
    private Appointments_model $appointmentsModel;

    protected function setUp(): void
    {
        parent::setUp();

        $CI = &get_instance();
        $CI->load->model('appointments_model');
        $this->appointmentsModel = $CI->appointments_model;
    }

    public function testQueryUsesAppointmentsAliasForQualifiedColumns(): void
    {
        $sql = $this->appointmentsModel
            ->query()
            ->select('appointments.start_datetime')
            ->get_compiled_select('', true);

        $this->assertStringContainsString('`appointments`.`start_datetime`', $sql);
        $this->assertMatchesRegularExpression('/FROM\\s+`ea_appointments`\\s+(?:AS\\s+)?`appointments`/i', $sql);
    }
}
