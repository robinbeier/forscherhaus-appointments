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
 * Dashboard export controller.
 *
 * Handles PDF exports for the utilization dashboard.
 *
 * @package Controllers
 */
class Dashboard_export extends EA_Controller
{
    protected const PRINCIPAL_PDF_FIRST_PAGE_TEACHERS = 5;

    protected const PRINCIPAL_PDF_CONTINUATION_PAGE_TEACHERS = 13;

    protected const TEACHER_PDF_FIRST_PAGE_APPOINTMENTS = 10;

    protected const TEACHER_PDF_CONTINUATION_PAGE_APPOINTMENTS = 10;

    protected Dashboard_metrics $dashboardMetrics;

    protected Pdf_renderer $pdfRenderer;

    protected CI_Zip $zipLibrary;

    protected Services_model $servicesModel;

    protected Appointments_model $appointmentsModel;

    /**
     * Dashboard_export constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->library('dashboard_metrics');
        $this->load->library('pdf_renderer');
        $this->load->library('zip');
        $this->load->model('services_model');
        $this->load->model('appointments_model');
        $this->load->helper('date');

        $this->dashboardMetrics = $this->dashboard_metrics;
        $this->pdfRenderer = $this->pdf_renderer;
        $this->zipLibrary = $this->zip;
        $this->servicesModel = $this->services_model;
        $this->appointmentsModel = $this->appointments_model;
    }

    /**
     * Render the principal report PDF for the selected filters.
     */
    public function principal_pdf(): void
    {
        try {
            $this->assertAdmin();

            $period = $this->resolvePeriod((string) request('start_date'), (string) request('end_date'));

            $statuses = request('statuses', []);
            $service_id = request('service_id');
            $provider_ids = request('provider_ids', []);

            $normalized_statuses = $this->normalizeStatuses($statuses);
            $normalized_service_id = $this->normalizeServiceId($service_id);
            $normalized_provider_ids = $this->normalizeProviderIds($provider_ids);
            $threshold = $this->resolveThreshold(request('threshold'));

            $metrics = $this->dashboardMetrics->collect($period['start'], $period['end'], [
                'statuses' => $normalized_statuses,
                'service_id' => $normalized_service_id,
                'provider_ids' => $normalized_provider_ids,
                'threshold' => $threshold,
            ]);

            $summary = $this->buildSummary($metrics, $threshold);
            $mappedMetrics = $this->mapMetricsForView($metrics, $threshold);
            $sortedPrincipalMetrics = $this->sortPrincipalMetricsForReport($mappedMetrics);

            $this->load->helper('donut');

            $view_data = [
                'school_name' => $this->resolveSchoolName(),
                'logo_data_url' => $this->resolveLogoDataUrl(),
                'generated_at_text' => $this->formatGeneratedAt(new DateTimeImmutable('now')),
                'period_label' => $this->formatPeriod($period['start'], $period['end']),
                'service_label' => $this->resolveServiceLabel($normalized_service_id),
                'status_label' => $this->resolveStatusLabel($normalized_statuses),
                'threshold_percent' => $this->formatPercent($threshold, 0),
                'threshold_ratio' => $threshold,
                'summary' => $summary,
                'metrics' => $sortedPrincipalMetrics,
                'principal_pages' => $this->buildPrincipalPages($sortedPrincipalMetrics),
            ];

            $this->pdfRenderer->stream_view(
                'exports/dashboard_principal_pdf',
                $view_data,
                $this->buildFilename($period['start'], $period['end']),
                $this->buildPdfStreamOptions(APPPATH . '../storage/logs/dashboard_principal_pdf_dump.html'),
            );
        } catch (Throwable $exception) {
            log_message('error', 'Failed to render principal dashboard export: ' . $exception->getMessage());
            abort(400, $exception->getMessage());
        }
    }

    public function teacher_pdf(): void
    {
        try {
            $this->assertAdmin();

            $period = $this->resolvePeriod((string) request('start_date'), (string) request('end_date'));

            $statuses = request('statuses', []);
            $service_id = request('service_id');
            $provider_ids = request('provider_ids', []);

            $normalized_statuses = $this->normalizeStatuses($statuses);
            $normalized_service_id = $this->normalizeServiceId($service_id);
            $normalized_provider_ids = $this->normalizeProviderIds($provider_ids);
            $threshold = $this->resolveThreshold(request('threshold'));

            $metrics = $this->dashboardMetrics->collect($period['start'], $period['end'], [
                'statuses' => $normalized_statuses,
                'service_id' => $normalized_service_id,
                'provider_ids' => $normalized_provider_ids,
                'threshold' => $threshold,
            ]);

            $mappedMetrics = $this->mapMetricsForView($metrics, $threshold);
            $appointments = $this->loadAppointmentsByProvider(
                $metrics,
                $period['start'],
                $period['end'],
                $normalized_statuses,
                $normalized_service_id,
            );

            $teacherReports = $this->mapTeacherReports($metrics, $mappedMetrics, $appointments);

            $view_data = [
                'school_name' => $this->resolveSchoolName(),
                'logo_data_url' => $this->resolveLogoDataUrl(),
                'generated_at_text' => $this->formatGeneratedAt(new DateTimeImmutable('now')),
                'period_label' => $this->formatPeriod($period['start'], $period['end']),
                'threshold_ratio' => $threshold,
                'teachers' => $teacherReports,
                'teacher_pages' => $this->buildTeacherPages($teacherReports),
                'filters' => [
                    'start' => $period['start'],
                    'end' => $period['end'],
                ],
            ];

            $this->pdfRenderer->stream_view(
                'exports/dashboard_teacher_pdf',
                $view_data,
                $this->buildTeacherPdfFilename($period['start'], $period['end']),
                $this->buildPdfStreamOptions(APPPATH . '../storage/logs/dashboard_teacher_pdf_dump.html'),
            );
        } catch (Throwable $exception) {
            log_message('error', 'Failed to render teacher dashboard export: ' . $exception->getMessage());
            abort(400, $exception->getMessage());
        }
    }

