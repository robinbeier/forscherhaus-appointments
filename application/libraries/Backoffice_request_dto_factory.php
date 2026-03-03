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
 * Typed backoffice search request DTO.
 */
final class BackofficeSearchRequestDto
{
    public function __construct(
        public readonly string $keyword,
        public readonly string $orderBy,
        public readonly int $limit,
        public readonly int $offset,
    ) {
    }
}

/**
 * Typed backoffice payload request DTO.
 */
final class BackofficeEntityPayloadRequestDto
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(public readonly array $payload)
    {
    }
}

/**
 * Typed backoffice id request DTO.
 */
final class BackofficeEntityIdRequestDto
{
    public function __construct(public readonly string|int|null $id)
    {
    }
}

/**
 * Typed backoffice settings request DTO.
 */
final class BackofficeSettingsRequestDto
{
    /**
     * @param array<int|string, mixed> $settings
     */
    public function __construct(public readonly array $settings)
    {
    }
}

/**
 * Backoffice request DTO factory.
 *
 * @package Libraries
 */
class Backoffice_request_dto_factory
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

    public function buildSearchRequestDto(
        string $default_order_by = 'update_datetime DESC',
        int $default_limit = 1000,
    ): BackofficeSearchRequestDto {
        return $this->createSearchRequestDto(
            request('keyword', ''),
            request('order_by', $default_order_by),
            request('limit', $default_limit),
            request('offset', '0'),
            $default_order_by,
            $default_limit,
        );
    }

    public function buildEntityPayloadRequestDto(string $key): BackofficeEntityPayloadRequestDto
    {
        return $this->createEntityPayloadRequestDto(request($key));
    }

    public function buildEntityIdRequestDto(string $key): BackofficeEntityIdRequestDto
    {
        return $this->createEntityIdRequestDto(request($key));
    }

    public function buildSettingsRequestDto(string $key = 'settings'): BackofficeSettingsRequestDto
    {
        return $this->createSettingsRequestDto(request($key));
    }

    public function createSearchRequestDto(
        mixed $keyword,
        mixed $order_by,
        mixed $limit,
        mixed $offset,
        string $default_order_by = 'update_datetime DESC',
        int $default_limit = 1000,
    ): BackofficeSearchRequestDto {
        $normalized_keyword = $this->request_normalizer->normalizeString($keyword, '', false) ?? '';
        $normalized_order_by = $this->request_normalizer->normalizeString($order_by, $default_order_by, false);
        $normalized_limit = $this->request_normalizer->normalizeInt($limit, $default_limit) ?? $default_limit;
        $normalized_offset = $this->request_normalizer->normalizeInt($offset, 0) ?? 0;

        return new BackofficeSearchRequestDto(
            $normalized_keyword,
            $normalized_order_by ?? $default_order_by,
            max(0, $normalized_limit),
            max(0, $normalized_offset),
        );
    }

    public function createEntityPayloadRequestDto(mixed $payload): BackofficeEntityPayloadRequestDto
    {
        return new BackofficeEntityPayloadRequestDto($this->normalizeAssocPayload($payload));
    }

    public function createEntityIdRequestDto(mixed $id): BackofficeEntityIdRequestDto
    {
        return new BackofficeEntityIdRequestDto($this->normalizeEntityIdCompat($id));
    }

    public function createSettingsRequestDto(mixed $settings): BackofficeSettingsRequestDto
    {
        return new BackofficeSettingsRequestDto($this->normalizeArrayPayload($settings));
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

    /**
     * @return array<int|string, mixed>
     */
    private function normalizeArrayPayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (!is_string($payload)) {
            return [];
        }

        $decoded = json_decode(trim($payload), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeEntityIdCompat(mixed $id): string|int|null
    {
        $normalized_int = $this->request_normalizer->normalizeInt($id, null);

        if ($normalized_int !== null) {
            return $normalized_int;
        }

        $normalized_string = $this->request_normalizer->normalizeString($id, null, true);

        if ($normalized_string === null) {
            return null;
        }

        return $normalized_string;
    }
}
