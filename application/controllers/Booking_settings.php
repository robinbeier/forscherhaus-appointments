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
 * Booking settings controller.
 *
 * Handles booking settings related operations.
 *
 * @package Controllers
 */
class Booking_settings extends EA_Controller
{
    private const BOOKING_SETTING_NAMES = [
        'display_first_name',
        'require_first_name',
        'display_last_name',
        'require_last_name',
        'display_email',
        'require_email',
        'display_phone_number',
        'require_phone_number',
        'display_address',
        'require_address',
        'display_city',
        'require_city',
        'display_zip_code',
        'require_zip_code',
        'display_notes',
        'require_notes',
        'label_custom_field_1',
        'display_custom_field_1',
        'require_custom_field_1',
        'label_custom_field_2',
        'display_custom_field_2',
        'require_custom_field_2',
        'label_custom_field_3',
        'display_custom_field_3',
        'require_custom_field_3',
        'label_custom_field_4',
        'display_custom_field_4',
        'require_custom_field_4',
        'label_custom_field_5',
        'display_custom_field_5',
        'require_custom_field_5',
        'limit_customer_access',
        'require_captcha',
        'display_any_provider',
        'display_login_button',
        'display_delete_personal_information',
        'disable_booking',
        'disable_booking_message',
    ];

    /**
     * Booking_settings constructor.
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
            session(['dest_url' => site_url('booking_settings')]);
            redirect('login');
            return;
        }

        if (cannot('view', PRIV_SYSTEM_SETTINGS, $user_id)) {
            abort(403, 'Forbidden');
            return;
        }

        session(['dest_url' => site_url('booking_settings')]);

        $role_slug = $this->roles_model->value($this->users_model->value($user_id, 'id_roles'), 'slug');

        $booking_settings = $this->settings_model
            ->query()
            ->select('name, value')
            ->where_in('name', self::BOOKING_SETTING_NAMES)
            ->get()
            ->result_array();

        script_vars([
            'user_id' => $user_id,
            'role_slug' => $role_slug,
            'booking_settings' => $booking_settings,
        ]);

        html_vars([
            'page_title' => lang('settings'),
            'active_menu' => PRIV_SYSTEM_SETTINGS,
            'user_display_name' => $this->accounts->get_user_display_name($user_id),
        ]);

        $this->load->view('pages/booking_settings');
    }

    /**
     * Save booking settings.
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

            $settings_request = $this->backofficeRequestDtoFactory()->buildSettingsRequestDto('booking_settings');
            $settings = [];
            $seen_names = [];

            foreach ($settings_request->settings as $setting) {
                if (
                    !is_array($setting) ||
                    !is_string($setting['name'] ?? null) ||
                    !in_array($setting['name'], self::BOOKING_SETTING_NAMES, true) ||
                    !array_key_exists('value', $setting) ||
                    !is_scalar($setting['value']) ||
                    isset($seen_names[$setting['name']])
                ) {
                    throw new InvalidArgumentException('Invalid booking setting.');
                }

                $seen_names[$setting['name']] = true;
                $settings[] = ['name' => $setting['name'], 'value' => $setting['value']];
            }

            $db = $this->settings_model->db;
            $owns_transaction = !$db->trans_active();
            if ($owns_transaction && !$db->trans_begin()) {
                throw new RuntimeException('Could not start booking settings transaction.');
            }
            try {
                if (!$this->lockAndCheckCurrentEditPermission($user_id, $db)) {
                    if ($owns_transaction) {
                        $db->trans_rollback();
                        $owns_transaction = false;
                    }
                    abort(403, 'Forbidden');
                    return;
                }
                $this->settings_model->save_batch($settings);
                if (!$db->trans_status()) {
                    throw new RuntimeException('Could not save booking settings.');
                }
                if ($owns_transaction && !$db->trans_commit()) {
                    throw new RuntimeException('Could not commit booking settings transaction.');
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

    private function lockAndCheckCurrentEditPermission(int $user_id, object $db): bool
    {
        $actor_query = $db->query('SELECT `id_roles` FROM `' . $db->dbprefix('users') . '` WHERE `id` = ? FOR UPDATE', [
            $user_id,
        ]);
        if ($actor_query === false) {
            throw new RuntimeException('Could not lock settings actor.');
        }
        $actor = $actor_query->row_array();
        if (!$actor) {
            return false;
        }
        $role_query = $db->query(
            'SELECT `system_settings` FROM `' . $db->dbprefix('roles') . '` WHERE `id` = ? FOR UPDATE',
            [(int) $actor['id_roles']],
        );
        if ($role_query === false) {
            throw new RuntimeException('Could not lock settings role.');
        }
        $role = $role_query->row_array();
        $mask = (int) ($role['system_settings'] ?? 0);
        $known_mask = PRIV_VIEW | PRIV_ADD | PRIV_EDIT | PRIV_DELETE;
        return is_array($role) && $mask >= 0 && ($mask & ~$known_mask) === 0 && ($mask & PRIV_EDIT) === PRIV_EDIT;
    }
}
