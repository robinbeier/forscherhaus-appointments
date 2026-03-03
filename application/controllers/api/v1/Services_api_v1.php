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
 * Services API v1 controller.
 *
 * @package Controllers
 */
class Services_api_v1 extends EA_Controller
{
    /**
     * Services_api_v1 constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->model('appointments_model');
        $this->load->library('api');
        $this->load->library('api_request_dto_factory');
        $this->load->library('webhooks_client');

        $this->api->auth();

        $this->api->model('services_model');
    }

    /**
     * Get a service collection.
     */
    public function index(): void
    {
        try {
            $query = $this->apiRequestDtoFactory()->buildCollectionQueryDto($this->api);

            $services = empty($query->keyword)
                ? $this->services_model->get(null, $query->limit, $query->offset, $query->orderBy)
                : $this->services_model->search($query->keyword, $query->limit, $query->offset, $query->orderBy);

            foreach ($services as &$service) {
                $this->services_model->api_encode($service);

                if (!empty($query->fields)) {
                    $this->services_model->only($service, $query->fields);
                }

                if (!empty($query->with)) {
                    $this->services_model->load($service, $query->with);
                }
            }

            json_response($services);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Get a single service.
     *
     * @param int|null $id Service ID.
     */
    public function show(?int $id = null): void
    {
        try {
            $occurrences = $this->services_model->get(['id' => $id]);

            if (empty($occurrences)) {
                response('', 404);

                return;
            }

            $fields = $this->api->request_fields();

            $with = $this->api->request_with();

            $service = $this->services_model->find($id);

            $this->services_model->api_encode($service);

            if (!empty($fields)) {
                $this->services_model->only($service, $fields);
            }

            if (!empty($with)) {
                $this->services_model->load($service, $with);
            }

            json_response($service);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Store a new service.
     */
    public function store(): void
    {
        try {
            $service = request();

            $this->services_model->api_decode($service);

            if (array_key_exists('id', $service)) {
                unset($service['id']);
            }

            $service_id = $this->services_model->save($service);

            $created_service = $this->services_model->find($service_id);

            $this->webhooks_client->trigger(WEBHOOK_SERVICE_SAVE, $created_service);

            $this->services_model->api_encode($created_service);

            json_response($created_service, 201);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Update a service.
     *
     * @param int $id Service ID.
     */
    public function update(int $id): void
    {
        try {
            $occurrences = $this->services_model->get(['id' => $id]);

            if (empty($occurrences)) {
                response('', 404);

                return;
            }

            $original_service = $occurrences[0];

            $service = request();

            $this->services_model->api_decode($service, $original_service);

            $buffer_values_changed =
                (int) ($original_service['buffer_before'] ?? 0) !== (int) ($service['buffer_before'] ?? 0) ||
                (int) ($original_service['buffer_after'] ?? 0) !== (int) ($service['buffer_after'] ?? 0);

            if (!$this->db->trans_begin()) {
                throw new RuntimeException('Could not start service transaction.');
            }

            try {
                $service_id = $this->services_model->save($service);

                if ($buffer_values_changed) {
                    $this->appointments_model->sync_service_buffer_unavailabilities($service_id);
                }

                if (!$this->db->trans_commit()) {
                    throw new RuntimeException('Could not commit service transaction.');
                }
            } catch (Throwable $exception) {
                $this->db->trans_rollback();

                throw $exception;
            }

            $updated_service = $this->services_model->find($service_id);

            $this->webhooks_client->trigger(WEBHOOK_SERVICE_SAVE, $updated_service);

            $this->services_model->api_encode($updated_service);

            json_response($updated_service);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Delete a service.
     *
     * @param int $id Service ID.
     */
    public function destroy(int $id): void
    {
        try {
            $occurrences = $this->services_model->get(['id' => $id]);

            if (empty($occurrences)) {
                response('', 404);

                return;
            }

            $deleted_service = $occurrences[0];

            $this->services_model->delete($id);

            $this->webhooks_client->trigger(WEBHOOK_SERVICE_DELETE, $deleted_service);

            response('', 204);
        } catch (Throwable $e) {
            json_exception($e);
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
}