    /**
     * Render the teacher report bundle as ZIP (one teacher PDF per file).
     */
    public function teacher_zip(): void
    {
        try {
            $this->assertAdmin();

            $period = $this->resolvePeriod((string) request('start_date'), (string) request('end_date'));

            $statuses = request('statuses', []);
            $service_id = request('service_id');
            $provider_ids = request('provider_ids', []);

            $normalized_statuses = $this->normalizeStatuses($statuses);
            $normalized_service_id = $this->normalizeServiceId($service_id);
            $normalized_provider_ids = $this->normalizeProviderIds($provider_ids);
            $threshold = $this->resolveThreshold(request('threshold'));

            $metrics = $this->dashboardMetrics->collect($period['start'], $period['end'], [
                'statuses' => $normalized_statuses,
                'service_id' => $normalized_service_id,
                'provider_ids' => $normalized_provider_ids,
                'threshold' => $threshold,
            ]);

            $mappedMetrics = $this->mapMetricsForView($metrics, $threshold);
            $appointments = $this->loadAppointmentsByProvider(
                $metrics,
                $period['start'],
                $period['end'],
                $normalized_statuses,
                $normalized_service_id,
            );

            $teacherReports = $this->mapTeacherReports($metrics, $mappedMetrics, $appointments);

            $base_view_data = [
                'school_name' => $this->resolveSchoolName(),
                'logo_data_url' => $this->resolveLogoDataUrl(),
                'generated_at_text' => $this->formatGeneratedAt(new DateTimeImmutable('now')),
                'period_label' => $this->formatPeriod($period['start'], $period['end']),
                'threshold_ratio' => $threshold,
                'filters' => [
                    'start' => $period['start'],
                    'end' => $period['end'],
                ],
            ];

            $this->streamTeacherZipDownload($teacherReports, $base_view_data, $period['start'], $period['end']);
        } catch (Throwable $exception) {
            log_message('error', 'Failed to render teacher dashboard ZIP export: ' . $exception->getMessage());
            abort(400, $exception->getMessage());
        }
    }

    /**
     * Ensure the current user is an authenticated admin.
     */
    protected function assertAdmin(): void
    {
        if (session('role_slug') !== DB_SLUG_ADMIN) {
            abort(403, 'Forbidden');
        }
    }

    /**
     * Build PDF stream options with optional debug dump output.
     */
    protected function buildPdfStreamOptions(?string $debug_dump_path = null): array
    {
        $options = [
            'attachment' => true,
        ];

        if ($debug_dump_path !== null && $debug_dump_path !== '' && $this->isPdfDebugDumpEnabled()) {
            $options['debug_dump_path'] = $debug_dump_path;
        }

        return $options;
    }

    /**
     * Determine whether PDF HTML debug dumps are enabled.
     */
    protected function isPdfDebugDumpEnabled(): bool
    {
        $value = $this->resolvePdfDebugDumpFlag();

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value !== 0.0;
        }

        if (is_string($value)) {
            $normalized = trim($value);

            if ($normalized === '') {
                return false;
            }

            $parsed = filter_var($normalized, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            return $parsed ?? false;
        }

        return false;
    }

    /**
     * Resolve the PDF debug dump flag from environment variables.
     */
    protected function resolvePdfDebugDumpFlag(): mixed
    {
        return env('PDF_RENDERER_DEBUG_DUMP', false);
    }

    /**
     * Validate the provided period parameters.
     *
     * @param string|null $start_input
     * @param string|null $end_input
     *
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable}
     */
    protected function resolvePeriod(?string $start_input, ?string $end_input): array
    {
        if (!$start_input || !$end_input) {
            throw new InvalidArgumentException(lang('filter_period_required'));
        }

        $start = DateTimeImmutable::createFromFormat('Y-m-d', $start_input);
        $end = DateTimeImmutable::createFromFormat('Y-m-d', $end_input);

        if (!$start || $start->format('Y-m-d') !== $start_input) {
            throw new InvalidArgumentException(lang('filter_period_required'));
        }

        if (!$end || $end->format('Y-m-d') !== $end_input) {
            throw new InvalidArgumentException(lang('filter_period_required'));
        }

        if ($start > $end) {
            throw new InvalidArgumentException(lang('filter_period_required'));
        }

        return [
            'start' => $start,
            'end' => $end,
        ];
    }

