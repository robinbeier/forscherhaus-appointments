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
 * Providers API v1 controller.
 *
 * @package Controllers
 */
class Providers_api_v1 extends EA_Controller
{
    /**
     * Providers_api_v1 constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->library('api');
        $this->load->library('api_request_dto_factory');

        $this->api->auth();

        $this->api->model('providers_model');
    }

    /**
     * Get a provider collection.
     */
    public function index(): void
    {
        if (!$this->enforceReadMethod()) {
            return;
        }

        try {
            if (!$this->validatePaginationRequest()) {
                return;
            }

            $query = $this->apiRequestDtoFactory()->buildCollectionQueryDto($this->api);

            $providers = empty($query->keyword)
                ? $this->providers_model->get(null, $query->limit, $query->offset, $query->orderBy)
                : $this->providers_model->search($query->keyword, $query->limit, $query->offset, $query->orderBy);

            foreach ($providers as &$provider) {
                $this->providers_model->api_encode($provider);

                if (!empty($query->with)) {
                    $this->providers_model->load($provider, $query->with);
                }

                if (!empty($query->fields)) {
                    $this->providers_model->only($provider, array_merge($query->fields, $query->with ?? []));
                }
            }

            json_response($providers);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Validate collection pagination before the providers model or query DTO is used.
     */
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

    /**
     * Get a single provider.
     *
     * @param int|null $id Provider ID.
     */
    public function show(?int $id = null): void
    {
        if (!$this->enforceReadMethod()) {
            return;
        }

        try {
            $occurrences = $this->providers_model->get(['id' => $id]);

            if (empty($occurrences)) {
                response('', 404);

                return;
            }

            $fields = $this->api->request_fields();

            $with = $this->api->request_with();

            $provider = $this->providers_model->find($id);

            $this->providers_model->api_encode($provider);

            if (!empty($with)) {
                $this->providers_model->load($provider, $with);
            }

            if (!empty($fields)) {
                $this->providers_model->only($provider, array_merge($fields, $with ?? []));
            }

            json_response($provider);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Store a new provider.
     */
    public function store(): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            response('', 405, ['Allow: POST']);

            return;
        }

        try {
            $provider = $this->apiRequestDtoFactory()->buildEntityWritePayloadDto()->payload;

            $this->providers_model->api_decode($provider);

            if (array_key_exists('id', $provider)) {
                unset($provider['id']);
            }

            if (!array_key_exists('services', $provider)) {
                throw new InvalidArgumentException('No services property provided.');
            }

            if (!array_key_exists('settings', $provider)) {
                throw new InvalidArgumentException('No settings property provided.');
            }

            if (!array_key_exists('working_plan', $provider['settings'])) {
                $provider['settings']['working_plan'] = setting('company_working_plan');
            }

            if (!array_key_exists('working_plan_exceptions', $provider['settings'])) {
                $provider['settings']['working_plan_exceptions'] = '{}';
            }

            $provider_id = $this->providers_model->save($provider);

            $created_provider = $this->providers_model->find($provider_id);

            $this->providers_model->api_encode($created_provider);

            json_response($created_provider, 201);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Update a provider.
     *
     * @param int $id Provider ID.
     */
    public function update(int $id): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'PUT') {
            response('', 405, ['Allow: PUT']);

            return;
        }

        try {
            $occurrences = $this->providers_model->get(['id' => $id]);

            if (empty($occurrences)) {
                response('', 404);

                return;
            }

            $original_provider = $occurrences[0];

            $provider = $this->apiRequestDtoFactory()->buildEntityWritePayloadDto()->payload;

            // The URL selects the provider; a body ID may only repeat that target.
            if (array_key_exists('id', $provider)) {
                if (!is_int($provider['id']) || $provider['id'] !== $id) {
                    response('', 400);

                    return;
                }

                unset($provider['id']);
            }

            $this->providers_model->api_decode($provider, $original_provider);

            $provider['id'] = $id;

            $provider_id = $this->providers_model->save($provider);

            $updated_provider = $this->providers_model->find($provider_id);

            $this->providers_model->api_encode($updated_provider);

            json_response($updated_provider);
        } catch (Throwable $e) {
            json_exception($e);
        }
    }

    /**
     * Delete a provider.
     *
     * @param int $id Provider ID.
     */
    public function destroy(int $id): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'DELETE') {
            response('', 405, ['Allow: DELETE']);

            return;
        }

        try {
            $occurrences = $this->providers_model->get(['id' => $id]);

            if (empty($occurrences)) {
                response('', 404);

                return;
            }

            $deleted_provider = $occurrences[0];

            $this->providers_model->delete($id);

            response('', 204);
        } catch (InvalidArgumentException $e) {
            // A provider can change roles after the initial read. The model's
            // conditional delete then has no target, matching the 404 contract.
            response('', 404);
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
