<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.0.0
 * ---------------------------------------------------------------------------- */

/**
 * Recovery controller.
 *
 * Handles the recovery page functionality.
 *
 * @package Controllers
 */
class Recovery extends EA_Controller
{
    /**
     * User constructor.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Display the password recovery page.
     */
    public function index(): void
    {
        $company_name = setting('company_name');

        html_vars([
            'page_title' => lang('forgot_your_password'),
            'dest_url' => session('dest_url', site_url('backend')),
            'company_name' => $company_name,
        ]);

        $this->load->view('pages/recovery');
    }

    /**
     * Recover the user password and notify the user via email.
     */
    public function perform(): void
    {
        try {
            $request_dto = $this->authRequestDtoFactory()->buildRecoveryRequestDto();
            $username = trim((string) $request_dto->username);
            $email = trim((string) $request_dto->email);

            $this->contactRobinResponse($this->getContactRobinResponseStatus($username, $email));
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Determine the HTTP status for neutralized recovery requests.
     */
    protected function getContactRobinResponseStatus(string $username, string $email): int
    {
        return $username === '' || $email === '' ? 400 : 200;
    }

    /**
     * Return the neutral recovery response used for disabled self-service password recovery.
     */
    protected function contactRobinResponse(int $status = 200): void
    {
        json_response(
            [
                'success' => $status < 400,
                'message' => lang('password_recovery_contact_robin'),
            ],
            $status,
        );
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
}
