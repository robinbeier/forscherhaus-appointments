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

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * PDF renderer library.
 *
 * Wrapper around the headless Chrome renderer sidecar.
 *
 * @package Libraries
 */
class Pdf_renderer
{
    protected EA_Controller|CI_Controller $CI;

    protected Client $client;

    protected array $endpoints;

    protected ?string $token;

    protected string $defaultPaper;

    protected string $defaultOrientation;

    protected array $defaultMargin;

    protected ?string $defaultWaitFor;

    /**
     * Pdf_renderer constructor.
     *
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        $this->CI = &get_instance();

        $defaults = [
            'paper' => 'A4',
            'orientation' => 'portrait',
            'margin' => [
                'top' => '12mm',
                'right' => '12mm',
                'bottom' => '14mm',
                'left' => '12mm',
            ],
            'wait_for' => 'networkidle',
            'timeout' => 30.0,
            'connect_timeout' => 5.0,
            'base_url' => null,
            'fallback_urls' => [],
            'token' => null,
        ];

        $config = array_replace_recursive($defaults, $config);

        $this->defaultPaper = (string) ($config['paper'] ?? $defaults['paper']);
        $this->defaultOrientation = (string) ($config['orientation'] ?? $defaults['orientation']);
        $this->defaultMargin = $this->normalizeMargin($config['margin'] ?? []);
        $this->defaultWaitFor = $config['wait_for'] !== null ? (string) $config['wait_for'] : null;

        $timeout = (float) ($config['timeout'] ?? $defaults['timeout']);
        $connectTimeout = (float) ($config['connect_timeout'] ?? $defaults['connect_timeout']);

        $this->token = $this->resolveToken($config);
        $this->endpoints = $this->resolveEndpoints($config);

        $this->client = new Client([
            'timeout' => $timeout,
            'connect_timeout' => $connectTimeout,
            'http_errors' => false,
        ]);
    }

    /**
     * Render a view into a PDF binary string.
     *
     * @param string $view
     * @param array $data
     * @param array $options
     *
     * @return string
     */
    public function render_view(string $view, array $data = [], array $options = []): string
    {
        $html = $this->CI->load->view($view, $data, true);

        return $this->render_html($html, $options);
    }

    /**
     * Render an HTML string into a PDF binary string.
     *
     * @param string $html
     * @param array $options
     *
     * @return string
     */
    public function render_html(string $html, array $options = []): string
    {
        $renderOptions = $options;
        $this->dumpDebugHtmlIfRequested($html, $renderOptions);

        $payload = $this->buildPayload($html, $renderOptions);

        $lastException = null;

        foreach ($this->endpoints as $endpoint) {
            try {
                return $this->callRenderer($endpoint, $payload);
            } catch (Throwable $exception) {
                $lastException = $exception;
                log_message('error', 'Pdf_renderer failed for endpoint ' . $endpoint . ': ' . $exception->getMessage());
            }
        }

        if ($lastException instanceof Throwable) {
            throw new RuntimeException('PDF rendering failed for all configured endpoints.', 0, $lastException);
        }

        throw new RuntimeException('PDF rendering failed: no renderer endpoint available.');
    }

    /**
     * Stream an HTML view as PDF to the browser.
     *
     * @param string $view
     * @param array $data
     * @param string $filename
     * @param array $options
     */
    public function stream_view(string $view, array $data, string $filename, array $options = []): void
    {
        $html = $this->CI->load->view($view, $data, true);

        $this->stream_html($html, $filename, $options);
    }

