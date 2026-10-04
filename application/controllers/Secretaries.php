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
 * Secretaries controller.
 *
 * Handles the secretaries related operations.
 *
 * @package Controllers
 */
class Secretaries extends EA_Controller
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
        'providers',
    ];

    private const READ_SETTING_FIELDS = ['username', 'calendar_view'];

    public array $allowed_provider_fields = ['id', 'first_name', 'last_name'];
    public array $allowed_secretary_fields = [
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
        'timezone',
        'language',
        'is_private',
        'ldap_dn',
        'id_roles',
        'settings',
        'providers',
    ];

    public array $optional_secretary_fields = [
        'providers' => [],
    ];

    public array $allowed_secretary_setting_fields = ['username', 'password', 'calendar_view'];

    public array $optional_secretary_setting_fields = [
        //
    ];

    /**
     * Secretaries constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->model('secretaries_model');
        $this->load->model('providers_model');
        $this->load->model('roles_model');

        $this->load->library('accounts');
        $this->load->library('timezones');
    }

    /**
     * Render the backend secretaries page.
     *
     * On this page secretary users will be able to manage secretaries, which are eventually selected by customers during the
     * booking process.
     */
    public function index(): void
    {
        $user_id = (int) session('user_id');

        if (!$user_id) {
            session(['dest_url' => site_url('secretaries')]);
            redirect('login');
            return;
        }

        if (cannot('view', PRIV_USERS, $user_id)) {
            abort(403, 'Forbidden');
            return;
        }

        session(['dest_url' => site_url('secretaries')]);

        $this->load->model('users_model');
        $role_slug = $this->roles_model->value($this->users_model->value($user_id, 'id_roles'), 'slug');

        $providers = $this->providers_model->get();

        foreach ($providers as &$provider) {
            $this->providers_model->only($provider, $this->allowed_provider_fields);
        }

        script_vars([
            'user_id' => $user_id,
            'role_slug' => $role_slug,
            'timezones' => $this->timezones->to_array(),
            'min_password_length' => MIN_PASSWORD_LENGTH,
            'providers' => $providers,
            'default_language' => setting('default_language'),
            'default_timezone' => setting('default_timezone'),
        ]);

        html_vars([
            'page_title' => lang('secretaries'),
            'active_menu' => PRIV_USERS,
            'user_display_name' => $this->accounts->get_user_display_name($user_id),
            'grouped_timezones' => $this->timezones->to_grouped_array(),
            'privileges' => $this->roles_model->get_permissions_by_slug($role_slug),
            'providers' => $this->providers_model->get(),
        ]);

        $this->load->view('pages/secretaries');
    }

    /**
     * Filter secretaries by the provided keyword.
     */
    public function search(): void
    {
        try {
            $user_id = (int) session('user_id');

            if (!$user_id || cannot('view', PRIV_USERS, $user_id)) {
                abort(403, 'Forbidden');
            }

            $request_dto = $this->backofficeRequestDtoFactory()->buildSearchRequestDto();

            $secretaries = $this->secretaries_model->search(
                $request_dto->keyword,
                $request_dto->limit,
                $request_dto->offset,
                $request_dto->orderBy,
            );

            foreach ($secretaries as &$secretary) {
                $this->projectReadSecretary($secretary);
            }

            json_response($secretaries);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Store a new secretary.
     */
    public function store(): void
    {
        try {
            if (cannot('add', PRIV_USERS)) {
                abort(403, 'Forbidden');
            }
            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
                abort(405, 'Method Not Allowed', ['Allow: POST']);
                return;
            }

            $request_dto = $this->backofficeRequestDtoFactory()->buildEntityPayloadRequestDto('secretary');
            $secretary = $request_dto->payload;

            $this->secretaries_model->only($secretary, $this->allowed_secretary_fields);

            $this->secretaries_model->optional($secretary, $this->optional_secretary_fields);

            $this->secretaries_model->only($secretary['settings'], $this->allowed_secretary_setting_fields);

            $this->secretaries_model->optional($secretary['settings'], $this->optional_secretary_setting_fields);

            $secretary_id = $this->secretaries_model->save($secretary);

            $secretary = $this->secretaries_model->find($secretary_id);

            json_response([
                'success' => true,
                'id' => $secretary_id,
            ]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Find a secretary.
     */
    public function find(): void
    {
        try {
            $user_id = (int) session('user_id');

            if (!$user_id || cannot('view', PRIV_USERS, $user_id)) {
                abort(403, 'Forbidden');
            }

            $request_dto = $this->backofficeRequestDtoFactory()->buildEntityIdRequestDto('secretary_id');
            $secretary_id = $request_dto->id;

            $secretaries = $this->secretaries_model->get(['id' => $secretary_id], 1);

            if (!$secretaries) {
                abort(404, 'Not Found');
            }

            $secretary = $secretaries[0];

            $this->projectReadSecretary($secretary);

            json_response($secretary);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /** Keep backoffice read responses limited to fields used by the secretary form. */
    private function projectReadSecretary(array &$secretary): void
    {
        $this->secretaries_model->only($secretary, self::READ_FIELDS);
        $settings = $secretary['settings'] ?? [];
        $this->secretaries_model->only($settings, self::READ_SETTING_FIELDS);
        $secretary['settings'] = $settings;
    }

    /**
     * Update a secretary.
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

            $request_dto = $this->backofficeRequestDtoFactory()->buildEntityPayloadRequestDto('secretary');
            $secretary = $request_dto->payload;

            $this->secretaries_model->only($secretary, $this->allowed_secretary_fields);

            $this->secretaries_model->only($secretary['settings'], $this->allowed_secretary_setting_fields);

            $this->secretaries_model->optional($secretary, $this->optional_secretary_fields);

            $secretary_id = $this->secretaries_model->save($secretary);

            $secretary = $this->secretaries_model->find($secretary_id);

            json_response([
                'success' => true,
                'id' => $secretary_id,
            ]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Remove a secretary.
     */
    public function destroy(): void
    {
        $owns_transaction = false;

        try {
            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
                abort(405, 'Method Not Allowed', ['Allow: POST']);
                return;
            }

            $user_id = (int) session('user_id');
            if (!$user_id || !$this->hasCurrentDeletePermission($user_id)) {
                abort(403, 'Forbidden');
            }

            $request_dto = $this->backofficeRequestDtoFactory()->buildEntityIdRequestDto('secretary_id');
            $secretary_id = filter_var($request_dto->id, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($secretary_id === false) {
                abort(400, 'Invalid secretary ID');
            }

            $owns_transaction = !$this->db->trans_active();
            if ($owns_transaction && !$this->db->trans_begin()) {
                throw new RuntimeException('Could not start secretary delete transaction.');
            }

            // Lock the actor and target in the shared numeric user order.
            $user_ids = array_values(array_unique([$user_id, $secretary_id]));
            sort($user_ids, SORT_NUMERIC);
            $placeholders = implode(', ', array_fill(0, count($user_ids), '?'));
            $db_debug = $this->db->db_debug;
            $this->db->db_debug = false;
            try {
                $locked_users = $this->db->query(
                    'SELECT `id`, `id_roles` FROM `' .
                        $this->db->dbprefix('users') .
                        '` WHERE `id` IN (' .
                        $placeholders .
                        ') ORDER BY `id` ASC FOR UPDATE',
                    $user_ids,
                );
            } catch (Throwable $e) {
                throw new RuntimeException('Could not lock secretary delete users.', 0, $e);
            } finally {
                $this->db->db_debug = $db_debug;
            }
            if ($locked_users === false) {
                throw new RuntimeException('Could not lock secretary delete users.');
            }

            $users_by_id = [];
            foreach ($locked_users->result_array() as $row) {
                $users_by_id[(int) $row['id']] = $row;
            }
            if (!isset($users_by_id[$user_id]) || !$this->hasCurrentDeletePermission($user_id)) {
                if ($owns_transaction) {
                    $this->db->trans_rollback();
                    $owns_transaction = false;
                }
                abort(403, 'Forbidden');
            }
            if (
                !isset($users_by_id[$secretary_id]) ||
                (int) $users_by_id[$secretary_id]['id_roles'] !== $this->secretaries_model->get_secretary_role_id()
            ) {
                if ($owns_transaction) {
                    $this->db->trans_rollback();
                    $owns_transaction = false;
                }
                abort(404, 'Secretary not found');
            }

            $this->secretaries_model->delete($secretary_id);

            if (!$this->db->trans_status()) {
                throw new RuntimeException('Could not complete secretary delete transaction.');
            }
            if ($owns_transaction && !$this->db->trans_commit()) {
                throw new RuntimeException('Could not commit secretary delete transaction.');
            }
            $owns_transaction = false;

            json_response([
                'success' => true,
            ]);
        } catch (Throwable $e) {
            if ($owns_transaction) {
                $this->db->trans_rollback();
            }
            json_exception($e);
        }
    }

    private function hasCurrentDeletePermission(int $user_id): bool
    {
        try {
            return can('delete', PRIV_USERS, $user_id);
        } catch (InvalidArgumentException $e) {
            // A principal lookup that fails after login has no authority.
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
