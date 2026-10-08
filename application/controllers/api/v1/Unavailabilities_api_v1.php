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
 * Unavailabilities API v1 controller.
 *
 * @package Controllers
 */
class Unavailabilities_api_v1 extends EA_Controller
{
    /**
     * Unavailabilities_api_v1 constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->library('api');
        $this->load->library('api_request_dto_factory');

        $this->api->auth();

        $this->api->model('unavailabilities_model');
        $this->load->model('appointments_model');
    }

    /**
     * Get an unavailability collection.
     */
    public function index(): void
    {
        if (!$this->enforceReadMethod()) {
            return;
        }

        if (!$this->validatePaginationRequest()) {
            return;
        }

        try {
            $keyword = $this->api->request_keyword();

            $limit = $this->api->request_limit();

            $offset = $this->api->request_offset();

            $order_by = $this->api->request_order_by();

            $fields = $this->api->request_fields();

            $with = $this->api->request_with();

            $unavailabilities = empty($keyword)
                ? $this->unavailabilities_model->get(null, $limit, $offset, $order_by)
                : $this->unavailabilities_model->search($keyword, $limit, $offset, $order_by);

            foreach ($unavailabilities as &$unavailability) {
                $this->unavailabilities_model->api_encode($unavailability);
            }
            unset($unavailability);

            if (!empty($with)) {
                $this->unavailabilities_model->loadCollection($unavailabilities, $with);
            }

            if (!empty($fields)) {
                $this->unavailabilities_model->only($unavailabilities, array_merge($fields, $with ?? []));
            }

            json_response($unavailabilities);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Get a single unavailability.
     *
     * @param int|null $id Unavailability ID.
     */
    public function show(?int $id = null): void
    {
        if (!$this->enforceReadMethod()) {
            return;
        }

        try {
            $occurrences = $this->unavailabilities_model->get(['id' => $id]);

            if (empty($occurrences)) {
                response('', 404);

                return;
            }

            $fields = $this->api->request_fields();

            $with = $this->api->request_with();

            $unavailability = $this->unavailabilities_model->find($id);

            $this->unavailabilities_model->api_encode($unavailability);

            if (!empty($with)) {
                $this->unavailabilities_model->load($unavailability, $with);
            }

            if (!empty($fields)) {
                $this->unavailabilities_model->only($unavailability, array_merge($fields, $with ?? []));
            }

            json_response($unavailability);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Store a new unavailability.
     */
    public function store(): void
    {
        if (!$this->enforceWriteMethod('POST')) {
            return;
        }

        try {
            $unavailability = $this->apiRequestDtoFactory()->buildEntityWritePayloadDto()->payload;

            $this->unavailabilities_model->api_decode($unavailability);

            if (array_key_exists('id', $unavailability)) {
                unset($unavailability['id']);
            }

            $unavailability_id = $this->unavailabilities_model->save($unavailability);

            $created_unavailability = $this->unavailabilities_model->find($unavailability_id);

            $this->unavailabilities_model->api_encode($created_unavailability);

            json_response($created_unavailability, 201);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Update an unavailability.
     *
     * @param int $id Unavailability ID.
     */
    public function update(int $id): void
    {
        if (!$this->enforceWriteMethod('PUT')) {
            return;
        }

        try {
            $occurrences = $this->unavailabilities_model->get(['id' => $id]);

            if (empty($occurrences)) {
                response('', 404);

                return;
            }

            $original_unavailability = $occurrences[0];

            $unavailability = $this->apiRequestDtoFactory()->buildEntityWritePayloadDto()->payload;

            // The route identifies the only resource this request may update.
            if (array_key_exists('id', $unavailability)) {
                if (!is_int($unavailability['id']) || $unavailability['id'] !== $id) {
                    response('', 400);

                    return;
                }

                unset($unavailability['id']);
            }

            $requested_unavailability = $unavailability;
            $this->unavailabilities_model->api_decode($unavailability, $original_unavailability);

            if (!$this->db->trans_begin()) {
                throw new RuntimeException('Could not start unavailability transaction.');
            }

            try {
                // Match public booking and Calendar writes: lock both possible
                // providers before the row, then re-read the row under the lock.
                $this->appointments_model->lock_update_parents($original_unavailability, $unavailability);

                $locked_unavailability = $this->db
                    ->query(
                        'SELECT * FROM `' .
                            $this->db->dbprefix('appointments') .
                            '` WHERE `id` = ? AND `is_unavailability` = 1 AND `id_parent_appointment` IS NULL FOR UPDATE',
                        [$id],
                    )
                    ->row_array();

                if (
                    !$locked_unavailability ||
                    (int) $locked_unavailability['id_users_provider'] !==
                        (int) $original_unavailability['id_users_provider']
                ) {
                    throw new RuntimeException('The unavailability changed during this request.', 409);
                }

                $unavailability = $requested_unavailability;
                $this->unavailabilities_model->api_decode($unavailability, $locked_unavailability);
                $unavailability['id'] = $id;

                $unavailability_id = $this->unavailabilities_model->save($unavailability);

                if ($this->db->trans_status() === false || !$this->db->trans_commit()) {
                    throw new RuntimeException('Could not commit unavailability transaction.');
                }
            } catch (Throwable $e) {
                $this->db->trans_rollback();

                throw $e;
            }

            $updated_unavailability = $this->unavailabilities_model->find($unavailability_id);

            $this->unavailabilities_model->api_encode($updated_unavailability);

            json_response($updated_unavailability);
        } catch (Throwable $e) {
            if ($e->getCode() === 409) {
                json_response(
                    ['success' => false, 'message' => 'The unavailability changed during this request.'],
                    409,
                );
            } else {
                json_exception($e);
            }
        }
    }

    /**
     * Delete an unavailability.
     *
     * @param int $id Unavailability ID.
     */
    public function destroy(int $id): void
    {
        if (!$this->enforceWriteMethod('DELETE')) {
            return;
        }

        try {
            $occurrences = $this->unavailabilities_model->get(['id' => $id]);

            if (empty($occurrences)) {
                response('', 404);

                return;
            }

            $deleted_unavailability = $occurrences[0];

            $this->unavailabilities_model->delete($id);

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

    private function enforceWriteMethod(string $expected): bool
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === $expected) {
            return true;
        }

        response('', 405, ['Allow: ' . $expected]);

        return false;
    }

    private function enforceReadMethod(): bool
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'GET') {
            return true;
        }

        response('', 405, ['Allow: GET']);

        return false;
    }

    /** Validate collection pagination before the unavailabilities model is queried. */
    private function validatePaginationRequest(): bool
    {
        foreach (['length' => 100, 'page' => 10000] as $parameter => $maximum) {
            $value = request($parameter);

            if ($value === null) {
                continue;
            }

            if ((is_int($value) || is_string($value)) && preg_match('/^[0-9]+$/', (string) $value)) {
                $number = (int) $value;

                if ($number >= 1 && $number <= $maximum) {
                    continue;
                }
            }

            json_response(
                [
                    'success' => false,
                    'message' => sprintf('The %s parameter must be an integer between 1 and %d.', $parameter, $maximum),
                ],
                400,
            );

            return false;
        }

        return true;
    }
}
