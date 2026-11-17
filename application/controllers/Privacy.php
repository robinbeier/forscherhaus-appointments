<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.3.2
 * ---------------------------------------------------------------------------- */

/**
 * Privacy controller.
 *
 * Handles the privacy related operations.
 *
 * @package Controllers
 */
class Privacy extends EA_Controller
{
    private const CUSTOMER_TOKEN_V3_CACHE_PREFIX = 'customer-token-v3-';
    /**
     * Privacy constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->model('customers_model');
    }

    /**
     * Remove all customer data (including appointments) from the system.
     */
    public function delete_personal_information(): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            abort(405, 'Method Not Allowed', ['Allow: POST']);
        }

        try {
            $display_delete_personal_information = setting('display_delete_personal_information');

            if (!$display_delete_personal_information) {
                abort(403, 'Forbidden');
            }

            $request_dto = $this->authRequestDtoFactory()->buildPrivacyDeleteRequestDto();
            $customer_token = $request_dto->customerToken;

            if (empty($customer_token)) {
                throw new InvalidArgumentException('Invalid customer token value provided.');
            }

            $cache = $this->customerTokenCache();

            if (!is_object($cache) || !method_exists($cache, 'get')) {
                throw new RuntimeException(
                    'Customer token cache is unavailable, please reload the page and try again.',
                );
            }

            $customer_id = null;
            $privacy_appointment_id = null;
            $privacy_token_is_current = null;

            // v3 keeps one bounded cache entry per appointment while retaining
            // a fresh random token for every page view. The exact token is
            // compared server-side before the associated customer is used.
            if (preg_match('/\Av3-([1-9][0-9]{0,18})-([A-Za-z0-9_-]{43})\z/D', $customer_token, $matches) === 1) {
                $appointment_id = (int) $matches[1];
                if ($appointment_id <= 0 || (string) $appointment_id !== $matches[1]) {
                    throw new InvalidArgumentException('Invalid customer token value provided.');
                }

                $entry = $cache->get(self::CUSTOMER_TOKEN_V3_CACHE_PREFIX . $matches[1]);
                if (
                    is_array($entry) &&
                    isset($entry['token'], $entry['customer_id'], $entry['appointment_id']) &&
                    is_string($entry['token']) &&
                    hash_equals($entry['token'], $customer_token) &&
                    (int) $entry['appointment_id'] === $appointment_id &&
                    (string) (int) $entry['appointment_id'] === $matches[1]
                ) {
                    $customer_id = (int) $entry['customer_id'];
                    $privacy_appointment_id = $appointment_id;
                    $privacy_token_is_current = static function () use (
                        $cache,
                        $matches,
                        $customer_token,
                        $customer_id,
                        $appointment_id,
                    ): bool {
                        $current = $cache->get(self::CUSTOMER_TOKEN_V3_CACHE_PREFIX . $matches[1]);

                        return is_array($current) &&
                            is_string($current['token'] ?? null) &&
                            hash_equals($current['token'], $customer_token) &&
                            (int) ($current['customer_id'] ?? 0) === $customer_id &&
                            (int) ($current['appointment_id'] ?? 0) === $appointment_id;
                    };
                }
            }

            if (empty($customer_id)) {
                throw new InvalidArgumentException(
                    'Customer ID does not exist, please reload the page ' . 'and try again.',
                );
            }

            $this->customers_model->delete($customer_id, $privacy_appointment_id, $privacy_token_is_current);

            json_response([
                'success' => true,
            ]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    private function authRequestDtoFactory(): Auth_request_dto_factory
    {
        if (
            isset($this->auth_request_dto_factory) &&
            $this->auth_request_dto_factory instanceof Auth_request_dto_factory
        ) {
            return $this->auth_request_dto_factory;
        }

        /** @var EA_Controller|CI_Controller $CI */
        $CI = &get_instance();

        if (
            !isset($CI->auth_request_dto_factory) ||
            !$CI->auth_request_dto_factory instanceof Auth_request_dto_factory
        ) {
            $CI->load->library('auth_request_dto_factory');
        }

        $this->auth_request_dto_factory = $CI->auth_request_dto_factory;

        return $this->auth_request_dto_factory;
    }

    private function customerTokenCache(): ?object
    {
        $CI = &get_instance();
        $cache = $this->cache ?? null;

        if (is_object($cache) && method_exists($cache, 'get')) {
            $this->cache = $cache;

            return $cache;
        }

        $cache = $CI->cache ?? null;

        if (is_object($cache) && method_exists($cache, 'get')) {
            $this->cache = $cache;

            return $cache;
        }

        try {
            $this->load->driver('cache', ['adapter' => 'file']);
        } catch (Throwable $e) {
            log_message('error', 'Privacy: cache bootstrap failed - ' . $e->getMessage());

            return null;
        }

        $cache = $this->cache ?? ($CI->cache ?? null);

        if (is_object($cache) && method_exists($cache, 'get')) {
            $this->cache = $cache;

            return $cache;
        }

        log_message('error', 'Privacy: cache driver did not expose get() after bootstrap.');

        return null;
    }
}
