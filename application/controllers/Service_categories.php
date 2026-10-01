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
 * Service-categories controller.
 *
 * Handles the service-categories related operations.
 *
 * @package Controllers
 */
class Service_categories extends EA_Controller
{
    private const READ_FIELDS = ['id', 'name', 'description'];

    public array $allowed_service_category_fields = ['id', 'name', 'description'];

    public array $optional_service_category_fields = [];

    /**
     * Service-categories constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->model('service_categories_model');
        $this->load->model('roles_model');
        $this->load->model('users_model');

        $this->load->library('accounts');
        $this->load->library('timezones');
    }

    /**
     * Render the backend service-categories page.
     *
     * On this page admin users will be able to manage service-categories, which are eventually selected by customers during the
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
            session(['dest_url' => site_url('service_categories')]);
            redirect('login');
            return;
        }

        if (cannot('view', PRIV_SERVICES, $user_id)) {
            abort(403, 'Forbidden');
            return;
        }

        session(['dest_url' => site_url('service_categories')]);

        $role_slug = $this->roles_model->value($this->users_model->value($user_id, 'id_roles'), 'slug');

        script_vars([
            'user_id' => $user_id,
            'role_slug' => $role_slug,
        ]);

        html_vars([
            'page_title' => lang('service_categories'),
            'active_menu' => PRIV_SERVICES,
            'user_display_name' => $this->accounts->get_user_display_name($user_id),
            'timezones' => $this->timezones->to_array(),
            'privileges' => $this->roles_model->get_permissions_by_slug($role_slug),
        ]);

        $this->load->view('pages/service_categories');
    }

    /**
     * Filter service-categories by the provided keyword.
     */
    public function search(): void
    {
        try {
            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
                abort(405, 'Method Not Allowed', ['Allow: POST']);
                return;
            }

            $user_id = (int) session('user_id');
            if (!$user_id || cannot('view', PRIV_SERVICES, $user_id)) {
                abort(403, 'Forbidden');
                return;
            }

            $request_dto = $this->backofficeRequestDtoFactory()->buildSearchRequestDto();

            $service_categories = $this->service_categories_model->search(
                $request_dto->keyword,
                $request_dto->limit,
                $request_dto->offset,
                $request_dto->orderBy,
            );

            $read_fields = array_flip(self::READ_FIELDS);
            json_response(
                array_map(
                    static fn(array $category): array => array_intersect_key($category, $read_fields),
                    $service_categories,
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
        try {
            $user_id = (int) session('user_id');
            if (!$user_id || cannot('add', PRIV_SERVICES, $user_id)) {
                abort(403, 'Forbidden');
                return;
            }

            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
                abort(405, 'Method Not Allowed', ['Allow: POST']);
                return;
            }

            $request_dto = $this->backofficeRequestDtoFactory()->buildEntityPayloadRequestDto('service_category');
            $service_category = $request_dto->payload;

            if (array_key_exists('id', $service_category)) {
                json_response(
                    ['success' => false, 'message' => 'Use the update endpoint to edit an existing record.'],
                    400,
                );
                return;
            }

            $this->service_categories_model->only($service_category, $this->allowed_service_category_fields);

            $this->service_categories_model->optional($service_category, $this->optional_service_category_fields);

            $service_category_id = $this->service_categories_model->save($service_category);

            $service_category = $this->service_categories_model->find($service_category_id);

            json_response([
                'success' => true,
                'id' => $service_category_id,
            ]);
        } catch (Throwable $e) {
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
            if (!$user_id || cannot('view', PRIV_SERVICES, $user_id)) {
                abort(403, 'Forbidden');
                return;
            }

            $request_dto = $this->backofficeRequestDtoFactory()->buildEntityIdRequestDto('service_category_id');
            $service_category_id = $request_dto->id;

            $service_category = $this->service_categories_model->find($service_category_id);

            json_response(array_intersect_key($service_category, array_flip(self::READ_FIELDS)));
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Update a service-category.
     */
    public function update(): void
    {
        try {
            $user_id = (int) session('user_id');
            if (!$user_id || cannot('edit', PRIV_SERVICES, $user_id)) {
                abort(403, 'Forbidden');
                return;
            }

            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
                abort(405, 'Method Not Allowed', ['Allow: POST']);
                return;
            }

            $request_dto = $this->backofficeRequestDtoFactory()->buildEntityPayloadRequestDto('service_category');
            $service_category = $request_dto->payload;

            if (empty($service_category['id'])) {
                json_response(['success' => false, 'message' => 'Use the store endpoint to create a new record.'], 400);
                return;
            }

            $this->service_categories_model->only($service_category, $this->allowed_service_category_fields);

            $this->service_categories_model->optional($service_category, $this->optional_service_category_fields);

            $service_category_id = $this->service_categories_model->save($service_category);

            $service_category = $this->service_categories_model->find($service_category_id);

            json_response([
                'success' => true,
                'id' => $service_category_id,
            ]);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Remove a service-category.
     */
    public function destroy(): void
    {
        try {
            $user_id = (int) session('user_id');
            if (!$user_id || cannot('delete', PRIV_SERVICES, $user_id)) {
                abort(403, 'Forbidden');
                return;
            }

            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
                abort(405, 'Method Not Allowed', ['Allow: POST']);
                return;
            }

            $request_dto = $this->backofficeRequestDtoFactory()->buildEntityIdRequestDto('service_category_id');
            $service_category_id = $request_dto->id;

            $service_category = $this->service_categories_model->find($service_category_id);

            $this->service_categories_model->delete($service_category_id);

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
