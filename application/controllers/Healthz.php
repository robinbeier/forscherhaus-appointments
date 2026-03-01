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
 * Health check controller.
 *
 * Provides a token-protected deep health endpoint for monitoring systems.
 *
 * @package Controllers
 */
class Healthz extends EA_Controller
{
    private const PDF_CONNECT_TIMEOUT_SECONDS = 1;
    private const PDF_REQUEST_TIMEOUT_SECONDS = 2;
    private const PDF_LOOPBACK_CONNECT_TIMEOUT_MS = 250;
    private const PDF_LOOPBACK_REQUEST_TIMEOUT_MS = 500;

    /**
     * Return a deep health payload.
     */
    public function index(): void
    {
        $expectedToken = trim((string) env('HEALTHZ_TOKEN', ''));

        if ($expectedToken === '') {
            json_response(
                [
                    'status' => 'error',
                    'message' => 'HEALTHZ_TOKEN is not configured.',
                ],
                503,
                $this->cacheControlHeaders(),
            );

            return;
        }

        $providedToken = trim((string) $this->input->get_request_header('X-Health-Token'));

        if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
            json_response(
                [
                    'status' => 'error',
                    'message' => 'Unauthorized.',
                ],
                401,
                $this->cacheControlHeaders(),
            );

            return;
        }

        $checks = [
            'database' => $this->runCheck(fn() => $this->checkDatabase()),
            'gd' => $this->runCheck(fn() => $this->checkGd()),
            'storage' => $this->runCheck(fn() => $this->checkStorage()),
            'pdf_renderer' => $this->runCheck(fn() => $this->checkPdfRenderer()),
        ];

        $hasFailure = false;

        foreach ($checks as $check) {
            if (empty($check['ok'])) {
                $hasFailure = true;
                break;
            }
        }

        $statusCode = $hasFailure ? 503 : 200;