    /**
     * Stream an HTML string as PDF to the browser.
     *
     * @param string $html
     * @param string $filename
     * @param array $options
     */
    public function stream_html(string $html, string $filename, array $options = []): void
    {
        $renderOptions = $options;
        $attachment = array_key_exists('attachment', $renderOptions) ? (bool) $renderOptions['attachment'] : true;
        unset($renderOptions['attachment']);

        $pdf = $this->render_html($html, $renderOptions);

        $sanitizedFilename = $this->sanitizeFilename($filename);
        $dispositionType = $attachment ? 'attachment' : 'inline';

        $output = $this->CI->output;

        $output->set_header('Content-Type: application/pdf');
        $output->set_header(sprintf('Content-Disposition: %s; filename="%s"', $dispositionType, $sanitizedFilename));
        $output->set_header('Content-Length: ' . strlen($pdf));
        $output->set_output($pdf);
        $output->_display();

        exit();
    }

    /**
     * Convert an image file into a base64 data URL.
     *
     * @param string $path
     *
     * @return string|null
     */
    public function image_to_data_url(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $data = @file_get_contents($path);

        if ($data === false) {
            return null;
        }

        $mime = mime_content_type($path);

        if (!$mime) {
            $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

            $mime = match ($extension) {
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'gif' => 'image/gif',
                'svg' => 'image/svg+xml',
                default => 'application/octet-stream',
            };
        }

        return 'data:' . $mime . ';base64,' . base64_encode($data);
    }

    /**
     * Dump the generated HTML to a file when requested via debug option.
     */
    protected function dumpDebugHtmlIfRequested(string $html, array &$options): void
    {
        if (empty($options['debug_dump_path'])) {
            return;
        }

        $path = (string) $options['debug_dump_path'];
        unset($options['debug_dump_path']);

        if ($path === '') {
            return;
        }

        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            log_message('error', 'Pdf_renderer debug dump failed to create directory: ' . $directory);

            return;
        }

