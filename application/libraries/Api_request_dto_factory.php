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
 * Typed API collection query DTO.
 */
final class ApiCollectionQueryDto
{
    /**
     * @param array<int, string>|null $fields
     * @param array<int, string>|null $with
     */
    public function __construct(
        public readonly ?string $keyword,
        public readonly int $limit,
        public readonly int $page,
        public readonly int $offset,
        public readonly ?string $orderBy,
        public readonly ?array $fields,
        public readonly ?array $with,
    ) {}
}

/**
 * Typed API appointments read DTO.
 */
final class ApiAppointmentsReadRequestDto
{
    public function __construct(
        public readonly ApiCollectionQueryDto $query,
        public readonly bool $includeBufferBlocks,
        public readonly bool $aggregates,
        public readonly ?string $date,
        public readonly ?string $from,
        public readonly ?string $till,
        public readonly string|int|null $serviceId,
        public readonly string|int|null $providerId,
        public readonly string|int|null $customerId,
    ) {}
}

/**
 * Typed API appointments show DTO.
 */
final class ApiAppointmentsShowRequestDto
{
    /**
     * @param array<int, string>|null $fields
     * @param array<int, string>|null $with
     */
    public function __construct(
        public readonly bool $includeBufferBlocks,
        public readonly ?array $fields,
        public readonly ?array $with,
    ) {}
}

/**
 * Typed API availabilities request DTO.
 */
final class ApiAvailabilitiesRequestDto
{
    public function __construct(
        public readonly ?int $providerId,
        public readonly ?int $serviceId,
        public readonly string $date,
    ) {}
}

/**
 * Typed API write payload DTO.
 */
final class ApiEntityWritePayloadDto
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(public readonly array $payload) {}
}

/**
 * Typed API date-filter DTO.
 */
final class ApiDateFilterDto
{
    public function __construct(
        public readonly ?string $date,
        public readonly ?string $from,
        public readonly ?string $till,
    ) {}
}

/**
 * Typed API settings update DTO.
 */
final class ApiSettingsUpdateDto
{
    public function __construct(public readonly mixed $value) {}
}

/**
 * API request DTO factory.
 *
 * @package Libraries
 */
