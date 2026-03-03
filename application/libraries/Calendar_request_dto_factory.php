<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.8.0
 * ---------------------------------------------------------------------------- */

/**
 * Typed calendar save-appointment request DTO.
 */
final class CalendarSaveAppointmentRequestDto
{
    /**
     * @param array<string, mixed> $customerData
     * @param array<string, mixed> $appointmentData
     */
    public function __construct(public readonly array $customerData, public readonly array $appointmentData)
    {
    }
}

/**
 * Typed calendar delete-appointment request DTO.
 */
final class CalendarDeleteAppointmentRequestDto
{
    public function __construct(public readonly ?int $appointmentId, public readonly ?string $cancellationReason)
    {
    }
}

/**
 * Typed calendar unavailability request DTO.
 */
final class CalendarUnavailabilityRequestDto
{
    /**
     * @param array<string, mixed> $unavailability
     */
    public function __construct(public readonly array $unavailability)
    {
    }
}

/**
 * Typed calendar working-plan-exception request DTO.
 */
final class CalendarWorkingPlanExceptionRequestDto
{
    /**
     * @param array<string, mixed> $workingPlanException
     */
    public function __construct(
        public readonly ?int $providerId,
        public readonly ?string $date,
        public readonly ?string $originalDate,
        public readonly array $workingPlanException,
    ) {
    }
}

/**
 * Typed calendar range request DTO.
 */
final class CalendarRangeRequestDto
{
    public function __construct(public readonly ?string $startDate, public readonly ?string $endDate)
    {
    }
}

/**
 * Typed calendar filter request DTO.
 */
final class CalendarFilterRequestDto
{
    public function __construct(
        public readonly string|int|null $recordId,
        public readonly ?string $filterType,
        public readonly bool $isAll,
    ) {
    }
}

/**
 * Calendar request DTO factory.
 *
 * @package Libraries
 */
class Calendar_request_dto_factory
{
    protected Request_normalizer $request_normalizer;

    public function __construct(?Request_normalizer $request_normalizer = null)
    {
        if ($request_normalizer instanceof Request_normalizer) {
            $this->request_normalizer = $request_normalizer;

            return;
        }

        /** @var EA_Controller|CI_Controller $CI */
        $CI = &get_instance();

        if (!isset($CI->request_normalizer) || !$CI->request_normalizer instanceof Request_normalizer) {
            $CI->load->library('request_normalizer');
        }

        $this->request_normalizer = $CI->request_normalizer;
    }

    public function buildSaveAppointmentRequestDto(): CalendarSaveAppointmentRequestDto
    {
        return $this->createSaveAppointmentRequestDto(request('customer_data'), request('appointment_data'));
    }

    public function buildDeleteAppointmentRequestDto(): CalendarDeleteAppointmentRequestDto
    {
        return $this->createDeleteAppointmentRequestDto(request('appointment_id'), request('cancellation_reason'));
    }

    public function buildUnavailabilityRequestDto(string $key = 'unavailability'): CalendarUnavailabilityRequestDto
    {
        return $this->createUnavailabilityRequestDto(request($key));
    }

    public function buildWorkingPlanExceptionRequestDto(): CalendarWorkingPlanExceptionRequestDto
    {
        return $this->createWorkingPlanExceptionRequestDto(
            request('provider_id'),
            request('date'),
            request('original_date'),
            request('working_plan_exception'),
        );
    }

    public function buildRangeRequestDto(
        string $start_key = 'start_date',
        string $end_key = 'end_date',
    ): CalendarRangeRequestDto {
        return $this->createRangeRequestDto(request($start_key), request($end_key));
    }

    public function buildFilterRequestDto(
        string $record_id_key = 'record_id',
        string $filter_type_key = 'filter_type',
        string $is_all_key = 'is_all',
    ): CalendarFilterRequestDto {
        return $this->createFilterRequestDto(request($record_id_key), request($filter_type_key), request($is_all_key));
    }

    public function createSaveAppointmentRequestDto(
        mixed $customer_data,
        mixed $appointment_data,
    ): CalendarSaveAppointmentRequestDto {
        return new CalendarSaveAppointmentRequestDto(
            $this->normalizeAssocPayload($customer_data),
            $this->normalizeAssocPayload($appointment_data),
        );
    }

    public function createDeleteAppointmentRequestDto(
        mixed $appointment_id,
        mixed $cancellation_reason,
    ): CalendarDeleteAppointmentRequestDto {
        return new CalendarDeleteAppointmentRequestDto(
            $this->request_normalizer->normalizePositiveInt($appointment_id, null),
            $this->request_normalizer->normalizeString($cancellation_reason, null, true),
        );
    }

    public function createUnavailabilityRequestDto(mixed $unavailability): CalendarUnavailabilityRequestDto
    {
        return new CalendarUnavailabilityRequestDto($this->normalizeAssocPayload($unavailability));
    }

    public function createWorkingPlanExceptionRequestDto(
        mixed $provider_id,
        mixed $date,
        mixed $original_date,
        mixed $working_plan_exception,
    ): CalendarWorkingPlanExceptionRequestDto {
        return new CalendarWorkingPlanExceptionRequestDto(
            $this->request_normalizer->normalizePositiveInt($provider_id, null),
            $this->normalizeDateCompat($date),
            $this->normalizeDateCompat($original_date),
            $this->normalizeAssocPayload($working_plan_exception),
        );
    }

    public function createRangeRequestDto(mixed $start_date, mixed $end_date): CalendarRangeRequestDto
    {
        return new CalendarRangeRequestDto(
            $this->normalizeDateCompat($start_date),
            $this->normalizeDateCompat($end_date),
        );
    }

    public function createFilterRequestDto(
        mixed $record_id,
        mixed $filter_type,
        mixed $is_all,
    ): CalendarFilterRequestDto {
        $normalized_record_id = $this->request_normalizer->normalizeInt($record_id, null);

        if ($normalized_record_id === null) {
            $normalized_record_id = $this->request_normalizer->normalizeString($record_id, null, true);
        }

        return new CalendarFilterRequestDto(
            $normalized_record_id,
            $this->request_normalizer->normalizeString($filter_type, null, true),
            $this->request_normalizer->normalizeBool($is_all, false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeAssocPayload(mixed $payload): array
    {
        if (is_string($payload)) {
            return $this->request_normalizer->normalizeJsonAssocArray($payload);
        }

        return $this->request_normalizer->normalizeAssocArray($payload);
    }

    private function normalizeDateCompat(mixed $value): ?string
    {
        $normalized = $this->request_normalizer->normalizeDateYmd($value, null);

        if ($normalized !== null) {
            return $normalized;
        }

        return $this->request_normalizer->normalizeString($value, null, true);
    }
}
