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

            $customer_id = $cache->get('customer-token-' . $customer_token);

            if (empty($customer_id)) {
                throw new InvalidArgumentException(
                    'Customer ID does not exist, please reload the page ' . 'and try again.',
                );
            }

            $this->customers_model->delete($customer_id);

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