class Api_request_dto_factory
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

    public function buildCollectionQueryDto(Api $api): ApiCollectionQueryDto
    {
        $keyword = $api->request_keyword();
        $limit = $api->request_limit() ?? 20;
        $page = $this->request_normalizer->normalizePositiveInt(request('page', 1), 1) ?? 1;
        $offset = $api->request_offset() ?? 0;
        $order_by = $api->request_order_by();
        $fields = $api->request_fields();
        $with = $api->request_with();

        return $this->createCollectionQueryDto($keyword, $limit, $page, $offset, $order_by, $fields, $with);
    }

    public function buildAppointmentsReadRequestDto(Api $api): ApiAppointmentsReadRequestDto
    {
        return $this->createAppointmentsReadRequestDto(
            $this->buildCollectionQueryDto($api),
            request('include_buffer_blocks'),
            request('aggregates'),
            request('date'),
            request('from'),
            request('till'),
            request('serviceId'),
            request('providerId'),
            request('customerId'),
        );
    }

    public function buildAppointmentsShowRequestDto(Api $api): ApiAppointmentsShowRequestDto
    {
        return new ApiAppointmentsShowRequestDto(
            $this->request_normalizer->normalizeBool(request('include_buffer_blocks'), false),
            $api->request_fields(),
            $api->request_with(),
        );
    }

    public function buildAvailabilitiesRequestDto(): ApiAvailabilitiesRequestDto
    {
        return $this->createAvailabilitiesRequestDto(request('providerId'), request('serviceId'), request('date'));
    }

    public function buildEntityWritePayloadDto(): ApiEntityWritePayloadDto
    {
        return $this->createEntityWritePayloadDto(request());
    }

    /**
     * Build the strict JSON payload used by the appointments write API.
     *
     * Appointment writes deliberately do not use request(), because that
     * helper accepts query/form input and normalizes malformed JSON to an
     * empty array. The API contract requires a non-empty JSON object.
     */
    public function buildAppointmentsWritePayloadDto(): ApiEntityWritePayloadDto
    {
        /** @var EA_Controller|CI_Controller $CI */
        $CI = &get_instance();
        $content_type = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));

        if ($content_type === '' || !preg_match('/^application\/json(?:\s*;|$)/', $content_type)) {
            throw new InvalidArgumentException('Appointment writes require application/json.', 415);
        }

        return $this->createAppointmentsWritePayloadDto((string) $CI->input->raw_input_stream);
    }

    public function buildDateFilterDto(): ApiDateFilterDto
    {
        return $this->createDateFilterDto(request('date'), request('from'), request('till'));
    }

    public function buildSettingsUpdateDto(): ApiSettingsUpdateDto
    {
        return $this->createSettingsUpdateDto(request('value'));
    }

    /**
     * @param array<int, string>|null $fields
     * @param array<int, string>|null $with
     */
    public function createCollectionQueryDto(
        ?string $keyword,
        int $limit,
        int $page,
        int $offset,
        ?string $order_by,
        ?array $fields,
        ?array $with,
    ): ApiCollectionQueryDto {
        return new ApiCollectionQueryDto(
            $keyword,
            max(0, $limit),
            max(1, $page),
            max(0, $offset),
            $order_by,
            $fields,
            $with,
        );
    }

    public function createAppointmentsReadRequestDto(
        ApiCollectionQueryDto $query,
        mixed $include_buffer_blocks,
        mixed $aggregates,
        mixed $date,
        mixed $from,
        mixed $till,
        mixed $service_id,
        mixed $provider_id,
        mixed $customer_id,
    ): ApiAppointmentsReadRequestDto {
        $date_filter = $this->createDateFilterDto($date, $from, $till);

        return new ApiAppointmentsReadRequestDto(
            $query,
            $this->request_normalizer->normalizeBool($include_buffer_blocks, false),
            $aggregates !== null,
            $date_filter->date,
            $date_filter->from,
            $date_filter->till,
            $this->normalizeCollectionFilterIdCompat($service_id),
            $this->normalizeCollectionFilterIdCompat($provider_id),
            $this->normalizeCollectionFilterIdCompat($customer_id),
        );
    }

    public function createAvailabilitiesRequestDto(
        mixed $provider_id,
        mixed $service_id,
        mixed $date,
    ): ApiAvailabilitiesRequestDto {
        $normalized_date = $this->normalizeDateCompat($date);

        if (!$normalized_date) {
            $normalized_date = date('Y-m-d');
        }

        return new ApiAvailabilitiesRequestDto(
            $this->request_normalizer->normalizePositiveInt($provider_id, null),
            $this->request_normalizer->normalizePositiveInt($service_id, null),
            $normalized_date,
        );
    }

    public function createEntityWritePayloadDto(mixed $payload): ApiEntityWritePayloadDto
    {
        if (is_string($payload)) {
            return new ApiEntityWritePayloadDto($this->request_normalizer->normalizeJsonAssocArray($payload));
        }

        if (is_object($payload)) {
            /** @var array<string, mixed> $object_vars */
            $object_vars = get_object_vars($payload);

            return new ApiEntityWritePayloadDto($this->request_normalizer->normalizeAssocArray($object_vars));
        }

        return new ApiEntityWritePayloadDto($this->request_normalizer->normalizeAssocArray($payload));
    }

    /**
     * @param string $raw_payload
     */
    public function createAppointmentsWritePayloadDto(string $raw_payload): ApiEntityWritePayloadDto
    {
        if (trim($raw_payload) === '') {
            throw new InvalidArgumentException('Appointment write payload must be a non-empty JSON object.', 400);
        }

        try {
            $decoded = json_decode($raw_payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Appointment write payload must be valid JSON.', 400);
        }

        if (!is_array($decoded) || !$this->isAssociativeArray($decoded) || $decoded === []) {
            throw new InvalidArgumentException('Appointment write payload must be a non-empty JSON object.', 400);
        }

        $allowed = ['start', 'end', 'location', 'color', 'status', 'notes', 'customerId', 'providerId', 'serviceId'];

        $unsupported = array_diff(array_keys($decoded), $allowed);

        if ($unsupported !== []) {
            throw new InvalidArgumentException('Appointment write payload contains unsupported fields.', 400);
        }

        return new ApiEntityWritePayloadDto($decoded);
    }

    public function createDateFilterDto(mixed $date, mixed $from, mixed $till): ApiDateFilterDto
    {
        return new ApiDateFilterDto(
            $this->normalizeDateCompat($date),
            $this->normalizeDateCompat($from),
            $this->normalizeDateCompat($till),
        );
    }

    public function createSettingsUpdateDto(mixed $value): ApiSettingsUpdateDto
    {
        return new ApiSettingsUpdateDto($value);
    }

    private function normalizeDateCompat(mixed $date_input): ?string
    {
        $normalized = $this->request_normalizer->normalizeDateYmd($date_input, null);

        if ($normalized !== null) {
            return $normalized;
        }

        return $this->request_normalizer->normalizeString($date_input, null, true);
    }

    private function normalizeCollectionFilterIdCompat(mixed $id_input): string|int|null
    {
        if ($id_input === null || $id_input === false) {
            return null;
        }

        if (is_int($id_input)) {
            return $id_input === 0 ? null : $id_input;
        }

        if (is_float($id_input)) {
            if (!is_finite($id_input)) {
                return null;
            }

            if (floor($id_input) === $id_input) {
                $normalized_int = (int) $id_input;

                return $normalized_int === 0 ? null : $normalized_int;
            }

            return (string) $id_input;
        }

        if (is_array($id_input) || is_object($id_input)) {
            return null;
        }

        if (is_string($id_input) && ($id_input === '' || $id_input === '0')) {
            return null;
        }

        $candidate = is_string($id_input) ? trim($id_input) : (string) $id_input;

        if ($candidate === '') {
            return (string) $id_input;
        }

        $normalized_int = $this->request_normalizer->normalizeInt($candidate, null);

        if ($normalized_int === null) {
            return $candidate;
        }

        if ($normalized_int === 0) {
            return $candidate;
        }

        return $normalized_int;
    }

    /**
     * @param array<mixed> $value
     */
    private function isAssociativeArray(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }
}
