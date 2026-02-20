<?php

namespace Tests\Unit\Libraries;

use Ics_file;
use Tests\TestCase;

require_once APPPATH . 'libraries/Ics_file.php';

class IcsFileTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        get_instance()->load->helper('language');
        get_instance()->load->helper('url');
    }

    public function testGetStreamUsesManageLinkWhenHashExists(): void
    {
        $library = new Ics_file();

        $stream = $library->get_stream(
            $this->makeAppointment(['hash' => 'abc123']),
            $this->makeService(),
            $this->makeProvider(),
            $this->makeCustomer(),
        );

        $description = $this->extractDescription($stream);

        $this->assertStringContainsString('booking/reschedule/abc123', $description);
        $this->assertStringNotContainsString(lang('provider'), $description);
        $this->assertStringNotContainsString(lang('customer'), $description);
    }

    public function testGetStreamUsesDetailedDescriptionWhenHashMissing(): void
    {
        $library = new Ics_file();

        $stream = $library->get_stream(
            $this->makeAppointment(['hash' => '']),
            $this->makeService(),
            $this->makeProvider(),
            $this->makeCustomer(),
        );

        $description = $this->extractDescription($stream);

        $this->assertStringNotContainsString('booking/reschedule/', $description);
        $this->assertStringContainsString(lang('provider'), $description);
        $this->assertStringContainsString(lang('customer'), $description);
        $this->assertStringContainsString('Bring materials', $description);
    }

    private function extractDescription(string $stream): string
    {
        $lines = preg_split("/\\r\\n|\\n|\\r/", $stream);
        $description = '';
        $capturing = false;

        foreach ($lines as $line) {
            if (str_starts_with($line, 'DESCRIPTION:')) {
                $description = substr($line, strlen('DESCRIPTION:'));
                $capturing = true;
                continue;
            }

            if ($capturing) {
                if ($line === '' || !str_starts_with($line, ' ')) {
                    break;
                }

                $description .= substr($line, 1);
            }
        }

        return $description;
    }

    private function makeAppointment(array $overrides = []): array
    {
        return array_merge(
            [
                'id' => 11,
                'start_datetime' => '2024-02-20 10:00:00',
                'end_datetime' => '2024-02-20 11:00:00',
                'id_caldav_calendar' => '',
                'hash' => '',
                'notes' => 'Bring materials',
            ],
            $overrides,
        );
    }

    private function makeService(): array
    {
        return [
            'name' => 'Workshop',
            'location' => 'Room 101',
        ];
    }

    private function makeProvider(): array
    {
        return [
            'timezone' => 'Europe/Berlin',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.org',
            'phone_number' => '12345',
            'address' => 'Main St',
            'city' => 'Berlin',
            'zip_code' => '10115',
        ];
    }

    private function makeCustomer(): array
    {
        return [
            'first_name' => 'Alan',
            'last_name' => 'Turing',
            'email' => 'alan@example.org',
            'phone_number' => '67890',
            'address' => 'Second St',
            'city' => 'Berlin',
            'zip_code' => '10117',
        ];
    }
}
