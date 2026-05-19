<?php

namespace Tests\Unit\Views;

use Tests\TestCase;

class AppointmentModalConflictFeedbackTest extends TestCase
{
    public function testCalendarHttpClientPassesFailureResponseToErrorCallback(): void
    {
        $source = file_get_contents(FCPATH . 'assets/js/http/calendar_http_client.js');

        $this->assertIsString($source);
        $this->assertStringContainsString('.fail((jqXHR, textStatus, errorThrown) => {', $source);
        $this->assertStringContainsString('errorCallback(jqXHR, textStatus, errorThrown);', $source);
    }

    public function testAppointmentModalShowsServerErrorMessageForExpectedConflictsOnly(): void
    {
        $source = file_get_contents(FCPATH . 'assets/js/components/appointments_modal.js');

        $this->assertIsString($source);
        $this->assertStringContainsString('jqXHR?.status === 409 && jqXHR?.responseJSON?.message', $source);
        $this->assertStringContainsString('jqXHR.responseJSON.message', $source);
        $this->assertStringContainsString('.text(responseMessage)', $source);
        $this->assertStringContainsString("lang('service_communication_error')", $source);
    }
}
