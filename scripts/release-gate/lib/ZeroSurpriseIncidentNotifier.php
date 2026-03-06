<?php

declare(strict_types=1);

namespace ReleaseGate;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;

final class ZeroSurpriseIncidentNotifier
{
    /**
     * @return array{ok:bool,status_code:?int,error:?string,payload:array<string, mixed>,config:array<string, mixed>}
     */
    public function notify(string $configPath, array $context, ?DateTimeImmutable $nowUtc = null): array
    {
        $config = $this->loadConfig($configPath);
        $payload = $this->buildPayload($context, $config, $nowUtc);
        $timeoutSeconds =
            isset($context['timeout_seconds']) && is_numeric($context['timeout_seconds'])
                ? (int) $context['timeout_seconds']
                : (int) $config['timeout_seconds'];

        return $this->send($config, $payload, $timeoutSeconds);
    }

    /**
     * @return array{url:string,authorization_header:?string,report_url_template:?string,timeout_seconds:int}
     */
    public function loadConfig(string $configPath): array
    {
        if (trim($configPath) === '') {
            throw new RuntimeException('Incident webhook config path must not be empty.');
        }

        if (!is_file($configPath) || !is_readable($configPath)) {
            throw new RuntimeException('Incident webhook config is not readable: ' . $configPath);
        }

        $parsed = parse_ini_file($configPath, false, INI_SCANNER_RAW);
        if ($parsed === false || !is_array($parsed)) {
            throw new RuntimeException('Could not parse incident webhook INI: ' . $configPath);
        }

        $url = trim((string) ($parsed['url'] ?? ''));
        if ($url === '') {
            throw new RuntimeException('Incident webhook config requires non-empty "url".');
        }

        $authorizationHeader = trim((string) ($parsed['authorization_header'] ?? ''));
        $reportUrlTemplate = trim((string) ($parsed['report_url_template'] ?? ''));
        $timeoutSeconds = $this->normalizePositiveInt($parsed['timeout_seconds'] ?? 10, 'timeout_seconds');

        return [
            'url' => $url,
            'authorization_header' => $authorizationHeader !== '' ? $authorizationHeader : null,
            'report_url_template' => $reportUrlTemplate !== '' ? $reportUrlTemplate : null,
            'timeout_seconds' => $timeoutSeconds,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @param array{url:string,authorization_header:?string,report_url_template:?string,timeout_seconds:int} $config
     * @return array<string, mixed>
     */
    public function buildPayload(array $context, array $config, ?DateTimeImmutable $nowUtc = null): array
    {
        $releaseId = trim((string) ($context['release_id'] ?? ''));
        $event = trim((string) ($context['event'] ?? ''));
        $severity = trim((string) ($context['severity'] ?? ''));
        $reason = trim((string) ($context['reason'] ?? ''));

        if ($releaseId === '' || $event === '' || $severity === '' || $reason === '') {
            throw new RuntimeException('Incident payload requires event, severity, release_id, and reason.');
        }

        $reportPath = $this->normalizeOptionalString($context['report_path'] ?? null);
        $reportRoot = $this->normalizeOptionalString($context['report_root'] ?? null);
        $failedInvariants = $this->extractFailedInvariants($reportPath);
        $reportUrl = $this->buildReportUrl($config['report_url_template'], $reportPath, $reportRoot, $releaseId);
        $now = ($nowUtc ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));

        return [
            'event' => $event,
            'severity' => $severity,
            'release_id' => $releaseId,
            'reason' => $reason,
            'rollback_result' => $this->normalizeOptionalString($context['rollback_result'] ?? null),
            'report_path' => $reportPath,
            'report_url' => $reportUrl,
            'failed_invariants' => $failedInvariants,
            'log_path' => $this->normalizeOptionalString($context['log_path'] ?? null),
            'breakglass_used' => filter_var($context['breakglass_used'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'ticket' => $this->normalizeOptionalString($context['ticket'] ?? null),
            'timestamp_utc' => $now->format('c'),
        ];
    }

    /**
     * @param array{url:string,authorization_header:?string,report_url_template:?string,timeout_seconds:int} $config
     * @param array<string, mixed> $payload
     * @return array{ok:bool,status_code:?int,error:?string,payload:array<string, mixed>,config:array<string, mixed>}
     */
    public function send(array $config, array $payload, int $timeoutSeconds): array
    {
        $effectiveTimeout = $timeoutSeconds > 0 ? $timeoutSeconds : (int) $config['timeout_seconds'];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $headers = ['Content-Type: application/json'];

        if ($config['authorization_header'] !== null) {
            $headers[] = 'Authorization: ' . $config['authorization_header'];
        }

        $httpContext = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => $effectiveTimeout,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($config['url'], false, $httpContext);
        $statusCode = $this->extractStatusCode($http_response_header ?? []);
        $error = null;

        if ($response === false && $statusCode === null) {
            $error = 'HTTP request failed without response.';
        }

        if ($statusCode !== null && ($statusCode < 200 || $statusCode >= 300)) {
            $error = 'Incident webhook returned HTTP ' . $statusCode . '.';
        }

        return [
            'ok' => $error === null,
            'status_code' => $statusCode,
            'error' => $error,
            'payload' => $payload,
            'config' => $config,
        ];
    }

    /**
     * @return string[]
     */
    public function extractFailedInvariants(?string $reportPath): array
    {
        if ($reportPath === null || !is_file($reportPath) || !is_readable($reportPath)) {
            return [];
        }

        $raw = file_get_contents($reportPath);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $invariants = $decoded['invariants'] ?? null;
        if (!is_array($invariants)) {
            return [];
        }

        $failed = [];
        foreach ($invariants as $name => $payload) {
            if (!is_string($name) || !is_array($payload)) {
                continue;
            }

            if (($payload['status'] ?? null) !== 'pass') {
                $failed[] = $name;
            }
        }

        sort($failed);

        return $failed;
    }

    private function buildReportUrl(
        ?string $template,
        ?string $reportPath,
        ?string $reportRoot,
        string $releaseId,
    ): ?string {
        if ($template === null || $reportPath === null) {
            return null;
        }

        $relativePath = $this->buildRelativePath($reportPath, $reportRoot);
        $basename = basename($reportPath);

        return strtr($template, [
            '{relative_path}' => $relativePath,
            '{basename}' => $basename,
            '{release_id}' => $releaseId,
        ]);
    }

    private function buildRelativePath(string $reportPath, ?string $reportRoot): string
    {
        if ($reportRoot === null || trim($reportRoot) === '') {
            return basename($reportPath);
        }

        $normalizedRoot = rtrim(str_replace('\\', '/', $reportRoot), '/');
        $normalizedPath = str_replace('\\', '/', $reportPath);
        $prefix = $normalizedRoot . '/';

        if ($normalizedPath === $normalizedRoot) {
            return basename($reportPath);
        }

        if (str_starts_with($normalizedPath, $prefix)) {
            return substr($normalizedPath, strlen($prefix));
        }

        return basename($reportPath);
    }

    /**
     * @param mixed[] $headers
     */
    private function extractStatusCode(array $headers): ?int
    {
        foreach ($headers as $header) {
            if (!is_string($header)) {
                continue;
            }

            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    private function normalizePositiveInt(mixed $value, string $field): int
    {
        if (is_int($value)) {
            $normalized = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $normalized = (int) trim($value);
        } else {
            throw new RuntimeException($field . ' must be a positive integer.');
        }

        if ($normalized <= 0) {
            throw new RuntimeException($field . ' must be a positive integer.');
        }

        return $normalized;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
