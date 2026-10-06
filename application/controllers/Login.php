<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.5.0
 * ---------------------------------------------------------------------------- */

/**
 * Login controller.
 *
 * Handles the login page functionality.
 *
 * @package Controllers
 */
class Login extends EA_Controller
{
    /**
     * Login constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->library('accounts');
        $this->load->library('ldap_client');

        script_vars([
            'dest_url' => session('dest_url', site_url('calendar')),
        ]);
    }

    /**
     * Render the login page.
     */
    public function index(): void
    {
        if (session('user_id')) {
            redirect('calendar');
            return;
        }

        html_vars([
            'page_title' => lang('login'),
            'base_url' => config('base_url'),
            'dest_url' => session('dest_url', site_url('calendar')),
            'company_name' => setting('company_name'),
        ]);

        $this->load->view('pages/login');
    }

    /**
     * Validate the provided credentials and start a new session if the validation was successful.
     */
    public function validate(): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            json_response(['success' => false, 'message' => 'Method Not Allowed'], 405, ['Allow: POST']);

            return;
        }

        try {
            $request_dto = $this->authRequestDtoFactory()->buildLoginValidateRequestDto();
            $username = $request_dto->username;

            if (empty($username)) {
                json_response(
                    [
                        'success' => false,
                        'message' => 'No username value provided.',
                    ],
                    400,
                );

                return;
            }

            $password = $request_dto->password;

            if (empty($password)) {
                json_response(
                    [
                        'success' => false,
                        'message' => 'No password value provided.',
                    ],
                    400,
                );

                return;
            }

            $user_data = $this->accounts->check_login($username, $password);

            if (empty($user_data) && !$this->is_provider_ui_smoke_auth_username($username)) {
                if (!$this->is_customers_ui_smoke_auth_username($username)) {
                    $user_data = $this->ldap_client->check_login($username, $password);
                }
            }

            if (empty($user_data)) {
                json_response(
                    [
                        'success' => false,
                        'message' => lang('invalid_credentials_provided'),
                    ],
                    200,
                );

                return;
            }

            $this->session->sess_regenerate();

            session($user_data); // Save data in the session.

            json_response([
                'success' => true,
            ]);
        } catch (LdapOperationalException $e) {
            // Keep LDAP availability details out of the public authentication oracle.
            log_message('error', 'LDAP authentication unavailable; login rejected.');
            json_response(
                [
                    'success' => false,
                    'message' => lang('invalid_credentials_provided'),
                ],
                200,
            );
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
}
