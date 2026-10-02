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
 * Account controller.
 *
 * Handles current account related operations.
 *
 * @package Controllers
 */
class Account extends EA_Controller
{
    public array $allowed_user_fields = [
        'id',
        'first_name',
        'last_name',
        'email',
        'mobile_number',
        'phone_number',
        'address',
        'city',
        'state',
        'zip_code',
        'notes',
        'timezone',
        'language',
        'settings',
    ];

    public array $optional_user_fields = [
        //
    ];

    public array $allowed_user_setting_fields = ['username', 'password', 'calendar_view'];

    public array $optional_user_setting_fields = [
        //
    ];

    /**
     * Account constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->model('appointments_model');
        $this->load->model('customers_model');
        $this->load->model('services_model');
        $this->load->model('providers_model');
        $this->load->model('roles_model');
        $this->load->model('settings_model');

        $this->load->library('accounts');
        $this->load->library('timezones');
    }

    /**
     * Render the settings page.
     */
    public function index(): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'GET') {
            json_response(['success' => false, 'message' => 'Method Not Allowed'], 405, ['Allow: GET']);

            return;
        }

        $user_id = (int) session('user_id');

        if (!$user_id) {
            session(['dest_url' => site_url('account')]);
            redirect('login');

            return;
        }

        if (cannot('view', PRIV_USER_SETTINGS, $user_id)) {
            abort(403, 'Forbidden');
        }

        session(['dest_url' => site_url('account')]);

        $account = $this->users_model->find($user_id);

        script_vars([
            'account' => $account,
        ]);

        html_vars([
            'page_title' => lang('settings'),
            'active_menu' => PRIV_SYSTEM_SETTINGS,
            'can_edit_account' => can('edit', PRIV_USER_SETTINGS, $user_id),
            'user_display_name' => $this->accounts->get_user_display_name($user_id),
            'grouped_timezones' => $this->timezones->to_grouped_array(),
        ]);

        $this->load->view('pages/account');
    }

    /**
     * Save general settings.
     */
    public function save(): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            json_response(['success' => false, 'message' => 'Method Not Allowed'], 405, ['Allow: POST']);

            return;
        }

        try {
            $user_id = (int) session('user_id');

            if (!$user_id || cannot('edit', PRIV_USER_SETTINGS, $user_id)) {
                throw new RuntimeException('You do not have the required permissions for this task.');
            }

            $account_request = $this->authRequestDtoFactory()->buildAccountSaveRequestDto();
            $account = $account_request->account;

            $account['id'] = $user_id;

            $this->users_model->only($account, $this->allowed_user_fields);

            $this->users_model->optional($account, $this->optional_user_fields);

            $this->users_model->only($account['settings'], $this->allowed_user_setting_fields);

            $this->users_model->optional($account['settings'], $this->optional_user_setting_fields);

            if (empty($account['password'])) {
                unset($account['password']);
            }

            $this->users_model->save($account);

            session([
                'user_email' => $account['email'],
                'username' => $account['settings']['username'],
                'timezone' => $account['timezone'],
                'language' => $account['language'],
            ]);

            response();
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Make sure the username is valid and unique in the database.
     */
    public function validate_username(): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            json_response(['success' => false, 'message' => 'Method Not Allowed'], 405, ['Allow: POST']);

            return;
        }

        try {
            $user_id = (int) session('user_id');

            if (!$user_id) {
                abort(403, 'Forbidden');

                return;
            }

            $can_edit_own = can('edit', PRIV_USER_SETTINGS, $user_id);
            $can_edit_users = can('edit', PRIV_USERS, $user_id);

            if (!$can_edit_own && !$can_edit_users) {
                abort(403, 'Forbidden');

                return;
            }

            $request_dto = $this->authRequestDtoFactory()->buildValidateUsernameRequestDto();

            $excluded_user_id = $this->username_validation_exclusion(
                $request_dto->userId,
                $user_id,
                $can_edit_own,
                $can_edit_users,
            );

            $is_valid = $this->users_model->validate_username($request_dto->username, $excluded_user_id);

            json_response([
                'is_valid' => $is_valid,
            ]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    private function username_validation_exclusion(
        string|int|null $requested_user_id,
        int $actor_id,
        bool $can_edit_own,
        bool $can_edit_users,
    ): ?int {
        $requested_id = filter_var($requested_user_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($requested_id === false) {
            return null;
        }

        if ($requested_id === $actor_id && $can_edit_own) {
            return $actor_id;
        }

        if (!$can_edit_users) {
            return null;
        }

        $target = $this->db
            ->select('roles.slug')
            ->from('users')
            ->join('roles', 'roles.id = users.id_roles')
            ->where('users.id', $requested_id)
            ->get()
            ->row_array();

        return in_array($target['slug'] ?? null, [DB_SLUG_ADMIN, DB_SLUG_SECRETARY], true) ? $requested_id : null;
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
