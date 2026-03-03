<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * @since       v1.8.0
 * ---------------------------------------------------------------------------- */

/**
 * Typed dashboard period request DTO.
 */
final class DashboardPeriodRequestDto
{
    public function __construct(public readonly DateTimeImmutable $start, public readonly DateTimeImmutable $end)
    {
    }
}

/**
 * Typed dashboard filter request DTO.
 */
final class DashboardFilterRequestDto
{
    /**
     * @param array<int, string> $statuses
     * @param array<int, int> $providerIds
     */
    public function __construct(
        public readonly array $statuses,
        public readonly ?int $serviceId,
        public readonly array $providerIds,
    ) {
    }
}

/**
 * Typed dashboard threshold request DTO.
 */
final class DashboardThresholdRequestDto
{
    public function __construct(public readonly float $threshold)
    {
    }
}

/**
 * Typed dashboard metrics/heatmap request DTO.
 */
final class DashboardMetricsRequestDto
{
    public function __construct(
        public readonly DashboardPeriodRequestDto $period,
        public readonly DashboardFilterRequestDto $filters,
    ) {
    }
}

/**
 * Typed dashboard provider-metrics request DTO.
 */
final class DashboardProviderMetricsRequestDto
{
    public function __construct(public readonly DashboardPeriodRequestDto $period)
    {
    }
}

/**
 * Typed dashboard export request DTO.
 */
final class DashboardExportRequestDto
{
    public function __construct(
        public readonly DashboardPeriodRequestDto $period,
        public readonly DashboardFilterRequestDto $filters,
        public readonly DashboardThresholdRequestDto $threshold,
    ) {
    }
}

/**
 * Dashboard request DTO factory.
 *
 * @package Libraries
 */
class Dashboard_request_dto_factory
{
    protected Request_normalizer $request_normalizer;

    public function __construct(?Request_normalizer $request_normalizer = null)
    {
        if ($request_normalizer instanceof Request_normalizer) {
            $this->request_normalizer = $request_normalizer;

            return;
        }

        /** @var EA_Controller|CI_Controller $CI */
        $CI = &get_instance();

        if (!isset($CI->request_normalizer) || !$CI->request_normalizer instanceof Request_normalizer) {
            $CI->load->library('request_normalizer');
        }

        $this->request_normalizer = $CI->request_normalizer;
    }

    public function buildProviderMetricsRequestFromGlobals(): DashboardProviderMetricsRequestDto
    {
        $period = $this->createPeriod(
            $this->request_normalizer->normalizeString(request('start_date'), null, true),
            $this->request_normalizer->normalizeString(request('end_date'), null, true),
        );

        return new DashboardProviderMetricsRequestDto($period);
    }

    public function buildMetricsRequestFromGlobals(): DashboardMetricsRequestDto
    {
        $period = $this->createPeriod(
            $this->request_normalizer->normalizeString(request('start_date'), null, true),
            $this->request_normalizer->normalizeString(request('end_date'), null, true),
        );
        $filters = $this->createFilter(request('statuses', []), request('service_id'), request('provider_ids', []), []);

        return new DashboardMetricsRequestDto($period, $filters);
    }

    public function buildHeatmapRequestFromGlobals(): DashboardMetricsRequestDto
    {
        $period = $this->createPeriod(
            $this->request_normalizer->normalizeString(request('start_date'), null, true),
            $this->request_normalizer->normalizeString(request('end_date'), null, true),
        );
        $filters = $this->createFilter(request('statuses', []), request('service_id'), request('provider_ids', []), []);

        return new DashboardMetricsRequestDto($period, $filters);
    }

    public function buildThresholdRequestFromGlobals(?string $validation_message = null): DashboardThresholdRequestDto
    {
        return $this->createThreshold(request('threshold'), null, $validation_message);
    }

    public function buildExportRequestFromGlobals(
        float $configured_threshold,
        ?string $validation_message = null,
    ): DashboardExportRequestDto {
        $period = $this->createPeriod(
            $this->request_normalizer->normalizeString(request('start_date'), null, true),
            $this->request_normalizer->normalizeString(request('end_date'), null, true),
        );
        $filters = $this->createFilter(request('statuses', []), request('service_id'), request('provider_ids', []), [
            'Booked',
        ]);
        $threshold = $this->createThreshold(request('threshold'), $configured_threshold, $validation_message);

        return new DashboardExportRequestDto($period, $filters, $threshold);
    }

    public function createPeriod(?string $start_input, ?string $end_input): DashboardPeriodRequestDto
    {
        if (!$start_input || !$end_input) {
            throw new InvalidArgumentException($this->getPeriodValidationMessage());
        }

        $start = DateTimeImmutable::createFromFormat('Y-m-d', $start_input);
        $end = DateTimeImmutable::createFromFormat('Y-m-d', $end_input);

        if (!$start || $start->format('Y-m-d') !== $start_input || !$end || $end->format('Y-m-d') !== $end_input) {
            throw new InvalidArgumentException($this->getPeriodValidationMessage());
        }

        if ($start > $end) {
            throw new InvalidArgumentException($this->getPeriodValidationMessage());
        }

        return new DashboardPeriodRequestDto($start, $end);
    }

    /**
     * @param mixed $statuses
     * @param mixed $service_id
     * @param mixed $provider_ids
     * @param array<int, string> $default_statuses
     */
    public function createFilter(
        mixed $statuses,
        mixed $service_id,
        mixed $provider_ids,
        array $default_statuses = [],
    ): DashboardFilterRequestDto {
        $normalized_statuses = $this->request_normalizer->normalizeStringList($statuses);

        if (empty($normalized_statuses) && !empty($default_statuses)) {
            $normalized_statuses = $this->request_normalizer->normalizeStringList($default_statuses);
        }

        $normalized_statuses = array_values(array_unique($normalized_statuses));

        return new DashboardFilterRequestDto(
            $normalized_statuses,
            $this->request_normalizer->normalizePositiveInt($service_id, null),
            $this->request_normalizer->normalizePositiveIntList($provider_ids),
        );
    }

    /**
     * @param mixed $threshold_input
     */
    public function createThreshold(
        mixed $threshold_input,
        ?float $default_threshold = null,
        ?string $validation_message = null,
    ): DashboardThresholdRequestDto {
        if ($threshold_input === null || $threshold_input === '') {
            if ($default_threshold !== null) {
                return new DashboardThresholdRequestDto($default_threshold);
            }

            throw new InvalidArgumentException($this->resolveThresholdValidationMessage($validation_message));
        }

        if (is_array($threshold_input) || !is_numeric($threshold_input)) {
            throw new InvalidArgumentException($this->resolveThresholdValidationMessage($validation_message));
        }

        $threshold = (float) $threshold_input;

        if (!is_finite($threshold) || $threshold < 0 || $threshold > 1) {
            throw new InvalidArgumentException($this->resolveThresholdValidationMessage($validation_message));
        }

        return new DashboardThresholdRequestDto($threshold);
    }

    private function getPeriodValidationMessage(): string
    {
        $message = trim((string) lang('filter_period_required'));

        return $message !== '' ? $message : 'Please provide a valid date range.';
    }

    private function resolveThresholdValidationMessage(?string $validation_message): string
    {
        $message = trim((string) $validation_message);

        if ($message !== '') {
            return $message;
        }

        $fallback = trim((string) lang('dashboard_conflict_threshold_invalid'));

        return $fallback !== '' ? $fallback : 'Please provide a threshold between 0 and 1.';
    }
}
