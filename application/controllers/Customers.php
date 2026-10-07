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
 * Customers controller.
 *
 * Handles the customers related operations.
 *
 * @package Controllers
 */
class Customers extends EA_Controller
{
    private const CUSTOMER_READ_FIELDS = [
        'id',
        'first_name',
        'last_name',
        'email',
        'phone_number',
        'address',
        'city',
        'zip_code',
        'notes',
        'timezone',
        'language',
        'ldap_dn',
        'custom_field_1',
        'custom_field_2',
        'custom_field_3',
        'custom_field_4',
        'custom_field_5',
    ];

    // The customer page links only appointments the current staff member may open.
    private const CUSTOMER_APPOINTMENT_READ_FIELDS = [
        'id',
        'start_datetime',
        'end_datetime',
        'hash',
        'id_users_provider',
        'service',
        'provider',
    ];

    public array $allowed_customer_fields = [
        'id',
        'first_name',
        'last_name',
        'email',
        'phone_number',
        'address',
        'city',
        'state',
        'zip_code',
        'notes',
        'timezone',
        'language',
        'custom_field_1',
        'custom_field_2',
        'custom_field_3',
        'custom_field_4',
        'custom_field_5',
        'ldap_dn',
    ];

    public array $optional_customer_fields = [
        //
    ];

    /**
     * Customers constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->model('appointments_model');
        $this->load->model('customers_model');
        $this->load->model('providers_model');
        $this->load->model('services_model');
        $this->load->model('secretaries_model');
        $this->load->model('roles_model');
        $this->load->model('users_model');

        $this->load->library('accounts');
        $this->load->library('permissions');
        $this->load->library('timezones');
    }

    /**
     * Render the backend customers page.
     *
     * On this page admin users will be able to manage customers, which are eventually selected by customers during the
     * booking process.
     */
    public function index(): void
    {
        $user_id = (int) session('user_id');

        if ($user_id <= 0) {
            session(['dest_url' => site_url('customers')]);
            redirect('login');

            return;
        }

        $role_slug = $this->currentCustomerReadRole($user_id);
        session(['dest_url' => site_url('customers')]);

        $date_format = setting('date_format');
        $time_format = setting('time_format');
        $require_first_name = setting('require_first_name');
        $require_last_name = setting('require_last_name');
        $require_email = setting('require_email');
        $require_phone_number = setting('require_phone_number');
        $require_address = setting('require_address');
        $require_city = setting('require_city');
        $require_zip_code = setting('require_zip_code');

        $secretary_providers = [];

        if ($role_slug === DB_SLUG_SECRETARY) {
            $secretary = $this->secretaries_model->find($user_id);

            $secretary_providers = $secretary['providers'];
        }

        script_vars([
            'user_id' => $user_id,
            'role_slug' => $role_slug,
            'date_format' => $date_format,
            'time_format' => $time_format,
            'timezones' => $this->timezones->to_array(),
            'secretary_providers' => $secretary_providers,
            'default_language' => setting('default_language'),
            'default_timezone' => setting('default_timezone'),
        ]);

        html_vars([
            'page_title' => lang('customers'),
            'active_menu' => PRIV_CUSTOMERS,
            'user_display_name' => $this->accounts->get_user_display_name($user_id),
            'timezones' => $this->timezones->to_array(),
            'grouped_timezones' => $this->timezones->to_grouped_array(),
            'privileges' => $this->roles_model->get_permissions_by_slug($role_slug),
            'require_first_name' => $require_first_name,
            'require_last_name' => $require_last_name,
            'require_email' => $require_email,
            'require_phone_number' => $require_phone_number,
            'require_address' => $require_address,
            'require_city' => $require_city,
            'require_zip_code' => $require_zip_code,
            'available_languages' => config('available_languages'),
        ]);

        $this->load->view('pages/customers');
    }