        try {
            if (@file_put_contents($path, $html) === false) {
                log_message('error', 'Pdf_renderer debug dump failed to write file: ' . $path);
            }
        } catch (Throwable $exception) {
            log_message('error', 'Pdf_renderer debug dump failed: ' . $exception->getMessage());
        }
    }

    /**
     * Build the payload that will be posted to the renderer sidecar.
     */
    protected function buildPayload(string $html, array $options): array
    {
        $paper = (string) ($options['paper'] ?? ($options['format'] ?? $this->defaultPaper));
        $orientation = (string) ($options['orientation'] ?? $this->defaultOrientation);

        $payload = [
            'html' => $html,
            'format' => $paper,
            'orientation' => $orientation,
            'landscape' => $this->shouldForceLandscape($orientation, $options),
        ];

        $waitFor = $this->resolveWaitFor($options);

        if ($waitFor !== null) {
            $payload['waitFor'] = $waitFor;
        }

        $margin = $this->normalizeMargin($options['margin'] ?? $this->defaultMargin);

        if (!empty($margin)) {
            $payload['margin'] = $margin;
        }

        return $payload;
    }

    /**
     * Decide what wait strategy should be used for the renderer request.
     */
    protected function resolveWaitFor(array $options): ?string
    {
        if (array_key_exists('waitFor', $options)) {
            $value = $options['waitFor'];

            if ($value === null || $value === '') {
                return null;
            }

            return (string) $value;
        }

        if (array_key_exists('wait_for', $options)) {
            $value = $options['wait_for'];

            if ($value === null || $value === '') {
                return null;
            }

            return (string) $value;
        }

        return $this->defaultWaitFor;
    }

    /**
     * Normalise the provided margin array into the format expected by the renderer.
     */
    protected function normalizeMargin(array $margin): array
    {
        $keys = ['top', 'right', 'bottom', 'left'];
        $normalized = [];

        foreach ($keys as $key) {
            if (!array_key_exists($key, $margin)) {
                continue;
            }

            $value = $margin[$key];

            if ($value === null) {
                continue;
            }

            if (is_numeric($value)) {
                $value = $value . 'mm';
            } else {
                $value = (string) $value;
            }

            $trimmed = trim($value);

            if ($trimmed !== '') {
                $normalized[$key] = $trimmed;
            }
        }

        return $normalized;
    }

    /**
     * Determine whether the renderer should produce a landscape PDF.
     */
    protected function shouldForceLandscape(string $orientation, array $options): bool
    {
        if (isset($options['landscape']) && is_bool($options['landscape'])) {
            return $options['landscape'];
        }

        return strtolower($orientation) === 'landscape';
    }

    /**
     * Execute the HTTP call towards the renderer service.
     *
     * @param string $endpoint
     * @param array $payload
     *
     * @return string
     */
    protected function callRenderer(string $endpoint, array $payload): string
    {
        $url = rtrim($endpoint, '/') . '/pdf';

        try {
            $response = $this->client->post($url, [
                'headers' => $this->buildHeaders(),
                'json' => $payload,
            ]);
        } catch (GuzzleException $exception) {
            throw new RuntimeException('Pdf_renderer HTTP request failed: ' . $exception->getMessage(), 0, $exception);
        }

        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            return (string) $response->getBody();
        }

        $body = (string) $response->getBody();

        throw new RuntimeException(
            sprintf('Pdf_renderer responded with status %d: %s', $status, $body !== '' ? $body : 'no body'),
        );
    }

    /**
     * Build the HTTP headers for the renderer request.
     */
    protected function buildHeaders(): array
    {
        $headers = [
            'Accept' => 'application/pdf',
        ];

        if ($this->token) {
            $headers['X-Pdf-Token'] = $this->token;
        }

        return $headers;
    }

    /**
     * Resolve the renderer token from configuration / environment.
     */
    protected function resolveToken(array $config): ?string
    {
        $token = $config['token'] ?? env('PDF_TOKEN');

        if ($token === null) {
            return null;
        }

        $token = trim((string) $token);

        return $token !== '' ? $token : null;
    }

    /**
     * Resolve the renderer endpoints from configuration / environment.
     *
     * @throws RuntimeException
     */
    protected function resolveEndpoints(array $config): array
    {
        $candidates = [];

        $configured = $config['base_url'] ?? env('PDF_RENDERER_URL', '');

        if (is_string($configured) && trim($configured) !== '') {
            $candidates[] = $configured;
        }

        $fallbacks = $config['fallback_urls'] ?? [];

        if (!is_array($fallbacks)) {
            $fallbacks = [$fallbacks];
        }

        $defaults = ['http://pdf-renderer:3000', 'http://localhost:3003'];

        $candidates = array_merge($candidates, $fallbacks, $defaults);

        $normalized = [];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $trimmed = rtrim(trim($candidate), '/');

            if ($trimmed === '') {
                continue;
            }

            if (!in_array($trimmed, $normalized, true)) {
                $normalized[] = $trimmed;
            }
        }

        if (empty($normalized)) {
            throw new RuntimeException('No PDF renderer endpoint configured.');
        }

        return $normalized;
    }

    /**
     * Allow non-local loopback fallback only when explicitly enabled.
     */
    protected function shouldAllowNonLocalLoopbackFallback(): bool
    {
        $raw = env('HEALTHZ_ALLOW_LOOPBACK_FALLBACK', 'false');
        $normalized = strtolower(trim((string) $raw));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Detect whether the app runs in a local-like runtime environment.
     */
    protected function isLocalEnvironment(): bool
    {
        $runtimeEnv = defined('ENVIRONMENT') ? ENVIRONMENT : (getenv('APP_ENV') ?: 'production');
        $appEnv = strtolower(trim((string) $runtimeEnv));

        return in_array($appEnv, ['development', 'testing', 'local'], true);
    }

    /**
     * Sanitize a filename for use in the Content-Disposition header.
     */
    protected function sanitizeFilename(string $filename): string
    {
        $sanitized = str_replace(["\r", "\n"], '', trim($filename));

        if ($sanitized === '') {
            return 'report.pdf';
        }

        return $sanitized;
    }
}
