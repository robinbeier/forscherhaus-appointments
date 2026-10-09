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
 * Blocked_periods controller.
 *
 * Handles the blocked-periods related operations.
 *
 * @package Controllers
 */
class Blocked_periods extends EA_Controller
{
    private const READ_FIELDS = ['id', 'name', 'start_datetime', 'end_datetime', 'notes'];

    public array $allowed_blocked_period_fields = ['id', 'name', 'start_datetime', 'end_datetime', 'notes'];

    public array $optional_blocked_period_fields = [
        //
    ];

    /**
     * Blocked_periods constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->model('blocked_periods_model');
        $this->load->model('roles_model');
        $this->load->model('users_model');

        $this->load->library('accounts');
        $this->load->library('timezones');
    }

    /**
     * Render the backend blocked-periods page.
     *
     * On this page admin users will be able to manage blocked-periods, which are eventually selected by customers during the
     * booking process.
     */
    public function index(): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'GET') {
            abort(405, 'Method Not Allowed', ['Allow: GET']);
            return;
        }

        $user_id = (int) session('user_id');

        if (!$user_id) {
            session(['dest_url' => site_url('blocked_periods')]);
            redirect('login');
            return;
        }

        if (cannot('view', PRIV_BLOCKED_PERIODS, $user_id)) {
            abort(403, 'Forbidden');
            return;
        }

        session(['dest_url' => site_url('blocked_periods')]);

        $role_slug = $this->roles_model->value($this->users_model->value($user_id, 'id_roles'), 'slug');

        script_vars([
            'user_id' => $user_id,
            'role_slug' => $role_slug,
            'date_format' => setting('date_format'),
            'time_format' => setting('time_format'),
            'first_weekday' => setting('first_weekday'),
        ]);

        html_vars([
            'page_title' => lang('blocked_periods'),
            'active_menu' => PRIV_BLOCKED_PERIODS,
            'user_display_name' => $this->accounts->get_user_display_name($user_id),
            'timezones' => $this->timezones->to_array(),
            'privileges' => $this->roles_model->get_permissions_by_slug($role_slug),
        ]);

        $this->load->view('pages/blocked_periods');
    }

    /**
     * Filter blocked-periods by the provided keyword.
     */
    public function search(): void
    {
        try {
            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
                abort(405, 'Method Not Allowed', ['Allow: POST']);
                return;
            }

            $user_id = (int) session('user_id');
            if (!$user_id || cannot('view', PRIV_BLOCKED_PERIODS, $user_id)) {
                abort(403, 'Forbidden');
                return;
            }

            $request_dto = $this->backofficeRequestDtoFactory()->buildSearchRequestDto();

            $blocked_periods = $this->blocked_periods_model->search(
                $request_dto->keyword,
                $request_dto->limit,
                $request_dto->offset,
                $request_dto->orderBy,
            );

            $read_fields = array_flip(self::READ_FIELDS);
            json_response(
                array_map(
                    static fn(array $period): array => array_intersect_key($period, $read_fields),
                    $blocked_periods,
                ),
            );
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Store a new service-category.
     */
    public function store(): void
    {
        $owns_transaction = false;

        try {
            $user_id = (int) session('user_id');
            if (!$user_id || cannot('add', PRIV_BLOCKED_PERIODS, $user_id)) {
                abort(403, 'Forbidden');
                return;
            }
            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
                abort(405, 'Method Not Allowed', ['Allow: POST']);
                return;
            }

            $owns_transaction = !$this->db->trans_active();
            if ($owns_transaction && !$this->db->trans_begin()) {
                throw new RuntimeException('Could not start blocked-period create transaction.');
            }

            if (!$this->lockActorAndCheckPermission($user_id, 'add')) {
                if ($owns_transaction) {
                    $this->db->trans_rollback();
                    $owns_transaction = false;
                }
                abort(403, 'Forbidden');
                return;
            }

            $request_dto = $this->backofficeRequestDtoFactory()->buildEntityPayloadRequestDto('blocked_period');
            $blocked_period = $request_dto->payload;

            $this->blocked_periods_model->only($blocked_period, $this->allowed_blocked_period_fields);

            $this->blocked_periods_model->optional($blocked_period, $this->optional_blocked_period_fields);

            if (array_key_exists('id', $blocked_period)) {
                $provided_id = $blocked_period['id'];
                if ($provided_id !== null && $provided_id !== '') {
                    if ($owns_transaction) {
                        $this->db->trans_rollback();
                        $owns_transaction = false;
                    }
                    abort(400, 'Invalid blocked-period create');
                    return;
                }
                unset($blocked_period['id']);
            }

            $blocked_period_id = $this->blocked_periods_model->save($blocked_period);

            $blocked_period = $this->blocked_periods_model->find($blocked_period_id);

            if (!$this->db->trans_status()) {
                throw new RuntimeException('Could not complete blocked-period create transaction.');
            }
            if ($owns_transaction && !$this->db->trans_commit()) {
                throw new RuntimeException('Could not commit blocked-period create transaction.');
            }
            $owns_transaction = false;

            json_response([
                'success' => true,
                'id' => $blocked_period_id,
            ]);
        } catch (Throwable $e) {
            if ($owns_transaction) {
                $this->db->trans_rollback();
            }
            json_exception($e);
        }
    }

    /**
     * Find a service-category.
     */
    public function find(): void
    {
        try {
            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
                abort(405, 'Method Not Allowed', ['Allow: POST']);
                return;
            }

            $user_id = (int) session('user_id');
            if (!$user_id || cannot('view', PRIV_BLOCKED_PERIODS, $user_id)) {
                abort(403, 'Forbidden');
                return;
            }

            $request_dto = $this->backofficeRequestDtoFactory()->buildEntityIdRequestDto('blocked_period_id');
            $blocked_period_id = $request_dto->id;

            $blocked_period = $this->blocked_periods_model->find($blocked_period_id);

            json_response(array_intersect_key($blocked_period, array_flip(self::READ_FIELDS)));
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Update a service-category.
     */
    public function update(): void
    {
        $owns_transaction = false;

        try {
            $user_id = (int) session('user_id');
            if (!$user_id || cannot('edit', PRIV_BLOCKED_PERIODS, $user_id)) {
                abort(403, 'Forbidden');
                return;
            }
            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
                abort(405, 'Method Not Allowed', ['Allow: POST']);
                return;
            }

            $owns_transaction = !$this->db->trans_active();
            if ($owns_transaction && !$this->db->trans_begin()) {
                throw new RuntimeException('Could not start blocked-period update transaction.');
            }

            if (!$this->lockActorAndCheckPermission($user_id, 'edit')) {
                if ($owns_transaction) {
                    $this->db->trans_rollback();
                    $owns_transaction = false;
                }
                abort(403, 'Forbidden');
                return;
            }

            $request_dto = $this->backofficeRequestDtoFactory()->buildEntityPayloadRequestDto('blocked_period');
            $blocked_period = $request_dto->payload;

            $this->blocked_periods_model->only($blocked_period, $this->allowed_blocked_period_fields);

            $this->blocked_periods_model->optional($blocked_period, $this->optional_blocked_period_fields);

            $blocked_period_id = filter_var($blocked_period['id'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($blocked_period_id === false) {
                if ($owns_transaction) {
                    $this->db->trans_rollback();
                    $owns_transaction = false;
                }
                abort(400, 'Invalid blocked-period update');
                return;
            }
            $blocked_period['id'] = $blocked_period_id;

            $blocked_period_id = $this->blocked_periods_model->save($blocked_period);

            $blocked_period = $this->blocked_periods_model->find($blocked_period_id);

            if (!$this->db->trans_status()) {
                throw new RuntimeException('Could not complete blocked-period update transaction.');
            }
            if ($owns_transaction && !$this->db->trans_commit()) {
                throw new RuntimeException('Could not commit blocked-period update transaction.');
            }
            $owns_transaction = false;

            json_response([
                'success' => true,
                'id' => $blocked_period_id,
            ]);
        } catch (Throwable $e) {
            if ($owns_transaction) {
                $this->db->trans_rollback();
            }
            json_exception($e);
        }
    }

    /**
     * Remove a service-category.
     */
    public function destroy(): void
    {
        $owns_transaction = false;

        try {
            $user_id = (int) session('user_id');
            if (!$user_id || cannot('delete', PRIV_BLOCKED_PERIODS, $user_id)) {
                abort(403, 'Forbidden');
                return;
            }
            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
                abort(405, 'Method Not Allowed', ['Allow: POST']);
                return;
            }

            $owns_transaction = !$this->db->trans_active();
            if ($owns_transaction && !$this->db->trans_begin()) {
                throw new RuntimeException('Could not start blocked-period deletion transaction.');
            }

            if (!$this->lockActorAndCheckPermission($user_id, 'delete')) {
                if ($owns_transaction) {
                    $this->db->trans_rollback();
                    $owns_transaction = false;
                }
                abort(403, 'Forbidden');
                return;
            }

            $request_dto = $this->backofficeRequestDtoFactory()->buildEntityIdRequestDto('blocked_period_id');
            $blocked_period_id = $request_dto->id;

            $blocked_period = $this->blocked_periods_model->find($blocked_period_id);

            $this->blocked_periods_model->delete($blocked_period_id);

            if (!$this->db->trans_status()) {
                throw new RuntimeException('Could not complete blocked-period deletion transaction.');
            }
            if ($owns_transaction && !$this->db->trans_commit()) {
                throw new RuntimeException('Could not commit blocked-period deletion transaction.');
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

    private function lockActorAndCheckPermission(int $user_id, string $action): bool
    {
        $locked_actor = $this->db->query(
            'SELECT `id` FROM `' . $this->db->dbprefix('users') . '` WHERE `id` = ? FOR UPDATE',
            [$user_id],
        );

        if ($locked_actor === false) {
            throw new RuntimeException('Could not lock blocked-period mutation actor.');
        }

        $actor = $locked_actor->row_array();

        return !empty($actor) && !cannot($action, PRIV_BLOCKED_PERIODS, $user_id);
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