    /**
     * Normalize the provided statuses payload.
     *
     * @param mixed $statuses
     *
     * @return array
     */
    protected function normalizeStatuses(mixed $statuses): array
    {
        if ($statuses === null) {
            $statuses = [];
        }

        if (!is_array($statuses)) {
            $statuses = [$statuses];
        }

        $normalized = array_map(static fn($value) => trim((string) $value), $statuses);
        $normalized = array_filter($normalized, static fn($value) => $value !== '');

        if (empty($normalized)) {
            return ['Booked'];
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Normalize the provided service ID payload.
     *
     * @param mixed $service_id
     *
     * @return int|null
     */
    protected function normalizeServiceId(mixed $service_id): ?int
    {
        if ($service_id === null || $service_id === '') {
            return null;
        }

        $value = (int) $service_id;

        return $value > 0 ? $value : null;
    }

    /**
     * Normalize the provided provider IDs payload.
     *
     * @param mixed $provider_ids
     *
     * @return array
     */
    protected function normalizeProviderIds(mixed $provider_ids): array
    {
        if ($provider_ids === null) {
            return [];
        }

        if (!is_array($provider_ids)) {
            $provider_ids = [$provider_ids];
        }

        $ids = array_map(static fn($value) => (int) $value, $provider_ids);
        $ids = array_filter($ids, static fn($value) => $value > 0);

        return array_values(array_unique($ids));
    }

    /**
     * Resolve and validate the utilization threshold from request/setting.
     *
     * @param mixed $threshold_input
     *
     * @return float
     */
    protected function resolveThreshold(mixed $threshold_input): float
    {
        if ($threshold_input === null || $threshold_input === '') {
            return $this->getConfiguredThreshold();
        }

        if (is_array($threshold_input) || !is_numeric($threshold_input)) {
            throw new InvalidArgumentException($this->getThresholdValidationMessage());
        }

        $threshold = (float) $threshold_input;

        if (!is_finite($threshold) || $threshold < 0 || $threshold > 1) {
            throw new InvalidArgumentException($this->getThresholdValidationMessage());
        }

        return $threshold;
    }

    /**
     * Return the persisted dashboard threshold with a hard fallback.
     *
     * @return float
     */
    protected function getConfiguredThreshold(): float
    {
        $configured = setting('dashboard_conflict_threshold', '0.90');

        if (!is_numeric($configured)) {
            return 0.9;
        }

        $threshold = (float) $configured;

        if (!is_finite($threshold) || $threshold < 0 || $threshold > 1) {
            return 0.9;
        }

        return $threshold;
    }

    /**
     * Resolve a localized validation message for threshold errors.
     *
     * @return string
     */
    protected function getThresholdValidationMessage(): string
    {
        $message = trim((string) lang('dashboard_conflict_threshold_invalid'));

        return $message !== '' ? $message : 'Please provide a threshold between 0 and 1.';
    }

    /**
     * Build a formatted summary DTO for the PDF.
     *
     * @param array $metrics
     * @param float $threshold
     *
     * @return array
     */
    protected function buildSummary(array $metrics, float $threshold): array
    {
        $total_target = 0;
        $total_booked = 0;
        $total_open = 0;
        $attention_count = 0;
        $fallback_count = 0;
        $explicit_target_count = 0;
        $with_plan_count = 0;
        $missing_to_threshold_total = 0;

        foreach ($metrics as $metric) {
            $total_target += (int) ($metric['target'] ?? 0);
            $total_booked += (int) ($metric['booked'] ?? 0);
            $total_open += (int) ($metric['open'] ?? 0);
            $target = (int) ($metric['target'] ?? 0);
            $booked = (int) ($metric['booked'] ?? 0);
            $threshold_target = (int) ceil($threshold * $target);
            $gap_to_threshold = max($threshold_target - $booked, 0);
            $missing_to_threshold_total += $gap_to_threshold;

            if (!empty($metric['needs_attention'])) {
                $attention_count++;
            }

            if (!empty($metric['is_target_fallback'])) {
                $fallback_count++;
            }

            if (!empty($metric['has_explicit_target'])) {
                $explicit_target_count++;
            }

            if (!empty($metric['has_plan'])) {
                $with_plan_count++;
            }
        }

        $provider_count = count($metrics);
        $fill_rate = $total_target > 0 ? $total_booked / $total_target : 0.0;

        return [
            'provider_count' => $provider_count,
            'fill_rate' => $fill_rate,
            'fill_rate_formatted' => $this->formatPercent($fill_rate, $fill_rate > 0 && $fill_rate < 1 ? 1 : 0),
            'target_total' => $total_target,
            'target_total_formatted' => $this->formatNumber($total_target),
            'booked_total' => $total_booked,
            'booked_total_formatted' => $this->formatNumber($total_booked),
            'open_total' => $total_open,
            'open_total_formatted' => $this->formatNumber($total_open),
            'attention_count' => $attention_count,
            'fallback_count' => $fallback_count,
            'explicit_target_count' => $explicit_target_count,
            'without_target_count' => max($provider_count - $explicit_target_count, 0),
            'with_plan_count' => $with_plan_count,
            'missing_to_threshold_total' => $missing_to_threshold_total,
            'missing_to_threshold_total_formatted' => $this->formatNumber($missing_to_threshold_total),
            'booked_distinct_total' => $total_booked,
            'booked_distinct_total_formatted' => $this->formatNumber($total_booked),
            'providers_below_threshold' => $attention_count,
        ];
    }

    /**
     * Map the metrics payload to view friendly data.
     *
     * @param array $metrics
     * @param float $threshold
     *
     * @return array
     */
    protected function mapMetricsForView(array $metrics, float $threshold): array
    {
        return array_map(function (array $metric) use ($threshold): array {
            $fill_rate = (float) ($metric['fill_rate'] ?? 0);
            $fill_rate_percent = $fill_rate * 100;
            $rounded_percent = round($fill_rate_percent);

            $target = (int) ($metric['target'] ?? 0);
            $booked = (int) ($metric['booked'] ?? 0);
            $open = (int) ($metric['open'] ?? 0);
            $slots_planned = null;
            $slots_required = null;

            if (array_key_exists('slots_planned', $metric) && $metric['slots_planned'] !== null) {
                $slots_planned = (int) round((float) $metric['slots_planned']);
                $slots_planned = max(0, $slots_planned);
            }

            if (array_key_exists('slots_required', $metric) && $metric['slots_required'] !== null) {
                $slots_required = (int) round((float) $metric['slots_required']);
                $slots_required = max(0, $slots_required);
            }

            $fill_rate_decimals = $fill_rate_percent > 0 && $fill_rate_percent < 100 ? 1 : 0;

            $threshold_target = (int) ceil($threshold * $target);
            $gap_to_threshold = max($threshold_target - $booked, 0);
            $is_zero_target = $target === 0;
            $is_under_target = $target > 0 && $gap_to_threshold > 0;

            $status_variant = 'ok';
            $status_label = 'Ok';

            if ($is_under_target) {
                $status_variant = 'warn';
                $status_label = 'Unter Ziel';
            }

            if ($is_zero_target) {
                $status_variant = 'muted';
                $status_label = 'Kein Ziel gepflegt';
            }

            return [
                'provider_name' => (string) ($metric['provider_name'] ?? ''),
                'target' => $this->formatNumber($target),
                'booked' => $this->formatNumber($booked),
                'open' => $this->formatNumber($open),
                'target_raw' => $target,
                'booked_raw' => $booked,
                'open_raw' => $open,
                'fill_rate' => $fill_rate,
                'fill_rate_percent' => $this->formatPercent($fill_rate, $fill_rate_decimals),
                'fill_rate_percent_value' => max(0, min(100, $rounded_percent)),
                'needs_attention' => !empty($metric['needs_attention']),
                'below_threshold' => !empty($metric['needs_attention']),
                'is_target_fallback' => !empty($metric['is_target_fallback']),
                'target_origin_label' => !empty($metric['is_target_fallback'])
                    ? 'Automatische Zielgröße'
                    : 'Klassengröße',
                'has_plan' => !empty($metric['has_plan']),
                'has_explicit_target' => !empty($metric['has_explicit_target']),
                'is_zero_target' => $is_zero_target,
                'threshold_percent' => $threshold * 100,
                'threshold_absolute' => $threshold_target,
                'gap_to_threshold' => $gap_to_threshold,
                'gap_to_threshold_formatted' => $this->formatNumber($gap_to_threshold),
                'fill_ratio' => $fill_rate,
                'status_variant' => $status_variant,
                'status_label' => $status_label,
                'slots_planned_raw' => $slots_planned,
                'slots_planned_formatted' => $slots_planned !== null ? $this->formatNumber($slots_planned) : null,
                'slots_required_raw' => $slots_required,
                'slots_required_formatted' => $slots_required !== null ? $this->formatNumber($slots_required) : null,
                'has_capacity_gap' => !empty($metric['has_capacity_gap']),
                'provider_id' => (int) ($metric['provider_id'] ?? 0),
            ];
        }, $metrics);
    }

    /**
     * Sort principal metrics by urgency (missing-to-threshold first).
     *
     * @param array $metrics
     *
     * @return array
     */
    protected function sortPrincipalMetricsForReport(array $metrics): array
    {
        $sorted_metrics = array_values($metrics);

        usort($sorted_metrics, static function (array $left, array $right): int {
            $gap_sort = ((int) ($right['gap_to_threshold'] ?? 0)) <=> ((int) ($left['gap_to_threshold'] ?? 0));

            if ($gap_sort !== 0) {
                return $gap_sort;
            }

            return ((float) ($left['fill_ratio'] ?? 0)) <=> ((float) ($right['fill_ratio'] ?? 0));
        });

        return $sorted_metrics;
    }

    /**
     * Split principal metrics into first-page and continuation-page chunks.
     *
     * @param array $metrics
     *
     * @return array
     */
    protected function buildPrincipalPages(array $metrics): array
    {
        $metrics_all = array_values($metrics);

        if (empty($metrics_all)) {
            return [[]];
        }

        $first_page_size = max(1, self::PRINCIPAL_PDF_FIRST_PAGE_TEACHERS);
        $continuation_page_size = max(1, self::PRINCIPAL_PDF_CONTINUATION_PAGE_TEACHERS);

        $first_chunk = array_splice($metrics_all, 0, $first_page_size);
        $pages = [$first_chunk];

        while (!empty($metrics_all)) {
            $pages[] = array_splice($metrics_all, 0, $continuation_page_size);
        }

        return $pages;
    }

    /**
     * Resolve the label for the selected service filter.
     *
     * @param int|null $service_id
     *
     * @return string
     */
    protected function resolveServiceLabel(?int $service_id): string
    {
        if ($service_id === null) {
            return 'Alle Angebote';
        }

        $services = $this->servicesModel->get(['id' => $service_id], 1);

        if (empty($services)) {
            return 'Alle Angebote';
        }

        return (string) ($services[0]['name'] ?? 'Alle Angebote');
    }

    /**
     * Build the status label string shown in the PDF metadata block.
     *
     * @param array $statuses
     *
     * @return string
     */
    protected function resolveStatusLabel(array $statuses): string
    {
        if (empty($statuses)) {
            $statuses = ['Booked'];
        }

        $labels = array_map(static function ($status) {
            $key = strtolower((string) $status);
            $translation = lang($key);

            if ($translation) {
                return $translation;
            }

            return (string) $status;
        }, $statuses);

        return implode(', ', $labels);
    }

    /**
     * Resolve the school name for the PDF header.
     *
     * @return string
     */
    protected function resolveSchoolName(): string
    {
        $name = (string) setting('company_name');

        if (trim($name) === '') {
            return 'Forscherhaus Grundschule';
        }

        return $name;
    }

    /**
     * Convert the installation logo into a data URL.
     *
     * @return string|null
     */
    protected function resolveLogoDataUrl(): ?string
    {
        $paths = [APPPATH . 'views/exports/logo.png', FCPATH . 'logo.png'];

        foreach ($paths as $path) {
            if (is_file($path) && is_readable($path)) {
                return $this->pdfRenderer->image_to_data_url($path);
            }
        }

        return null;
    }

    /**
     * Resolve the locale that should be used for localized formatting.
     *
     * @return string|null
     */
    protected function resolveLocale(): ?string
    {
        try {
            $language = (string) setting('language');
        } catch (Throwable $exception) {
            log_message('debug', 'Falling back to default locale for exports: ' . $exception->getMessage());

            return null;
        }

        $normalized = strtolower(str_replace(['_', ' '], ['-', '-'], trim($language)));

        if ($normalized === '') {
            return null;
        }

        $localeMap = [
            'de' => 'de-DE',
            'de-de' => 'de-DE',
            'deutsch' => 'de-DE',
            'german' => 'de-DE',
            'en' => 'en-US',
            'en-us' => 'en-US',
            'en-gb' => 'en-GB',
            'english' => 'en-US',
            'fr' => 'fr-FR',
            'fr-fr' => 'fr-FR',
            'french' => 'fr-FR',
            'pt' => 'pt-PT',
            'pt-pt' => 'pt-PT',
            'pt-br' => 'pt-BR',
            'portuguese' => 'pt-PT',
            'es' => 'es-ES',
            'es-es' => 'es-ES',
            'spanish' => 'es-ES',
            'it' => 'it-IT',
            'it-it' => 'it-IT',
            'italian' => 'it-IT',
            'hu' => 'hu-HU',
            'hu-hu' => 'hu-HU',
            'hungarian' => 'hu-HU',
        ];

        if (array_key_exists($normalized, $localeMap)) {
            return $localeMap[$normalized];
        }

        if (preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $normalized) === 1) {
            $parts = explode('-', $normalized);
            $parts[0] = strtolower($parts[0]);

            if (isset($parts[1])) {
                $parts[1] = strtoupper($parts[1]);
            }

            return implode('-', $parts);
        }

        return null;
    }

    /**
     * Format a DateTime instance according to the installation settings.
     *
     * @param DateTimeImmutable $date
     *
     * @return string
     */
    protected function formatDate(DateTimeImmutable $date): string
    {
        $defaultFormat = 'Y-m-d';

        try {
            if (function_exists('format_date')) {
                $formattedDate = format_date($date);
            } else {
                $format = function_exists('get_date_format') ? get_date_format() : $defaultFormat;
                $formattedDate = $date->format($format);
            }
        } catch (Throwable $exception) {
            log_message('error', 'Invalid date format configuration: ' . $exception->getMessage());
            $formattedDate = $date->format($defaultFormat);
        }

        if (!class_exists('\IntlDateFormatter')) {
            return $formattedDate;
        }

        $locale = $this->resolveLocale();

        if ($locale === null) {
            return $formattedDate;
        }

        $timezone = $date->getTimezone() ?: new DateTimeZone(date_default_timezone_get());
        $formatter = new \IntlDateFormatter(
            $locale,
            \IntlDateFormatter::NONE,
            \IntlDateFormatter::NONE,
            $timezone->getName(),
            \IntlDateFormatter::GREGORIAN,
            'EEE',
        );

        $dayName = $formatter->format($date);

        if ($dayName === false) {
            return $formattedDate;
        }

        return sprintf('%s, %s', $dayName, $formattedDate);
    }

    /**
     * Format the selected period for display.
     *
     * @param DateTimeImmutable $start
     * @param DateTimeImmutable $end
     *
     * @return string
     */
    protected function formatPeriod(DateTimeImmutable $start, DateTimeImmutable $end): string
    {
        $start_day = $start->format('j.');
        $end_day = $end->format('j.');
        $start_month = $this->resolveMonthAbbreviation((int) $start->format('n'), $start);
        $end_month = $this->resolveMonthAbbreviation((int) $end->format('n'), $end);
        $start_year = $start->format('Y');
        $end_year = $end->format('Y');

        if ($start_year === $end_year && $start->format('m') === $end->format('m')) {
            return sprintf('%s–%s %s %s', $start_day, $end_day, $start_month, $start_year);
        }

        if ($start_year === $end_year) {
            return sprintf('%s %s – %s %s %s', $start_day, $start_month, $end_day, $end_month, $start_year);
        }

        return sprintf('%s %s %s – %s %s %s', $start_day, $start_month, $start_year, $end_day, $end_month, $end_year);
    }

    /**
     * Format the generated-at timestamp for display.
     *
     * @param DateTimeImmutable $date
     *
     * @return string
     */
    protected function formatGeneratedAt(DateTimeImmutable $date): string
    {
        $day = $date->format('j.');
        $month = $this->resolveMonthAbbreviation((int) $date->format('n'), $date);
        $year = $date->format('Y');
        $time = $date->format('H:i');

        return sprintf('%s %s %s, %s Uhr', $day, $month, $year, $time);
    }

    /**
     * Format an integer using the locale aware separators.
     *
     * @param int $value
     *
     * @return string
     */
    protected function formatNumber(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    /**
     * Format a ratio as percentage string.
     *
     * @param float $value
     * @param int $decimals
     *
     * @return string
     */
    protected function formatPercent(float $value, int $decimals = 0): string
    {
        $percentage = $value * 100;

        return number_format($percentage, $decimals, ',', '.') . ' %';
    }

    /**
     * Build a file name for the export download.
     *
     * @param DateTimeImmutable $start
     * @param DateTimeImmutable $end
     *
     * @return string
     */
    protected function buildFilename(DateTimeImmutable $start, DateTimeImmutable $end): string
    {
        return sprintf('dashboard-schulleitung-%s-%s.pdf', $start->format('Ymd'), $end->format('Ymd'));
    }

    /**
     * Build a file name for the teacher ZIP export download.
     */
    protected function buildTeacherZipFilename(DateTimeImmutable $start, DateTimeImmutable $end): string
    {
        return sprintf('dashboard-lehrkraefte-%s-%s.zip', $start->format('Ymd'), $end->format('Ymd'));
    }

    /**
     * Build a file name for the teacher PDF export download.
     */
    protected function buildTeacherPdfFilename(DateTimeImmutable $start, DateTimeImmutable $end): string
    {
        return sprintf('dashboard-lehrkraefte-%s-%s.pdf', $start->format('Ymd'), $end->format('Ymd'));
    }

    /**
     * Build a file name for an individual teacher PDF within the ZIP archive.
     *
     * @param array $teacher_report
     * @param DateTimeImmutable $start
     * @param DateTimeImmutable $end
     *
     * @return string
     */
    protected function buildTeacherPdfMemberFilename(
        array $teacher_report,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
    ): string {
        $provider_id = (int) ($teacher_report['provider_id'] ?? 0);
        $provider_name = (string) ($teacher_report['provider_name'] ?? '');
        $provider_slug = $this->slugifyForFilename($provider_name, 'lehrkraft');
        $id_part = $provider_id > 0 ? 'id-' . $provider_id : 'id-unbekannt';

        return sprintf(
            'dashboard-lehrkraft-%s-%s-%s-%s.pdf',
            $id_part,
            $provider_slug,
            $start->format('Ymd'),
            $end->format('Ymd'),
        );
    }

    /**
     * Build a placeholder filename when no teacher report rows are available.
     */
    protected function buildTeacherEmptyPdfFilename(DateTimeImmutable $start, DateTimeImmutable $end): string
    {
        return sprintf('dashboard-lehrkraefte-leer-%s-%s.pdf', $start->format('Ymd'), $end->format('Ymd'));
    }

    /**
     * Resolve the localized month abbreviation.
     *
     * @param int $month
     * @param DateTimeImmutable|null $fallback
     *
     * @return string
     */
    protected function resolveMonthAbbreviation(int $month, ?DateTimeImmutable $fallback = null): string
    {
        $months = [
            1 => 'Jan',
            2 => 'Feb',
            3 => 'Mär',
            4 => 'Apr',
            5 => 'Mai',
            6 => 'Jun',
            7 => 'Jul',
            8 => 'Aug',
            9 => 'Sep',
            10 => 'Okt',
            11 => 'Nov',
            12 => 'Dez',
        ];

        if (array_key_exists($month, $months)) {
            return $months[$month];
        }

        if ($fallback) {
            return $fallback->format('M');
        }

        return '';
    }

    /**
     * Map the raw metrics and appointments into teacher report DTOs.
     *
     * @param array $rawMetrics
     * @param array $mappedMetrics
     * @param array $appointments
     *
     * @return array
     */
    protected function mapTeacherReports(array $rawMetrics, array $mappedMetrics, array $appointments): array
    {
        $reports = [];

        foreach ($mappedMetrics as $index => $metric) {
            $raw = $rawMetrics[$index] ?? [];

            $provider_id = (int) ($metric['provider_id'] ?? ($raw['provider_id'] ?? 0));
            if ($provider_id <= 0) {
                continue;
            }

            $target = max(0, (int) ($metric['target_raw'] ?? ($raw['target'] ?? 0)));
            $booked = max(0, (int) ($metric['booked_raw'] ?? ($raw['booked'] ?? 0)));
            $open = max(0, (int) ($metric['open_raw'] ?? ($raw['open'] ?? $target - $booked)));

            if ($open === 0) {
                $open = max(0, $target - $booked);
            }

            $slots_planned_raw = $metric['slots_planned_raw'] ?? ($raw['slots_planned'] ?? null);
            $slots_required_raw = $metric['slots_required_raw'] ?? ($raw['slots_required'] ?? null);

            $slots_planned = $slots_planned_raw !== null ? max(0, (int) $slots_planned_raw) : null;
            $slots_required = $slots_required_raw !== null ? max(0, (int) $slots_required_raw) : null;

            $fill_rate = $target > 0 ? $booked / $target : 0.0;
            $fill_rate_percent = $this->formatPercent($fill_rate, $fill_rate > 0 && $fill_rate < 1 ? 1 : 0);

            $progress_base = max($target, 1);
            $progress_booked = $progress_base > 0 ? max(0, min(100, round(($booked / $progress_base) * 100))) : 0;
            $progress_open =
                $progress_base > 0 ? max(0, min(100 - $progress_booked, round(($open / $progress_base) * 100))) : 0;

            $appointments_for_provider = $appointments[$provider_id] ?? [];
            $appointment_rows = array_map(function (array $appointment): array {
                $start = new DateTimeImmutable($appointment['start_datetime']);
                $end = new DateTimeImmutable($appointment['end_datetime']);

                return [
                    'parent_lastname' => $this->resolveCustomerLastName($appointment),
                    'date' => $this->formatDate($start),
                    'start' => $this->formatTime($start),
                    'end' => $this->formatTime($end),
                    'notes' => '',
                ];
            }, $appointments_for_provider);

            $slotInfoWithTarget = lang('dashboard_teacher_pdf_slot_info_with_target') ?: '%s von %s Terminen gebucht';
            $slotInfoWithoutTarget = lang('dashboard_teacher_pdf_slot_info_without_target') ?: '%s Termine gebucht';

            $reports[] = [
                'provider_id' => $provider_id,
                'provider_name' => (string) ($metric['provider_name'] ?? ''),
                'target' => $target,
                'target_formatted' => $target > 0 ? $this->formatNumber($target) : '—',
                'booked' => $booked,
                'booked_formatted' => $this->formatNumber($booked),
                'booked_percent_formatted' => $fill_rate_percent,
                'booked_percent_value' => $fill_rate > 0 ? round($fill_rate * 100, 1) : 0.0,
                'open' => $open,
                'open_formatted' => $this->formatNumber($open),
                'slots_planned' => $slots_planned,
                'slots_planned_formatted' => $slots_planned !== null ? $this->formatNumber($slots_planned) : '—',
                'slots_required' => $slots_required,
                'slots_required_formatted' => $slots_required !== null ? $this->formatNumber($slots_required) : '—',
                'has_capacity_gap' => !empty($metric['has_capacity_gap']),
                'appointments' => $appointment_rows,
                'appointments_count' => count($appointment_rows),
                'fill_rate_value' => $fill_rate,
                'progress' => [
                    'booked_percent' => $progress_booked,
                    'open_percent' => $progress_open,
                ],
                'slot_info_text' =>
                    $target > 0
                        ? sprintf($slotInfoWithTarget, $this->formatNumber($booked), $this->formatNumber($target))
                        : sprintf($slotInfoWithoutTarget, $this->formatNumber($booked)),
            ];
        }

        return $reports;
    }

    /**
     * Split teacher appointments into fixed-size PDF pages.
     *
     * @param array $teachers
     *
     * @return array
     */
    protected function buildTeacherPages(array $teachers): array
    {
        $teacher_pages = [];

        foreach ($teachers as $teacher_index => $teacher) {
            $appointments_all = array_values($teacher['appointments'] ?? []);
            $has_any_appointments = !empty($appointments_all);
            $teacher_for_page = $teacher;
            unset($teacher_for_page['appointments']);

            if (!$has_any_appointments) {
                $chunks = [[]];
            } else {
                $first_chunk = array_splice($appointments_all, 0, self::TEACHER_PDF_FIRST_PAGE_APPOINTMENTS);
                $chunks = [$first_chunk];

                while (!empty($appointments_all)) {
                    $chunks[] = array_splice($appointments_all, 0, self::TEACHER_PDF_CONTINUATION_PAGE_APPOINTMENTS);
                }
            }

            $chunks_total = count($chunks);

            foreach ($chunks as $chunk_index => $chunk_appointments) {
                $teacher_pages[] = [
                    'teacher' => $teacher_for_page,
                    'teacher_index' => $teacher_index,
                    'chunk_index' => $chunk_index,
                    'chunks_total' => $chunks_total,
                    'appointments' => $chunk_appointments,
                    'has_any_appointments' => $has_any_appointments,
                ];
            }
        }

        return $teacher_pages;
    }

    /**
     * Render one-teacher PDFs and stream them as a ZIP archive.
     *
     * @param array $teacher_reports
     * @param array $base_view_data
     * @param DateTimeImmutable $start
     * @param DateTimeImmutable $end
     */
    protected function streamTeacherZipDownload(
        array $teacher_reports,
        array $base_view_data,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
    ): void {
        $this->zipLibrary->clear_data();

        if (empty($teacher_reports)) {
            $empty_pdf = $this->pdfRenderer->render_view(
                'exports/dashboard_teacher_pdf',
                array_merge($base_view_data, ['teachers' => [], 'teacher_pages' => []]),
            );

            $this->zipLibrary->add_data($this->buildTeacherEmptyPdfFilename($start, $end), $empty_pdf);
            $this->streamZipDownload($this->buildTeacherZipFilename($start, $end));

            return;
        }

        foreach ($teacher_reports as $teacher_report) {
            $teacher_data = [$teacher_report];
            $pdf_binary = $this->pdfRenderer->render_view(
                'exports/dashboard_teacher_pdf',
                array_merge($base_view_data, [
                    'teachers' => $teacher_data,
                    'teacher_pages' => $this->buildTeacherPages($teacher_data),
                ]),
            );

            $member_filename = $this->buildTeacherPdfMemberFilename($teacher_report, $start, $end);
            $this->zipLibrary->add_data($member_filename, $pdf_binary);
        }

        $this->streamZipDownload($this->buildTeacherZipFilename($start, $end));
    }

    /**
     * Stream the generated ZIP bytes to the browser.
     */
    protected function streamZipDownload(string $filename): void
    {
        $zip_binary = $this->zipLibrary->get_zip();

        if (!is_string($zip_binary) || $zip_binary === '') {
            throw new RuntimeException('ZIP export could not be generated.');
        }

        $safe_filename = $this->sanitizeDownloadFilename($filename, 'dashboard-export.zip');
        $output = $this->output;

        $output->set_header('Content-Type: application/zip');
        $output->set_header(sprintf('Content-Disposition: attachment; filename="%s"', $safe_filename));
        $output->set_header('Content-Length: ' . strlen($zip_binary));
        $output->set_output($zip_binary);
        $output->_display();

        exit();
    }

    /**
     * Make a readable ASCII-safe filename slug.
     */
    protected function slugifyForFilename(string $value, string $fallback = 'export'): string
    {
        $normalized = trim($value);

        if ($normalized === '') {
            return $fallback;
        }

        $normalized = strtr($normalized, [
            'ä' => 'ae',
            'ö' => 'oe',
            'ü' => 'ue',
            'Ä' => 'Ae',
            'Ö' => 'Oe',
            'Ü' => 'Ue',
            'ß' => 'ss',
        ]);
        $normalized = preg_replace('/[^A-Za-z0-9]+/', '-', $normalized) ?? '';
        $normalized = trim($normalized, '-');

        if ($normalized === '') {
            return $fallback;
        }

        return strtolower($normalized);
    }

    /**
     * Ensure attachment filenames are header-safe.
     */
    protected function sanitizeDownloadFilename(string $filename, string $fallback): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '-', trim($filename)) ?? '';
        $safe = trim($safe, '.-');

        if ($safe === '') {
            return $fallback;
        }

        return $safe;
    }