        json_response(
            [
                'status' => $hasFailure ? 'error' : 'ok',
                'timestamp_utc' => gmdate('c'),
                'checks' => $checks,
            ],
            $statusCode,
            $this->cacheControlHeaders(),
        );
    }

    /**
     * Execute a health check and normalize its output.
     */
    protected function runCheck(callable $callback): array
    {
        $startedAt = microtime(true);

        try {
            $details = $callback();

            $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

            return [
                'ok' => true,
                'latency_ms' => $elapsedMs,
                'details' => is_array($details) ? $details : [],
            ];
        } catch (Throwable $exception) {
            $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
            $message = trim((string) $exception->getMessage());
            $safeMessage = $message !== '' ? $message : 'unknown_error';

            log_message('error', 'Healthz check failed: ' . $safeMessage);

            return [
                'ok' => false,
                'latency_ms' => $elapsedMs,
                'message' => $safeMessage,
            ];
        }
    }

    /**
     * Check database connectivity.
     */
    protected function checkDatabase(): array
    {
        $query = $this->db->query('SELECT 1 AS ok');

        if (!$query) {
            throw new RuntimeException('Database query failed.');
        }

        $row = $query->row_array();
        $value = (int) ($row['ok'] ?? 0);

        if ($value !== 1) {
            throw new RuntimeException('Unexpected database ping result.');
        }

        return ['ping' => 1];
    }

    /**
     * Check whether GD is available for donut rendering.
     */
    protected function checkGd(): array
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('GD extension is missing.');
        }

        return ['imagecreatetruecolor' => true];
    }

    /**
     * Check storage path is writable.
     */
    protected function checkStorage(): array
    {
        $storagePath = APPPATH . '../storage';

        if (!is_dir($storagePath)) {
            throw new RuntimeException('Storage directory is missing.');
        }

        if (!is_writable($storagePath)) {
            throw new RuntimeException('Storage directory is not writable.');
        }

        return ['path' => realpath($storagePath) ?: $storagePath];
    }

    /**
     * Check PDF renderer endpoint.
     */
    protected function checkPdfRenderer(): array
    {
        $endpoints = $this->resolvePdfRendererEndpoints();
        $errors = [];

        foreach ($endpoints as $endpoint) {
            $healthUrl = rtrim($endpoint, '/') . '/healthz';
            $curl = $this->initCurl($healthUrl);

            if ($curl === false) {
                $errors[] = sprintf('%s -> curl_init_failed', $endpoint);
                continue;
            }

            $this->configureCurl($curl, $endpoint);

            $responseBody = $this->executeCurl($curl);
            $curlError = $this->getCurlError($curl);
            $statusCode = $this->getCurlStatusCode($curl);
            $this->closeCurl($curl);

            if ($responseBody === false) {
                $errors[] = sprintf('%s -> %s', $endpoint, $curlError !== '' ? $curlError : 'request_failed');
                continue;
            }

            if ($this->isHealthyPdfRendererResponse((string) $responseBody, $statusCode, $endpoint)) {
                return [
                    'endpoint' => $endpoint,
                    'status_code' => $statusCode,
                ];
            }

            if ($statusCode >= 200 && $statusCode < 300) {
                $errors[] = sprintf('%s -> invalid_health_payload', $endpoint);
                continue;
            }

            $errors[] = sprintf('%s -> status_%d', $endpoint, $statusCode);
        }

        if ($errors === []) {
            $errors[] = 'no_reachable_endpoint';
        }

        throw new RuntimeException('No healthy PDF renderer endpoint found: ' . implode('; ', $errors));
    }

    /**
     * Initialize a cURL request.
     */
    protected function initCurl(string $url): mixed
    {
        return curl_init($url);
    }

    /**
     * Apply cURL defaults for health checks.
     */
    protected function configureCurl(mixed $curl, ?string $endpoint = null): void
    {
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
        ];

        $timeoutOptions =
            $endpoint !== null
                ? $this->resolvePdfTimeoutOptions($endpoint)
                : [
                    CURLOPT_CONNECTTIMEOUT => self::PDF_CONNECT_TIMEOUT_SECONDS,
                    CURLOPT_TIMEOUT => self::PDF_REQUEST_TIMEOUT_SECONDS,
                ];

        foreach ($timeoutOptions as $option => $value) {
            $options[$option] = $value;
        }

        curl_setopt_array($curl, $options);
    }

    /**
     * Execute a cURL request.
     */
    protected function executeCurl(mixed $curl): string|bool
    {
        return curl_exec($curl);
    }

    /**
     * Return the latest cURL error text.
     */
    protected function getCurlError(mixed $curl): string
    {
        return curl_error($curl);
    }

    /**
     * Return the HTTP status code from the response metadata.
     */
    protected function getCurlStatusCode(mixed $curl): int
    {
        return (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    }

    /**
     * Close the cURL handle.
     */
    protected function closeCurl(mixed $curl): void
    {
        curl_close($curl);
    }

    /**
     * Resolve cURL timeout options for a renderer health probe.
     */
    protected function resolvePdfTimeoutOptions(string $endpoint): array
    {
        if (!$this->isLocalEnvironment() && $this->isLoopbackEndpoint($endpoint)) {
            return [
                CURLOPT_CONNECTTIMEOUT_MS => self::PDF_LOOPBACK_CONNECT_TIMEOUT_MS,
                CURLOPT_TIMEOUT_MS => self::PDF_LOOPBACK_REQUEST_TIMEOUT_MS,
            ];
        }

        return [
            CURLOPT_CONNECTTIMEOUT => self::PDF_CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::PDF_REQUEST_TIMEOUT_SECONDS,
        ];
    }

    /**
     * Determine whether an endpoint points at a local loopback host.
     */
    protected function isLoopbackEndpoint(string $endpoint): bool
    {
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));

        return in_array($host, ['localhost', '127.0.0.1'], true);
    }

    /**
     * Determine whether a renderer health response counts as healthy.
     */
    protected function isHealthyPdfRendererResponse(string $responseBody, int $statusCode, string $endpoint): bool
    {
        if ($statusCode < 200 || $statusCode >= 300) {
            return false;
        }

        // In non-local runtimes, require explicit {ok:true} payload for loopback probes.
        if (!$this->isLocalEnvironment() && $this->isLoopbackEndpoint($endpoint)) {
            $payload = json_decode($responseBody, true);

            return is_array($payload) && ($payload['ok'] ?? false) === true;
        }

        return true;
    }

    /**
     * Resolve PDF renderer endpoint candidates.
     */
    protected function resolvePdfRendererEndpoints(): array
    {
        $candidates = [];
        $configured = trim((string) env('PDF_RENDERER_URL', ''));
        $isLocalEnv = $this->isLocalEnvironment();

        if ($configured !== '') {
            $candidates[] = $configured;
        }

        $candidates[] = 'http://pdf-renderer:3000';
        $candidates[] = 'http://localhost:3003';

        // Keep explicit loopback alias for local hosts where localhost is remapped.
        if ($isLocalEnv) {
            $candidates[] = 'http://127.0.0.1:3003';
        }

        $resolved = [];

        foreach ($candidates as $candidate) {
            $normalized = rtrim(trim((string) $candidate), '/');

            if ($normalized === '') {
                continue;
            }

            if (!in_array($normalized, $resolved, true)) {
                $resolved[] = $normalized;
            }
        }

        return $resolved;
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
     * Build no-cache headers for monitoring responses.
     */
    protected function cacheControlHeaders(): array
    {
        return ['Cache-Control: no-store, no-cache, must-revalidate', 'Pragma: no-cache'];
    }
}
