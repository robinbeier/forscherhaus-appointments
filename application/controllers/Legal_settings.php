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
 * Client form controller.
 *
 * Handles legal contents settings related operations.
 *
 * @package Controllers
 */
class Legal_settings extends EA_Controller
{
    private const LEGAL_SETTING_NAMES = [
        'display_cookie_notice',
        'cookie_notice_content',
        'display_terms_and_conditions',
        'terms_and_conditions_content',
        'display_privacy_policy',
        'privacy_policy_content',
    ];

    /**
     * Legal_contents constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->model('settings_model');
        $this->load->model('users_model');
        $this->load->model('roles_model');

        $this->load->library('accounts');
    }

    /**
     * Render the settings page.
     */
    public function index(): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'GET') {
            abort(405, 'Method Not Allowed', ['Allow: GET']);
            return;
        }

        $user_id = (int) session('user_id');

        if (!$user_id) {
            session(['dest_url' => site_url('legal_settings')]);
            redirect('login');
            return;
        }

        if (cannot('view', PRIV_SYSTEM_SETTINGS, $user_id)) {
            abort(403, 'Forbidden');
            return;
        }

        session(['dest_url' => site_url('legal_settings')]);

        $role_slug = $this->roles_model->value($this->users_model->value($user_id, 'id_roles'), 'slug');

        $legal_settings = $this->settings_model
            ->query()
            ->select('name, value')
            ->where_in('name', self::LEGAL_SETTING_NAMES)
            ->get()
            ->result_array();

        script_vars([
            'user_id' => $user_id,
            'role_slug' => $role_slug,
            'legal_settings' => $legal_settings,
        ]);

        html_vars([
            'page_title' => lang('settings'),
            'active_menu' => PRIV_SYSTEM_SETTINGS,
            'user_display_name' => $this->accounts->get_user_display_name($user_id),
        ]);

        $this->load->view('pages/legal_settings');
    }

    /**
     * Save legal settings.
     */
    public function save(): void
    {
        try {
            $user_id = (int) session('user_id');
            if (!$user_id || cannot('edit', PRIV_SYSTEM_SETTINGS, $user_id)) {
                throw new RuntimeException('You do not have the required permissions for this task.');
            }
            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
                abort(405, 'Method Not Allowed', ['Allow: POST']);
                return;
            }

            $settings_request = $this->backofficeRequestDtoFactory()->buildSettingsRequestDto('legal_settings');
            $settings = [];
            $seen_names = [];

            foreach ($settings_request->settings as $setting) {
                if (
                    !is_array($setting) ||
                    !is_string($setting['name'] ?? null) ||
                    !in_array($setting['name'], self::LEGAL_SETTING_NAMES, true) ||
                    !array_key_exists('value', $setting) ||
                    !is_scalar($setting['value']) ||
                    isset($seen_names[$setting['name']])
                ) {
                    throw new InvalidArgumentException('Invalid legal setting.');
                }

                $seen_names[$setting['name']] = true;
                $settings[] = ['name' => $setting['name'], 'value' => $setting['value']];
            }

            $this->settings_model->save_batch($settings);

            response();
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    private function backofficeRequestDtoFactory(): Backoffice_request_dto_factory
    {
        if (
            isset($this->backoffice_request_dto_factory) &&
            $this->backoffice_request_dto_factory instanceof Backoffice_request_dto_factory
        ) {
            return $this->backoffice_request_dto_factory;
        }

        /** @var EA_Controller|CI_Controller $CI */
        $CI = &get_instance();

        if (
            !isset($CI->backoffice_request_dto_factory) ||
            !$CI->backoffice_request_dto_factory instanceof Backoffice_request_dto_factory
        ) {
            $CI->load->library('backoffice_request_dto_factory');
        }

        $this->backoffice_request_dto_factory = $CI->backoffice_request_dto_factory;

        return $this->backoffice_request_dto_factory;
    }
}