    /**
     * Load appointment rows grouped by provider for the teacher report.
     *
     * @param array $metrics
     * @param DateTimeImmutable $start
     * @param DateTimeImmutable $end
     * @param array $statuses
     * @param int|null $service_id
     *
     * @return array
     */
    protected function loadAppointmentsByProvider(
        array $metrics,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        array $statuses,
        ?int $service_id,
    ): array {
        $provider_ids = array_unique(
            array_map(static fn(array $metric): int => (int) ($metric['provider_id'] ?? 0), $metrics),
        );

        $provider_ids = array_values(array_filter($provider_ids, static fn(int $id): bool => $id > 0));

        if (empty($provider_ids)) {
            return [];
        }

        $query = $this->appointmentsModel
            ->query()
            ->select([
                'appointments.id',
                'appointments.id_users_provider',
                'appointments.start_datetime',
                'appointments.end_datetime',
                'appointments.id_users_customer',
                'appointments.notes',
                'appointments.status',
                'customers.first_name AS customer_first_name',
                'customers.last_name AS customer_last_name',
                'customers.email AS customer_email',
                'customers.phone_number AS customer_phone_number',
            ])
            ->join('users AS customers', 'customers.id = appointments.id_users_customer', 'left')
            ->where('appointments.is_unavailability', false)
            ->where_in('appointments.id_users_provider', $provider_ids)
            ->where('appointments.start_datetime <', $end->setTime(23, 59, 59)->format('Y-m-d H:i:s'))
            ->where('appointments.end_datetime >', $start->setTime(0, 0, 0)->format('Y-m-d H:i:s'));

        if (!empty($statuses)) {
            $query->where_in('appointments.status', $statuses);
        }

        if ($service_id !== null) {
            $query->where('appointments.id_services', $service_id);
        }

        $rows = $query
            ->order_by('appointments.start_datetime', 'ASC')
            ->get()
            ->result_array();

        $grouped = [];

        foreach ($rows as $row) {
            $provider_id = (int) ($row['id_users_provider'] ?? 0);

            if ($provider_id <= 0) {
                continue;
            }

            $grouped[$provider_id][] = $row;
        }

        return $grouped;
    }