    /**
     * Find a customer.
     */
    public function find(): void
    {
        try {
            $user_id = (int) session('user_id');
            $this->currentCustomerReadRole($user_id);

            $request_dto = $this->backofficeRequestDtoFactory()->buildEntityIdRequestDto('customer_id');
            $customer_id = $request_dto->id;

            if (!$this->permissions->has_customer_access($user_id, $customer_id)) {
                abort(403, 'Forbidden');
            }

            $customer = $this->customers_model->find($customer_id);
            $this->customers_model->only($customer, self::CUSTOMER_READ_FIELDS);

            json_response($customer);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Filter customers by the provided keyword.
     */
    public function search(): void
    {
        try {
            $user_id = (int) session('user_id');
            $role_slug = $this->currentCustomerReadRole($user_id);

            $request_dto = $this->backofficeRequestDtoFactory()->buildSearchRequestDto();

            $sessionUsername = session('username');

            if (is_string($sessionUsername) && Customers_ui_smoke_access_policy::isReservedUsername($sessionUsername)) {
                if (!Customers_ui_smoke_access_policy::isSafeSearchKeyword($request_dto->keyword)) {
                    abort(403, 'Forbidden');
                }

                json_response([]);

                return;
            }

            $visible_provider_ids = match ($role_slug) {
                DB_SLUG_PROVIDER => [$user_id],
                DB_SLUG_SECRETARY => array_map('intval', $this->secretaries_model->find($user_id)['providers']),
                default => null, // Admin may see all appointments.
            };
            $customer_scope_provider_ids = setting('limit_customer_access') ? $visible_provider_ids : null;

            $customers = $this->customers_model->search(
                $request_dto->keyword,
                $request_dto->limit,
                $request_dto->offset,
                $request_dto->orderBy,
                $customer_scope_provider_ids,
            );

            $can_view_appointments = can('view', PRIV_APPOINTMENTS, $user_id);

            foreach ($customers as $index => &$customer) {
                if (!$this->permissions->has_customer_access($user_id, $customer['id'])) {
                    unset($customers[$index]);

                    continue;
                }

                $this->customers_model->only($customer, self::CUSTOMER_READ_FIELDS);
                $customer['appointments'] = [];

                if (!$can_view_appointments) {
                    continue;
                }

                $appointments = $this->appointments_model->get(['id_users_customer' => $customer['id']]);

                foreach ($appointments as $appointment) {
                    if (
                        $visible_provider_ids !== null &&
                        !in_array((int) $appointment['id_users_provider'], $visible_provider_ids, true)
                    ) {
                        continue;
                    }

                    $this->appointments_model->load($appointment, ['service', 'provider']);
                    $this->services_model->only($appointment['service'], ['name']);
                    $this->providers_model->only($appointment['provider'], ['first_name', 'last_name', 'timezone']);
                    $this->appointments_model->only($appointment, self::CUSTOMER_APPOINTMENT_READ_FIELDS);
                    $customer['appointments'][] = $appointment;
                }
            }
            unset($customer);

            json_response(array_values($customers));
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /** Read the actor's current database role, never the role cached at login. */
    private function currentCustomerReadRole(int $user_id): string
    {
        if ($user_id <= 0 || cannot('view', PRIV_CUSTOMERS, $user_id)) {
            abort(403, 'Forbidden');
        }

        $role_id = (int) $this->users_model->value($user_id, 'id_roles');
        $role_slug = (string) $this->roles_model->value($role_id, 'slug');

        if (!in_array($role_slug, [DB_SLUG_ADMIN, DB_SLUG_PROVIDER, DB_SLUG_SECRETARY], true)) {
            abort(403, 'Forbidden');
        }

        return $role_slug;
    }

    /**
     * Store a new customer.
     */
    public function store(): void
    {
        try {
            $user_id = (int) session('user_id');
            if (!$user_id || cannot('add', PRIV_CUSTOMERS, $user_id)) {
                abort(403, 'Forbidden');
            }
            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
                abort(405, 'Method Not Allowed', ['Allow: POST']);
                return;
            }

            $role_id = (int) $this->users_model->value($user_id, 'id_roles');
            $role_slug = (string) $this->roles_model->value($role_id, 'slug');
            if ($role_slug !== DB_SLUG_ADMIN && setting('limit_customer_visibility')) {
                abort(403);
            }

            $request_dto = $this->backofficeRequestDtoFactory()->buildEntityPayloadRequestDto('customer');
            $customer = $request_dto->payload;

            if (!empty($customer['id'])) {
                json_response(
                    ['success' => false, 'message' => 'Use the update endpoint to edit an existing record.'],
                    403,
                );
                return;
            }
            unset($customer['id']);

            $this->customers_model->only($customer, $this->allowed_customer_fields);

            $this->customers_model->optional($customer, $this->optional_customer_fields);

            $customer_id = $this->customers_model->insert_new($customer);

            $customer = $this->customers_model->find($customer_id);

            json_response([
                'success' => true,
                'id' => $customer_id,
            ]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Update a customer.
     */
    public function update(): void
    {
        $transaction_open = false;

        try {
            $user_id = (int) session('user_id');
            if (!$user_id || cannot('edit', PRIV_CUSTOMERS, $user_id)) {
                abort(403, 'Forbidden');
            }
            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
                abort(405, 'Method Not Allowed', ['Allow: POST']);
                return;
            }

            $request_dto = $this->backofficeRequestDtoFactory()->buildEntityPayloadRequestDto('customer');
            $customer = $request_dto->payload;

            if (!$this->db->trans_begin()) {
                throw new RuntimeException('Could not start customer update transaction.');
            }
            $transaction_open = true;

            if (!$this->hasLockedCustomerWriteAccess($user_id, (int) $customer['id'], 'edit')) {
                $this->db->trans_rollback();
                $transaction_open = false;
                abort(403, 'Forbidden');
                return;
            }

            $this->customers_model->only($customer, $this->allowed_customer_fields);

            $this->customers_model->optional($customer, $this->optional_customer_fields);

            $customer_id = $this->customers_model->save($customer);

            $customer = $this->customers_model->find($customer_id);

            if (!$this->db->trans_status() || !$this->db->trans_commit()) {
                throw new RuntimeException('Could not commit customer update transaction.');
            }
            $transaction_open = false;

            json_response([
                'success' => true,
                'id' => $customer_id,
            ]);
        } catch (Throwable $e) {
            if ($transaction_open) {
                $this->db->trans_rollback();
            }
            json_exception($e);
        }
    }

    /**
     * Remove a customer.
     */
    public function destroy(): void
    {
        $transaction_open = false;

        try {
            $user_id = (int) session('user_id');
            if (!$user_id || cannot('delete', PRIV_CUSTOMERS, $user_id)) {
                abort(403, 'Forbidden');
            }
            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
                abort(405, 'Method Not Allowed', ['Allow: POST']);
                return;
            }

            $request_dto = $this->backofficeRequestDtoFactory()->buildEntityIdRequestDto('customer_id');
            $customer_id = $request_dto->id;

            if (!$this->db->trans_begin()) {
                throw new RuntimeException('Could not start customer delete transaction.');
            }
            $transaction_open = true;

            if (!$this->hasLockedCustomerWriteAccess($user_id, $customer_id, 'delete')) {
                $this->db->trans_rollback();
                $transaction_open = false;
                abort(403, 'Forbidden');
                return;
            }

            $customer = $this->customers_model->find($customer_id);

            $this->customers_model->delete($customer_id);

            if (!$this->db->trans_status() || !$this->db->trans_commit()) {
                throw new RuntimeException('Could not commit customer delete transaction.');
            }
            $transaction_open = false;

            json_response([
                'success' => true,
            ]);
        } catch (Throwable $e) {
            if ($transaction_open) {
                $this->db->trans_rollback();
            }
            json_exception($e);
        }
    }

    /** Keep a changing appointment relationship authoritative through the customer write. */
    private function hasLockedCustomerWriteAccess(int $user_id, int $customer_id, string $action): bool
    {
        $user_ids = array_values(array_unique([$user_id, $customer_id]));
        sort($user_ids, SORT_NUMERIC);
        $placeholders = implode(', ', array_fill(0, count($user_ids), '?'));
        $locked_users = $this->db->query(
            'SELECT `id` FROM `' .
                $this->db->dbprefix('users') .
                '` WHERE `id` IN (' .
                $placeholders .
                ') ORDER BY `id` ASC FOR UPDATE',
            $user_ids,
        );

        if ($locked_users === false) {
            throw new RuntimeException('Could not lock customer write users.');
        }

        if ($locked_users->num_rows() !== count($user_ids)) {
            return false;
        }

        // The permission helper reads appointment relationships. Lock that same
        // customer scope before its first transaction-scoped nonlocking read.
        $locked_appointments = $this->db->query(
            'SELECT `id` FROM `' .
                $this->db->dbprefix('appointments') .
                '` WHERE `id_users_customer` = ? ORDER BY `id` ASC FOR UPDATE',
            [$customer_id],
        );

        if ($locked_appointments === false) {
            throw new RuntimeException('Could not lock customer appointment relationships.');
        }

        try {
            return !cannot($action, PRIV_CUSTOMERS, $user_id) &&
                $this->permissions->has_customer_access($user_id, $customer_id);
        } catch (InvalidArgumentException) {
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
