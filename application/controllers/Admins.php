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
 * Admins controller.
 *
 * Handles the admins related operations.
 *
 * @package Controllers
 */
class Admins extends EA_Controller
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
        'timezone',
        'language',
        'ldap_dn',
        'settings',
    ];

    private const READ_SETTING_FIELDS = ['username', 'calendar_view'];

    public array $allowed_admin_fields = [
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
        'ldap_dn',
        'settings',
    ];

    public array $optional_admin_fields = [
        //
    ];

    public array $allowed_admin_setting_fields = ['username', 'password', 'calendar_view'];

    public array $optional_admin_setting_fields = [
        //
    ];

    /**
     * Admins constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->model('admins_model');
        $this->load->model('roles_model');

        $this->load->library('accounts');
        $this->load->library('timezones');
    }

    /**
     * Render the backend admins page.
     *
     * On this page admin users will be able to manage admins, which are eventually selected by customers during the
     * booking process.
     */
    public function index(): void
    {
        $user_id = (int) session('user_id');

        if (!$user_id || cannot('view', PRIV_USERS, $user_id)) {
            if ($user_id) {
                abort(403, 'Forbidden');
            }

            redirect('login');

            return;
        }

        session(['dest_url' => site_url('admins')]);

        $this->load->model('users_model');
        $role_slug = $this->roles_model->value($this->users_model->value($user_id, 'id_roles'), 'slug');

        script_vars([
            'user_id' => $user_id,
            'role_slug' => $role_slug,
            'timezones' => $this->timezones->to_array(),
            'min_password_length' => MIN_PASSWORD_LENGTH,
            'default_language' => setting('default_language'),
            'default_timezone' => setting('default_timezone'),
        ]);

        html_vars([
            'page_title' => lang('admins'),
            'active_menu' => PRIV_USERS,
            'user_display_name' => $this->accounts->get_user_display_name($user_id),
            'grouped_timezones' => $this->timezones->to_grouped_array(),
            'privileges' => $this->roles_model->get_permissions_by_slug($role_slug),
        ]);

        $this->load->view('pages/admins');
    }

    /**
     * Filter admins by the provided keyword.
     */
    public function search(): void
    {
        try {
            $user_id = (int) session('user_id');

            if (!$user_id || cannot('view', PRIV_USERS, $user_id)) {
                abort(403, 'Forbidden');
            }

            $request_dto = $this->backofficeRequestDtoFactory()->buildSearchRequestDto();

            $admins = $this->admins_model->search(
                $request_dto->keyword,
                $request_dto->limit,
                $request_dto->offset,
                $request_dto->orderBy,
            );

            foreach ($admins as &$admin) {
                $this->projectReadAdmin($admin);
            }

            json_response($admins);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Store a new admin.
     */
    public function store(): void
    {
        $owns_transaction = false;
        $client_validation_error = null;
        $db_debug = $this->db->db_debug;
        $this->db->db_debug = false;

        try {
            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
                abort(405, 'Method Not Allowed', ['Allow: POST']);
                return;
            }

            $user_id = (int) session('user_id');

            if (!$user_id || !$this->hasCurrentAddPermission($user_id)) {
                abort(403, 'Forbidden');
            }

            $request_dto = $this->backofficeRequestDtoFactory()->buildEntityPayloadRequestDto('admin');
            $admin = $request_dto->payload;

            if (array_key_exists('id', $admin)) {
                if ($admin['id'] !== '') {
                    abort(400, 'Invalid admin create');
                }

                unset($admin['id']);
            }

            $this->admins_model->only($admin, $this->allowed_admin_fields);

            $this->admins_model->optional($admin, $this->optional_admin_fields);

            $this->admins_model->only($admin['settings'], $this->allowed_admin_setting_fields);

            $this->admins_model->optional($admin['settings'], $this->optional_admin_setting_fields);

            $owns_transaction = !$this->db->trans_active();
            if ($owns_transaction && !$this->db->trans_begin()) {
                throw new RuntimeException('Could not start admin create transaction.');
            }

            $locked_actor = $this->db->query(
                'SELECT `id_roles` FROM `' . $this->db->dbprefix('users') . '` WHERE `id` = ? FOR UPDATE',
                [$user_id],
            );
            if ($locked_actor === false) {
                throw new RuntimeException('Could not lock admin create actor.');
            }
            $actor_row = $locked_actor->row_array();
            $locked_role = $actor_row
                ? $this->db->query(
                    'SELECT `users` FROM `' . $this->db->dbprefix('roles') . '` WHERE `id` = ? FOR UPDATE',
                    [(int) $actor_row['id_roles']],
                )
                : null;
            if ($locked_role === false) {
                throw new RuntimeException('Could not lock admin create role.');
            }
            $role_row = $locked_role?->row_array();
            if (!$actor_row || !$role_row || !((int) $role_row['users'] & PRIV_ADD)) {
                if ($owns_transaction) {
                    $this->db->trans_rollback();
                    $owns_transaction = false;
                }
                abort(403, 'Forbidden');
                return;
            }

            // Model input validation is safe to report; infrastructure and write errors are not.
            try {
                $this->admins_model->validate($admin);
            } catch (InvalidArgumentException $e) {
                $client_validation_error = $e;
                throw $e;
            }

            try {
                $admin_id = $this->admins_model->save($admin);
            } catch (InvalidArgumentException $e) {
                // Save validates again; a concurrent duplicate is still safe to explain.
                if (
                    in_array(
                        $e->getMessage(),
                        [
                            'The provided username is already in use, please use a different one.',
                            'The provided email address is already in use, please use a different one.',
                        ],
                        true,
                    )
                ) {
                    $client_validation_error = $e;
                }
                throw $e;
            }
            if (!$this->db->trans_status()) {
                throw new RuntimeException('Could not complete admin create transaction.');
            }
            if ($owns_transaction && !$this->db->trans_commit()) {
                throw new RuntimeException('Could not commit admin create transaction.');
            }
            $owns_transaction = false;

            json_response([
                'success' => true,
                'id' => $admin_id,
            ]);
        } catch (Throwable $e) {
            if ($owns_transaction) {
                try {
                    if (!$this->db->trans_rollback()) {
                        log_message('error', 'Admin creation rollback could not be confirmed.');
                    }
                } catch (Throwable $rollback_error) {
                    log_message('error', 'Admin creation rollback could not be confirmed.');
                }
            }

            json_exception($client_validation_error ?? new RuntimeException('Admin creation failed.', 0, $e));
        } finally {
            $this->db->db_debug = $db_debug;
        }
    }

    /**
     * Find an admin.
     */
    public function find(): void
    {
        try {
            $user_id = (int) session('user_id');

            if (!$user_id || cannot('view', PRIV_USERS, $user_id)) {
                abort(403, 'Forbidden');
            }

            $request_dto = $this->backofficeRequestDtoFactory()->buildEntityIdRequestDto('admin_id');
            $admin_id = $request_dto->id;

            $admins = $this->admins_model->get(['id' => $admin_id], 1);

            if (!$admins) {
                abort(404, 'Not Found');
            }

            $admin = $admins[0];

            $this->projectReadAdmin($admin);

            json_response($admin);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /** Keep backoffice read responses limited to fields used by the admin form. */
    private function projectReadAdmin(array &$admin): void
    {
        $this->admins_model->only($admin, self::READ_FIELDS);
        $settings = $admin['settings'] ?? [];
        $this->admins_model->only($settings, self::READ_SETTING_FIELDS);
        $admin['settings'] = $settings;
    }

    /**
     * Update an admin.
     */
    public function update(): void
    {
        try {
            if (cannot('edit', PRIV_USERS)) {
                abort(403, 'Forbidden');
            }
            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
                abort(405, 'Method Not Allowed', ['Allow: POST']);
                return;
            }

            $request_dto = $this->backofficeRequestDtoFactory()->buildEntityPayloadRequestDto('admin');
            $admin = $request_dto->payload;

            $this->admins_model->only($admin, $this->allowed_admin_fields);

            $this->admins_model->optional($admin, $this->optional_admin_fields);

            $this->admins_model->only($admin['settings'], $this->allowed_admin_setting_fields);

            $this->admins_model->optional($admin['settings'], $this->optional_admin_setting_fields);

            $admin_id = $this->admins_model->save($admin);

            $admin = $this->admins_model->find($admin_id);

            json_response([
                'success' => true,
                'id' => $admin_id,
            ]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Remove an admin.
     */
    public function destroy(): void
    {
        try {
            if (cannot('delete', PRIV_USERS)) {
                abort(403, 'Forbidden');
            }
            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
                abort(405, 'Method Not Allowed', ['Allow: POST']);
                return;
            }

            $request_dto = $this->backofficeRequestDtoFactory()->buildEntityIdRequestDto('admin_id');
            $admin_id = $request_dto->id;

            $this->admins_model->delete($admin_id);

            json_response([
                'success' => true,
            ]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    private function hasCurrentAddPermission(int $user_id): bool
    {
        try {
            return can('add', PRIV_USERS, $user_id);
        } catch (InvalidArgumentException $e) {
            return false;
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