    /**
     * Resolve the customer last name with fallback.
     *
     * @param array $appointment
     *
     * @return string
     */
    protected function resolveCustomerLastName(array $appointment): string
    {
        $last_name = trim((string) ($appointment['customer_last_name'] ?? ''));

        if ($last_name !== '') {
            return $last_name;
        }

        $first_name = trim((string) ($appointment['customer_first_name'] ?? ''));

        if ($first_name !== '') {
            return $first_name;
        }

        $email = trim((string) ($appointment['customer_email'] ?? ''));

        if ($email !== '') {
            return $email;
        }

        $phone = trim((string) ($appointment['customer_phone_number'] ?? ''));

        if ($phone !== '') {
            return $phone;
        }

        return '—';
    }

    /**
     * Format a DateTime instance according to the installation time format setting.
     *
     * @param DateTimeImmutable $date
     *
     * @return string
     */
    protected function formatTime(DateTimeImmutable $date): string
    {
        $defaultFormat = 'H:i';

        try {
            if (function_exists('get_time_format')) {
                $format = get_time_format();
            } else {
                $format = $defaultFormat;
            }
        } catch (Throwable $exception) {
            log_message('error', 'Invalid time format configuration: ' . $exception->getMessage());
            $format = $defaultFormat;
        }

        return $date->format($format);
    }
}
