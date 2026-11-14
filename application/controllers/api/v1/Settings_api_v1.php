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
 * Settings API v1 controller.
 *
 * @package Controllers
 */
class Settings_api_v1 extends EA_Controller
{
    /** @var list<string> Settings that are safe to expose through this API. */
    private const PUBLIC_SETTING_NAMES = [
        'appointment_status_options',
        'book_advance_timeout',
        'company_color',
        'company_link',
        'company_logo',
        'company_name',
        'company_working_plan',
        'cookie_notice_content',
        'dashboard_conflict_threshold',
        'date_format',
        'default_language',
        'default_timezone',
        'disable_booking',
        'disable_booking_message',
        'display_address',
        'display_any_provider',
        'display_city',
        'display_cookie_notice',
        'display_delete_personal_information',
        'display_email',
        'display_first_name',
        'display_last_name',
        'display_login_button',
        'display_notes',
        'display_custom_field_1',
        'display_custom_field_2',
        'display_custom_field_3',
        'display_custom_field_4',
        'display_custom_field_5',
        'display_phone_number',
        'display_privacy_policy',
        'display_terms_and_conditions',
        'display_zip_code',
        'first_weekday',
        'future_booking_limit',
        'google_analytics_code',
        'language',
        'label_custom_field_1',
        'label_custom_field_2',
        'label_custom_field_3',
        'label_custom_field_4',
        'label_custom_field_5',
        'ldap_host',
        'ldap_is_active',
        'ldap_port',
        'limit_customer_access',
        'limit_customer_visibility',
        'matomo_analytics_site_id',
        'matomo_analytics_url',
        'privacy_policy_content',
        'require_address',
        'require_captcha',
        'require_city',
        'require_custom_field_1',
        'require_custom_field_2',
        'require_custom_field_3',
        'require_custom_field_4',
        'require_custom_field_5',
        'require_email',
        'require_first_name',
        'require_last_name',
        'require_notes',
        'require_phone_number',
        'require_zip_code',
        'status_options',
        'terms_and_conditions_content',
        'theme',
        'time_format',
    ];

    /**
     * Settings_api_v1 constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->library('api');
        $this->load->library('api_request_dto_factory');

        $this->api->auth();

        $this->api->model('settings_model');
    }

    /**
     * Get a setting collection.
     */
    public function index(): void
    {
        if (!$this->enforceReadMethod()) {
            return;
        }

        try {
            $keyword = $this->api->request_keyword();

            $limit = $this->api->request_limit();

            $offset = $this->api->request_offset();

            $order_by = $this->api->request_order_by();

            $fields = $this->api->request_fields();

            // Fetch before pagination so removing secret rows does not create
            // empty pages or skip public rows that follow them in the order.
            $settings = empty($keyword)
                ? $this->settings_model->get(null, null, null, $order_by)
                : $this->settings_model->search($keyword, null, null, $order_by);

            $settings = array_values(
                array_filter(
                    $settings,
                    fn(array $setting): bool => $this->isPublicSettingName((string) ($setting['name'] ?? '')),
                ),
            );
            $settings = array_slice($settings, $offset, $limit);

            foreach ($settings as &$setting) {
                $this->settings_model->api_encode($setting);

                if (!empty($fields)) {
                    $this->settings_model->only($setting, $fields);
                }
            }

            json_response($settings);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Get a setting value by name.
     *
     * @param string $name Setting name.
     */
    public function show(string $name): void
    {
        if (!$this->enforceReadMethod()) {
            return;
        }

        if (!$this->isPublicSettingName($name)) {
            response('', 404);

            return;
        }

        try {
            $value = setting($name);

            json_response([
                'name' => $name,
                'value' => $value,
            ]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Set a setting value by name.
     *
     * @param string $name Setting name.
     */
    public function update(string $name): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'PUT') {
            response('', 405, ['Allow: PUT']);

            return;
        }

        if (!$this->isPublicSettingName($name)) {
            response('', 404);

            return;
        }

        try {
            $request_dto = $this->apiRequestDtoFactory()->buildSettingsUpdateDto();
            $value = $request_dto->value;

            setting([$name => $value]);

            json_response([
                'name' => $name,
                'value' => setting($name),
            ]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    private function enforceReadMethod(): bool
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'GET') {
            return true;
        }

        response('', 405, ['Allow: GET']);

        return false;
    }

    /**
     * Settings API responses are a public administrative surface; reusable
     * credentials and secret material must never be readable or echoed.
     */
    private function isPublicSettingName(string $name): bool
    {
        if (in_array($name, self::PUBLIC_SETTING_NAMES, true)) {
            return true;
        }

        // The isolated defense-cycle creates run-scoped synthetic settings.
        // This test-only namespace is never accepted by production requests.
        return defined('ENVIRONMENT') &&
            ENVIRONMENT === 'testing' &&
            preg_match('/^defense_[a-f0-9]{16}_setting_[a-z0-9_-]+$/D', $name) === 1;
    }

    private function apiRequestDtoFactory(): Api_request_dto_factory
    {
        if (
            isset($this->api_request_dto_factory) &&
            $this->api_request_dto_factory instanceof Api_request_dto_factory
        ) {
            return $this->api_request_dto_factory;
        }

        /** @var EA_Controller|CI_Controller $CI */
        $CI = &get_instance();

        if (!isset($CI->api_request_dto_factory) || !$CI->api_request_dto_factory instanceof Api_request_dto_factory) {
            $CI->load->library('api_request_dto_factory');
        }

        $this->api_request_dto_factory = $CI->api_request_dto_factory;

        return $this->api_request_dto_factory;
    }
}
