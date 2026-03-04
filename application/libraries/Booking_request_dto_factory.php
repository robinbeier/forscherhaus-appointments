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
 * Typed booking register request DTO.
 */
final class BookingRegisterRequestDto
{
    /**
     * @param array<string, mixed> $appointment
     * @param array<string, mixed> $customer
     */
    public function __construct(
        public readonly array $appointment,
        public readonly array $customer,
        public readonly bool $manageMode,
        public readonly ?string $captcha,
    ) {
    }
}

/**
 * Typed booking available-hours request DTO.
 */
final class BookingAvailableHoursRequestDto
{
    public function __construct(
        public readonly string|int|null $providerId,
        public readonly ?int $serviceId,
        public readonly string $selectedDate,
        public readonly bool $manageMode,
        public readonly ?int $appointmentId,
    ) {
    }
}

/**
 * Typed booking unavailable-dates request DTO.
 */
final class BookingUnavailableDatesRequestDto
{
    public function __construct(
        public readonly string|int|null $providerId,
        public readonly ?int $serviceId,
        public readonly ?int $appointmentId,
        public readonly bool $manageMode,
        public readonly string $selectedDate,
    ) {
    }
}

/**
 * Typed booking index request DTO.
 */
final class BookingThemeRequestDto
{
    public function __construct(public readonly string $theme)
    {
    }
}

/**
 * Typed booking cancellation request DTO.
 */
final class BookingCancellationRequestDto
{
    public function __construct(public readonly mixed $cancellationReason)
    {
    }
}

/**
 * Booking request DTO factory.
 *
 * @package Libraries
 */
class Booking_request_dto_factory
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

    public function buildRegisterRequest(): BookingRegisterRequestDto
    {
        return $this->fromRegisterPayload(request('post_data'), request('captcha'));
    }

    public function buildAvailableHoursRequest(): BookingAvailableHoursRequestDto
    {
        return $this->fromAvailableHoursPayload(
            request('provider_id'),
            request('service_id'),
            request('selected_date'),
            request('manage_mode'),
            request('appointment_id'),
        );
    }

    public function buildUnavailableDatesRequest(): BookingUnavailableDatesRequestDto
    {
        return $this->fromUnavailableDatesPayload(
            request('provider_id'),
            request('service_id'),
            request('appointment_id'),
            request('manage_mode'),
            request('selected_date'),
        );
    }

    public function buildThemeRequest(?string $default_theme = null): BookingThemeRequestDto
    {
        return $this->fromThemePayload(request('theme', $default_theme), $default_theme);
    }

    public function buildCancellationRequest(): BookingCancellationRequestDto
    {
        return $this->fromCancellationPayload(request('cancellation_reason'));
    }

    public function fromRegisterPayload(mixed $post_data, mixed $captcha): BookingRegisterRequestDto
    {
        $normalized_post_data = $this->request_normalizer->normalizeAssocArray($post_data);
        $appointment = $this->request_normalizer->normalizeAssocArray($normalized_post_data['appointment'] ?? []);
        $customer = $this->request_normalizer->normalizeAssocArray($normalized_post_data['customer'] ?? []);

        foreach (['address', 'city', 'zip_code', 'notes', 'phone_number'] as $optional_customer_key) {
            if (!array_key_exists($optional_customer_key, $customer)) {
                $customer[$optional_customer_key] = '';
            }
        }

        $manage_mode = $this->request_normalizer->normalizeBool($normalized_post_data['manage_mode'] ?? false, false);
        $normalized_captcha = $this->request_normalizer->normalizeString($captcha, null, true);

        return new BookingRegisterRequestDto($appointment, $customer, $manage_mode, $normalized_captcha);
    }

    public function fromAvailableHoursPayload(
        mixed $provider_id,
        mixed $service_id,
        mixed $selected_date,
        mixed $manage_mode,
        mixed $appointment_id,
    ): BookingAvailableHoursRequestDto {
        $normalized_date = $this->normalizeDateCompat($selected_date);

        return new BookingAvailableHoursRequestDto(
            $this->normalizeProviderIdCompat($provider_id),
            $this->request_normalizer->normalizePositiveInt($service_id, null),
            $normalized_date,
            $this->request_normalizer->normalizeBool($manage_mode, false),
            $this->request_normalizer->normalizePositiveInt($appointment_id, null),
        );
    }

    public function fromUnavailableDatesPayload(
        mixed $provider_id,
        mixed $service_id,
        mixed $appointment_id,
        mixed $manage_mode,
        mixed $selected_date,
    ): BookingUnavailableDatesRequestDto {
        $normalized_date = $this->normalizeDateCompat($selected_date);

        return new BookingUnavailableDatesRequestDto(
            $this->normalizeProviderIdCompat($provider_id),
            $this->request_normalizer->normalizePositiveInt($service_id, null),
            $this->request_normalizer->normalizePositiveInt($appointment_id, null),
            $this->request_normalizer->normalizeBool($manage_mode, false),
            $normalized_date,
        );
    }

    public function fromThemePayload(mixed $theme, ?string $default_theme = null): BookingThemeRequestDto
    {
        $normalized_theme = $this->request_normalizer->normalizeString($theme, $default_theme, false);

        return new BookingThemeRequestDto($normalized_theme ?? '');
    }

    public function fromCancellationPayload(mixed $cancellation_reason): BookingCancellationRequestDto
    {
        return new BookingCancellationRequestDto($cancellation_reason);
    }

    private function normalizeProviderIdCompat(mixed $provider_id): string|int|null
    {
        if ($provider_id === ANY_PROVIDER) {
            return ANY_PROVIDER;
        }

        $normalized_int = $this->request_normalizer->normalizePositiveInt($provider_id, null);

        if ($normalized_int !== null) {
            return $normalized_int;
        }

        $normalized_string = $this->request_normalizer->normalizeString($provider_id, null, true);

        if ($normalized_string === null) {
            return null;
        }

        return $normalized_string === ANY_PROVIDER ? ANY_PROVIDER : $normalized_string;
    }

    private function normalizeDateCompat(mixed $selected_date): string
    {
        $normalized_date = $this->request_normalizer->normalizeDateYmd($selected_date, null);

        if ($normalized_date !== null) {
            return $normalized_date;
        }

        return $this->request_normalizer->normalizeString($selected_date, '', false) ?? '';
    }
}
