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
 * Typed LDAP search request DTO.
 */
final class LdapSearchRequestDto
{
    public function __construct(public readonly string $keyword) {}
}

/**
 * Typed webhook CRUD request DTO.
 */
final class WebhookCrudRequestDto
{
    /**
     * @param array<string, mixed> $webhook
     */
    public function __construct(
        public readonly string $keyword,
        public readonly string $orderBy,
        public readonly int $limit,
        public readonly int $offset,
        public readonly string|int|null $webhookId,
        public readonly array $webhook,
    ) {}
}

/**
 * Integrations request DTO factory.
 *
 * @package Libraries
 */
class Integrations_request_dto_factory
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

    public function buildLdapSearchRequestDto(): LdapSearchRequestDto
    {
        return $this->createLdapSearchRequestDto(request('keyword'));
    }

    public function buildWebhookCrudRequestDto(
        string $webhook_key = 'webhook',
        string $webhook_id_key = 'webhook_id',
    ): WebhookCrudRequestDto {
        return $this->createWebhookCrudRequestDto(
            request('keyword', ''),
            request('order_by', 'update_datetime DESC'),
            request('limit', 1000),
            request('offset', '0'),
            request($webhook_id_key),
            request($webhook_key),
        );
    }

    public function createLdapSearchRequestDto(mixed $keyword): LdapSearchRequestDto
    {
        return new LdapSearchRequestDto($this->normalizeRawStringNonNullCompat($keyword));
    }

    public function createWebhookCrudRequestDto(
        mixed $keyword,
        mixed $order_by,
        mixed $limit,
        mixed $offset,
        mixed $webhook_id,
        mixed $webhook,
    ): WebhookCrudRequestDto {
        $normalized_keyword = $this->request_normalizer->normalizeString($keyword, '', false) ?? '';
        $normalized_order_by = $this->request_normalizer->normalizeString($order_by, 'update_datetime DESC', false);
        $normalized_limit = $this->request_normalizer->normalizeInt($limit, 1000) ?? 1000;
        $normalized_offset = $this->request_normalizer->normalizeInt($offset, 0) ?? 0;

        return new WebhookCrudRequestDto(
            $normalized_keyword,
            $normalized_order_by ?? 'update_datetime DESC',
            max(0, $normalized_limit),
            max(0, $normalized_offset),
            $this->normalizeEntityIdCompat($webhook_id),
            $this->normalizeAssocPayload($webhook),
        );
    }

    private function normalizeEntityIdCompat(mixed $id): string|int|null
    {
        $normalized_int = $this->request_normalizer->normalizeInt($id, null);

        if ($normalized_int !== null) {
            return $normalized_int;
        }

        return $this->request_normalizer->normalizeString($id, null, true);
    }

    private function normalizeSensitiveStringCompat(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        return (string) $value;
    }

    private function normalizeRawStringNonNullCompat(mixed $value): string
    {
        return $this->normalizeSensitiveStringCompat($value) ?? '';
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
}
