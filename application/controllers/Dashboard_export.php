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
    protected Dashboard_metrics $dashboardMetrics;

    protected Pdf_renderer $pdfRenderer;

    protected Services_model $servicesModel;

    /**
     * Dashboard_export constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->library('dashboard_metrics');
        $this->load->library('pdf_renderer');
        $this->load->model('services_model');

        $this->dashboardMetrics = $this->dashboard_metrics;
        $this->pdfRenderer = $this->pdf_renderer;
        $this->servicesModel = $this->services_model;
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
            $threshold = (float) setting('dashboard_conflict_threshold', '0.75');

            $metrics = $this->dashboardMetrics->collect($period['start'], $period['end'], [
                'statuses' => $normalized_statuses,
                'service_id' => $normalized_service_id,
                'provider_ids' => $normalized_provider_ids,
                'threshold' => $threshold,
            ]);

            $summary = $this->buildSummary($metrics);

            $view_data = [
                'school_name' => $this->resolveSchoolName(),
                'logo_data_url' => $this->resolveLogoDataUrl(),
                'generated_at' => $this->formatDate(new DateTimeImmutable('now')),
                'period_label' => $this->formatPeriod($period['start'], $period['end']),
                'service_label' => $this->resolveServiceLabel($normalized_service_id),
                'status_label' => $this->resolveStatusLabel($normalized_statuses),
                'threshold_percent' => $this->formatPercent($threshold, 0),
                'summary' => $summary,
                'metrics' => $this->mapMetricsForView($metrics, $threshold),
            ];

            $this->pdfRenderer->stream_view(
                'exports/dashboard_principal_pdf',
                $view_data,
                $this->buildFilename($period['start'], $period['end']),
                [
                    'attachment' => true,
                ],
            );
        } catch (Throwable $exception) {
            log_message('error', 'Failed to render principal dashboard export: ' . $exception->getMessage());
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
     * Build a formatted summary DTO for the PDF.
     *
     * @param array $metrics
     *
     * @return array
     */
    protected function buildSummary(array $metrics): array
    {
        $total_target = 0;
        $total_booked = 0;
        $total_open = 0;
        $attention_count = 0;
        $fallback_count = 0;
        $explicit_target_count = 0;
        $with_plan_count = 0;

        foreach ($metrics as $metric) {
            $total_target += (int) ($metric['target'] ?? 0);
            $total_booked += (int) ($metric['booked'] ?? 0);
            $total_open += (int) ($metric['open'] ?? 0);

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

            $fill_rate_decimals = $fill_rate_percent > 0 && $fill_rate_percent < 100 ? 1 : 0;

            return [
                'provider_name' => (string) ($metric['provider_name'] ?? ''),
                'target' => $this->formatNumber($target),
                'booked' => $this->formatNumber((int) ($metric['booked'] ?? 0)),
                'open' => $this->formatNumber((int) ($metric['open'] ?? 0)),
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
                'is_zero_target' => $target === 0,
                'threshold_percent' => $threshold * 100,
            ];
        }, $metrics);
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
        $logo_path = FCPATH . 'logo.png';

        return $this->pdfRenderer->image_to_data_url($logo_path);
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
        $format = setting('date_format') ?: 'd.m.Y';

        return $date->format($format);
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
        return $this->formatDate($start) . ' - ' . $this->formatDate($end);
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
}
