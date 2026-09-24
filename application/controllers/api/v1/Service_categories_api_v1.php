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
 * Service-categories API v1 controller.
 *
 * @package Controllers
 */
class Service_categories_api_v1 extends EA_Controller
{
    /**
     * Service_categories_api_v1 constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->library('api');
        $this->load->library('api_request_dto_factory');

        $this->api->auth();

        $this->api->model('service_categories_model');
    }

    /**
     * Get a service-category collection.
     */
    public function index(): void
    {
        try {
            $keyword = $this->api->request_keyword();

            $limit = $this->api->request_limit();

            $offset = $this->api->request_offset();

            $order_by = $this->api->request_order_by();

            $fields = $this->api->request_fields();

            $with = $this->api->request_with();

            $service_categories = empty($keyword)
                ? $this->service_categories_model->get(null, $limit, $offset, $order_by)
                : $this->service_categories_model->search($keyword, $limit, $offset, $order_by);

            foreach ($service_categories as &$service_category) {
                $this->service_categories_model->api_encode($service_category);

                if (!empty($fields)) {
                    $this->service_categories_model->only($service_category, $fields);
                }

                if (!empty($with)) {
                    $this->service_categories_model->load($service_category, $with);
                }
            }

            json_response($service_categories);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Get a single service-category.
     *
     * @param int|null $id Service-category ID.
     */
    public function show(?int $id = null): void
    {
        try {
            $occurrences = $this->service_categories_model->get(['id' => $id]);

            if (empty($occurrences)) {
                response('', 404);

                return;
            }

            $fields = $this->api->request_fields();

            $with = $this->api->request_with();

            $service_category = $this->service_categories_model->find($id);

            $this->service_categories_model->api_encode($service_category);

            if (!empty($fields)) {
                $this->service_categories_model->only($service_category, $fields);
            }

            if (!empty($with)) {
                $this->service_categories_model->load($service_category, $with);
            }

            json_response($service_category);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Store a new service-category.
     */
    public function store(): void
    {
        if (!$this->enforceWriteMethod('POST')) {
            return;
        }

        try {
            $service_category = $this->apiRequestDtoFactory()->buildEntityWritePayloadDto()->payload;

            $this->service_categories_model->api_decode($service_category);

            if (array_key_exists('id', $service_category)) {
                unset($service_category['id']);
            }

            $created_service_category = $this->saveAndEncode($service_category);

            json_response($created_service_category, 201);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Update a service-category.
     *
     * @param int $id Service-category ID.
     */
    public function update(int $id): void
    {
        if (!$this->enforceWriteMethod('PUT')) {
            return;
        }

        try {
            $occurrences = $this->service_categories_model->get(['id' => $id]);

            if (empty($occurrences)) {
                response('', 404);

                return;
            }

            $original_category = $occurrences[0];

            $service_category = $this->apiRequestDtoFactory()->buildEntityWritePayloadDto()->payload;

            // The URL selects the category. A body ID may only repeat that target.
            if (array_key_exists('id', $service_category)) {
                if (!is_int($service_category['id']) || $service_category['id'] !== $id) {
                    response('', 400);

                    return;
                }

                unset($service_category['id']);
            }

            if (!array_key_exists('name', $service_category) && !array_key_exists('description', $service_category)) {
                response('', 400);

                return;
            }

            $this->service_categories_model->api_decode($service_category, $original_category);

            $service_category['id'] = $id;

            $updated_service_category = $this->saveAndEncode($service_category);

            json_response($updated_service_category);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Delete a service-category.
     *
     * @param int $id Service-category ID.
     */
    public function destroy(int $id): void
    {
        if (!$this->enforceWriteMethod('DELETE')) {
            return;
        }

        try {
            $occurrences = $this->service_categories_model->get(['id' => $id]);

            if (empty($occurrences)) {
                response('', 404);

                return;
            }

            $deleted_service_category = $occurrences[0];

            $this->service_categories_model->delete($id);

            response('', 204);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /** Keep the write and response projection atomic before reporting success. */
    private function saveAndEncode(array $service_category): array
    {
        if ($this->db->trans_active() || !$this->db->trans_begin()) {
            throw new RuntimeException('Could not start service-category write transaction.');
        }

        try {
            $service_category_id = $this->service_categories_model->save($service_category);
            $saved_service_category = $this->service_categories_model->find($service_category_id);
            $this->service_categories_model->api_encode($saved_service_category);

            // A response that cannot be represented as JSON must not commit a write.
            json_encode($saved_service_category, JSON_THROW_ON_ERROR);

            if (!$this->db->trans_status() || !$this->db->trans_commit()) {
                throw new RuntimeException('Could not commit service-category write transaction.');
            }

            return $saved_service_category;
        } catch (Throwable $error) {
            if ($this->db->trans_active() && !$this->db->trans_rollback()) {
                throw new RuntimeException('Could not roll back service-category write transaction.', 0, $error);
            }

            throw $error;
        }
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

    private function enforceWriteMethod(string $expected): bool
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === $expected) {
            return true;
        }

        response('', 405, ['Allow: ' . $expected]);

        return false;
    }
}
