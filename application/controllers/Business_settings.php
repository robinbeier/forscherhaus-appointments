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
 * Business logic controller.
 *
 * Handles general settings related operations.
 *
 * @package Controllers
 */
class Business_settings extends EA_Controller
{
    private const PAGE_SETTING_NAMES = [
        'book_advance_timeout',
        'future_booking_limit',
        'company_working_plan',
        'appointment_status_options',
    ];

    public array $allowed_setting_fields = ['id', 'name', 'value'];

    public array $optional_setting_fields = [
        //
    ];

    /**
     * Business_logic constructor.
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
        $this->load->model('users_model');

        $this->load->library('accounts');
        $this->load->library('timezones');
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
            session(['dest_url' => site_url('business_settings')]);
            redirect('login');
            return;
        }

        if (cannot('view', PRIV_SYSTEM_SETTINGS, $user_id)) {
            abort(403, 'Forbidden');
            return;
        }

        session(['dest_url' => site_url('business_settings')]);

        $role_slug = $this->roles_model->value($this->users_model->value($user_id, 'id_roles'), 'slug');

        $page_settings = $this->settings_model
            ->query()
            ->select('name, value')
            ->where_in('name', self::PAGE_SETTING_NAMES)
            ->get()
            ->result_array();

        script_vars([
            'user_id' => $user_id,
            'role_slug' => $role_slug,
            'business_settings' => $page_settings,
            'first_weekday' => setting('first_weekday'),
            'time_format' => setting('time_format'),
        ]);

        html_vars([
            'page_title' => lang('settings'),
            'active_menu' => PRIV_SYSTEM_SETTINGS,
            'user_display_name' => $this->accounts->get_user_display_name($user_id),
        ]);

        $this->load->view('pages/business_settings');
    }

    /**
     * Save general settings.
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

            $settings_request = $this->backofficeRequestDtoFactory()->buildSettingsRequestDto('business_settings');
            $settings = $settings_request->settings;

            foreach ($settings as &$setting) {
                $this->settings_model->only($setting, $this->allowed_setting_fields);
                $this->settings_model->optional($setting, $this->optional_setting_fields);
            }
            unset($setting);
            $this->settings_model->save_batch($settings);

            response();
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Apply global working plan to all providers.
     */
    public function apply_global_working_plan(): void
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

            $request_dto = $this->backofficeRequestDtoFactory()->buildEntityPayloadRequestDto('working_plan');
            $working_plan_payload = $request_dto->payload;

            krsort($working_plan_payload);

            $working_plan = json_encode(empty($working_plan_payload) ? new stdClass() : $working_plan_payload);

            if (!is_string($working_plan)) {
                throw new RuntimeException('Could not encode global working plan.');
            }

            $providers = $this->providers_model->get();

            if ($providers === []) {
                response();
                return;
            }

            $db = $this->providers_model->db;
            $owns_transaction = !$db->trans_active();
            if ($owns_transaction && !$db->trans_begin()) {
                throw new RuntimeException('Could not start global working plan transaction.');
            }

            try {
                foreach ($providers as $provider) {
                    $this->providers_model->set_setting($provider['id'], 'working_plan', $working_plan);
                }

                if (!$db->trans_status()) {
                    throw new RuntimeException('Could not apply global working plan.');
                }
                if ($owns_transaction && !$db->trans_commit()) {
                    throw new RuntimeException('Could not commit global working plan transaction.');
                }
            } catch (Throwable $exception) {
                if ($owns_transaction) {
                    $db->trans_rollback();
                }
                throw $exception;
            }

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
