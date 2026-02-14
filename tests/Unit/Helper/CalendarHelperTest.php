<?php

namespace Tests\Helper;

use Tests\TestCase;

class CalendarHelperTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        get_instance()->load->helper('calendar');
    }

    public function test_google_calendar_link_preserves_event_duration(): void
    {
        $event = [
            'title' => 'Lesson',
            'start' => '2024-01-01T10:00:00+00:00',
            'end' => '2024-01-01T11:15:00+00:00',
        ];

        $link = build_google_calendar_link($event);
        $query = parse_url($link, PHP_URL_QUERY);
        parse_str($query, $params);

        $this->assertArrayHasKey('dates', $params);
        $this->assertSame('20240101T100000Z/20240101T111500Z', $params['dates']);
    }

    public function test_outlook_calendar_link_preserves_event_duration(): void
    {
        $event = [
            'title' => 'Lesson',
            'start' => '2024-02-01T08:30:00+00:00',
            'end' => '2024-02-01T09:45:00+00:00',
        ];

        $link = build_outlook_calendar_link($event);
        $query = parse_url($link, PHP_URL_QUERY);
        parse_str($query, $params);

        $this->assertArrayHasKey('startdt', $params);
        $this->assertArrayHasKey('enddt', $params);
        $this->assertSame('2024-02-01T08:30:00+00:00', $params['startdt']);
        $this->assertSame('2024-02-01T09:45:00+00:00', $params['enddt']);
    }
}
