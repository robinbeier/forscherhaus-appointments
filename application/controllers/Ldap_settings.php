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
 * LDAP settings controller.
 *
 * Handles LDAP settings related operations.
 *
 * @package Controllers
 */
class Ldap_settings extends EA_Controller
{
    /**
     * Ldap_settings constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->model('settings_model');

        $this->load->library('accounts');
        $this->load->library('ldap_client');
    }

    /**
     * Render the settings page.
     */
    public function index(): void
    {
        session(['dest_url' => site_url('ldap_settings')]);

        $user_id = session('user_id');

        if (cannot('view', PRIV_SYSTEM_SETTINGS)) {
            if ($user_id) {
                abort(403, 'Forbidden');
            }

            redirect('login');

            return;
        }

        $role_slug = session('role_slug');

        script_vars([
            'user_id' => $user_id,
            'role_slug' => $role_slug,
            'ldap_settings' => $this->settings_model->get('name like "ldap_%"'),
            'ldap_default_filter' => LDAP_DEFAULT_FILTER,
            'ldap_default_field_mapping' => LDAP_DEFAULT_FIELD_MAPPING,
        ]);

        html_vars([
            'page_title' => lang('ldap'),
            'active_menu' => PRIV_SYSTEM_SETTINGS,
            'user_display_name' => $this->accounts->get_user_display_name($user_id),
            'roles' => $this->roles_model->get(),
        ]);

        $this->load->view('pages/ldap_settings');
    }

    /**
     * Save general settings.
     */
    public function save(): void
    {
        try {
            if (cannot('edit', PRIV_SYSTEM_SETTINGS)) {
                throw new RuntimeException('You do not have the required permissions for this task.');
            }

            $settings_request = $this->backofficeRequestDtoFactory()->buildSettingsRequestDto('ldap_settings');
            $settings = $settings_request->settings;

            foreach ($settings as $setting) {
                $existing_setting = $this->settings_model
                    ->query()
                    ->where('name', $setting['name'])
                    ->get()
                    ->row_array();

                if (!empty($existing_setting)) {
                    $setting['id'] = $existing_setting['id'];
                }

                $this->settings_model->save($setting);
            }

            response();
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Search the LDAP directory.
     *
     * @return void
     */
    public function search(): void
    {
        try {
            if (cannot('edit', PRIV_SYSTEM_SETTINGS)) {
                throw new RuntimeException('You do not have the required permissions for this task.');
            }

            if (!extension_loaded('ldap')) {
                throw new RuntimeException('The LDAP extension is not loaded.');
            }

            $request_dto = $this->integrationsRequestDtoFactory()->buildLdapSearchRequestDto();
            $keyword = $request_dto->keyword;

            $entries = $this->ldap_client->search($keyword);

            json_response($entries);
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

    private function integrationsRequestDtoFactory(): Integrations_request_dto_factory
    {
        if (
            isset($this->integrations_request_dto_factory) &&
            $this->integrations_request_dto_factory instanceof Integrations_request_dto_factory
        ) {
            return $this->integrations_request_dto_factory;
        }

        /** @var EA_Controller|CI_Controller $CI */
        $CI = &get_instance();

        if (
            !isset($CI->integrations_request_dto_factory) ||
            !$CI->integrations_request_dto_factory instanceof Integrations_request_dto_factory
        ) {
            $CI->load->library('integrations_request_dto_factory');
        }

        $this->integrations_request_dto_factory = $CI->integrations_request_dto_factory;

        return $this->integrations_request_dto_factory;
    }
}
