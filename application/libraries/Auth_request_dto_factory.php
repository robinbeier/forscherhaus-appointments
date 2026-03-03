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
 * Typed login validate request DTO.
 */
final class LoginValidateRequestDto
{
    public function __construct(public readonly ?string $username, public readonly ?string $password)
    {
    }
}

/**
 * Typed recovery request DTO.
 */
final class RecoveryRequestDto
{
    public function __construct(public readonly ?string $username, public readonly ?string $email)
    {
    }
}

/**
 * Typed account save request DTO.
 */
final class AccountSaveRequestDto
{
    /**
     * @param array<string, mixed> $account
     */
    public function __construct(public readonly array $account)
    {
    }
}

/**
 * Typed validate-username request DTO.
 */
final class ValidateUsernameRequestDto
{
    public function __construct(public readonly ?string $username, public readonly string|int|null $userId)
    {
    }
}

/**
 * Typed localization request DTO.
 */
final class LocalizationRequestDto
{
    public function __construct(public readonly ?string $language)
    {
    }
}

/**
 * Typed privacy delete request DTO.
 */
final class PrivacyDeleteRequestDto
{
    public function __construct(public readonly ?string $customerToken)
    {
    }
}

/**
 * Typed consent save request DTO.
 */
final class ConsentSaveRequestDto
{
    /**
     * @param array<string, mixed> $consent
     */
    public function __construct(public readonly array $consent)
    {
    }
}

/**
 * Auth request DTO factory.
 *
 * @package Libraries
 */
class Auth_request_dto_factory
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

    public function buildLoginValidateRequestDto(): LoginValidateRequestDto
    {
        return $this->createLoginValidateRequestDto(request('username'), request('password'));
    }

    public function buildRecoveryRequestDto(): RecoveryRequestDto
    {
        return $this->createRecoveryRequestDto(request('username'), request('email'));
    }

    public function buildAccountSaveRequestDto(string $key = 'account'): AccountSaveRequestDto
    {
        return $this->createAccountSaveRequestDto(request($key));
    }

    public function buildValidateUsernameRequestDto(): ValidateUsernameRequestDto
    {
        return $this->createValidateUsernameRequestDto(request('username'), request('user_id'));
    }

    public function buildLocalizationRequestDto(): LocalizationRequestDto
    {
        return $this->createLocalizationRequestDto(request('language'));
    }

    public function buildPrivacyDeleteRequestDto(): PrivacyDeleteRequestDto
    {
        return $this->createPrivacyDeleteRequestDto(request('customer_token'));
    }

    public function buildConsentSaveRequestDto(string $key = 'consent'): ConsentSaveRequestDto
    {
        return $this->createConsentSaveRequestDto(request($key));
    }

    public function createLoginValidateRequestDto(mixed $username, mixed $password): LoginValidateRequestDto
    {
        return new LoginValidateRequestDto(
            $this->request_normalizer->normalizeString($username, null, true),
            $this->request_normalizer->normalizeString($password, null, true),
        );
    }

    public function createRecoveryRequestDto(mixed $username, mixed $email): RecoveryRequestDto
    {
        return new RecoveryRequestDto(
            $this->request_normalizer->normalizeString($username, null, true),
            $this->request_normalizer->normalizeString($email, null, true),
        );
    }

    public function createAccountSaveRequestDto(mixed $account): AccountSaveRequestDto
    {
        return new AccountSaveRequestDto($this->normalizeAssocPayload($account));
    }

    public function createValidateUsernameRequestDto(mixed $username, mixed $user_id): ValidateUsernameRequestDto
    {
        return new ValidateUsernameRequestDto(
            $this->request_normalizer->normalizeString($username, null, true),
            $this->normalizeEntityIdCompat($user_id),
        );
    }

    public function createLocalizationRequestDto(mixed $language): LocalizationRequestDto
    {
        return new LocalizationRequestDto($this->request_normalizer->normalizeString($language, null, true));
    }

    public function createPrivacyDeleteRequestDto(mixed $customer_token): PrivacyDeleteRequestDto
    {
        return new PrivacyDeleteRequestDto($this->request_normalizer->normalizeString($customer_token, null, true));
    }

    public function createConsentSaveRequestDto(mixed $consent): ConsentSaveRequestDto
    {
        return new ConsentSaveRequestDto($this->normalizeAssocPayload($consent));
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

    private function normalizeEntityIdCompat(mixed $id): string|int|null
    {
        $normalized_int = $this->request_normalizer->normalizeInt($id, null);

        if ($normalized_int !== null) {
            return $normalized_int;
        }

        return $this->request_normalizer->normalizeString($id, null, true);
    }
}
