<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.3.0
 * ---------------------------------------------------------------------------- */

use Jsvrcek\ICS\CalendarExport;
use Jsvrcek\ICS\CalendarStream;
use Jsvrcek\ICS\Exception\CalendarEventException;
use Jsvrcek\ICS\Model\CalendarAlarm;
use Jsvrcek\ICS\Model\CalendarEvent;
use Jsvrcek\ICS\Model\Description\Location;
use Jsvrcek\ICS\Utility\Formatter;

/**
 * Ics file library.
 *
 * Handle ICS related functionality.
 *
 * An ICS file is a calendar file saved in a universal calendar format used by many email and calendar programs,
 * including Microsoft Outlook, Google Calendar, and Apple Calendar.
 *
 * @package Libraries
 */
class Ics_file
{
    /**
     * @var EA_Controller|CI_Controller
     */
    protected EA_Controller|CI_Controller $CI;

    /**
     * Availability constructor.
     */
    public function __construct()
    {
        $this->CI = &get_instance();

        $this->CI->load->library('ics_provider');
        $this->CI->load->library('ics_calendar');
    }

    /**
     * Get the ICS file contents for the provided arguments.
     *
     * @param array $appointment Appointment data.
     * @param array $service Service data.
     * @param array $provider Provider data.
     * @param array $customer Customer data.
     *
     * @return string Returns the contents of the ICS file.
     *
     * @throws CalendarEventException
     * @throws Exception
     */
    public function get_stream(array $appointment, array $service, array $provider, array $customer): string
    {
        $appointment_timezone = new DateTimeZone($provider['timezone']);

        $appointment_start = new DateTime($appointment['start_datetime'], $appointment_timezone);

        $appointment_end = new DateTime($appointment['end_datetime'], $appointment_timezone);

        // Set up the event.
        $event = new CalendarEvent();

        $event
            ->setStart($appointment_start)
            ->setEnd($appointment_end)
            ->setStatus('CONFIRMED')
            ->setSummary($service['name'])
            ->setUid($appointment['id_caldav_calendar'] ?: $this->generate_uid($appointment['id']));

        if (!empty($service['location'])) {
            $location = new Location();
            $location->setName((string) $service['location']);
            $event->addLocation($location);
        }

        $manage_url = '';

        if (!empty($appointment['hash'])) {
            $manage_url = public_site_url('booking/reschedule/' . $appointment['hash']);
        }

        if ($manage_url !== '') {
            $manage_hint = lang('calendar_event_manage_hint');

            if ($manage_hint === 'calendar_event_manage_hint') {
                $manage_hint = 'Manage appointment:';
            }

            $provider_name = trim((string) $provider['first_name'] . ' ' . (string) $provider['last_name']);
            $event->setDescription(
                trim($manage_hint . ' ' . $manage_url) . '\\n' . lang('provider') . ': ' . $provider_name,
            );
        } else {
            $description = [
                '',
                lang('provider'),
                '',
                lang('name') . ': ' . $provider['first_name'] . ' ' . $provider['last_name'],
                lang('phone_number') . ': ' . $provider['phone_number'],
                lang('address') . ': ' . $provider['address'],
                lang('city') . ': ' . $provider['city'],
                lang('zip_code') . ': ' . $provider['zip_code'],
                '',
                lang('customer'),
                '',
                lang('name') . ': ' . $customer['first_name'] . ' ' . $customer['last_name'],
                lang('phone_number') . ': ' . ($customer['phone_number'] ?? '-'),
                lang('address') . ': ' . $customer['address'],
                lang('city') . ': ' . $customer['city'],
                lang('zip_code') . ': ' . $customer['zip_code'],
                '',
                lang('notes'),
                '',
                $appointment['notes'],
            ];

            $event->setDescription(implode("\\n", $description));
        }

        // Preserve local calendar reminders without creating email recipients.
        foreach (['-15 minutes', '-60 minutes'] as $offset) {
            $alarm = new CalendarAlarm();
            $alarm
                ->setTrigger((clone $appointment_start)->modify($offset))
                ->setAction('DISPLAY')
                ->setDescription('Appointment reminder');
            $event->addAlarm($alarm);
        }

        // Setup calendar.
        $calendar = new Ics_calendar();

        $calendar
            ->setProdId('-//EasyAppointments//Open Source Web Scheduler//EN')
            ->setTimezone(new DateTimeZone($provider['timezone']))
            ->addEvent($event);

        // Setup exporter.
        $calendarExport = new CalendarExport(new CalendarStream(), new Formatter());
        $calendarExport->setDateTimeFormat('utc');
        $calendarExport->addCalendar($calendar);

        return $calendarExport->getStream();
    }

    public function get_unavailability_stream(array $unavailability, array $provider): string
    {
        $unavailability_timezone = new DateTimeZone($provider['timezone']);

        $unavailability_start = new DateTime($unavailability['start_datetime'], $unavailability_timezone);

        $unavailability_end = new DateTime($unavailability['end_datetime'], $unavailability_timezone);

        // Set up the event.
        $event = new CalendarEvent();

        $event
            ->setStart($unavailability_start)
            ->setEnd($unavailability_end)
            ->setStatus('CONFIRMED')
            ->setSummary('Unavailability')
            ->setUid($unavailability['id_caldav_calendar'] ?: $this->generate_uid($unavailability['id']));

        $provider_name = trim((string) $provider['first_name'] . ' ' . (string) $provider['last_name']);
        $event->setDescription(
            str_replace("\n", "\\n", (string) $unavailability['notes']) .
                '\\n' .
                lang('provider') .
                ': ' .
                $provider_name,
        );

        // Setup calendar.
        $calendar = new Ics_calendar();

        $calendar
            ->setProdId('-//EasyAppointments//Open Source Web Scheduler//EN')
            ->setTimezone(new DateTimeZone($provider['timezone']))
            ->addEvent($event);

        // Setup exporter.
        $calendarExport = new CalendarExport(new CalendarStream(), new Formatter());
        $calendarExport->setDateTimeFormat('utc');
        $calendarExport->addCalendar($calendar);

        return $calendarExport->getStream();
    }

    public function generate_uid(int $db_record_id): string
    {
        return 'ea-' . md5($db_record_id);
    }
}
