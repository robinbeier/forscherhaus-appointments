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
 * Providers controller.
 *
 * Handles the providers related operations.
 *
 * @package Controllers
 */
class Providers extends EA_Controller
{
    private const READ_FIELDS = [
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
        'room',
        'class_size_default',
        'timezone',
        'language',
        'is_private',
        'ldap_dn',
        'settings',
        'services',
    ];

    private const READ_SETTING_FIELDS = ['username', 'working_plan', 'working_plan_exceptions', 'calendar_view'];

    public array $allowed_provider_fields = [
        'id',
        'first_name',
        'last_name',
        'email',
        'alt_number',
        'phone_number',
        'address',
        'city',
        'state',
        'zip_code',
        'notes',
        'room',
        'class_size_default',
        'timezone',
        'language',
        'is_private',
        'ldap_dn',
        'settings',
        'services',
    ];

    public array $optional_provider_fields = [
        'services' => [],
    ];

    public array $allowed_provider_setting_fields = [
        'username',
        'password',
        'working_plan',
        'working_plan_exceptions',
        'calendar_view',
    ];

    public array $optional_provider_setting_fields = [
        'working_plan' => null,
        'working_plan_exceptions' => '{}',
    ];

    public array $allowed_service_fields = ['id', 'name'];

    /**
     * Providers constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->model('providers_model');
        $this->load->model('services_model');
        $this->load->model('roles_model');

        $this->load->library('accounts');
        $this->load->library('timezones');

        $this->optional_provider_setting_fields['working_plan'] = setting('company_working_plan');
    }

    /**
     * Render the backend providers page.
     *
     * On this page admin users will be able to manage providers, which are eventually selected by customers during the
     * booking process.
     */
    public function index(): void
    {
        session(['dest_url' => site_url('providers')]);

        $user_id = session('user_id');

        if (!$user_id || cannot('view', PRIV_USERS, (int) $user_id)) {
            if ($user_id) {
                abort(403, 'Forbidden');
            }

            redirect('login');

            return;
        }

        $this->load->model('users_model');
        $role_slug = $this->roles_model->value($this->users_model->value((int) $user_id, 'id_roles'), 'slug');

        $services = $this->services_model->get();

        foreach ($services as &$service) {
            $this->services_model->only($service, $this->allowed_service_fields);
        }

        script_vars([
            'user_id' => $user_id,
            'role_slug' => $role_slug,
            'company_working_plan' => setting('company_working_plan'),
            'date_format' => setting('date_format'),
            'time_format' => setting('time_format'),
            'first_weekday' => setting('first_weekday'),
            'min_password_length' => MIN_PASSWORD_LENGTH,
            'timezones' => $this->timezones->to_array(),
            'services' => $services,
            'default_language' => setting('default_language'),
            'default_timezone' => setting('default_timezone'),
        ]);

        html_vars([
            'page_title' => lang('providers'),
            'active_menu' => PRIV_USERS,
            'user_display_name' => $this->accounts->get_user_display_name($user_id),
            'grouped_timezones' => $this->timezones->to_grouped_array(),
            'privileges' => $this->roles_model->get_permissions_by_slug($role_slug),
            'services' => $this->services_model->get(),
        ]);

        $this->load->view('pages/providers');
    }

    /**
     * Filter providers by the provided keyword.
     */
    public function search(): void
    {
        try {
            if (!session('user_id') || cannot('view', PRIV_USERS, (int) session('user_id'))) {
                abort(403, 'Forbidden');
            }

            $request_dto = $this->backofficeRequestDtoFactory()->buildSearchRequestDto();

            $providers = $this->providers_model->search(
                $request_dto->keyword,
                $request_dto->limit,
                $request_dto->offset,
                $request_dto->orderBy,
            );

            foreach ($providers as &$provider) {
                $this->projectReadProvider($provider);
            }

            json_response($providers);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Store a new provider.
     */
    public function store(): void
    {
        try {
            if (cannot('add', PRIV_USERS)) {
                abort(403, 'Forbidden');
            }

            $request_dto = $this->backofficeRequestDtoFactory()->buildEntityPayloadRequestDto('provider');
            $provider = $request_dto->payload;

            $this->providers_model->only($provider, $this->allowed_provider_fields);

            $this->providers_model->only($provider['settings'], $this->allowed_provider_setting_fields);

            $this->providers_model->optional($provider, $this->optional_provider_fields);

            $this->providers_model->optional($provider['settings'], $this->optional_provider_setting_fields);

            $class_size_default = $provider['class_size_default'] ?? null;
            $provider['class_size_default'] =
                $class_size_default === '' || $class_size_default === null ? null : (int) $class_size_default;

            $provider_id = $this->providers_model->save($provider);

            $provider = $this->providers_model->find($provider_id);

            json_response([
                'success' => true,
                'id' => $provider_id,
            ]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Find a provider.
     */
    public function find(): void
    {
        try {
            if (!session('user_id') || cannot('view', PRIV_USERS, (int) session('user_id'))) {
                abort(403, 'Forbidden');
            }

            $request_dto = $this->backofficeRequestDtoFactory()->buildEntityIdRequestDto('provider_id');
            $provider_id = $request_dto->id;

            $providers = $this->providers_model->get(['id' => $provider_id], 1);

            if (!$providers) {
                abort(404, 'Not Found');
            }

            $provider = $providers[0];

            $this->projectReadProvider($provider);

            json_response($provider);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /** Keep backoffice read responses limited to fields used by the provider form. */
    private function projectReadProvider(array &$provider): void
    {
        $this->providers_model->only($provider, self::READ_FIELDS);
        $settings = $provider['settings'] ?? [];
        $this->providers_model->only($settings, self::READ_SETTING_FIELDS);
        $provider['settings'] = $settings;
    }

    /**
     * Update a provider.
     */
    public function update(): void
    {
        $owns_transaction = false;

        try {
            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
                abort(405, 'Method Not Allowed', ['Allow: POST']);

                return;
            }

            $user_id = (int) session('user_id');

            if (!$user_id || cannot('edit', PRIV_USERS, $user_id)) {
                abort(403, 'Forbidden');
            }

            $request_dto = $this->backofficeRequestDtoFactory()->buildEntityPayloadRequestDto('provider');
            $provider = $request_dto->payload;

            $provider_id = filter_var($provider['id'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);

            if ($provider_id === false || array_key_exists('id_roles', $provider)) {
                abort(400, 'Invalid provider update');
            }

            $provider['id'] = $provider_id;

            $owns_transaction = !$this->db->trans_active();

            if ($owns_transaction && !$this->db->trans_begin()) {
                throw new RuntimeException('Could not start provider update transaction.');
            }

            // Lock both users in the shared numeric order before checking either role.
            $user_ids = array_values(array_unique([$user_id, $provider_id]));
            sort($user_ids, SORT_NUMERIC);
            $placeholders = implode(', ', array_fill(0, count($user_ids), '?'));
            $locked_users = $this->db->query(
                'SELECT `id`, `id_roles` FROM `' .
                    $this->db->dbprefix('users') .
                    '` WHERE `id` IN (' .
                    $placeholders .
                    ') ORDER BY `id` ASC FOR UPDATE',
                $user_ids,
            );

            if ($locked_users === false) {
                throw new RuntimeException('Could not lock provider update users.');
            }

            $users_by_id = [];

            foreach ($locked_users->result_array() as $row) {
                $users_by_id[(int) $row['id']] = $row;
            }

            if (!isset($users_by_id[$user_id]) || cannot('edit', PRIV_USERS, $user_id)) {
                if ($owns_transaction) {
                    $this->db->trans_rollback();
                    $owns_transaction = false;
                }

                abort(403, 'Forbidden');
            }

            if (
                !isset($users_by_id[$provider_id]) ||
                (int) $users_by_id[$provider_id]['id_roles'] !== $this->providers_model->get_provider_role_id()
            ) {
                if ($owns_transaction) {
                    $this->db->trans_rollback();
                    $owns_transaction = false;
                }

                abort(404, 'Provider not found');
            }

            $this->providers_model->only($provider, $this->allowed_provider_fields);

            $this->providers_model->only($provider['settings'], $this->allowed_provider_setting_fields);

            $this->providers_model->optional($provider, $this->optional_provider_fields);

            $this->providers_model->optional($provider['settings'], $this->optional_provider_setting_fields);

            $class_size_default = $provider['class_size_default'] ?? null;
            $provider['class_size_default'] =
                $class_size_default === '' || $class_size_default === null ? null : (int) $class_size_default;

            $provider_id = $this->providers_model->save($provider);

            $provider = $this->providers_model->find($provider_id);

            if (!$this->db->trans_status()) {
                throw new RuntimeException('Could not complete provider update transaction.');
            }

            if ($owns_transaction && !$this->db->trans_commit()) {
                throw new RuntimeException('Could not commit provider update transaction.');
            }

            $owns_transaction = false;

            json_response([
                'success' => true,
                'id' => $provider_id,
            ]);
        } catch (Throwable $e) {
            if ($owns_transaction) {
                $this->db->trans_rollback();
            }

            json_exception($e);
        }
    }

    /**
     * Remove a provider.
     */
    public function destroy(): void
    {
        try {
            if (cannot('delete', PRIV_USERS)) {
                abort(403, 'Forbidden');
            }

            $request_dto = $this->backofficeRequestDtoFactory()->buildEntityIdRequestDto('provider_id');
            $provider_id = $request_dto->id;

            $provider = $this->providers_model->find($provider_id);

            $this->providers_model->delete($provider_id);

            json_response([
                'success' => true,
            ]);
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
